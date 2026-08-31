<?php

namespace App\Services\Dashboard;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * THE SIX-MONTH WORKFORCE & ATTENDANCE SERIES.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * ABSENT IS NOT STORED ANYWHERE — IT IS DERIVED, AND CAREFULLY
 * ═══════════════════════════════════════════════════════════════════════
 *
 *   absent = (expected working days x active employees) - present - leave
 *
 * The naive form, `total - present`, counts every weekend and public holiday
 * as mass absenteeism. That is what the existing weekly attendance endpoint
 * does (AttendanceDashboardApiController treats every calendar day as a working
 * day) and it is why its numbers cannot be trusted for a monthly view.
 *
 * The working calendar comes from two tenant-scoped tables:
 *   hrms_weekdays  - day_type per weekday: full | half | weekend
 *   hrms_holidays  - dated closures, day_type full | half
 *
 * NOTE ON hrms_weekdays: its migration file has no sub_institute_id, but the
 * LIVE table does and it is populated per tenant (verified: tenant 6 has all
 * seven days, sunday = weekend). Migrations have drifted from live in this
 * database; the live column is what this reads.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * THE PERCENTAGE IS DEFINED HERE, BECAUSE THE OLD ONE WAS NOT DEFINABLE
 * ═══════════════════════════════════════════════════════════════════════
 *
 * The static design's numbers were internally inconsistent: Dec 2024 showed
 * present 9,800 / leave 820 / absent 620 against 12,548 employees and called it
 * 96% — 9800/11240 is 87%. No formula reproduces the labelled figure, so it was
 * not copied. This uses:
 *
 *   attendance% = present / (present + absent)
 *
 * i.e. of the people expected at work and not on approved leave, how many came.
 * Leave is excluded from the denominator rather than counted as a failure.
 *
 * NULL, NEVER ZERO: a month with no attendance rows at all returns null for
 * `present` and `attendance`, because "nobody was recorded" is a different fact
 * from "nobody came".
 */
class WorkforceTrendService
{
    /** @return array{months: array<int,array>, weekly_pattern: array, calendar_available: bool, note: ?string} */
    public function series(int $sid, int $months = 6, ?int $scopeUserId = null): array
    {
        $pattern  = $this->weeklyPattern($sid);
        $holidays = $this->holidayDays($sid, $months);

        $out = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $start = Carbon::now()->startOfMonth()->subMonths($i);
            $end   = (clone $start)->endOfMonth();

            $present = $this->presentDays($sid, $start, $end, $scopeUserId);
            $leave   = $this->leaveDays($sid, $start, $end, $scopeUserId);

            // Headcount is measured as it is TODAY for every month. A per-month
            // historical headcount would need joined_date/terminated_date
            // reconstruction, which this schema supports only partially — so it
            // is stated as an approximation rather than silently implied.
            //
            // WHEN SCOPED TO ONE PERSON THE DENOMINATOR IS THAT PERSON. Every
            // other query in this method already honours $scopeUserId; this one
            // did not, because until the employee dashboard existed nothing ever
            // passed a scope. Left as it was, `expected` would be one person's
            // working days multiplied by the whole organisation's headcount, and
            // `absent` — expected minus present minus leave — would report an
            // employee as absent for hundreds of days they were never expected.
            $active = $scopeUserId !== null ? 1 : (int) DB::table('tbluser')
                ->where('tbluser.sub_institute_id', $sid)
                ->where('tbluser.status', 1)
                ->whereNull('tbluser.terminated_date')
                ->whereNull('tbluser.deleted_at')
                ->count();

            $workingDays = $pattern === null ? null : $this->workingDays($start, $end, $pattern, $holidays);
            $expected    = ($workingDays === null || $active === 0) ? null : $workingDays * $active;

            $absent = null;
            if ($expected !== null && $present !== null) {
                $absent = max(0, $expected - $present - $leave);
            }

            $attendance = null;
            if ($present !== null && $absent !== null && ($present + $absent) > 0) {
                $attendance = round(($present / ($present + $absent)) * 100, 1);
            }

            $out[] = [
                'month'                 => $start->format('M Y'),
                'present'               => $present,
                'leave'                 => $leave,
                'absent'                => $absent,
                'attendance'            => $attendance,
                'expected_working_days' => $workingDays,
                'active_employees'      => $active,
            ];
        }

        return [
            'months'             => $out,
            'weekly_pattern'     => $pattern ?? [],
            'calendar_available' => $pattern !== null,
            'note'               => $pattern === null
                ? 'No working-week pattern is configured for this organisation, so Absent and Attendance % cannot be calculated. Set weekly offs under Leave → Configuration.'
                : null,
        ];
    }

    /**
     * weekday name (lowercase) => weight. full = 1, half = 0.5, weekend = 0.
     * Returns null when the tenant has no pattern at all — the caller must then
     * suppress Absent rather than assume a Mon–Fri week on its behalf.
     */
    private function weeklyPattern(int $sid): ?array
    {
        $rows = DB::table('hrms_weekdays')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->get(['day', 'day_type']);

        if ($rows->isEmpty()) {
            return null;
        }

        $map = [];
        foreach ($rows as $r) {
            $type = strtolower(trim((string) $r->day_type));
            $map[strtolower(trim((string) $r->day))] = $type === 'weekend' ? 0.0 : ($type === 'half' ? 0.5 : 1.0);
        }

        return $map;
    }

    /** date (Y-m-d) => weight lost. A full holiday removes a whole working day. */
    private function holidayDays(int $sid, int $months): array
    {
        $from = Carbon::now()->startOfMonth()->subMonths($months);

        $rows = DB::table('hrms_holidays')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->where('to_date', '>=', $from->toDateString())
            ->get(['from_date', 'to_date', 'day_type']);

        $out = [];
        foreach ($rows as $r) {
            if (!$r->from_date) {
                continue;
            }
            $cursor = Carbon::parse($r->from_date);
            $last   = $r->to_date ? Carbon::parse($r->to_date) : (clone $cursor);
            $weight = strtolower((string) $r->day_type) === 'half' ? 0.5 : 1.0;

            while ($cursor->lte($last)) {
                $out[$cursor->toDateString()] = $weight;
                $cursor->addDay();
            }
        }

        return $out;
    }

    /** Working days in the month, net of the weekly pattern and holidays. */
    private function workingDays(Carbon $start, Carbon $end, array $pattern, array $holidays): float
    {
        $total  = 0.0;
        $cursor = (clone $start);

        while ($cursor->lte($end)) {
            $weight = $pattern[strtolower($cursor->format('l'))] ?? 1.0;

            if ($weight > 0 && isset($holidays[$cursor->toDateString()])) {
                $weight = max(0.0, $weight - $holidays[$cursor->toDateString()]);
            }

            $total += $weight;
            $cursor->addDay();
        }

        return $total;
    }

    /**
     * Attended man-days in the month.
     * NULL when the month has no attendance rows at all — not recorded is not
     * the same as nobody attended.
     */
    private function presentDays(int $sid, Carbon $start, Carbon $end, ?int $scopeUserId): ?int
    {
        $q = DB::table('hrms_attendances')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->whereBetween('day', [$start->toDateString(), $end->toDateString()]);

        if ($scopeUserId !== null) {
            $q->where('user_id', $scopeUserId);
        }

        if (!(clone $q)->exists()) {
            return null;
        }

        return (int) (clone $q)->where('status', 1)->count();
    }

    /** Approved leave days overlapping the month. */
    private function leaveDays(int $sid, Carbon $start, Carbon $end, ?int $scopeUserId): int
    {
        $q = DB::table('hrms_emp_leaves')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->whereRaw("LOWER(COALESCE(status,'')) IN ('approved','approved_lwp')")
            ->where('from_date', '<=', $end->toDateString())
            ->where('to_date', '>=', $start->toDateString());

        if ($scopeUserId !== null) {
            $q->where('user_id', $scopeUserId);
        }

        $days = 0;
        foreach ($q->get(['from_date', 'to_date', 'day_type']) as $r) {
            // Only the portion inside this month counts; a leave spanning the
            // month boundary must not be charged twice.
            $from = Carbon::parse($r->from_date)->max($start);
            $to   = Carbon::parse($r->to_date)->min($end);

            if ($from->gt($to)) {
                continue;
            }

            $span   = $from->diffInDays($to) + 1;
            $factor = is_numeric($r->day_type) ? (float) $r->day_type : 1.0;
            $days  += (int) round($span * ($factor > 0 ? $factor : 1.0));
        }

        return $days;
    }
}
