<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 2026_12_27_090200_drop_gtm_forecast_snapshots_and_stage_settings: the two tables only the removed GTM Forecast screen used are dropped, guarded, and come back EMPTY with the exact
 * columns and indexes of 2026_10_11_100000_gtm_forecasting when it is rolled back. Run here on sqlite (the PHPUnit database); the MariaDB branch is raw DDL written for 10.1 and is
 * rehearsed on the dev host and on a scratch table before live, as DB-RULES says.
 */
class GtmDropForecastTablesMigrationTest extends TestCase
{
    private const ORIGINAL = 'database/migrations/2026_10_11_100000_gtm_forecasting.php';

    private const DROP = 'database/migrations/2026_12_27_090200_drop_gtm_forecast_snapshots_and_stage_settings.php';

    private const TABLES = ['gtm_stage_settings', 'gtm_forecast_snapshots'];

    private function original(): object
    {
        return require base_path(self::ORIGINAL);
    }

    private function drop(): object
    {
        return require base_path(self::DROP);
    }

    /** @return array<string, array{columns: list<array<string, mixed>>, indexes: list<array<string, mixed>>}> */
    private function shape(): array
    {
        $out = [];
        foreach (self::TABLES as $table) {
            $columns = array_map(fn (array $c) => array_intersect_key($c, array_flip(['name', 'nullable', 'default', 'auto_increment'])), Schema::getColumns($table));
            $indexes = array_map(fn (array $i) => array_intersect_key($i, array_flip(['name', 'columns', 'unique', 'primary'])), Schema::getIndexes($table));
            usort($columns, fn ($a, $b) => strcmp($a['name'], $b['name']));
            usort($indexes, fn ($a, $b) => strcmp($a['name'], $b['name']));
            $out[$table] = ['columns' => $columns, 'indexes' => $indexes];
        }

        return $out;
    }

    public function test_up_drops_both_tables_and_a_second_run_does_nothing(): void
    {
        $this->original()->up();
        $this->assertTrue(Schema::hasTable('gtm_stage_settings') && Schema::hasTable('gtm_forecast_snapshots'));

        $this->drop()->up();
        $this->assertFalse(Schema::hasTable('gtm_stage_settings'));
        $this->assertFalse(Schema::hasTable('gtm_forecast_snapshots'));

        $this->drop()->up();   // guarded: nothing left to drop, no error
        $this->assertFalse(Schema::hasTable('gtm_stage_settings'));
    }

    public function test_up_drops_only_these_two_tables(): void
    {
        $this->original()->up();
        Schema::create('g2g_forecast_snapshots', fn ($t) => $t->id());
        Schema::create('gtm_deals', fn ($t) => $t->id());

        $this->drop()->up();

        $this->assertTrue(Schema::hasTable('g2g_forecast_snapshots'), 'the CRM forecast record stays');
        $this->assertTrue(Schema::hasTable('gtm_deals'), 'every other gtm_ table stays');
    }

    public function test_down_recreates_both_tables_empty_with_the_original_columns_and_indexes(): void
    {
        $this->original()->up();
        $original = $this->shape();
        $this->drop()->up();

        $this->drop()->down();

        $this->assertSame($original, $this->shape());
        foreach (self::TABLES as $table) {
            $this->assertSame(0, \Illuminate\Support\Facades\DB::table($table)->count(), "{$table} comes back empty");
        }

        $this->drop()->down();   // guarded: they exist, so a second rollback does nothing
        $this->assertSame($original, $this->shape());
    }

    public function test_the_unique_keys_of_the_original_are_kept(): void
    {
        $this->drop()->down();

        $unique = fn (string $table) => collect(Schema::getIndexes($table))->where('unique', true)->where('primary', false)->map(fn ($i) => [$i['name'], $i['columns']])->values()->all();
        $this->assertSame([['gtm_stage_settings_unique', ['sub_institute_id', 'stage']]], $unique('gtm_stage_settings'));
        $this->assertSame([['gtm_forecast_snapshots_unique', ['sub_institute_id', 'snapshot_date', 'period', 'currency']]], $unique('gtm_forecast_snapshots'));
    }
}
