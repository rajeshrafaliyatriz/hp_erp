<?php

namespace Tests\Unit\Signals;

use App\Domain\Signals\Market\MarketReport;
use PHPUnit\Framework\TestCase;

final class MarketReportTest extends TestCase
{
    private function row(int $id, string $co, ?string $url, array $o = []): array
    {
        return $o + ['id' => $id, 'company' => $co, 'source_url' => $url, 'import_source' => 'web-research', 'signal_kind' => 'tender', 'is_sample' => 0,
            'claim_level' => 'confirmed', 'fetch_level' => 'page_text', 'qualification' => 'Qualified', 'event_date' => '2026-01-01',
            'source_published_at' => '2026-01-01', 'product_key' => 'g2g', 'customer_model' => 'B2B'];
    }

    public function test_cohort_exclusion_and_denominators(): void
    {
        $rows = [
            $this->row(1, 'A Corp', 'https://a.com/1'),
            $this->row(2, 'B Corp', 'https://b.com/1', ['import_source' => 'demo_fixture_2026']),
            $this->row(3, 'C Corp', 'https://c.com/1', ['signal_kind' => 'public_reporting']),
            $this->row(4, 'D Corp', null),
            $this->row(5, 'E Corp', 'https://e.com/1', ['is_sample' => 1]),
        ];
        $r = MarketReport::build($rows, []);
        $this->assertSame(5, $r['cohort']['denominator']);
        $this->assertSame('2/5', $r['cohort']['by_class']['market_signal']['ratio']);
        $this->assertSame('1/5', $r['cohort']['by_class']['data_quality_note']['ratio']);
        $this->assertSame('1/5', $r['cohort']['by_class']['no_source_url']['ratio']);
        $this->assertSame('1/5', $r['cohort']['by_class']['sample']['ratio']);
        $this->assertSame(2, $r['market']['denominator']);
    }

    public function test_real_duplicate_versus_shared_article(): void
    {
        $rows = [
            $this->row(1, 'Cactus', 'https://x.com/a?utm_source=z'),
            $this->row(2, 'Cactus Communications', 'https://www.x.com/a'),
            $this->row(3, 'Nihilent', 'https://y.com/best'),
            $this->row(4, 'Exusia', 'https://y.com/best'),
        ];
        $d = MarketReport::build($rows, [])['market']['duplicates'];
        $this->assertCount(1, $d['real_duplicate_groups']);
        $this->assertSame([1, 2], $d['real_duplicate_groups'][0]['ids']);
        $this->assertCount(1, $d['shared_article_groups']);
        $this->assertSame('1/4', $d['rows_beyond_first']['ratio']);
    }

    public function test_maturity_uses_market_cohort_denominator(): void
    {
        $rows = [
            $this->row(1, 'A', 'https://a.com/1'),
            $this->row(2, 'B', 'https://b.com/1', ['claim_level' => 'inference']),
            $this->row(3, 'C', 'https://c.com/1', ['fetch_level' => 'snippet']),
            $this->row(4, 'D', 'https://d.com/1', ['claim_level' => null]),
            $this->row(5, 'N', 'https://n.com/1', ['signal_kind' => 'public_reporting']),
        ];
        $mat = MarketReport::build($rows, [])['market']['maturity'];
        $this->assertSame('1/4', $mat['E3']['ratio']);
        $this->assertSame(25.0, $mat['E3']['pct']);
        $this->assertSame('1/4', $mat['E2']['ratio']);
        $this->assertSame('1/4', $mat['E0']['ratio']);
        $this->assertSame('1/4', $mat['not_assessed']['ratio']);
    }

    public function test_zero_denominator_gives_null_not_a_percentage(): void
    {
        $r = MarketReport::build([], []);
        $this->assertNull($r['market']['missing_event_date']['pct']);
        $this->assertSame('0/0', $r['market']['missing_event_date']['ratio']);
        $this->assertNull($r['runs']['failed_share']['pct']);
        $this->assertNull($r['runs']['zero_signal_share_of_successful']['pct']);
    }

    public function test_missing_dates_and_product_counts(): void
    {
        $rows = [
            $this->row(1, 'A', 'https://a.com/1', ['event_date' => null]),
            $this->row(2, 'B', 'https://b.com/1', ['source_published_at' => '', 'product_key' => 'sc', 'customer_model' => 'B2C']),
        ];
        $m = MarketReport::build($rows, [])['market'];
        $this->assertSame('1/2', $m['missing_event_date']['ratio']);
        $this->assertSame('1/2', $m['missing_source_published_at']['ratio']);
        $this->assertSame('1/2', $m['by_product_customer_model']['sc / B2C']['ratio']);
    }

    public function test_run_health(): void
    {
        $runs = [
            ['id' => 1, 'status' => 'success', 'opportunities_new' => 0],
            ['id' => 2, 'status' => 'success', 'opportunities_new' => 3],
            ['id' => 3, 'status' => 'failed', 'opportunities_new' => 0],
            ['id' => 4, 'status' => 'success', 'opportunities_new' => 0],
        ];
        $h = MarketReport::build([], $runs)['runs'];
        $this->assertSame('1/4', $h['failed_share']['ratio']);
        $this->assertSame('2/3', $h['zero_signal_share_of_successful']['ratio']);
        $this->assertSame('3/4', $h['status_counts']['success']['ratio']);
    }
}
