<?php

namespace App\Services\Talent;

use Illuminate\Support\Facades\DB;

/**
 * The one place an exit case is created from.
 *
 * ── WHY THIS EXISTS ─────────────────────────────────────────────────────────
 *
 * Two things now start an exit:
 *
 *   OffboardingController::store()                HR opens a case by hand
 *   OnboardingProbationController::decide()       a probation is terminated
 *
 * The second did not exist. Terminating a probation set `confirmation_status`
 * and cancelled the journey and stopped there - no exit case, no clearance
 * checklist, and not even `tbluser.terminated_date`, so the Lifecycle Timeline
 * for a terminated hire stayed blank forever. Somebody had to notice and open
 * the case by hand, or the person simply stayed on the books.
 *
 * ── WHICH GENERATION THIS TARGETS, AND WHY IT MATTERS ───────────────────────
 *
 * There used to be two offboarding controllers with overlapping names. The v1
 * Api\Talent\OffboardingCaseController wrote `case_code`, `resignation_date`,
 * `fnf_status`, `owner_id` and `closed_at` - NONE of which exist on
 * talent_offboarding_cases. It could not execute at all; the migration that
 * would have added those columns was never written. Sprint 6 deleted it, along
 * with the rest of that generation.
 *
 * This service targets the v2 shape, which is what the live Offboarding Center
 * reads. Building the probation handoff against v1 would have produced an
 * immediate "Unknown column" and looked like a bug in the handoff - which is
 * why the dead generation was worth removing rather than leaving to mislead.
 */
class OffboardingCaseFactory
{
    /** Seeded onto every new case so the Clearance Tracker has something to track. */
    public const DEFAULT_CLEARANCE_TASKS = [
        ['id' => 'c1', 'department' => 'IT', 'item' => 'Laptop Return', 'status' => 'Pending'],
        ['id' => 'c2', 'department' => 'IT', 'item' => 'Access Revocation', 'status' => 'Pending'],
        ['id' => 'c3', 'department' => 'IT', 'item' => 'Email Deactivation', 'status' => 'Pending'],
        ['id' => 'c4', 'department' => 'HR', 'item' => 'ID Card Return', 'status' => 'Pending'],
        ['id' => 'c5', 'department' => 'HR', 'item' => 'NDA Signoff', 'status' => 'Pending'],
        ['id' => 'c6', 'department' => 'Finance', 'item' => 'Expense Settlement', 'status' => 'Pending'],
        ['id' => 'c7', 'department' => 'Finance', 'item' => 'Final Dues Calculation', 'status' => 'Pending'],
        ['id' => 'c8', 'department' => 'Admin', 'item' => 'Desk Keys Return', 'status' => 'Pending'],
    ];

    public const DEFAULT_DOCUMENTS = [
        ['id' => 'd1', 'title' => 'Resignation Letter', 'fileName' => 'resignation.pdf', 'status' => 'Submitted', 'isMandatory' => true],
        ['id' => 'd2', 'title' => 'Clearance Certificate', 'fileName' => null, 'status' => 'Pending', 'isMandatory' => true],
        ['id' => 'd3', 'title' => 'Exit Survey Form', 'fileName' => null, 'status' => 'Pending', 'isMandatory' => false],
        ['id' => 'd4', 'title' => 'Signed NDA', 'fileName' => null, 'status' => 'Pending', 'isMandatory' => true],
    ];

    /**
     * Open an exit case, unless one is already open for this employee.
     *
     * Returns the id of the case — the new one, or the existing open one. The
     * caller is told which by `created`, because a probation termination that
     * silently did nothing because a case already existed would be worse than an
     * error.
     *
     * @return array{ok:bool, case_id:?int, created:bool, message:string}
     */
    public function open(
        int $tenantId,
        int $employeeId,
        ?int $actorId,
        array $attributes,
        string $openedBecause
    ): array {
        /*
         * `reporting_manager_id`, not `reportmanager`.
         *
         * OffboardingController::store() reads `$employee->reportmanager`, which
         * is not a column on tbluser. It gets away with it because it selects the
         * whole row, so the undefined property reads as null and every case it has
         * ever created silently has no manager. Selecting explicitly turned that
         * into a hard error, which is how it was found.
         */
        $employee = DB::table('tbluser')
            ->where('sub_institute_id', $tenantId)
            ->where('id', $employeeId)
            ->first(['id', 'department_id', 'city', 'reporting_manager_id']);

        if (!$employee) {
            return ['ok' => false, 'case_id' => null, 'created' => false, 'message' => 'Selected employee not found'];
        }

        // One open case per person. 'Closed' is the only terminal status.
        $existing = DB::table('talent_offboarding_cases')
            ->where('sub_institute_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->whereNull('deleted_at')
            ->where('status', '!=', 'Closed')
            ->first(['id']);

        if ($existing) {
            return [
                'ok' => true,
                'case_id' => (int) $existing->id,
                'created' => false,
                'message' => 'An exit case was already open for this employee.',
            ];
        }

        $actor = $actorId ? DB::table('tbluser')->where('id', $actorId)->first(['first_name', 'last_name']) : null;
        $actorName = $actor ? trim($actor->first_name . ' ' . $actor->last_name) : 'HR Specialist';

        $caseId = DB::table('talent_offboarding_cases')->insertGetId([
            'sub_institute_id' => $tenantId,
            'employee_id' => $employeeId,
            'department_id' => $employee->department_id,
            'location' => $attributes['location'] ?? $employee->city ?? 'Main Campus',
            'exit_type' => $attributes['exit_type'],
            'exit_reason' => $attributes['exit_reason'],
            'notice_date' => $attributes['notice_date'],
            'last_working_day' => $attributes['last_working_day'],
            // varchar(20). 'Resignation Submitted' is 21 characters and cannot
            // round-trip, which is why a new case starts at Notice Period.
            'status' => 'Notice Period',
            'manager_id' => $attributes['manager_id'] ?? $employee->reporting_manager_id ?? null,
            'clearance_tasks' => json_encode(self::DEFAULT_CLEARANCE_TASKS),
            'documents' => json_encode(self::DEFAULT_DOCUMENTS),
            'comments' => json_encode([]),
            'activity_log' => json_encode([[
                'id' => uniqid(),
                'action' => 'Exit Case Opened',
                'description' => $openedBecause,
                'timestamp' => date('d M Y, h:i A'),
                'actor' => $actorName,
            ]]),
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'ok' => true,
            'case_id' => (int) $caseId,
            'created' => true,
            'message' => 'Exit case created.',
        ];
    }
}
