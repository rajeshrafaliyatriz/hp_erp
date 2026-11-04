<?php

namespace Tests\Feature;

use App\Support\ColumnExists;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Slice 2 schema: the decision-history table, the product and date-status columns and four indexes on the signal, and the cost columns on the
 * research run. Runs each migration's real up() and down() on the in-memory sqlite database, on top of the migrations that already build the tables.
 */
class SignalReviewSchemaTest extends TestCase
{
    private const HISTORY = '2026_12_08_090000_create_g2g_signal_reviews_table';

    private const OPPORTUNITIES = '2026_12_08_090100_add_review_and_product_columns_to_g2g_company_opportunities';

    private const RUNS = '2026_12_08_090200_add_cost_and_product_columns_to_g2g_research_runs';

    private const OPPORTUNITY_COLUMNS = ['product_master_id', 'date_status'];

    private const RUN_COLUMNS = ['product_master_id', 'ai_input_tokens', 'ai_output_tokens', 'ai_cost_usd', 'search_requests', 'search_credits'];

    private const INDEXES = ['g2g_opp_tenant_fit_review', 'g2g_opp_tenant_discovered', 'g2g_opp_tenant_event_date', 'g2g_opp_tenant_pm'];

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
        ] as $m) {
            $this->migration($m)->up();
        }
    }

    private function migration(string $name): object
    {
        return require base_path("database/migrations/{$name}.php");
    }

    /** @return list<string> */
    private function indexNames(): array
    {
        return array_map(fn ($i) => (string) $i['name'], Schema::getIndexes('g2g_company_opportunities'));
    }

    private function upAll(): void
    {
        foreach ([self::HISTORY, self::OPPORTUNITIES, self::RUNS] as $m) {
            $this->migration($m)->up();
        }
    }

    public function test_up_creates_the_history_table_the_columns_and_the_indexes(): void
    {
        foreach (self::OPPORTUNITY_COLUMNS as $c) {
            $this->assertFalse(ColumnExists::has('g2g_company_opportunities', $c), "{$c} must not exist before the migration");
        }
        $this->assertFalse(Schema::hasTable('g2g_signal_reviews'));

        $this->upAll();

        $this->assertTrue(Schema::hasTable('g2g_signal_reviews'));
        foreach (['id', 'sub_institute_id', 'opportunity_id', 'decision', 'reason_code', 'note', 'from_status', 'to_status', 'via', 'decided_by', 'decided_at', 'created_at'] as $c) {
            $this->assertTrue(ColumnExists::has('g2g_signal_reviews', $c), "g2g_signal_reviews.{$c}");
        }
        foreach (self::OPPORTUNITY_COLUMNS as $c) {
            $this->assertTrue(ColumnExists::has('g2g_company_opportunities', $c), "g2g_company_opportunities.{$c}");
        }
        foreach (self::RUN_COLUMNS as $c) {
            $this->assertTrue(ColumnExists::has('g2g_research_runs', $c), "g2g_research_runs.{$c}");
        }
        foreach (self::INDEXES as $i) {
            $this->assertContains($i, $this->indexNames(), "index {$i}");
        }
        $this->assertContains('g2g_sr_tenant_opp_decided', array_map(fn ($i) => (string) $i['name'], Schema::getIndexes('g2g_signal_reviews')));
    }

    public function test_the_history_table_has_no_unique_key_because_two_decisions_in_one_second_are_legitimate(): void
    {
        $this->upAll();

        foreach (Schema::getIndexes('g2g_signal_reviews') as $index) {
            if (($index['primary'] ?? false) === true) {
                continue;
            }
            $this->assertFalse((bool) ($index['unique'] ?? false), 'index ' . $index['name'] . ' must not be unique');
        }
    }

    public function test_the_history_table_accepts_a_decision_and_defaults_via_to_the_new_screen(): void
    {
        $this->upAll();

        \DB::table('g2g_signal_reviews')->insert([
            'sub_institute_id' => 6, 'opportunity_id' => 12, 'decision' => 'dismissed', 'reason_code' => 'stale', 'note' => null,
            'from_status' => 'New', 'to_status' => 'Dismissed', 'decided_by' => 3, 'decided_at' => '2026-11-03 10:00:00', 'created_at' => '2026-11-03 10:00:00',
        ]);
        \DB::table('g2g_signal_reviews')->insert([
            'sub_institute_id' => 6, 'opportunity_id' => 12, 'decision' => 'reopened', 'reason_code' => null, 'note' => 'new tender',
            'from_status' => 'Dismissed', 'to_status' => 'New', 'decided_by' => null, 'decided_at' => '2026-11-03 10:00:00', 'created_at' => '2026-11-03 10:00:00',
        ]);

        $rows = \DB::table('g2g_signal_reviews')->orderBy('id')->get();
        $this->assertCount(2, $rows, 'two decisions on the same signal in the same second are both kept');
        $this->assertSame('signal_review', $rows[0]->via);
        $this->assertNull($rows[1]->decided_by, 'decided_by may be unknown');
    }

    public function test_running_up_twice_changes_nothing(): void
    {
        $this->upAll();
        $indexesAfterFirst = $this->indexNames();
        $columnsAfterFirst = ColumnExists::all('g2g_company_opportunities');
        $runColumnsAfterFirst = ColumnExists::all('g2g_research_runs');

        $this->upAll();

        $this->assertSame($indexesAfterFirst, $this->indexNames());
        $this->assertSame($columnsAfterFirst, ColumnExists::all('g2g_company_opportunities'));
        $this->assertSame($runColumnsAfterFirst, ColumnExists::all('g2g_research_runs'));
    }

    public function test_down_removes_only_what_up_added(): void
    {
        $columnsBefore = ColumnExists::all('g2g_company_opportunities');
        $runColumnsBefore = ColumnExists::all('g2g_research_runs');
        $indexesBefore = $this->indexNames();

        $this->upAll();
        foreach ([self::RUNS, self::OPPORTUNITIES, self::HISTORY] as $m) {
            $this->migration($m)->down();
        }

        $this->assertFalse(Schema::hasTable('g2g_signal_reviews'));
        $this->assertEqualsCanonicalizing($columnsBefore, ColumnExists::all('g2g_company_opportunities'), 'no opportunity column added or lost');
        $this->assertEqualsCanonicalizing($runColumnsBefore, ColumnExists::all('g2g_research_runs'), 'no run column added or lost');
        $this->assertEqualsCanonicalizing($indexesBefore, $this->indexNames(), 'no index added or lost');
    }

    public function test_down_twice_and_up_after_down_both_work(): void
    {
        $this->upAll();
        foreach ([self::RUNS, self::OPPORTUNITIES, self::HISTORY] as $m) {
            $this->migration($m)->down();
            $this->migration($m)->down();
        }
        $this->upAll();

        $this->assertTrue(Schema::hasTable('g2g_signal_reviews'));
        $this->assertTrue(ColumnExists::has('g2g_company_opportunities', 'date_status'));
    }

    public function test_the_existing_review_columns_are_not_touched(): void
    {
        $this->upAll();

        foreach (['review_status', 'review_notes', 'reviewed_by', 'reviewed_at'] as $c) {
            $this->assertTrue(ColumnExists::has('g2g_company_opportunities', $c), "{$c} must still exist");
        }
    }

    public function test_every_reason_code_fits_the_column_and_the_config_has_the_fallback_codes(): void
    {
        $reasons = config('signals.review.reasons');
        $this->assertIsArray($reasons);
        foreach (array_keys($reasons) as $code) {
            $this->assertLessThanOrEqual(30, strlen($code), "{$code} must fit varchar(30)");
            $this->assertMatchesRegularExpression('/^[a-z_]+$/', $code);
        }
        $this->assertArrayHasKey(config('signals.review.legacy_dismiss_reason'), $reasons, 'the legacy fallback must be a real reason');
        $this->assertArrayHasKey('other', $reasons);
        $this->assertArrayNotHasKey('no_budget', $reasons, 'deliberately left out: see the config comment');
    }
}
