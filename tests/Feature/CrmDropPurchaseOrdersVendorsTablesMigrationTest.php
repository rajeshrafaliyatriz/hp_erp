<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 2026_12_27_090300_drop_crm_purchase_orders_and_vendors_tables: the five tables only the removed CRM Purchase Orders and Vendors modules used are dropped, child tables first, guarded,
 * and come back EMPTY with the exact columns and indexes of their create migrations when it is rolled back. Run here on sqlite (the PHPUnit database); the MariaDB branch is raw DDL written
 * for 10.1 and is rehearsed on the dev host and on a scratch table before live, as DB-RULES says. Its text is checked here against what the real create migrations build: the same columns in
 * the same order, the same index names over the same columns.
 */
class CrmDropPurchaseOrdersVendorsTablesMigrationTest extends TestCase
{
    private const ORIGINALS = [
        'database/migrations/2026_11_24_082200_create_crm_vendors_table.php',
        'database/migrations/2026_11_24_082300_create_crm_vendor_contacts_table.php',
        'database/migrations/2026_11_24_082600_create_crm_purchase_orders_table.php',
        'database/migrations/2026_11_24_082700_create_crm_purchase_order_line_items_table.php',
        'database/migrations/2026_11_24_083000_create_crm_order_to_cash_status_history_tables.php',
    ];

    private const DROP = 'database/migrations/2026_12_27_090300_drop_crm_purchase_orders_and_vendors_tables.php';

    private const TABLES = ['crm_vendors', 'crm_vendor_contacts', 'crm_purchase_orders', 'crm_purchase_order_line_items', 'crm_purchase_order_status_history'];

    private function createOriginals(): void
    {
        foreach (self::ORIGINALS as $file) {
            (require base_path($file))->up();
        }
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
            usort($indexes, fn ($a, $b) => strcmp($a['name'], $b['name']));
            $out[$table] = ['columns' => $columns, 'indexes' => $indexes];
        }

        return $out;
    }

    public function test_up_drops_all_five_tables_and_a_second_run_does_nothing(): void
    {
        $this->createOriginals();
        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }

        $this->drop()->up();
        foreach (self::TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} is dropped");
        }

        $this->drop()->up();   // guarded: nothing left to drop, no error
        foreach (self::TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
    }

    public function test_up_drops_only_these_five_tables(): void
    {
        $this->createOriginals();   // 083000 also makes crm_sales_order_status_history and crm_invoice_status_history
        foreach (['crm_quotes', 'crm_sales_orders', 'crm_invoices', 'crm_products', 'lms_vendors'] as $other) {
            Schema::create($other, fn ($t) => $t->id());
        }
        Schema::table('crm_products', fn ($t) => $t->string('vendor')->nullable());

        $this->drop()->up();

        foreach (['crm_sales_order_status_history', 'crm_invoice_status_history', 'crm_quotes', 'crm_sales_orders', 'crm_invoices', 'crm_products', 'lms_vendors'] as $other) {
            $this->assertTrue(Schema::hasTable($other), "{$other} stays");
        }
        $this->assertTrue(Schema::hasColumn('crm_products', 'vendor'), 'the free-text vendor of a product stays');
    }

    public function test_up_skips_a_table_a_host_does_not_have_and_drops_the_rest(): void
    {
        $this->createOriginals();
        Schema::drop('crm_purchase_order_status_history');
        Schema::drop('crm_vendor_contacts');

        $this->drop()->up();

        foreach (self::TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), $table);
        }
    }

    public function test_down_recreates_all_five_tables_empty_with_the_original_columns_and_indexes(): void
    {
        $this->createOriginals();
        $original = $this->shape();
        $this->drop()->up();

        $this->drop()->down();

        $this->assertSame($original, $this->shape());
        foreach (self::TABLES as $table) {
            $this->assertSame(0, DB::table($table)->count(), "{$table} comes back empty");
        }

        $this->drop()->down();   // guarded: they exist, so a second rollback does nothing
        $this->assertSame($original, $this->shape());
    }

    public function test_down_recreates_a_table_that_is_missing_and_leaves_the_others_as_they_are(): void
    {
        $this->createOriginals();
        DB::table('crm_vendors')->insert(['vendor_no' => 'VEN-1', 'name' => 'Kept', 'sub_institute_id' => 1]);
        Schema::drop('crm_vendor_contacts');

        $this->drop()->down();

        $this->assertTrue(Schema::hasTable('crm_vendor_contacts'));
        $this->assertSame(1, DB::table('crm_vendors')->count(), 'a table that was there is not touched, rows and all');
    }

    public function test_the_unique_keys_of_the_originals_are_kept(): void
    {
        $this->drop()->down();

        $unique = fn (string $table) => collect(Schema::getIndexes($table))->where('unique', true)->where('primary', false)->map(fn ($i) => [$i['name'], $i['columns']])->sortBy(0)->values()->all();
        $this->assertSame([['crm_vendors_vendor_no_unique', ['vendor_no']]], $unique('crm_vendors'));
        $this->assertSame([['crm_vendor_contacts_vendor_id_contact_id_unique', ['vendor_id', 'contact_id']]], $unique('crm_vendor_contacts'));
        $this->assertSame([['crm_purchase_orders_po_no_unique', ['po_no']]], $unique('crm_purchase_orders'));
    }

    /**
     * The MariaDB text is never run by PHPUnit (sqlite), so it is read: for each table the CREATE TABLE names the same columns, in the same order, as the create migrations build,
     * and the same KEY and UNIQUE KEY names over the same columns.
     */
    public function test_the_mariadb_ddl_names_the_same_columns_and_indexes_as_the_create_migrations(): void
    {
        $this->createOriginals();
        $migration = $this->drop();
        $ddl = new \ReflectionMethod($migration, 'ddl');

        foreach (self::TABLES as $table) {
            $sql = $ddl->invoke($migration, $table);

            preg_match_all('/^\s+`([a-z_0-9]+)` [A-Z]/m', $sql, $columns);
            $this->assertSame(array_column(Schema::getColumns($table), 'name'), $columns[1], "{$table}: the columns, in order");

            preg_match_all('/^\s+(UNIQUE KEY|KEY) `([a-z_0-9]+)` \(([^)]*)\)/m', $sql, $keys, PREG_SET_ORDER);
            $fromSql = [];
            foreach ($keys as [, $kind, $name, $cols]) {
                $fromSql[$name] = [$kind === 'UNIQUE KEY', array_map(fn ($c) => trim($c, ' `'), explode(',', $cols))];
            }
            $fromSchema = [];
            foreach (Schema::getIndexes($table) as $i) {
                if (! $i['primary']) {
                    $fromSchema[$i['name']] = [(bool) $i['unique'], $i['columns']];
                }
            }
            ksort($fromSql);
            ksort($fromSchema);
            $this->assertSame($fromSchema, $fromSql, "{$table}: the indexes, by name");
            $this->assertStringContainsString('PRIMARY KEY (`id`)', $sql);
        }
    }

    public function test_the_mariadb_ddl_is_10_1_safe_and_states_its_charset(): void
    {
        $migration = $this->drop();
        $ddl = new \ReflectionMethod($migration, 'ddl');

        foreach (self::TABLES as $table) {
            $sql = $ddl->invoke($migration, $table);
            $this->assertStringStartsWith("CREATE TABLE `{$table}` (", $sql);
            $this->assertStringContainsString('ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', $sql, 'DB-RULES: the charset is stated, the database default is latin1');
            $this->assertDoesNotMatchRegularExpression('/\b(JSON|GENERATED|VIRTUAL|CURRENT_TIMESTAMP)\b/i', $sql, 'MariaDB 10.1: no JSON, no generated column, no timestamp default that the live server rewrites');
            $this->assertStringNotContainsString('FOREIGN KEY', $sql, 'DB-RULES item 5: loose pointer columns; a rollback cannot fail on a host that lacks a parent table');
        }
    }
}
