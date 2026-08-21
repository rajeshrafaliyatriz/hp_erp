<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * The one place the application decides WHICH JOB ROLE AN EMPLOYEE HOLDS.
 *
 * An employee's role is reachable through two columns on tbluser, and neither
 * one alone is sufficient:
 *
 *   jobtitle_id          a job role id. Set on 23 of 98 live employees;
 *                        0 for everybody else.
 *   allocated_standards  a TEXT column that also holds a job role id (the name
 *                        is a leftover from when it held academic standards).
 *                        Set on 95 of 98. This is what the employee edit form
 *                        actually writes - see the job role field in
 *                        edit-employee/personal-info-tab.tsx - which is why
 *                        jobtitle_id is empty for most people.
 *
 * Reading jobtitle_id alone resolves 23 of 98 employees. Reading it with the
 * allocated_standards fallback resolves 96 of 98. Seven controllers and
 * services were doing the former and silently seeing nothing for 76% of
 * employees, while three Competency controllers had each written their own
 * correct copy of the fallback.
 *
 * This is that fallback, written once. Extracted from
 * CareerPathController::roleForUser(), which documented the problem first.
 *
 * NEVER read jobtitle_id or allocated_standards directly. Call jobRoleForUser()
 * or resolveJobRoleId().
 */
trait ResolvesEmployeeJobRole
{
    /**
     * The employee's job role row, or null.
     *
     * Order: jobtitle_id, then allocated_standards, then - if
     * $useAssessmentFallback - the job role named on their most recent
     * competency assessment. That last step is how a role is recovered for
     * someone whose profile columns were never filled in, and is opt-in
     * because it costs a second query and only the Competency module has a
     * reason to want it.
     */
    protected function jobRoleForUser(int $subInstituteId, int $userId, bool $useAssessmentFallback = false)
    {
        $user = DB::table('tbluser')
            ->where('id', $userId)
            ->where('sub_institute_id', $subInstituteId)
            ->first(['id', 'jobtitle_id', 'allocated_standards']);

        if (!$user) {
            return null;
        }

        $role = $this->jobRoleFromUserRow($subInstituteId, $user);

        if ($role || !$useAssessmentFallback) {
            return $role;
        }

        $recent = DB::table('s_competency_assessments')
            ->where('sub_institute_id', $subInstituteId)
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->whereNotNull('jobrole')
            ->where('jobrole', '!=', '')
            ->orderByDesc('id')
            ->first(['jobrole']);

        return $recent ? $this->jobRoleByName($subInstituteId, $recent->jobrole) : null;
    }

    /**
     * Same resolution, for a tbluser row you have already loaded.
     *
     * Use this inside a loop over many employees - jobRoleForUser() would
     * re-query tbluser once per person.
     */
    protected function jobRoleFromUserRow(int $subInstituteId, $user)
    {
        foreach ($this->candidateJobRoleIds($user) as $roleId) {
            $role = DB::table('s_user_jobrole')
                ->where('id', $roleId)
                ->where('sub_institute_id', $subInstituteId)
                ->whereNull('deleted_at')
                ->first();

            if ($role) {
                return $role;
            }
        }

        return null;
    }

    /**
     * Just the id, without loading the role. For writes and comparisons.
     */
    protected function resolveJobRoleId($user): ?int
    {
        foreach ($this->candidateJobRoleIds($user) as $roleId) {
            return $roleId;
        }

        return null;
    }

    /**
     * The candidate ids in priority order, skipping empties.
     *
     * @return list<int>
     */
    protected function candidateJobRoleIds($user): array
    {
        $candidates = [];

        $jobtitleId = (int) ($user->jobtitle_id ?? 0);
        if ($jobtitleId > 0) {
            $candidates[] = $jobtitleId;
        }

        $allocated = $this->firstNumericId($user->allocated_standards ?? null);
        if ($allocated !== null && $allocated > 0 && !in_array($allocated, $candidates, true)) {
            $candidates[] = $allocated;
        }

        return $candidates;
    }

    protected function jobRoleByName(int $subInstituteId, string $name)
    {
        return DB::table('s_user_jobrole')
            ->where('sub_institute_id', $subInstituteId)
            ->where('jobrole', $name)
            ->whereNull('deleted_at')
            ->first();
    }

    /**
     * First numeric entry of a comma-separated value.
     *
     * allocated_standards is TEXT and historically held a list, so a bare
     * (int) cast is not safe. Live data is single-valued today, but the column
     * has no constraint preventing a list from reappearing.
     */
    protected function firstNumericId($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        foreach (array_map('trim', explode(',', (string) $value)) as $part) {
            if (is_numeric($part)) {
                return (int) $part;
            }
        }

        return null;
    }
}
