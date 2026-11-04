<?php

namespace Tests\Unit\Signals;

use App\Domain\Signals\Opportunities\DateStatus;
use App\Domain\Signals\Opportunities\FeedFilters;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/** The parts of the Signal Review feed filters that need no database. The request-level behaviour is in Tests\Feature\SignalFeedFiltersTest. */
class FeedFiltersTest extends TestCase
{
    public function test_the_30_and_90_day_windows_start_exactly_that_many_days_back(): void
    {
        $today = Carbon::parse('2026-10-20 15:30:00');

        $this->assertSame('2026-09-20', FeedFilters::windowStart('last_30_days', $today));
        $this->assertSame('2026-07-22', FeedFilters::windowStart('last_90_days', $today));
        $this->assertSame('2026-10-20 15:30:00', $today->toDateTimeString(), 'the date passed in is left alone');
    }

    public function test_the_windows_cross_month_year_and_leap_day_boundaries(): void
    {
        $this->assertSame('2026-12-06', FeedFilters::windowStart('last_30_days', Carbon::parse('2027-01-05')));
        $this->assertSame('2026-10-07', FeedFilters::windowStart('last_90_days', Carbon::parse('2027-01-05')));
        $this->assertSame('2028-01-31', FeedFilters::windowStart('last_30_days', Carbon::parse('2028-03-01')));
        $this->assertSame('2027-01-30', FeedFilters::windowStart('last_30_days', Carbon::parse('2027-03-01')));
    }

    public function test_only_the_two_windows_have_a_start(): void
    {
        $today = Carbon::parse('2026-10-20');

        $this->assertNull(FeedFilters::windowStart('older', $today));
        $this->assertNull(FeedFilters::windowStart('undated', $today));
        $this->assertNull(FeedFilters::windowStart('last_7_days', $today));
    }

    public function test_the_effective_date_reads_the_event_date_before_the_source_date(): void
    {
        $sql = FeedFilters::effectiveDate();

        $this->assertStringStartsWith('COALESCE(', $sql);
        $this->assertLessThan(strpos($sql, 'o.source_published_at'), strpos($sql, 'o.event_date'));
        $this->assertSame(2, substr_count($sql, "'0000-00-00'"), 'a zero date counts as no date on both columns');
    }

    public function test_the_freshness_values_and_the_date_status_options_are_the_documented_ones(): void
    {
        $this->assertSame(['last_30_days', 'last_90_days', 'older', 'undated'], FeedFilters::FRESHNESS);
        $this->assertSame('recent', FeedFilters::SORT_RECENT);

        $options = FeedFilters::dateStatusOptions();
        $this->assertSame(DateStatus::ALL, array_column($options, 'value'));
        foreach ($options as $option) {
            $this->assertNotSame('', $option['label']);
        }
    }
}
