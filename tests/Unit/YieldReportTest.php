<?php

namespace Tests\Unit;

use App\Domain\Signals\Run\YieldReport;
use PHPUnit\Framework\TestCase;

/** The daily-yield report arranges real rows and never invents a number. Synthetic rows only: no database, no history. */
class YieldReportTest extends TestCase
{
    private function runRow(array $o = []): array
    {
        return $o + ['id' => 1, 'product_key' => 'g2g', 'report_date' => '2026-10-27', 'status' => 'success', 'stage_stats' => [
            'outcome' => ['new' => 2, 'updated' => 1, 'unchanged_existing' => 3, 'qualified_total' => 6], 'zero_reasons' => [], 'completion' => 'completed',
            'queries' => ['failed' => 1], 'pages' => ['failed_by_category' => ['timeout' => 2], 'robots_disallowed' => 1], 'ai' => ['main_failed' => 0, 'followup_failed' => 0],
        ]];
    }

    private function signals(int $n, string $day = '2026-10-27', string $product = 'g2g'): array
    {
        return array_map(fn ($i) => ['id' => $i, 'product_key' => $product, 'first_day' => $day, 'claim_level' => 'confirmed', 'evidence_class' => 'direct_evidence'], range(1, $n));
    }

    public function test_the_target_is_compared_with_unique_new_signals_only(): void
    {
        $r = YieldReport::build([$this->runRow()], $this->signals(10));
        $day = $r['days'][0];
        $this->assertSame(10, $day['unique_new']);
        $this->assertTrue($day['target_met']);
        $this->assertSame(6, $day['qualified_reported'], 'the run reports re-observations as qualified too');
        $this->assertSame(4, $day['reobserved']);
        $this->assertFalse(YieldReport::build([$this->runRow()], $this->signals(9))['days'][0]['target_met']);
    }

    public function test_it_counts_runs_by_status_and_never_counts_failed_runs_as_completed(): void
    {
        $r = YieldReport::build([$this->runRow(), $this->runRow(['id' => 2, 'status' => 'failed', 'stage_stats' => null]), $this->runRow(['id' => 3, 'status' => 'partial'])], []);
        $runs = $r['days'][0]['runs'];
        $this->assertSame(3, $runs['total']);
        $this->assertSame([1, 1, 1], [$runs['success'], $runs['failed'], $runs['partial']]);
        $this->assertSame(1, $runs['without_stage_stats']);
    }

    public function test_a_day_with_no_run_says_so_and_a_day_with_only_failed_runs_says_that(): void
    {
        $this->assertSame('No research ran that day.', YieldReport::build([], $this->signals(2))['days'][0]['shortfall']);
        $failed = YieldReport::build([$this->runRow(['status' => 'failed', 'stage_stats' => null])], [])['days'][0];
        $this->assertStringContainsString('No run completed', $failed['shortfall']);
    }

    public function test_an_incomplete_run_is_reported_as_not_finished_not_as_nothing_found(): void
    {
        $stats = $this->runRow()['stage_stats'];
        $stats['completion'] = 'incomplete';
        $stats['ai'] = ['main_failed' => 1, 'followup_failed' => 0];
        $day = YieldReport::build([$this->runRow(['stage_stats' => $stats])], $this->signals(2))['days'][0];
        $this->assertStringContainsString('did not finish', $day['shortfall']);
        $this->assertSame(1, $day['ai_failed']);
    }

    public function test_rejections_and_failures_are_summed_from_the_runs_and_the_biggest_reason_is_named(): void
    {
        $stats = $this->runRow()['stage_stats'];
        $stats['outcome'] = ['new' => 0, 'updated' => 0, 'unchanged_existing' => 0, 'qualified_total' => 0];
        $stats['rejection_detail'] = ['quote_not_in_source' => ['institution_not_named_in_source' => 5, 'quote_not_found' => 2]];
        $day = YieldReport::build([$this->runRow(['stage_stats' => $stats])], [])['days'][0];
        $this->assertSame(5, $day['rejection_detail']['quote_not_in_source:institution_not_named_in_source']);
        $this->assertStringContainsString('quote_not_in_source:institution_not_named_in_source', $day['shortfall']);
        $this->assertSame(1, $day['queries_failed']);
        $this->assertSame(2, $day['pages_failed']);
        $this->assertSame(1, $day['robots_blocked']);
    }

    public function test_products_and_days_are_kept_apart_and_the_evidence_mix_is_from_the_signal_rows(): void
    {
        $r = YieldReport::build([$this->runRow(), $this->runRow(['product_key' => 'scholarclone'])], array_merge($this->signals(3), $this->signals(2, '2026-10-26', 'scholarclone')));
        $this->assertCount(3, $r['days']);
        $byKey = [];
        foreach ($r['days'] as $d) {
            $byKey[$d['date'] . '|' . $d['product']] = $d;
        }
        $this->assertSame(3, $byKey['2026-10-27|g2g']['unique_new']);
        $this->assertSame(2, $byKey['2026-10-26|scholarclone']['unique_new']);
        $this->assertSame(0, $byKey['2026-10-27|scholarclone']['unique_new']);
        $this->assertSame(['direct_evidence' => 3], $byKey['2026-10-27|g2g']['evidence_mix']);
    }

    public function test_it_invents_nothing_when_given_nothing(): void
    {
        $r = YieldReport::build([], []);
        $this->assertSame([], $r['days']);
        $this->assertSame(10, $r['target']);
    }
}
