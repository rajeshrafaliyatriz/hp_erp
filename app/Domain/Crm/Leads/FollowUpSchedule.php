<?php

namespace App\Domain\Crm\Leads;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * When the next follow-up may go out. Pure: no database, no clock of its own (the caller passes "now").
 *
 * A follow-up is only ever scheduled INSIDE business hours: the wait is added first, then the result is moved to the next
 * moment that is within [window_start, window_end) on an allowed day, in the cadence's own time zone. So "2 days after a
 * Friday 16:30 attempt, weekdays only" is Tuesday 09:00, not Sunday at 16:30.
 */
final class FollowUpSchedule
{
    /** @return list<int> the waits in days, one per gap between attempts; never empty, each 0-60 */
    public static function parseIntervals(string $intervals): array
    {
        $out = [];
        foreach (explode(',', $intervals) as $part) {
            $part = trim($part);
            if ($part === '' || ! ctype_digit($part) || (int) $part > 60) {
                return [];
            }
            $out[] = (int) $part;
        }

        return $out;
    }

    /** The wait that follows attempt number `$attempt` (1-based). Past the end of the list the last wait repeats. */
    public static function waitAfter(array $intervals, int $attempt): int
    {
        return $intervals[min($attempt, count($intervals)) - 1] ?? 1;
    }

    /** Valid "HH:MM" (00:00-23:59). */
    public static function validTime(string $t): bool
    {
        return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t);
    }

    /**
     * @param  string  $timezone  an IANA zone such as Asia/Kolkata
     * @return CarbonImmutable the moment, expressed in $timezone
     */
    public static function nextSlot(CarbonInterface $from, int $waitDays, string $timezone, string $windowStart, string $windowEnd, bool $weekdaysOnly): CarbonImmutable
    {
        $t = CarbonImmutable::instance($from)->setTimezone($timezone)->addDays($waitDays);
        [$sh, $sm] = array_map('intval', explode(':', $windowStart));
        [$eh, $em] = array_map('intval', explode(':', $windowEnd));

        for ($i = 0; $i < 15; $i++) {
            $start = $t->setTime($sh, $sm, 0);
            $end = $t->setTime($eh, $em, 0);
            if ($weekdaysOnly && $t->isWeekend()) {
                $t = $t->addDay()->setTime($sh, $sm, 0);

                continue;
            }
            if ($t->lessThan($start)) {
                return $start;
            }
            if ($t->greaterThanOrEqualTo($end)) {
                $t = $t->addDay()->setTime($sh, $sm, 0);

                continue;
            }

            return $t;
        }

        return $t; // unreachable for a valid window; never loops forever
    }
}
