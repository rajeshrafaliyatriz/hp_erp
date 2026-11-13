<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The one migration of track P2-D (g2g_product_match_decisions), run from its real file on sqlite. The raw MariaDB DDL cannot run here; it was run on the dev database
 * (that one file, never an unscoped migrate) and its SHOW CREATE TABLE is saved in _evidence/s15/p2d-*. What this proves: the file sorts after the newest existing one,
 * it is guarded (a second run changes nothing, not even the rows), the unique key is the pair inside one organisation, down() removes only the table up() made, and the
 * raw DDL names the same columns as the Blueprint branch (so the two branches cannot drift apart unseen) and uses nothing MariaDB 10.1 refuses.
 */
class ProductMatchDecisionsMigrationTest extends TestCase
{
    private const FILE = '2026_12_11_180000_create_g2g_product_match_decisions_table';

    private const TABLE = 'g2g_product_match_decisions';

    private function migration(): object
    {
        return require base_path('database/migrations/' . self::FILE . '.php');
    }

    /** @return array<string, list<string>> */
    private function indexes(): array
    {
        $out = [];
        foreach (Schema::getIndexes(self::TABLE) as $index) {
            $out[$index['name']] = $index['columns'];
        }

        return $out;
    }

    private function row(array $over = []): array
    {
        return $over + [
            'sub_institute_id' => 6, 'item_master_id' => 66, 'survivor_master_id' => 46, 'decision' => 'same', 'score' => 98.50, 'signals' => 'category School ERP; SKU SCH-01',
            'name_kept' => 'portfolio', 'decided_by' => 7, 'decided_at' => '2026-11-13 10:00:00', 'created_at' => '2026-11-13 10:00:00', 'updated_at' => '2026-11-13 10:00:00',
        ];
    }

    public function test_the_file_sorts_after_the_newest_existing_migration_and_is_the_only_one_after_it(): void
    {
        $names = array_map(fn ($f) => basename($f, '.php'), glob(base_path('database/migrations/*.php')));
        sort($names);

        $this->assertContains(self::FILE, $names);
        $this->assertGreaterThan('2026_12_11_170000_reorder_crm_lifecycle_sidebar_and_cleanup_icons', self::FILE);
        $this->assertSame(self::FILE, end($names), 'it is the newest file, so it runs last on every host');
    }

    public function test_the_table_has_exactly_the_columns_of_the_brief_and_the_two_indexes(): void
    {
        $this->migration()->up();

        $this->assertSame(
            ['id', 'sub_institute_id', 'item_master_id', 'survivor_master_id', 'decision', 'score', 'signals', 'name_kept', 'decided_by', 'decided_at', 'created_at', 'updated_at'],
            Schema::getColumnListing(self::TABLE)
        );
        $indexes = $this->indexes();
        $this->assertSame(['sub_institute_id', 'item_master_id', 'survivor_master_id'], $indexes['g2g_pmd_tenant_pair']);
        $this->assertSame(['sub_institute_id', 'decision'], $indexes['g2g_pmd_tenant_decision']);
        $unique = collect(Schema::getIndexes(self::TABLE))->firstWhere('name', 'g2g_pmd_tenant_pair');
        $this->assertTrue($unique['unique'], 'the pair is unique');
        $this->assertFalse(collect(Schema::getIndexes(self::TABLE))->firstWhere('name', 'g2g_pmd_tenant_decision')['unique']);
        $this->assertSame([], Schema::getForeignKeys(self::TABLE), 'no foreign keys (DB-RULES.md section 5)');
    }

    public function test_a_pair_is_unique_inside_one_organisation_and_only_there(): void
    {
        $this->migration()->up();
        DB::table(self::TABLE)->insert($this->row());

        // the same pair for another organisation, the same item against another Sub-product, and another item against the same Sub-product are different rows
        DB::table(self::TABLE)->insert($this->row(['sub_institute_id' => 7]));
        DB::table(self::TABLE)->insert($this->row(['survivor_master_id' => 47]));
        DB::table(self::TABLE)->insert($this->row(['item_master_id' => 67]));
        $this->assertSame(4, DB::table(self::TABLE)->count());

        $this->expectException(QueryException::class);
        DB::table(self::TABLE)->insert($this->row(['decision' => 'different']));
    }

    public function test_the_optional_columns_accept_null_and_the_score_keeps_two_decimals(): void
    {
        $this->migration()->up();
        DB::table(self::TABLE)->insert([
            'sub_institute_id' => 6, 'item_master_id' => 1, 'survivor_master_id' => 2, 'decision' => 'different',
        ]);
        $r = DB::table(self::TABLE)->first();

        foreach (['score', 'signals', 'name_kept', 'decided_by', 'decided_at', 'created_at', 'updated_at'] as $column) {
            $this->assertNull($r->{$column}, $column . ' may be empty');
        }
        DB::table(self::TABLE)->insert($this->row(['item_master_id' => 3, 'score' => 55.55]));
        $this->assertEquals(55.55, (float) DB::table(self::TABLE)->where('item_master_id', 3)->value('score'));
    }

    public function test_a_second_run_changes_nothing_not_even_the_rows(): void
    {
        $this->migration()->up();
        DB::table(self::TABLE)->insert($this->row());
        $before = ['columns' => Schema::getColumnListing(self::TABLE), 'indexes' => $this->indexes(), 'rows' => DB::table(self::TABLE)->get()->map(fn ($r) => (array) $r)->all()];

        $this->migration()->up();

        $this->assertSame($before, ['columns' => Schema::getColumnListing(self::TABLE), 'indexes' => $this->indexes(), 'rows' => DB::table(self::TABLE)->get()->map(fn ($r) => (array) $r)->all()]);
    }

    /** @return list<string> */
    private function tables(): array
    {
        return collect(Schema::getTables())->pluck('name')->sort()->values()->all();
    }

    public function test_down_removes_only_the_table_it_made_and_a_second_down_is_harmless(): void
    {
        Schema::create('g2g_product_master', fn ($t) => $t->id());
        DB::table('g2g_product_master')->insert(['id' => 1]);
        $tablesBefore = $this->tables();

        $this->migration()->up();
        $this->assertTrue(Schema::hasTable(self::TABLE));
        $this->assertSame(
            collect($tablesBefore)->push(self::TABLE)->sort()->values()->all(),
            $this->tables(),
            'up() made one table and nothing else'
        );

        $this->migration()->down();
        $this->assertSame($tablesBefore, $this->tables(), 'down() took exactly that table away');
        $this->assertSame(1, DB::table('g2g_product_master')->count(), 'the master table and its rows are untouched');

        $this->migration()->down();
        $this->assertSame($tablesBefore, $this->tables());
    }

    public function test_the_raw_ddl_for_mariadb_names_the_columns_of_the_blueprint_branch_and_nothing_mariadb_10_1_refuses(): void
    {
        $source = (string) file_get_contents(base_path('database/migrations/' . self::FILE . '.php'));
        $this->assertSame(1, preg_match('/CREATE TABLE `g2g_product_match_decisions` \((.*?)\) ENGINE=InnoDB/s', $source, $m), 'the raw DDL is there');
        $ddl = $m[1];

        preg_match_all('/^\s*`(\w+)`\s/m', $ddl, $columns);
        $this->migration()->up();
        $this->assertSame(Schema::getColumnListing(self::TABLE), $columns[1], 'the raw DDL and the Blueprint branch create the same columns in the same order');

        $this->assertStringContainsString('DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', $source, 'dev\'s database default is latin1, so the charset is stated');
        $this->assertStringContainsString('UNIQUE KEY `g2g_pmd_tenant_pair` (`sub_institute_id`, `item_master_id`, `survivor_master_id`)', $ddl);
        $this->assertStringContainsString('KEY `g2g_pmd_tenant_decision` (`sub_institute_id`, `decision`)', $ddl);
        $this->assertStringContainsString('`created_at` TIMESTAMP NULL DEFAULT NULL', $ddl, 'explicit NULL default: no implicit ON UPDATE clause on the first timestamp');
        $this->assertStringContainsString('`updated_at` TIMESTAMP NULL DEFAULT NULL', $ddl);
        $this->assertStringContainsString('`decided_at` DATETIME NULL', $ddl);
        $this->assertDoesNotMatchRegularExpression('/\b(JSON|CURRENT_TIMESTAMP|ON UPDATE|FOREIGN KEY|REFERENCES|GENERATED|VIRTUAL|STORED)\b/i', $ddl);
        $this->assertStringContainsString('`sub_institute_id` BIGINT UNSIGNED NOT NULL', $ddl, 'the tenant column is BIGINT UNSIGNED NOT NULL');
    }
}
