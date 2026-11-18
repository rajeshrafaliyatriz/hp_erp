<?php

namespace Tests\Feature;

use App\Domain\Signals\Market\MarketImporter;
use App\Domain\Signals\Opportunities\CompanyOpportunity;
use App\Domain\Signals\Opportunities\ResearchRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A signal saved by the importer is stamped with its date status and its Product Master row at once, and a re-import keeps both in step with the row
 * it leaves behind. Runs on the in-memory sqlite database.
 */
class SignalStampsTest extends TestCase
{
    private const T = 1;

    private int $masterId;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            '2026_09_30_150000_create_g2g_opportunity_and_ingestion_tables', '2026_09_30_180000_add_signal_fields_to_ingestion_findings',
            '2026_09_30_200000_add_research_sources_and_signal_kind', '2026_09_30_220000_create_g2g_portfolio_and_partners_tables',
            '2026_09_30_230000_add_claude_feed_and_linking_fields', '2026_10_01_130000_add_stage_to_g2g_research_runs',
            '2026_10_08_100000_extend_g2g_signals_for_market_import', '2026_11_29_090000_add_assessment_to_g2g_company_opportunities',
            '2026_12_01_090000_add_product_key_to_signals_runs_and_sources', '2026_10_14_100000_create_g2g_icp_profiles_tables',
            '2026_10_31_090000_add_research_run_configuration_scope', '2026_12_07_090000_add_scope_to_g2g_company_opportunities',
            '2026_12_07_090000_create_g2g_product_master_table', '2026_12_07_090100_ensure_g2g_signal_products_table',
            '2026_12_08_090000_create_g2g_signal_reviews_table', '2026_12_08_090100_add_review_and_product_columns_to_g2g_company_opportunities',
            '2026_12_08_090200_add_cost_and_product_columns_to_g2g_research_runs',
        ] as $m) {
            (require base_path("database/migrations/{$m}.php"))->up();
        }
        Cache::flush();
        Carbon::setTestNow('2026-10-20 10:00:00');
        // The built-in Enterprise Brain product, shared by every organisation (sub_institute_id 0).
        $this->masterId = (int) DB::table('g2g_product_master')->insertGetId([
            'sub_institute_id' => 0, 'product_key' => 'enterprise_brain', 'kind' => 'product', 'name' => 'Enterprise Brain', 'status' => 'active',
            'origin' => 'builtin', 'signal_key' => 'eb', 'portfolio_name' => 'EB',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param array<string, mixed> $o */
    private function record(array $o = []): array
    {
        return $o + [
            'buyer' => ['name' => 'Gujarat Mills Ltd', 'type' => 'enterprise', 'state' => 'Gujarat'],
            'trigger_type' => 'expansion', 'trigger_summary' => 'Opening a new plant near Surat.',
            'what_happened' => 'Gujarat Mills Ltd is opening a new plant near Surat.', 'event_date' => '2026-10-10',
            'source_url' => 'https://news.example.com/mills-plant', 'source_title' => 'Mills opens plant',
            'claim_level' => 'hypothesis', 'fetch_level' => 'search_snippet', 'business_fit' => 'eb',
        ];
    }

    /** @param list<array<string, mixed>> $records @return array<string, mixed> */
    private function import(array $records): array
    {
        return app(MarketImporter::class)->import(self::T, $records, ['source_label' => 'test']);
    }

    public function test_a_new_signal_with_an_event_date_is_stamped_event_date_stated_and_with_its_product(): void
    {
        $this->import([$this->record()]);

        $o = CompanyOpportunity::sole();
        $this->assertSame('event_date_stated', $o->date_status);
        $this->assertSame($this->masterId, (int) $o->product_master_id);
    }

    public function test_a_new_signal_with_only_a_published_date_is_source_date_only(): void
    {
        $this->import([$this->record(['event_date' => null, 'observed_at' => '2026-10-10', 'source_published_at' => '2026-10-03'])]);

        $this->assertSame('source_date_only', CompanyOpportunity::sole()->date_status);
    }

    public function test_a_new_signal_with_no_date_at_all_is_undated(): void
    {
        $this->import([$this->record(['event_date' => null, 'observed_at' => '2026-10-10'])]);

        $o = CompanyOpportunity::sole();
        $this->assertNull($o->event_date);
        $this->assertSame('undated', $o->date_status);
    }

    public function test_a_signal_whose_product_cannot_be_resolved_keeps_no_product_not_a_guess(): void
    {
        $this->import([$this->record(['business_fit' => 'g2g'])]);

        $this->assertNull(CompanyOpportunity::sole()->product_master_id, 'there is no g2g product row: nothing is guessed');
        $this->assertSame('event_date_stated', CompanyOpportunity::sole()->date_status, 'the date status is still stamped');
    }

    public function test_a_re_import_that_brings_the_event_date_moves_the_status_with_it(): void
    {
        $this->import([$this->record(['event_date' => null, 'observed_at' => '2026-10-10'])]);
        $this->assertSame('undated', CompanyOpportunity::sole()->date_status);

        // The same event found again with the date this time, and a stronger claim so the importer treats it as an advance.
        $again = $this->import([$this->record(['event_date' => '2026-10-12', 'claim_level' => 'confirmed', 'fetch_level' => 'page_text'])]);

        $this->assertSame(1, CompanyOpportunity::count(), 'still one signal');
        $this->assertSame(1, $again['updated'] ?? 0, 'it was an update, not a new row');
        $this->assertSame('event_date_stated', CompanyOpportunity::sole()->date_status);
    }

    public function test_a_re_import_never_replaces_a_product_that_is_already_set(): void
    {
        $this->import([$this->record(['event_date' => null, 'observed_at' => '2026-10-10'])]);
        $other = (int) DB::table('g2g_product_master')->insertGetId([
            'sub_institute_id' => 0, 'product_key' => 'other', 'kind' => 'product', 'name' => 'Other', 'status' => 'active', 'origin' => 'builtin', 'signal_key' => 'oth',
        ]);
        DB::table('g2g_company_opportunities')->update(['product_master_id' => $other]);

        $this->import([$this->record(['event_date' => '2026-10-12', 'claim_level' => 'confirmed', 'fetch_level' => 'page_text'])]);

        $this->assertSame($other, (int) CompanyOpportunity::sole()->product_master_id, 'a value already there is left alone');
    }

    public function test_a_re_import_fills_a_missing_product_on_an_older_signal(): void
    {
        $this->import([$this->record(['event_date' => null, 'observed_at' => '2026-10-10'])]);
        DB::table('g2g_company_opportunities')->update(['product_master_id' => null]);

        $this->import([$this->record(['event_date' => '2026-10-12', 'claim_level' => 'confirmed', 'fetch_level' => 'page_text'])]);

        $this->assertSame($this->masterId, (int) CompanyOpportunity::sole()->product_master_id);
    }

    public function test_the_importer_still_works_on_a_database_without_the_new_columns(): void
    {
        (require base_path('database/migrations/2026_12_08_090100_add_review_and_product_columns_to_g2g_company_opportunities.php'))->down();

        $result = $this->import([$this->record()]);

        $this->assertSame(1, $result['accepted'], 'the signal is saved as before');
        $this->assertSame(1, CompanyOpportunity::count());
    }
}
