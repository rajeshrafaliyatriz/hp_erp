<?php

namespace Tests\Unit\Gtm;

use App\Domain\Gtm\Forecast\ForecastMath;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class ForecastMathTest extends TestCase
{
    private function deal(int $id, string $stage, ?string $amount, ?string $currency, ?string $close, ?string $category = null): array
    {
        return ['id' => $id, 'stage' => $stage, 'amount' => $amount, 'currency' => $currency, 'expected_close_date' => $close, 'forecast_category' => $category];
    }

    private function summary(array $deals, array $settings = [], string $from = '2026-11-01', string $to = '2027-02-28'): array
    {
        return ForecastMath::summarise($deals, $settings, Carbon::parse($from), Carbon::parse($to)->endOfDay());
    }

    public function test_currencies_are_never_added_together(): void
    {
        $r = $this->summary([
            $this->deal(1, 'proposal', '1000.00', 'INR', '2026-11-10'),
            $this->deal(2, 'proposal', '250.50', 'USD', '2026-11-12'),
            $this->deal(3, 'proposal', '500.00', 'INR', '2026-11-20'),
        ]);

        $byCurrency = collect($r['periods'])->keyBy('currency');
        $this->assertCount(2, $r['periods'], 'one row per currency within a month');
        $this->assertSame(1500.0, (float) $byCurrency['INR']['pipeline']);
        $this->assertSame(250.5, (float) $byCurrency['USD']['pipeline']);
        $this->assertCount(2, $r['totals_by_currency']);
    }

    public function test_money_is_exact_in_minor_units(): void
    {
        // 0.1 + 0.2 style float error must not appear in a currency total.
        $r = $this->summary([$this->deal(1, 'proposal', '0.10', 'USD', '2026-11-10'), $this->deal(2, 'proposal', '0.20', 'USD', '2026-11-11')]);
        $this->assertSame(0.3, (float) $r['periods'][0]['pipeline']);
    }

    public function test_deals_that_cannot_be_forecast_are_counted_not_valued(): void
    {
        $r = $this->summary([
            $this->deal(1, 'proposal', null, null, '2026-11-10'),        // no amount
            $this->deal(2, 'proposal', '100', 'INR', null),              // no close date
            $this->deal(3, 'proposal', '100', null, '2026-11-10'),       // amount but no currency
            $this->deal(4, 'proposal', '100', 'INR', '2026-11-10'),      // fine
        ]);

        $this->assertSame(2, $r['excluded']['no_amount']);
        $this->assertSame(1, $r['excluded']['no_close_date']);
        $this->assertSame(100.0, (float) $r['periods'][0]['pipeline']);
        $this->assertSame(1, $r['periods'][0]['deal_count']);
    }

    public function test_weighted_is_null_when_no_probability_was_configured(): void
    {
        $r = $this->summary([$this->deal(1, 'proposal', '1000', 'INR', '2026-11-10')]);
        $this->assertFalse($r['weighted_available']);
        $this->assertNull($r['periods'][0]['weighted'], 'no probability means no weighted figure - not zero');
    }

    public function test_weighted_uses_only_the_probability_an_administrator_set(): void
    {
        $settings = ['proposal' => ['probability' => 40.0, 'category' => 'pipeline'], 'discovery' => ['probability' => null, 'category' => 'pipeline']];
        $r = $this->summary([
            $this->deal(1, 'proposal', '1000', 'INR', '2026-11-10'),
            $this->deal(2, 'discovery', '5000', 'INR', '2026-11-10'),   // stage has no probability
        ], $settings);

        $this->assertTrue($r['weighted_available']);
        $this->assertSame(400.0, (float) $r['periods'][0]['weighted'], 'only the deal whose stage has a probability is weighted');
        $this->assertSame(6000.0, (float) $r['periods'][0]['pipeline'], 'the unweighted pipeline still includes both');
        $this->assertSame(1, $r['excluded']['no_probability'], 'the left-out deal is counted, not hidden');
    }

    public function test_categories_are_cumulative_and_a_deal_override_beats_the_stage(): void
    {
        $settings = ['proposal' => ['probability' => null, 'category' => 'best_case'], 'negotiation' => ['probability' => null, 'category' => 'commit']];
        $r = $this->summary([
            $this->deal(1, 'proposal', '100', 'INR', '2026-11-10'),                 // stage: best_case
            $this->deal(2, 'negotiation', '200', 'INR', '2026-11-10'),              // stage: commit
            $this->deal(3, 'negotiation', '400', 'INR', '2026-11-10', 'pipeline'),  // overridden down to pipeline
        ], $settings);

        $p = $r['periods'][0];
        $this->assertSame(700.0, (float) $p['pipeline']);
        $this->assertSame(300.0, (float) $p['best_case'], 'best_case includes commit');
        $this->assertSame(200.0, (float) $p['commit']);
        $this->assertTrue($r['categories_in_use']);
    }

    public function test_omitted_deals_are_left_out_of_every_view(): void
    {
        $r = $this->summary([$this->deal(1, 'proposal', '100', 'INR', '2026-11-10', 'omitted'), $this->deal(2, 'proposal', '50', 'INR', '2026-11-10')]);
        $this->assertSame(50.0, (float) $r['periods'][0]['pipeline']);
        $this->assertSame(1, $r['excluded']['omitted']);
    }

    public function test_overdue_and_out_of_window_deals_are_reported_separately(): void
    {
        $r = $this->summary([
            $this->deal(1, 'proposal', '100', 'INR', '2026-10-15'),   // before the window
            $this->deal(2, 'proposal', '300', 'INR', '2027-06-01'),   // after it
            $this->deal(3, 'proposal', '50', 'INR', '2026-12-01'),
        ]);
        $this->assertSame(1, $r['overdue']['count']);
        $this->assertSame(100.0, (float) $r['overdue']['value_by_currency']['INR']);
        $this->assertSame(1, $r['excluded']['outside_window']);
        $this->assertSame(50.0, (float) $r['periods'][0]['pipeline']);
    }

    public function test_periods_are_grouped_by_expected_close_month_in_order(): void
    {
        $r = $this->summary([$this->deal(1, 'proposal', '10', 'INR', '2027-01-05'), $this->deal(2, 'proposal', '20', 'INR', '2026-11-30'), $this->deal(3, 'proposal', '30', 'INR', '2026-11-01')]);
        $this->assertSame(['2026-11', '2027-01'], array_column($r['periods'], 'period'));
        $this->assertSame(50.0, (float) $r['periods'][0]['pipeline']);
    }
}
