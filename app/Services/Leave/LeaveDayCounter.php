<?php

namespace App\Services\Leave;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * How many days of leave a request actually costs. F-95.
 *
 * THE DEFECT THIS REPLACES.
 *
 * Leave was charged in CALENDAR days. LeaveAnalyticsService::requestDays() and
 * ::consumedByType() both called
 *
 *     countDays($from, $to, $dayType ?: 1, '')
 *
 * with an empty fourth argument - the skip-day mode - which selects the branch
 *
 *     $daysCount = ($fromDate->diffInDays($toDate) + 1) * $dayType;
 *
 * Every calendar day, times the day type. Sundays counted. Saturdays counted in
 * full. Public holidays counted.
 *
 * Measured on live rows in tenant 3, whose own hrms_weekdays says Saturday is a
 * half day and Sunday is a weekend:
 *
 *   leave #20  2026-04-04 (Sat) -> 2026-04-05 (Sun)   charged 2.0, should be 0.5
 *   leave #21  2026-06-12 (Fri) -> 2026-06-13 (Sat)   charged 2.0, should be 1.5
 *   leave #19  2026-03-05 -> 2026-03-18               charged 14.0, should be 11.0
 *
 * The tenant maintains 21 hrms_weekdays rows and 18 hrms_holidays rows through
 * working screens, and neither changed a single number anywhere in the product.
 *
 * THERE WERE THREE IMPLEMENTATIONS OF THIS SUM. countDays() in PHP, called from
 * two places, and a raw SQL copy in LeaveReportApiController::DAYS_EXPR. This
 * is the one; the count is written onto hrms_emp_leaves.chargeable_days when a
 * request is saved, so the reports sum a column instead of re-deriving it and
 * the two cannot drift.
 */
class LeaveDayCounter
{
    /** A weekday nobody has configured. See daysFor()'s fallback note. */
    private const DEFAULT_WEIGHT = 1.0;

    /** Per-tenant weekday pattern, memoised for the request. */
    private array $weekdayCache = [];

    /** Per-tenant+department holiday dates, memoised for the request. */
    private array $holidayCache = [];

    /**
     * Chargeable days for one request.
     *
     * @param  string  $dayType  '1' for a full day, '0.5' for a half day.
     */
    public function daysFor(
        int $subInstituteId,
        ?int $departmentId,
        string $fromDate,
        ?string $toDate,
        $dayType = '1'
    ): float {
        return $this->breakdown($subInstituteId, $departmentId, $fromDate, $toDate, $dayType)['days'];
    }

    /**
     * The same count, plus why each day was or was not charged.
     *
     * The breakdown exists so the UI can say "2 of these 4 days are a weekend"
     * before the employee submits, rather than quietly charging them and being
     * argued with later.
     *
     * @return array{days: float, charged: array<int, string>, excluded: array<int, array{date: string, reason: string}>}
     */
    public function breakdown(
        int $subInstituteId,
        ?int $departmentId,
        string $fromDate,
        ?string $toDate,
        $dayType = '1'
    ): array {
        $start = Carbon::parse($fromDate)->startOfDay();
        $end   = Carbon::parse($toDate ?: $fromDate)->startOfDay();

        if ($end->lt($start)) {
            [$start, $end] = [$end, $start];
        }

        $isHalfDay = (float) $dayType === 0.5;
        $weights   = $this->weekdayWeights($subInstituteId);
        $holidays  = $this->holidayDates($subInstituteId, $departmentId, $start, $end);

        $days     = 0.0;
        $charged  = [];
        $excluded = [];

        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $key     = $date->format('Y-m-d');
            $weekday = strtolower($date->format('l'));

            if (isset($holidays[$key])) {
                $excluded[] = ['date' => $key, 'reason' => $holidays[$key] ?: 'Public holiday'];
                continue;
            }

            $weight = $weights[$weekday] ?? self::DEFAULT_WEIGHT;

            if ($weight <= 0) {
                $excluded[] = ['date' => $key, 'reason' => 'Weekly off'];
                continue;
            }

            // A half-day request is a single day at half its normal weight, so a
            // half day taken on a half-Saturday costs 0.25 rather than 0.5.
            $days     += $isHalfDay ? $weight * 0.5 : $weight;
            $charged[] = $key;
        }

        return [
            'days'     => round($days, 2),
            'charged'  => $charged,
            'excluded' => $excluded,
        ];
    }

    /**
     * weekday name => how much of a day it is. 1.0 full, 0.5 half, 0.0 off.
     *
     * FALLBACK, AND IT IS DELIBERATE. A tenant with no hrms_weekdays rows gets
     * 1.0 for every day - which is exactly the old behaviour, so an unconfigured
     * tenant sees no change in its numbers from this release. Holidays are still
     * excluded for them, because a holiday is unambiguous and needs no weekly
     * pattern to interpret.
     *
     * Tenant 6 has no weekday rows today; tenants 1, 3 and 7 have all seven.
     */
    private function weekdayWeights(int $subInstituteId): array
    {
        if (isset($this->weekdayCache[$subInstituteId])) {
            return $this->weekdayCache[$subInstituteId];
        }

        $rows = DB::table('hrms_weekdays')
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->get(['day', 'day_type']);

        $weights = [];

        foreach ($rows as $row) {
            $weights[strtolower((string) $row->day)] = match (strtolower((string) $row->day_type)) {
                'weekend', 'off', 'holiday' => 0.0,
                'half'                      => 0.5,
                default                     => 1.0,
            };
        }

        return $this->weekdayCache[$subInstituteId] = $weights;
    }

    /**
     * Holiday dates in the window, keyed Y-m-d => name.
     *
     * A holiday row's `department` is a FIND_IN_SET list; an empty one applies
     * to the whole institute. Matches how AttendanceTrackingApiController and
     * PayrollController already read this table, so the three agree on which
     * days are holidays.
     */
    private function holidayDates(int $subInstituteId, ?int $departmentId, Carbon $start, Carbon $end): array
    {
        $cacheKey = $subInstituteId . ':' . ($departmentId ?: 0) . ':' . $start->format('Ymd') . ':' . $end->format('Ymd');

        if (isset($this->holidayCache[$cacheKey])) {
            return $this->holidayCache[$cacheKey];
        }

        $rows = DB::table('hrms_holidays')
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->where('from_date', '<=', $end->format('Y-m-d'))
            ->where(function ($q) use ($start) {
                $q->where('to_date', '>=', $start->format('Y-m-d'))
                  ->orWhereNull('to_date');
            })
            ->when($departmentId, function ($q) use ($departmentId) {
                $q->where(function ($inner) use ($departmentId) {
                    $inner->whereRaw('FIND_IN_SET(?, department)', [$departmentId])
                          ->orWhereNull('department')
                          ->orWhere('department', '');
                });
            })
            ->get(['holiday_name', 'from_date', 'to_date']);

        $dates = [];

        foreach ($rows as $row) {
            $holidayStart = Carbon::parse($row->from_date)->startOfDay();
            $holidayEnd   = Carbon::parse($row->to_date ?: $row->from_date)->startOfDay();

            for ($date = $holidayStart->copy(); $date->lte($holidayEnd); $date->addDay()) {
                if ($date->betweenIncluded($start, $end)) {
                    $dates[$date->format('Y-m-d')] = $row->holiday_name;
                }
            }
        }

        return $this->holidayCache[$cacheKey] = $dates;
    }
}
