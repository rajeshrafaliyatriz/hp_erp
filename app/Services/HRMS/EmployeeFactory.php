<?php

namespace App\Services\HRMS;

use App\Services\Events\EventRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The one place a `tbluser` row is created from.
 *
 * ── WHY THIS EXISTS ─────────────────────────────────────────────────────────
 *
 * Creating an employee is not one insert. It mints a credential, reserves a
 * user_name, writes the job role into TWO columns because different halves of
 * the codebase read different ones, records a department arrival that
 * department history is later read from, and emits `employee.hired`.
 *
 * Until Sprint 2 that whole sequence lived inside
 * EmployeeDirectoryController::store(), which meant the only way to create an
 * employee was for a human to fill in the Employee Directory form. An accepted
 * offer could not become an employee, so somebody retyped the candidate.
 *
 * Rather than write a second creator - which would drift from the first the way
 * `allocated_standards` and `jobtitle_id` drifted before - the sequence lives
 * here and both callers use it:
 *
 *   EmployeeDirectoryController::store()      a person filling in the form
 *   TalentOfferController::accept()           an accepted offer becoming a hire
 *
 * The rule this class exists to protect: the two writers must not disagree.
 */
class EmployeeFactory
{
    public function __construct(private EventRecorder $events)
    {
    }

    /**
     * Create the employee and everything that must exist alongside it.
     *
     * $attributes must already be whitelisted by the caller - this method does not
     * filter. It sets tenancy, credential, status and audit columns itself and will
     * overwrite any caller-supplied value for them, because those are not the
     * caller's to choose.
     *
     * Throws QueryException 1062 on a duplicate email; `tbluser_email_unique` is
     * global rather than per tenant, and the caller decides how to report it.
     *
     * @param  array{department_id?:int|string|null, effective_date?:string|null, remarks?:string, event_payload?:array}  $options
     */
    public function create(int $tenantId, int $actorId, array $attributes, array $options = []): int
    {
        $email = $attributes['email'] ?? null;

        /*
         * Generated, never supplied and never echoed. tbluser.password is NOT NULL,
         * so creating an employee means creating a credential whether or not anyone
         * intends to use it today. The employee sets their own through the existing
         * password-reset flow; plain_password stays empty, unlike the live rows that
         * carry one in cleartext.
         */
        $attributes['password']         = Hash::make(bin2hex(random_bytes(12)));
        $attributes['sub_institute_id'] = $tenantId;
        $attributes['user_name']        = $attributes['user_name'] ?? $this->uniqueUserName((string) $email);
        $attributes['status']           = $attributes['status'] ?? 1;
        $attributes['created_by']       = $actorId;
        $attributes['created_at']       = now();
        $attributes['updated_at']       = now();

        // Both columns, from the same role id, because the two halves of the codebase
        // read different ones. DepartmentManagementController::applyEmployeeAssignment
        // applies the same rule.
        if (!empty($attributes['allocated_standards'])) {
            $roleId = $attributes['allocated_standards'];
            $attributes['allocated_standards'] = (string) $roleId;
            $attributes['jobtitle_id']         = (int) $roleId;
        }

        return DB::transaction(function () use ($attributes, $tenantId, $actorId, $options, $email) {
            $newId = (int) DB::table('tbluser')->insertGetId($attributes);

            // A new hire joining a department is an arrival like any other, and
            // department history is read from this table.
            if (!empty($options['department_id'])) {
                DB::table('s_mobility_transfers')->insert([
                    'sub_institute_id'   => $tenantId,
                    'user_id'            => $newId,
                    'from_department_id' => null,
                    'to_department_id'   => (int) $options['department_id'],
                    'effective_date'     => $options['effective_date'] ?: now()->toDateString(),
                    'status'             => 'Completed',
                    'remarks'            => $options['remarks'] ?? 'Employee created',
                    'created_by'         => $actorId,
                    'created_at'         => now(),
                    'updated_at'         => now(),
                ]);
            }

            $this->events->record(
                'employee.hired',
                $tenantId,
                'employee',
                $newId,
                $actorId,
                array_merge([
                    'email'         => $email,
                    'department_id' => $options['department_id'] ?? null,
                    'jobrole_id'    => $attributes['allocated_standards'] ?? null,
                ], $options['event_payload'] ?? [])
            );

            /*
             * A NEW HIRE IS ALSO A ROLE ASSIGNMENT, AND NOTHING SAID SO.
             *
             * `employee.role_assigned` is what LearningAssigner reacts to in
             * order to assign a job role's MANDATORY courses
             * (LearningAssigner::HANDLES). It was emitted by the HR Employee
             * Directory when somebody's role changed, and - since F-73 - by
             * Mobility when a transfer or promotion completes.
             *
             * It was never emitted on HIRE. So an existing employee moved into a
             * role received that role's mandatory training, and a brand-new hire
             * given the identical role on day one received nothing. The person
             * who most needs the induction courses was the one person who never
             * got them.
             *
             * Emitted only when a role was actually assigned: a hire with no job
             * role has nothing to assign, and a role_assigned event carrying no
             * role would make LearningAssigner do the work of finding that out.
             *
             * Same transaction as the hire, because they are one fact.
             */
            if (!empty($attributes['allocated_standards'])) {
                $this->events->record(
                    'employee.role_assigned',
                    $tenantId,
                    'employee',
                    $newId,
                    $actorId,
                    [
                        'source'             => 'hire',
                        'from_department_id' => null,
                        'to_department_id'   => $options['department_id'] ?? null,
                        'jobrole_id'         => $attributes['allocated_standards'],
                    ]
                );
            }

            return $newId;
        });
    }

    /**
     * A free user_name derived from the email local part.
     *
     * ── THE REASON GIVEN HERE WAS WRONG ─────────────────────────────────────
     *
     * It used to read: *"`user_name` is indexed but not unique and login
     * resolves on it, so a collision would let one person's credentials reach
     * another's account."*
     *
     * Login does NOT resolve on it. `authController::index()` matches on
     * `email`, which is the table's only unique key; `tbluser.user_name` is
     * written by four code paths and read by none. The alarming premise made
     * this loop look like a security control when it is housekeeping.
     *
     * And the loop does not hold anyway - it is a check-then-insert race with
     * no unique index behind it. Live already carries eight duplicate
     * `user_name` groups in exactly this `first.last` shape.
     *
     * Kept because legacy Blade screens display the value, not because anything
     * depends on it being unique.
     */
    public function uniqueUserName(string $email): string
    {
        $base = Str::slug(Str::before($email, '@'), '.') ?: 'user';
        $candidate = $base;
        $suffix = 1;

        while (DB::table('tbluser')->where('user_name', $candidate)->exists()) {
            $candidate = $base . (++$suffix);
        }

        return $candidate;
    }

    /**
     * Get a set-password link to a new employee.
     *
     * ── THIS METHOD USED TO LIE ─────────────────────────────────────────────
     *
     * It inserted a `password_reset_tokens` row and returned
     *
     *     ['sent' => true, 'error' => null]
     *
     * with no mail call anywhere in it. The token was written and read by
     * nothing. Combined with `create()` minting a random password nobody knows,
     * that meant EVERY EMPLOYEE EVER CREATED HERE COULD NOT LOG IN - while the
     * Add Employee sheet told them an invite had been emailed.
     *
     * The work now lives in InviteService, which reports one of three states
     * and never claims a send it did not make. This wrapper stays so existing
     * callers keep working, and returns the fuller shape.
     *
     * @return array{delivered:string, link:?string, error:?string, expires_hours:int, sent:bool}
     */
    public function issueInvite(?string $email, ?int $tenantId = null, string $purpose = 'invite'): array
    {
        $result = app(\App\Services\Auth\InviteService::class)->issue($email, $tenantId, $purpose);

        /*
         * `sent` is kept for callers that have not moved to `delivered` yet -
         * and it is now TRUE ONLY WHEN SOMETHING WAS ACTUALLY SENT. A link the
         * administrator must hand over is not a send, and the old code calling
         * it one is the entire defect.
         */
        return $result + ['sent' => $result['delivered'] === 'email'];
    }

    /**
     * The id of an existing employee with this email in this tenant, if any.
     *
     * Used to make offer acceptance idempotent: accepting twice must return the
     * employee already created, never a second one.
     */
    public function findByEmail(int $tenantId, ?string $email): ?int
    {
        if (!$email) {
            return null;
        }

        $id = DB::table('tbluser')
            ->where('sub_institute_id', $tenantId)
            ->where('email', $email)
            ->value('id');

        return $id ? (int) $id : null;
    }
}
