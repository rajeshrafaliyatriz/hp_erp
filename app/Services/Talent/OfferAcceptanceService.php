<?php

namespace App\Services\Talent;

use App\Services\HRMS\EmployeeFactory;
use Illuminate\Support\Facades\DB;

/**
 * Recording a candidate's answer to an offer, and the hire that follows a yes.
 *
 * ── WHY THIS IS A SERVICE AND NOT A CONTROLLER METHOD ───────────────────────
 *
 * Two callers now record the same fact:
 *
 *   TalentOfferController::accept()   HR records the answer on the candidate's behalf
 *   OfferResponseController::respond() the candidate answers their own link
 *
 * If both built the employee themselves they would drift, exactly as
 * `allocated_standards` and `jobtitle_id` drifted before EmployeeFactory existed.
 * So the sequence lives here once and both call it.
 *
 * IDEMPOTENT in two independent ways, because an accepted offer must never
 * produce two employees:
 *   1. an acceptance already marked accepted returns the employee it created;
 *   2. failing that, an existing employee with the candidate's email in this
 *      tenant is adopted rather than duplicated.
 */
class OfferAcceptanceService
{
    public function __construct(private EmployeeFactory $employees)
    {
    }

    /**
     * @return array{ok:bool, status:int, message:string, employee_id:?int, created:bool, invite:array}
     */
    public function accept(object $offer, int $tenantId, string $syear, ?int $actorId, string $decidedVia, ?string $note = null): array
    {
        $existing = $this->existingAcceptance($offer->id, $tenantId);

        // (1) Already accepted. Report the employee it produced; create nothing.
        if ($existing && $existing->decision === 'accepted') {
            return [
                'ok' => true,
                'status' => 200,
                'message' => 'This offer was already accepted.',
                'employee_id' => $existing->accepted_employee_id ? (int) $existing->accepted_employee_id : null,
                'created' => false,
                'invite' => ['sent' => false, 'error' => null],
            ];
        }

        if (in_array($offer->status, ['rejected', 'expired'], true)) {
            return $this->fail('This offer is ' . $offer->status . ' and can no longer be accepted.', 422);
        }

        $application = DB::table('talent_job_applications')
            ->where('id', $offer->application_id)
            ->where('sub_institute_id', $tenantId)
            ->first();

        if (!$application) {
            return $this->fail('Application not found', 404);
        }

        if (!$application->email) {
            return $this->fail('This candidate has no email address, so an employee record cannot be created.', 422);
        }

        // A new hire needs a profile: tbluser.user_profile_id is NOT NULL, and a
        // user whose profile does not resolve to a role_key is refused by every
        // profile-gated route in the product.
        $profileId = DB::table('tbluserprofilemaster')
            ->where('sub_institute_id', $tenantId)
            ->where('role_key', 'employee')
            ->value('id');

        if (!$profileId) {
            return $this->fail('This organisation has no Employee profile, so a new hire cannot be given one.', 422);
        }

        $posting = DB::table('talent_job_postings')
            ->where('id', $offer->job_id)
            ->where('sub_institute_id', $tenantId)
            ->first(['department_id', 'title']);

        $departmentId = $posting->department_id ?? null;

        /*
         * THE JOB ROLE, WHICH A HIRE NEVER USED TO GET.
         *
         * This path set department_id and nothing else about the person's role,
         * so a candidate hired from an offer landed in the Employee Directory
         * with an empty `allocated_standards` - no job role, and therefore no
         * competency expectations, no role-based learning, and nothing for the
         * 9-box or succession planning to read. Someone added by hand through
         * the Add Employee wizard got one; the identical person arriving through
         * recruitment did not.
         *
         * ── WHY THE POSTING'S OWN jobrole_id IS NOT USED ────────────────────
         *
         * `talent_job_postings.jobrole_id` points at `s_jobrole`, the GLOBAL
         * catalogue of 3,347 roles used to generate assessments.
         * `tbluser.allocated_standards` holds an `s_user_jobrole` id - this
         * tenant's own list. Measured: of 21 populated values, 14 exist in
         * s_user_jobrole and ZERO exist in s_jobrole. Copying one into the other
         * would be F-73 again in a new form: a plausible-looking number from the
         * wrong table, silently detaching the employee from every join that
         * reads it.
         *
         * So the posting's TITLE is resolved against this tenant's job roles.
         * Measured on tenant 6: 12 of 13 posting titles match exactly.
         *
         * A miss leaves the column NULL - never the title text. An empty job
         * role is visibly missing and can be set by hand; a name in an id column
         * is corruption that looks like data.
         */
        $jobroleId = !empty($posting->title)
            ? DB::table('s_user_jobrole')
                ->where('sub_institute_id', $tenantId)
                ->whereNull('deleted_at')
                ->where('jobrole', $posting->title)
                ->value('id')
            : null;

        // (2) Adopt an employee that already exists for this address rather than
        // creating a second one - the same person may have been added by hand.
        $employeeId = $this->employees->findByEmail($tenantId, $application->email);
        $created = false;

        try {
            if (!$employeeId) {
                $employeeId = $this->employees->create($tenantId, $actorId ?? 0, [
                    'first_name'      => $application->first_name,
                    'middle_name'     => $application->middle_name,
                    'last_name'       => $application->last_name,
                    'email'           => $application->email,
                    'mobile'          => $application->mobile,
                    'department_id'   => $departmentId ? (int) $departmentId : null,
                    // EmployeeFactory writes BOTH allocated_standards and
                    // jobtitle_id from this one value, and emits
                    // employee.role_assigned so the role's mandatory learning
                    // is assigned. Null when the title did not resolve.
                    'allocated_standards' => $jobroleId ? (string) $jobroleId : null,
                    'user_profile_id' => (int) $profileId,
                    'joined_date'     => $offer->start_date,
                ], [
                    'department_id'  => $departmentId,
                    'effective_date' => $offer->start_date,
                    'remarks'        => 'Hired from offer #' . $offer->id,
                    'event_payload'  => ['offer_id' => (int) $offer->id, 'source' => 'offer_acceptance', 'via' => $decidedVia, 'jobrole_resolved' => (bool) $jobroleId],
                ]);
                $created = true;
            }
        } catch (\Illuminate\Database\QueryException $e) {
            // tbluser_email_unique is global rather than per tenant.
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                return $this->fail('That email address is already in use by another account.', 422);
            }
            throw $e;
        }

        $this->recordDecision($existing, $offer, $application, $tenantId, $syear, $actorId, 'accepted', $decidedVia, $note, $employeeId);

        $invite = $created
            ? $this->employees->issueInvite($application->email)
            : ['sent' => false, 'error' => null];

        return [
            'ok' => true,
            'status' => 200,
            'message' => $created
                ? 'Offer accepted. The candidate is now an employee.'
                : 'Offer accepted. An employee already existed for this address and was linked.',
            'employee_id' => (int) $employeeId,
            'created' => $created,
            'invite' => $invite,
        ];
    }

    /**
     * A no. Recorded with the same care as a yes: the offer and the application
     * both move, so the pipeline stops showing the candidate as live.
     *
     * @return array{ok:bool, status:int, message:string}
     */
    public function decline(object $offer, int $tenantId, string $syear, ?int $actorId, string $decidedVia, ?string $note = null): array
    {
        $existing = $this->existingAcceptance($offer->id, $tenantId);

        if ($existing && $existing->decision === 'accepted') {
            return $this->fail('This offer has already been accepted and cannot be declined.', 422);
        }

        if ($existing && $existing->decision === 'declined') {
            return ['ok' => true, 'status' => 200, 'message' => 'This offer was already declined.'];
        }

        $application = DB::table('talent_job_applications')
            ->where('id', $offer->application_id)
            ->where('sub_institute_id', $tenantId)
            ->first(['id', 'email']);

        DB::transaction(function () use ($existing, $offer, $application, $tenantId, $syear, $actorId, $decidedVia, $note) {
            $this->recordDecision($existing, $offer, $application, $tenantId, $syear, $actorId, 'declined', $decidedVia, $note, null, false);

            DB::table('talent_offers')
                ->where('id', $offer->id)
                ->where('sub_institute_id', $tenantId)
                ->update(['status' => 'rejected', 'rejected_at' => now(), 'updated_at' => now()]);

            if ($application) {
                // Title Case: talent_job_applications.status is the pipeline
                // vocabulary in talent_jobapplicationcontroller::STATUSES.
                DB::table('talent_job_applications')
                    ->where('id', $application->id)
                    ->where('sub_institute_id', $tenantId)
                    ->update(['status' => 'Rejected', 'updated_at' => now()]);
            }
        });

        return ['ok' => true, 'status' => 200, 'message' => 'Your response has been recorded. Thank you for letting us know.'];
    }

    private function existingAcceptance($offerId, int $tenantId)
    {
        return DB::table('talent_offer_acceptances')
            ->where('offer_id', $offerId)
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->first();
    }

    /** Upsert the acceptance row, and move the application when the answer is yes. */
    private function recordDecision(
        $existing, object $offer, $application, int $tenantId, string $syear,
        ?int $actorId, string $decision, string $decidedVia, ?string $note,
        $employeeId, bool $moveApplication = true
    ): void {
        $write = function () use ($existing, $offer, $application, $tenantId, $syear, $actorId, $decision, $decidedVia, $note, $employeeId, $moveApplication) {
            $row = [
                'decision'             => $decision,
                'decided_at'           => now(),
                'decided_via'          => $decidedVia,
                'accepted_employee_id' => $employeeId,
                'candidate_email'      => $application->email ?? null,
                'note'                 => $note,
                'updated_by'           => $actorId,
                'updated_at'           => now(),
            ];

            if ($existing) {
                DB::table('talent_offer_acceptances')->where('id', $existing->id)->update($row);
            } else {
                DB::table('talent_offer_acceptances')->insert($row + [
                    'sub_institute_id' => $tenantId,
                    'syear'            => $syear,
                    'offer_id'         => (int) $offer->id,
                    'application_id'   => (int) $offer->application_id,
                    'created_by'       => $actorId,
                    'created_at'       => now(),
                ]);
            }

            if ($moveApplication && $application) {
                // Moves the candidate to the Hired column and feeds the dashboard's
                // offer-acceptance rate, which reads acceptance from this status.
                DB::table('talent_job_applications')
                    ->where('id', $application->id)
                    ->where('sub_institute_id', $tenantId)
                    ->update(['status' => 'Hired', 'updated_at' => now()]);
            }
        };

        // decline() already owns a transaction; accept() does not.
        DB::transactionLevel() > 0 ? $write() : DB::transaction($write);
    }

    private function fail(string $message, int $status): array
    {
        return ['ok' => false, 'status' => $status, 'message' => $message, 'employee_id' => null, 'created' => false, 'invite' => ['sent' => false, 'error' => null]];
    }
}
