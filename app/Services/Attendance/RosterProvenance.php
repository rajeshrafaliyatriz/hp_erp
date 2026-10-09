<?php

namespace App\Services\Attendance;

use Illuminate\Support\Facades\DB;

/**
 * Where an employee's working hours came from, per weekday.
 *
 * ── THE QUESTION THIS ANSWERS ───────────────────────────────────────────────
 *
 * "Apply to department" writes a template onto its employees' `tbluser`
 * weekday columns. Once an employee can set their own hours and have them
 * approved, an apply would silently flatten that choice - which is exactly
 * what the PREVIOUS version of department shift-setting was deleted from the
 * product for: a bulk write wiped a 14:00 Saturday finish for 100 employees,
 * with no record and no warning.
 *
 * So the apply has to be able to ask, per employee per weekday: did a PERSON
 * choose this, or did a template put it here? Nothing in the schema could
 * answer that. This service is the one place that reads and writes the answer.
 *
 * ── ONE WRITER, THREE CALLERS ───────────────────────────────────────────────
 *
 * Three code paths write the 21 roster columns and all three must stamp
 * provenance, or the feature is wrong while looking right:
 *
 *   DepartmentScheduleController::apply()       source = department_apply
 *   EmployeeScheduleRequestController::decision() on approval
 *                                               source = employee_request
 *   EmployeeDirectoryController::update()       source = hr_directory
 *
 * **The third is the one that gets forgotten.** If an HR edit through the
 * directory does not re-stamp, it leaves a stale `employee_request` row behind -
 * and the department apply then SKIPS an employee whose hours HR itself last
 * set. That single omission is the difference between a feature that protects
 * people's choices and one that quietly refuses to work.
 *
 * ── ABSENCE MEANS "WE DO NOT KNOW" ──────────────────────────────────────────
 *
 * There is nothing to derive this from for rosters written before it shipped,
 * which is every roster that exists today and all 2,008 employees who have no
 * roster at all. So a missing row is NOT employee-set: the skip count starts at
 * zero and fills forward only. Any copy implying otherwise would be inventing
 * history.
 *
 * ── WHY A SIDE TABLE AND NOT A COLUMN ON tbluser ────────────────────────────
 *
 * The full reasoning is in the creating migration's docblock
 * (2026_10_09_100200). The short version: two of those three writers copy EVERY
 * request key into their update, held back only by their own separate
 * blocklists - so a provenance column on `tbluser` would be settable by anyone
 * posting that field, with the worst possible failure mode (provenance claiming
 * "the employee chose this" because somebody said so in a request body).
 * Nothing writes a side table by accident.
 */
class RosterProvenance
{
    /** The only sources that exist. A new one is a code change, not a migration. */
    public const EMPLOYEE_REQUEST  = 'employee_request';
    public const DEPARTMENT_APPLY  = 'department_apply';
    public const HR_DIRECTORY      = 'hr_directory';
    public const IMPORT            = 'import';

    /**
     * Stamp where a set of weekdays came from.
     *
     * One `updateOrInsert` per weekday against the unique index
     * (sub_institute_id, user_id, weekday), so re-stamping a weekday replaces
     * its provenance rather than accumulating a second row - which is what
     * makes "HR edited it last, so the apply may touch it again" expressible at
     * all.
     *
     * @param string[] $weekdays lowercase names - 'monday'..'sunday'
     */
    public function record(
        int $tenantId,
        int $userId,
        array $weekdays,
        string $source,
        ?int $refId,
        ?int $actorId,
    ): void {
        if ($weekdays === [] || $tenantId <= 0 || $userId <= 0) {
            return;
        }

        $now = now();

        foreach ($weekdays as $weekday) {
            DB::table('hrms_employee_roster_provenance')->updateOrInsert(
                [
                    'sub_institute_id' => $tenantId,
                    'user_id'          => $userId,
                    'weekday'          => $weekday,
                ],
                [
                    'source'        => $source,
                    'source_ref_id' => $refId,
                    'set_by'        => $actorId,
                    'set_at'        => $now,
                    'updated_at'    => $now,
                    'created_at'    => $now,
                ],
            );
        }
    }

    /**
     * Provenance for a whole department, in one query.
     *
     * The apply reads every employee at once rather than per employee - the
     * same shape `$headcount` and the grid's `edited` marks already use. A
     * preview that ran a query per employee would be one per person per
     * weekday on a screen HR opens to look at a department.
     *
     * @param  int[] $userIds
     * @return array<int, array<string, array{source: string, source_ref_id: ?int, set_at: ?string}>>
     *         [user_id][weekday] => row
     */
    public function forUsers(int $tenantId, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $rows = DB::table('hrms_employee_roster_provenance')
            ->where('sub_institute_id', $tenantId)
            ->whereIn('user_id', $userIds)
            ->get(['user_id', 'weekday', 'source', 'source_ref_id', 'set_at']);

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row->user_id][$row->weekday] = [
                'source'        => (string) $row->source,
                // Emulated prepares stringify every column, so these are cast
                // rather than passed through - a caller comparing an id would
                // otherwise be comparing a string.
                'source_ref_id' => $row->source_ref_id !== null ? (int) $row->source_ref_id : null,
                'set_at'        => $row->set_at,
            ];
        }

        return $out;
    }

    /**
     * Provenance for one employee.
     *
     * @return array<string, array{source: string, source_ref_id: ?int, set_at: ?string}>
     */
    public function forUser(int $tenantId, int $userId): array
    {
        return $this->forUsers($tenantId, [$userId])[$userId] ?? [];
    }

    /**
     * Did this employee choose this weekday's hours themselves?
     *
     * The question the department apply asks. A missing row answers **false** -
     * see the "absence means we do not know" note above. Reading it any other
     * way would make the apply skip everybody on day one.
     *
     * @param array<string, array{source: string}> $forUser
     */
    public function isEmployeeSet(array $forUser, string $weekday): bool
    {
        return ($forUser[$weekday]['source'] ?? null) === self::EMPLOYEE_REQUEST;
    }
}
