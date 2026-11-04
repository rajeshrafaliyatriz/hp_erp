<?php

namespace Tests\Feature;

use App\Support\ColumnExists;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * S2 add-on "AI prices in rupees": the three schema files (Docs/agentic-crm/slices/S02-pricing-contract.md). Each migration's real up() and
 * down() run on the in-memory sqlite database, on top of the migrations that build the tables today. Column TYPES (CHAR(3), DECIMAL(14,6)) cannot
 * be read back from sqlite, so they are not asserted here; they were read in the migration source only.
 */
class AiPricingSchemaTest extends TestCase
{
    private const MODELS = '2026_12_08_100000_add_currency_to_ai_models';

    private const EVENTS = '2026_12_08_100100_add_cost_columns_to_ai_usage_events';

    private const RUNS = '2026_12_08_100200_add_ai_cost_to_g2g_research_runs';

    protected function setUp(): void
    {
        parent::setUp();

        // These tests create and drop tables: never let them near anything but the in-memory database.
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());

        foreach ([
            '2026_09_19_120000_create_ai_intelligence_tables', '2026_09_21_150000_create_ai_usage_tables', '2026_10_09_160000_add_product_to_ai_usage_events',
            '2026_09_30_150000_create_g2g_opportunity_and_ingestion_tables', '2026_09_30_180000_add_signal_fields_to_ingestion_findings',
            '2026_09_30_200000_add_research_sources_and_signal_kind', '2026_09_30_220000_create_g2g_portfolio_and_partners_tables',
            '2026_09_30_230000_add_claude_feed_and_linking_fields', '2026_10_01_130000_add_stage_to_g2g_research_runs',
            '2026_10_08_100000_extend_g2g_signals_for_market_import', '2026_11_29_090000_add_assessment_to_g2g_company_opportunities',
            '2026_12_01_090000_add_product_key_to_signals_runs_and_sources', '2026_10_14_100000_create_g2g_icp_profiles_tables',
            '2026_10_31_090000_add_research_run_configuration_scope', '2026_12_07_090000_add_scope_to_g2g_company_opportunities',
            '2026_12_08_090200_add_cost_and_product_columns_to_g2g_research_runs',
        ] as $m) {
            $this->migration($m)->up();
        }
    }

    private function migration(string $name): object
    {
        return require base_path("database/migrations/{$name}.php");
    }

    /** @return list<string> */
    private function columns(string $table): array
    {
        $columns = ColumnExists::all($table);
        sort($columns);

        return $columns;
    }

    private function model(array $o = []): int
    {
        return (int) DB::table('ai_models')->insertGetId($o + [
            'provider' => 'deepseek', 'model_id' => 'deepseek-flash', 'label' => 'DeepSeek Flash', 'sub_institute_id' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── ai_models.currency ───────────────────────────────────────────────────

    public function test_models_get_a_currency_and_existing_rows_keep_every_value_and_become_inr(): void
    {
        $this->model(['model_id' => 'priced', 'input_cost_per_1k' => 0.0288, 'output_cost_per_1k' => 0.1152]);
        $this->model(['model_id' => 'blank']);
        $this->model(['model_id' => 'org-row', 'sub_institute_id' => 6, 'input_cost_per_1k' => 1.5, 'output_cost_per_1k' => 2.5, 'sort_order' => 7]);
        $before = $this->columns('ai_models');
        $rows = DB::table('ai_models')->orderBy('id')->get();   // the migration that builds the table also seeds the configured models
        $this->assertNotContains('currency', $before);

        $this->migration(self::MODELS)->up();

        $this->assertSame($this->sorted(array_merge($before, ['currency'])), $this->columns('ai_models'), 'exactly one column added');
        $after = DB::table('ai_models')->orderBy('id')->get();
        $this->assertCount(count($rows), $after);
        $this->assertGreaterThanOrEqual(3, count($after));
        foreach ($after as $i => $row) {
            $this->assertSame('INR', $row->currency, "existing row {$row->model_id} is rupees");
            $kept = (array) $rows[$i];
            foreach ($kept as $column => $value) {
                $this->assertEquals($value, $row->{$column}, "{$row->model_id}.{$column} is untouched");
            }
        }
        $this->assertNull(DB::table('ai_models')->where('model_id', 'blank')->value('input_cost_per_1k'), 'a blank rate stays blank, never 0');
    }

    public function test_a_model_added_after_the_migration_without_a_currency_is_inr(): void
    {
        $this->migration(self::MODELS)->up();

        $id = $this->model(['model_id' => 'later']);

        $this->assertSame('INR', DB::table('ai_models')->where('id', $id)->value('currency'));
    }

    public function test_the_models_migration_is_repeatable_and_down_restores_the_original_columns(): void
    {
        $id = $this->model(['model_id' => 'priced', 'input_cost_per_1k' => 0.5, 'output_cost_per_1k' => 1.5]);
        $original = $this->columns('ai_models');
        $migration = $this->migration(self::MODELS);

        $migration->up();
        $once = $this->columns('ai_models');
        $rowOnce = (array) DB::table('ai_models')->where('id', $id)->first();
        $migration->up();

        $this->assertSame($once, $this->columns('ai_models'), 'a second up() changes nothing');
        $this->assertEquals($rowOnce, (array) DB::table('ai_models')->where('id', $id)->first());

        $migration->down();
        $this->assertSame($original, $this->columns('ai_models'), 'down() leaves exactly the original columns');
        $this->assertEquals(0.5, DB::table('ai_models')->where('id', $id)->value('input_cost_per_1k'), 'down() leaves the rates alone');
        $migration->down();   // and a second down() is a no-op, not an error
        $this->assertSame($original, $this->columns('ai_models'));
    }

    // ── ai_usage_events.estimated_cost / cost_currency ───────────────────────

    private function event(array $o = []): int
    {
        return (int) DB::table('ai_usage_events')->insertGetId($o + [
            'ai_module' => 'signals', 'provider' => 'deepseek', 'model' => 'deepseek-flash', 'input_tokens' => 100, 'output_tokens' => 20,
            'estimated_cost_usd' => null, 'outcome' => 'success', 'sub_institute_id' => 6, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_usage_events_get_the_two_cost_columns_and_existing_rows_stay_unpriced(): void
    {
        $this->event(['estimated_cost_usd' => 0.25]);
        $this->event();
        $before = $this->columns('ai_usage_events');
        $rows = DB::table('ai_usage_events')->orderBy('id')->get();

        $this->migration(self::EVENTS)->up();

        $this->assertSame($this->sorted(array_merge($before, ['estimated_cost', 'cost_currency'])), $this->columns('ai_usage_events'));
        $after = DB::table('ai_usage_events')->orderBy('id')->get();
        foreach ($after as $i => $row) {
            $this->assertNull($row->estimated_cost, 'a call made before a price existed is never repriced');
            $this->assertNull($row->cost_currency);
            foreach ((array) $rows[$i] as $column => $value) {
                $this->assertEquals($value, $row->{$column}, "row {$row->id}.{$column} is untouched");
            }
        }
        $this->assertEquals(0.25, $after[0]->estimated_cost_usd, 'the dollar column keeps what it had');
    }

    public function test_the_usage_events_migration_is_repeatable_and_down_restores_the_original_columns(): void
    {
        $this->event(['estimated_cost_usd' => 0.25]);
        $original = $this->columns('ai_usage_events');
        $migration = $this->migration(self::EVENTS);

        $migration->up();
        $once = $this->columns('ai_usage_events');
        DB::table('ai_usage_events')->update(['estimated_cost' => 1.5, 'cost_currency' => 'INR']);
        $migration->up();

        $this->assertSame($once, $this->columns('ai_usage_events'), 'a second up() changes nothing');
        $this->assertEquals(1.5, DB::table('ai_usage_events')->value('estimated_cost'), 'a second up() does not wipe a filled value');

        $migration->down();
        $this->assertSame($original, $this->columns('ai_usage_events'));
        $this->assertEquals(0.25, DB::table('ai_usage_events')->value('estimated_cost_usd'));
        $migration->down();
        $this->assertSame($original, $this->columns('ai_usage_events'));
    }

    public function test_a_half_migrated_usage_table_is_completed_not_failed(): void
    {
        $this->migration(self::EVENTS)->up();
        Schema::table('ai_usage_events', fn ($t) => $t->dropColumn('cost_currency'));

        $this->migration(self::EVENTS)->up();

        $this->assertContains('cost_currency', $this->columns('ai_usage_events'));
        $this->assertContains('estimated_cost', $this->columns('ai_usage_events'));
    }

    // ── g2g_research_runs.ai_cost / ai_cost_currency ─────────────────────────

    public function test_runs_get_the_cost_and_currency_columns_and_the_old_dollar_column_is_left_alone(): void
    {
        $id = (int) DB::table('g2g_research_runs')->insertGetId([
            'sub_institute_id' => 6, 'report_date' => '2026-11-01', 'trigger' => 'manual', 'status' => 'success', 'ai_cost_usd' => 0.5, 'ai_input_tokens' => 900,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $before = $this->columns('g2g_research_runs');
        $row = (array) DB::table('g2g_research_runs')->where('id', $id)->first();
        $migration = $this->migration(self::RUNS);

        $migration->up();

        $this->assertSame($this->sorted(array_merge($before, ['ai_cost', 'ai_cost_currency'])), $this->columns('g2g_research_runs'));
        $after = (array) DB::table('g2g_research_runs')->where('id', $id)->first();
        $this->assertNull($after['ai_cost']);
        $this->assertNull($after['ai_cost_currency']);
        $this->assertContains('ai_cost_usd', $this->columns('g2g_research_runs'), 'the old column is not dropped: that would not be additive');
        foreach ($row as $column => $value) {
            $this->assertEquals($value, $after[$column], "run.{$column} is untouched");
        }

        $migration->up();
        $this->assertSame($this->sorted(array_merge($before, ['ai_cost', 'ai_cost_currency'])), $this->columns('g2g_research_runs'), 'a second up() changes nothing');

        $migration->down();
        $this->assertSame($before, $this->columns('g2g_research_runs'), 'down() removes only the two columns up() added');
        $this->assertEquals(0.5, DB::table('g2g_research_runs')->where('id', $id)->value('ai_cost_usd'));
    }

    // ── a host without the tables ────────────────────────────────────────────

    public function test_each_migration_does_nothing_when_its_table_does_not_exist(): void
    {
        Schema::drop('ai_models');
        Schema::drop('ai_usage_events');
        Schema::drop('g2g_research_runs');

        foreach ([self::MODELS, self::EVENTS, self::RUNS] as $name) {
            $migration = $this->migration($name);
            $migration->up();
            $migration->down();
        }

        foreach (['ai_models', 'ai_usage_events', 'g2g_research_runs'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} was not created by a migration that only adds columns");
        }
    }

    /** @param  list<string>  $columns @return list<string> */
    private function sorted(array $columns): array
    {
        sort($columns);

        return $columns;
    }
}
