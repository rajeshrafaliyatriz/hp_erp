<?php

namespace App\Services\Dashboard;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * WORKFORCE NUMBERS FOR THE HOME DASHBOARD.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * ONE DEFINITION OF "ACTIVE EMPLOYEE", AND WHY THAT MATTERS HERE
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Three different definitions exist in this codebase today:
 *
 *   Reports\KpiController:37              terminated_date IS NULL
 *   AttendanceDashboardApiController:77   status = 1 AND terminated_date IS NULL
 *   AttendanceDashboardApiController:182  neither
 *
 * Three definitions produce three different headcounts for the same tenant on
 * the same day. This class adopts the middle one for a specific reason: it is
 * the DENOMINATOR of Attendance %. Total Employees and Attendance % sit next to
 * each other on the dashboard, so if they disagree about who counts, the two
 * tiles are arithmetically inconsistent and neither can be trusted.
 *
 * Everything that needs a headcount calls activeEmployeeQuery().
 *
 * ═══════════════════════════════════════════════════════════════════════
 * NULLABLE TENANT COLUMNS ARE REPORTED, NEVER SILENTLY DROPPED
 * ═══════════════════════════════════════════════════════════════════════
 *
 * tbluser.sub_institute_id is NULLABLE. `where('sub_institute_id', $sid)`
 * silently excludes those rows, so a numerator and a denominator built from
 * different tables can disagree without anything looking wrong. Rows with no
 * tenant are excluded — an employee attributed to no organisation cannot be
 * counted in one — but the COUNT of them is returned as a diagnostic so the
 * discrepancy is visible instead of mysterious.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * $scopeUserId EXISTS FROM DAY ONE
 * ═══════════════════════════════════════════════════════════════════════
 *
 * null = the whole organisation (the HR/admin dashboard).
 * An id = that one person (the employee dashboard, built later).
 *
 * It is threaded through now, unused, because retrofitting it later means
 * touching every method and re-verifying every number.
 */
class WorkforceMetricsService
{
    /**
     * THE canonical active-employee query. Do not inline this rule elsewhere.
     */
    public function activeEmployeeQuery(int $sid, ?int $scopeUserId = null, ?int $departmentId = null): Builder
    {
        // EVERY COLUMN IS TABLE-QUALIFIED. departmentDistribution() joins
        // hrms_departments, which carries sub_institute_id and deleted_at of
        // its own — unqualified names make the WHERE ambiguous and MySQL
        // refuses the query outright.
        $q = DB::table('tbluser')
            ->where('tbluser.sub_institute_id', $sid)
            ->where('tbluser.status', 1)
            ->whereNull('tbluser.terminated_date')
            ->whereNull('tbluser.deleted_at');

        if ($scopeUserId !== null) {
            $q->where('tbluser.id', $scopeUserId);
        }

        // The Department filter is applied HERE, at the one query every
        // people-shaped number is built from, so headcount, attendance and the
        // department split cannot disagree about who is in scope.
        if ($departmentId !== null) {
            $q->where('tbluser.department_id', $departmentId);
        }

        return $q;
    }

    /** Headcount, plus the diagnostic that explains any discrepancy. */
    public function headcount(int $sid, ?int $scopeUserId = null, ?int $departmentId = null): array
    {
        return [
            'total'                    => (int) $this->activeEmployeeQuery($sid, $scopeUserId, $departmentId)->count(),
            'employees_without_tenant' => (int) DB::table('tbluser')
                ->whereNull('sub_institute_id')
                ->whereNull('deleted_at')
                ->count(),
        ];
    }

    /**
     * Attendance for one day, as a percentage of active employees.
     *
     * RETURNS null, NOT 0, WHEN NOTHING WAS RECORDED. A weekend, a public
     * holiday, or a tenant with no punch integration all produce zero rows —
     * and "0% of staff attended" is a different, alarming claim from "no
     * attendance was recorded". The first is a bug report; the second is a fact.
     */
    public function attendanceToday(int $sid, ?int $scopeUserId = null, ?int $departmentId = null): array
    {
        $today = date('Y-m-d');

        $present = DB::table('hrms_attendances')
            ->where('sub_institute_id', $sid)
            ->whereDate('day', $today)
            ->where('status', 1)
            ->whereNull('deleted_at');

        if ($scopeUserId !== null) {
            $present->where('user_id', $scopeUserId);
        }

        // Scoping attendance by department means joining the people table:
        // hrms_attendances carries no department of its own.
        if ($departmentId !== null) {
            $present->whereIn('user_id', $this->activeEmployeeQuery($sid, $scopeUserId, $departmentId)->select('tbluser.id'));
        }

        $presentCount = (int) $present->distinct()->count('user_id');
        $active       = (int) $this->activeEmployeeQuery($sid, $scopeUserId, $departmentId)->count();

        // Any row at all for today means attendance IS being recorded; zero
        // rows means it is not, which is the case that must not read as 0%.
        $anyRowsToday = DB::table('hrms_attendances')
            ->where('sub_institute_id', $sid)
            ->whereDate('day', $today)
            ->whereNull('deleted_at')
            ->exists();

        if (!$anyRowsToday || $active === 0) {
            return [
                'value'        => null,
                'present'      => 0,
                'active'       => $active,
                'empty_reason' => $active === 0
                    ? 'No active employees are recorded for this organisation, so attendance cannot be measured.'
                    : 'No attendance has been recorded for ' . date('j M Y') . '. This fills once employees punch in, or an attendance import runs.',
            ];
        }

        return [
            'value'        => round(($presentCount / $active) * 100, 1),
            'present'      => $presentCount,
            'active'       => $active,
            'empty_reason' => null,
        ];
    }

    /**
     * Headcount by department.
     *
     * THE SEGMENTS PLUS `unassigned` MUST EQUAL THE HEADCOUNT TILE. Both
     * tbluser.sub_institute_id and hrms_departments.sub_institute_id are
     * nullable and department_id may be 0 or NULL, so employees can fall out of
     * the join. They go into an explicit "No department" slice rather than
     * vanishing: a pie totalling 253 beside a tile reading 264 discredits both
     * numbers, and nobody can tell which one is wrong.
     */
    public function departmentDistribution(int $sid, int $topN = 5, ?int $departmentId = null): array
    {
        $rows = $this->activeEmployeeQuery($sid, null, $departmentId)
            ->leftJoin('hrms_departments as d', function ($join) use ($sid) {
                $join->on('d.id', '=', 'tbluser.department_id')
                     ->where('d.sub_institute_id', '=', $sid);
            })
            // GROUPED BY NAME, NOT BY ID, and that is deliberate. This tenant
            // has several departments that share a name with a different id
            // (tenant 6: six names duplicated up to 3x; tenant 1: up to 4x).
            // Grouping by id emits three slices all labelled "Data and
            // Artificial Intelligence", which reads as a broken chart and
            // collides as a React key. A reader thinks of those as one
            // department, so they are summed - and the duplication is reported
            // as a diagnostic rather than silently absorbed.
            ->select(
                DB::raw('MIN(d.id) as department_id'),
                'd.department as department_name',
                DB::raw('COUNT(*) as n'),
                DB::raw('COUNT(DISTINCT d.id) as source_rows')
            )
            ->groupBy('d.department')
            ->orderByDesc('n')
            ->get();

        $named      = $rows->filter(fn ($r) => $r->department_id !== null && $r->department_name !== null);
        $unassigned = (int) $rows->filter(fn ($r) => $r->department_id === null)->sum('n');

        $top   = $named->take($topN);
        $other = (int) $named->slice($topN)->sum('n');

        $segments = $top->map(fn ($r) => [
            'id'    => (int) $r->department_id,
            'name'  => (string) $r->department_name,
            'value' => (int) $r->n,
        ])->values()->all();

        if ($other > 0) {
            $segments[] = ['id' => null, 'name' => 'Others', 'value' => $other];
        }

        $total = array_sum(array_column($segments, 'value')) + $unassigned;

        return [
            'segments'   => $segments,
            'unassigned' => $unassigned,
            'total'      => $total,
            // How many department NAMES are backed by more than one row. Shown
            // so a merged slice is explicable rather than mysterious.
            'merged_duplicate_names' => (int) $rows->filter(fn ($r) => (int) $r->source_rows > 1)->count(),
        ];
    }

    /**
     * Overdue tasks.
     *
     * The rule is copied EXACTLY from TaskManagement\ReportController::productivity
     * so the dashboard tile and the task report cannot disagree. Two screens
     * asserting different overdue counts is worse than one screen not showing it.
     *
     * SYEAR is mandatory — the task tables are scoped by academic year as well
     * as tenant, and omitting it silently mixes years.
     */
    public function overdueTasks(int $sid, string $syear, ?int $scopeUserId = null): array
    {
        $q = DB::table('task')
            ->where('sub_institute_id', $sid)
            ->where('SYEAR', $syear)
            ->whereNull('deleted_at')
            ->whereDate('task_date', '<', date('Y-m-d'))
            ->whereRaw("UPPER(COALESCE(status,'PENDING')) <> 'COMPLETED'");

        if ($scopeUserId !== null) {
            $q->where('task_allocated_to', $scopeUserId);
        }

        return [
            'value'                => (int) $q->count(),
            'tasks_without_tenant' => (int) DB::table('task')->whereNull('sub_institute_id')->whereNull('deleted_at')->count(),
        ];
    }

    /** Blocked tasks — ON HOLD is the category the task module uses. */
    public function blockedTasks(int $sid, string $syear, ?int $scopeUserId = null): int
    {
        $q = DB::table('task')
            ->where('sub_institute_id', $sid)
            ->where('SYEAR', $syear)
            ->whereNull('deleted_at')
            ->whereRaw("UPPER(TRIM(COALESCE(status,''))) = 'ON HOLD'");

        if ($scopeUserId !== null) {
            $q->where('task_allocated_to', $scopeUserId);
        }

        return (int) $q->count();
    }
}
