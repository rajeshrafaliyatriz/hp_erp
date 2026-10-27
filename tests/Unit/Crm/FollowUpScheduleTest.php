<?php

namespace Tests\Unit\Crm;

use App\Domain\Crm\Leads\FollowUpSchedule;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/** The follow-up timing rules, with no database and no clock: business hours, weekends and time zones. */
class FollowUpScheduleTest extends TestCase
{
    private function slot(string $fromUtc, int $wait, bool $weekdays = true, string $tz = 'Asia/Kolkata', string $start = '09:00', string $end = '17:00'): string
    {
        return FollowUpSchedule::nextSlot(CarbonImmutable::parse($fromUtc, 'UTC'), $wait, $tz, $start, $end, $weekdays)->format('D Y-m-d H:i');
    }

    public function test_intervals_parse_and_reject_garbage(): void
    {
        $this->assertSame([2, 4, 7], FollowUpSchedule::parseIntervals('2, 4,7'));
        $this->assertSame([0], FollowUpSchedule::parseIntervals('0'));
        foreach (['', 'a', '2,,4', '-1', '61', '1.5', '2;4'] as $bad) {
            $this->assertSame([], FollowUpSchedule::parseIntervals($bad), "'$bad' must be refused");
        }
    }

    public function test_the_wait_after_an_attempt_repeats_the_last_value(): void
    {
        $this->assertSame(2, FollowUpSchedule::waitAfter([2, 4, 7], 1));
        $this->assertSame(7, FollowUpSchedule::waitAfter([2, 4, 7], 3));
        $this->assertSame(7, FollowUpSchedule::waitAfter([2, 4, 7], 9));
    }

    public function test_times_are_validated(): void
    {
        foreach (['00:00', '09:30', '23:59'] as $ok) {
            $this->assertTrue(FollowUpSchedule::validTime($ok));
        }
        foreach (['24:00', '9:00', '09:60', 'noon', ''] as $bad) {
            $this->assertFalse(FollowUpSchedule::validTime($bad), "'$bad'");
        }
    }

    public function test_inside_the_window_sends_immediately(): void
    {
        // Wed 2026-10-21 10:00 IST = 04:30 UTC
        $this->assertSame('Wed 2026-10-21 10:00', $this->slot('2026-10-21 04:30:00', 0));
    }

    public function test_before_the_window_waits_for_the_opening_time(): void
    {
        // Wed 07:30 IST = 02:00 UTC
        $this->assertSame('Wed 2026-10-21 09:00', $this->slot('2026-10-21 02:00:00', 0));
    }

    public function test_after_the_window_waits_for_the_next_morning(): void
    {
        // Wed 18:00 IST = 12:30 UTC
        $this->assertSame('Thu 2026-10-22 09:00', $this->slot('2026-10-21 12:30:00', 0));
    }

    public function test_the_window_end_itself_is_closed(): void
    {
        // Wed 17:00 IST exactly = 11:30 UTC
        $this->assertSame('Thu 2026-10-22 09:00', $this->slot('2026-10-21 11:30:00', 0));
    }

    public function test_a_wait_that_lands_on_a_weekend_moves_to_monday_when_weekdays_only(): void
    {
        // Fri 2026-10-23 16:30 IST = 11:00 UTC; +2 days = Sunday
        $this->assertSame('Mon 2026-10-26 09:00', $this->slot('2026-10-23 11:00:00', 2));
        // Fri after hours, no wait: Saturday -> Monday
        $this->assertSame('Mon 2026-10-26 09:00', $this->slot('2026-10-23 12:30:00', 0));
    }

    public function test_weekends_are_allowed_when_weekdays_only_is_off(): void
    {
        $this->assertSame('Sun 2026-10-25 16:30', $this->slot('2026-10-23 11:00:00', 2, false));
        $this->assertSame('Sat 2026-10-24 09:00', $this->slot('2026-10-23 12:30:00', 0, false));
    }

    public function test_the_time_zone_decides_which_day_and_hour_it_is(): void
    {
        // 2026-10-21 23:00 UTC is Thu 04:30 IST (before opening) but Wed 19:00 in New York (after closing, so Thursday morning there)
        $this->assertSame('Thu 2026-10-22 09:00', $this->slot('2026-10-21 23:00:00', 0, true, 'Asia/Kolkata'));
        $this->assertSame('Thu 2026-10-22 09:00', $this->slot('2026-10-21 23:00:00', 0, true, 'America/New_York'));
        // ...yet they are different instants
        $ist = FollowUpSchedule::nextSlot(CarbonImmutable::parse('2026-10-21 23:00:00', 'UTC'), 0, 'Asia/Kolkata', '09:00', '17:00', true);
        $ny = FollowUpSchedule::nextSlot(CarbonImmutable::parse('2026-10-21 23:00:00', 'UTC'), 0, 'America/New_York', '09:00', '17:00', true);
        $this->assertNotSame($ist->getTimestamp(), $ny->getTimestamp());
    }

    public function test_the_result_never_precedes_the_input(): void
    {
        $from = CarbonImmutable::parse('2026-10-21 04:30:00', 'UTC');
        foreach ([0, 1, 3, 30] as $wait) {
            $this->assertGreaterThanOrEqual($from->getTimestamp(), FollowUpSchedule::nextSlot($from, $wait, 'Asia/Kolkata', '09:00', '17:00', true)->getTimestamp());
        }
    }
}
