<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 2026_12_28_110000_drop_g2g_partners_table: the one table only the removed Partner Network used is dropped, guarded, and comes back EMPTY with the exact columns and indexes of its create
 * migration when it is rolled back. Run here on sqlite (the PHPUnit database); the MariaDB branch is raw DDL written for 10.1 and is rehearsed on the dev host and on a scratch table before
 * live, as DB-RULES says. Its text is checked here against what the real create migration builds: the same columns in the same order with the same nullability, the same index names over the
 * same columns.
 */
class DropG2gPartnersTableMigrationTest extends TestCase
{
    private const CREATE = 'database/migrations/2026_09_30_220000_create_g2g_portfolio_and_partners_tables.php';

    private const DROP = 'database/migrations/2026_12_28_110000_drop_g2g_partners_table.php';

    private function createOriginal(): void
    {
        (require base_path(self::CREATE))->up();   // g2g_product_offers, g2g_partners, g2g_opportunity_matches
    }

    private function drop(): object
    {
        return require base_path(self::DROP);
    }

    /** @return array{columns: list<array<string, mixed>>, indexes: list<array<string, mixed>>} */
    private function shape(string $table = 'g2g_partners'): array
    {
        $columns = array_map(fn (array $c) => array_intersect_key($c, array_flip(['name', 'type_name', 'nullable', 'default', 'auto_increment'])), Schema::getColumns($table));
        $indexes = array_map(fn (array $i) => array_intersect_key($i, array_flip(['name', 'columns', 'unique', 'primary'])), Schema::getIndexes($table));
        usort($indexes, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return ['columns' => $columns, 'indexes' => $indexes];
    }

    public function test_up_drops_the_table_and_a_second_run_does_nothing(): void
    {
        $this->createOriginal();
        $this->assertTrue(Schema::hasTable('g2g_partners'));

        $this->drop()->up();
        $this->assertFalse(Schema::hasTable('g2g_partners'));

        $this->drop()->up();   // guarded: nothing left to drop, no error
        $this->assertFalse(Schema::hasTable('g2g_partners'));
    }

    public function test_up_drops_the_table_with_rows_in_it_and_only_that_table(): void
    {
        $this->createOriginal();
        DB::table('g2g_partners')->insert(['partner_id' => 'PT-1', 'partner_name' => 'Acme', 'partner_type' => 'Regional SI']);
        DB::table('g2g_product_offers')->insert(['offer_id' => 'OFF-1', 'name' => 'Offer', 'parent_product' => 'G2G', 'readiness_status' => 'Ready', 'partner_sellable' => 1]);
        DB::table('g2g_opportunity_matches')->insert(['sub_institute_id' => 1, 'signal_type' => 'opportunity', 'signal_id' => 5, 'offer_id' => 'OFF-1', 'partner_id' => null]);

        $this->drop()->up();

        $this->assertFalse(Schema::hasTable('g2g_partners'));
        $this->assertTrue(Schema::hasTable('g2g_product_offers'));
        $this->assertTrue(Schema::hasColumn('g2g_product_offers', 'partner_sellable'), 'the product readiness flag stays');
        $this->assertSame(1, DB::table('g2g_product_offers')->count());
        $this->assertTrue(Schema::hasTable('g2g_opportunity_matches'));
        $this->assertTrue(Schema::hasColumn('g2g_opportunity_matches', 'partner_id'), 'the nullable column of the match table stays');
        $this->assertSame(1, DB::table('g2g_opportunity_matches')->count());
    }

    public function test_up_does_nothing_on_a_host_that_never_had_the_table(): void
    {
        $this->drop()->up();

        $this->assertFalse(Schema::hasTable('g2g_partners'));
    }

    public function test_down_recreates_the_table_empty_with_the_original_columns_and_indexes(): void
    {
        $this->createOriginal();
        $original = $this->shape();
        $this->assertCount(38, $original['columns'], 'the create migration made 38 columns: this guards the comparison below against an empty read');
        $this->drop()->up();

        $this->drop()->down();

        $this->assertSame($original, $this->shape());
        $this->assertSame(0, DB::table('g2g_partners')->count(), 'it comes back empty');
        $this->assertSame([
            ['g2g_partners_tenant_partner_id', ['sub_institute_id', 'partner_id']],
            ['g2g_partners_tenant_status', ['sub_institute_id', 'partner_status']],
        ], collect(Schema::getIndexes('g2g_partners'))->where('primary', false)->map(fn ($i) => [$i['name'], $i['columns']])->sortBy(0)->values()->all());

        $this->drop()->down();   // guarded: it exists, so a second rollback does nothing
        $this->assertSame($original, $this->shape());
    }

    public function test_down_does_not_touch_a_table_that_is_there(): void
    {
        $this->createOriginal();
        DB::table('g2g_partners')->insert(['partner_id' => 'PT-1', 'partner_name' => 'Kept', 'partner_type' => 'Regional SI']);

        $this->drop()->down();

        $this->assertSame(1, DB::table('g2g_partners')->count(), 'a table that was there is not touched, rows and all');
    }

    public function test_the_recreated_table_takes_a_row_with_the_defaults_of_the_original(): void
    {
        $this->drop()->down();

        DB::table('g2g_partners')->insert(['partner_id' => 'PT-1', 'partner_name' => 'Acme', 'partner_type' => 'Regional SI']);

        $row = DB::table('g2g_partners')->first();
        $this->assertSame('Prospect', $row->partner_status);
        $this->assertEquals(1, $row->max_concurrent_deals);
        $this->assertEquals(1, $row->capacity_available);
        $this->assertEquals(0, $row->deals_won);
        $this->assertEquals(0, $row->is_example);
    }

    /**
     * The MariaDB text is never run by PHPUnit (sqlite), so it is read: the CREATE TABLE names the same columns, in the same order, with the same nullability and the same defaults as the
     * create migration builds, and the same KEY names over the same columns.
     */
    public function test_the_mariadb_ddl_names_the_same_columns_and_indexes_as_the_create_migration(): void
    {
        $this->createOriginal();
        $migration = $this->drop();
        $ddl = new \ReflectionMethod($migration, 'ddl');
        $sql = $ddl->invoke($migration);

        preg_match_all('/^\s+`([a-z_0-9]+)` ([A-Z]+(?:\([0-9,]+\))?(?: UNSIGNED)?)( NOT NULL| NULL)(?: AUTO_INCREMENT)?(?: DEFAULT (\'[^\']*\'|[0-9]+))?,?$/m', $sql, $columns, PREG_SET_ORDER);
        $real = Schema::getColumns('g2g_partners');
        $this->assertSame(array_column($real, 'name'), array_column($columns, 1), 'the columns, in order');
        foreach ($columns as $parts) {
            $name = $parts[1];
            $null = $parts[3];
            $default = $parts[4] ?? '';
            $column = collect($real)->firstWhere('name', $name);
            if ($name === 'id') {
                continue;
            }
            $this->assertSame($column['nullable'], trim($null) === 'NULL', "{$name}: nullability");
            $this->assertSame($column['default'] === null ? null : trim((string) $column['default'], "'"), ($default ?? '') === '' ? null : trim($default, "'"), "{$name}: default");
        }

        preg_match_all('/^\s+(UNIQUE KEY|KEY) `([a-z_0-9]+)` \(([^)]*)\)/m', $sql, $keys, PREG_SET_ORDER);
        $fromSql = [];
        foreach ($keys as [, $kind, $name, $cols]) {
            $fromSql[$name] = [$kind === 'UNIQUE KEY', array_map(fn ($c) => trim($c, ' `'), explode(',', $cols))];
        }
        $fromSchema = [];
        foreach (Schema::getIndexes('g2g_partners') as $i) {
            if (! $i['primary']) {
                $fromSchema[$i['name']] = [(bool) $i['unique'], $i['columns']];
            }
        }
        ksort($fromSql);
        ksort($fromSchema);
        $this->assertSame($fromSchema, $fromSql, 'the indexes, by name');
        $this->assertStringContainsString('PRIMARY KEY (`id`)', $sql);
    }

    public function test_the_mariadb_ddl_is_10_1_safe_and_states_its_charset(): void
    {
        $migration = $this->drop();
        $ddl = new \ReflectionMethod($migration, 'ddl');
        $sql = $ddl->invoke($migration);

        $this->assertStringStartsWith('CREATE TABLE `g2g_partners` (', $sql);
        $this->assertStringContainsString('ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', $sql, 'DB-RULES: the charset is stated, the database default is latin1');
        $this->assertDoesNotMatchRegularExpression('/\b(JSON|GENERATED|VIRTUAL|CURRENT_TIMESTAMP)\b/i', $sql, 'MariaDB 10.1: no JSON, no generated column, no timestamp default that the live server rewrites');
        $this->assertStringNotContainsString('FOREIGN KEY', $sql, 'DB-RULES item 5: loose pointer columns');
    }

    public function test_the_file_says_when_to_run_it_and_sorts_after_every_other_migration_it_depends_on(): void
    {
        $text = (string) file_get_contents(base_path(self::DROP));
        $this->assertStringContainsString('RUN IT ONLY AFTER THE BACKEND THAT NO LONGER READS THE TABLE IS DEPLOYED', $text);
        $this->assertStringContainsString('MatchingEngine reads g2g_partners during EVERY signal import', $text);

        $files = array_map('basename', glob(base_path('database/migrations/*.php')) ?: []);
        sort($files);
        $drop = basename(self::DROP);
        $this->assertGreaterThan(array_search('2026_12_28_100000_create_g2g_signal_contacts_table.php', $files, true), array_search($drop, $files, true), 'it sorts after the newest file that existed when it was written');
        $this->assertGreaterThan(array_search(basename(self::CREATE), $files, true), array_search($drop, $files, true));
        $this->assertCount(1, array_filter($files, fn ($f) => str_starts_with($f, '2026_12_28_110000_')), 'the timestamp is unique');
    }
}
