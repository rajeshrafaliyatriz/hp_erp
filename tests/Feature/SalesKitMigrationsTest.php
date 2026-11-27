<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsSalesKitWorld;
use Tests\TestCase;

/**
 * The two migrations of the Sales Kit (27 Nov 2026), run from their real files on sqlite. The raw DDL for MariaDB cannot run here; it was run on the dev database one file at a time with
 * `--path`, down() was rehearsed there, and its SHOW CREATE TABLE is saved in Docs/agentic-crm/_evidence/sales-kit-2026-11-27/dev-after-migration.txt. What this proves: both files are guarded
 * (a second run changes nothing), down() removes what up() made and nothing else, the menu row sits beside the Product Portfolio row and grants nothing, and the files sort after every other one.
 */
class SalesKitMigrationsTest extends TestCase
{
    use BuildsSalesKitWorld;

    private const TABLE_FILE = '2026_12_30_090000_create_g2g_product_kit_items_table';

    private const MENU_FILE = '2026_12_30_090100_add_sales_kit_menu_row';

    private function migration(string $file): object
    {
        return require base_path("database/migrations/{$file}.php");
    }

    public function test_the_files_sort_after_every_other_migration_and_the_table_runs_before_the_menu_row(): void
    {
        $names = array_map(fn ($f) => basename($f, '.php'), glob(base_path('database/migrations/*.php')));
        sort($names);

        $others = array_values(array_diff($names, [self::TABLE_FILE, self::MENU_FILE]));
        $this->assertContains(self::TABLE_FILE, $names);
        $this->assertContains(self::MENU_FILE, $names);
        $this->assertGreaterThan(end($others), self::TABLE_FILE, 'it sorts after the newest existing migration, not by the calendar');
        $this->assertSame([self::TABLE_FILE, self::MENU_FILE], array_slice($names, -2));
    }

    public function test_the_table_has_the_contract_columns_and_the_tenant_product_index(): void
    {
        $this->buildSalesKitWorld(false, false);
        $this->assertFalse(Schema::hasTable('g2g_product_kit_items'));

        $this->migration(self::TABLE_FILE)->up();

        $this->assertSame(
            ['id', 'sub_institute_id', 'product_master_id', 'kind', 'title', 'description', 'document_id', 'url', 'sort_order', 'created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at'],
            Schema::getColumnListing('g2g_product_kit_items')
        );
        $indexes = [];
        foreach (Schema::getIndexes('g2g_product_kit_items') as $index) {
            $indexes[$index['name']] = $index['columns'];
        }
        $this->assertSame(['sub_institute_id', 'product_master_id', 'deleted_at', 'sort_order'], $indexes['g2g_pki_tenant_product']);
        $columns = array_column(Schema::getColumns('g2g_product_kit_items'), null, 'name');
        foreach (['description', 'document_id', 'url', 'created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at'] as $nullable) {
            $this->assertTrue($columns[$nullable]['nullable'], "{$nullable} is nullable");
        }
        foreach (['sub_institute_id', 'product_master_id', 'kind', 'title', 'sort_order'] as $required) {
            $this->assertFalse($columns[$required]['nullable'], "{$required} is NOT NULL");
        }
        $this->assertSame('0', trim((string) $columns['sort_order']['default'], "'"));
    }

    public function test_the_table_migration_is_guarded_and_its_down_drops_only_its_own_table(): void
    {
        $this->buildSalesKitWorld(false, false);
        $migration = $this->migration(self::TABLE_FILE);

        $migration->up();
        DB::table('g2g_product_kit_items')->insert(['sub_institute_id' => 6, 'product_master_id' => 1, 'kind' => 'video', 'title' => 'Kept by a second up()']);
        $migration->up();   // the table is there already: nothing happens, the row is still there

        $this->assertSame(1, DB::table('g2g_product_kit_items')->count());

        $migration->down();

        $this->assertFalse(Schema::hasTable('g2g_product_kit_items'));
        foreach (['g2g_product_master', 'document_library', 'document_library_links', 'tblmenumaster_g2g', 'tblgroupwise_rights_g2g'] as $other) {
            $this->assertTrue(Schema::hasTable($other), "{$other} is not this migration's");
        }
        $migration->down();   // and a second down() is harmless
        $this->assertFalse(Schema::hasTable('g2g_product_kit_items'));
    }

    public function test_the_mariadb_ddl_follows_the_database_rules(): void
    {
        $source = (string) file_get_contents(base_path('database/migrations/' . self::TABLE_FILE . '.php'));
        $ddl = substr($source, (int) strpos($source, 'CREATE TABLE'));
        $ddl = substr($ddl, 0, (int) strpos($ddl, '");'));

        $this->assertStringContainsString("SHOW TABLES LIKE 'g2g_product_kit_items'", $source, 'guarded on the table, not on the migration record');
        $this->assertStringContainsString('ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', $ddl, 'utf8mb4 is stated: the dev database default is latin1');
        $this->assertDoesNotMatchRegularExpression('/FOREIGN KEY|REFERENCES|\bJSON\b/i', $ddl, 'no foreign key and no JSON column: live is MariaDB 10.1');
        $this->assertSame(3, substr_count($ddl, 'TIMESTAMP NULL DEFAULT NULL'), 'created_at, updated_at and deleted_at carry an explicit NULL default, so no ON UPDATE is added');
        $this->assertDoesNotMatchRegularExpression('/CURRENT_TIMESTAMP/i', $ddl);
        $this->assertStringContainsString('`sub_institute_id` BIGINT UNSIGNED NOT NULL', $ddl);
        $this->assertStringContainsString('`product_master_id` BIGINT UNSIGNED NOT NULL', $ddl);
        $this->assertStringContainsString('KEY `g2g_pki_tenant_product` (`sub_institute_id`, `product_master_id`, `deleted_at`, `sort_order`)', $ddl);
        $this->assertDoesNotMatchRegularExpression('/DROP|ALTER TABLE/i', $ddl, 'additive: nothing existing is altered');
    }

    public function test_the_menu_row_sits_beside_the_product_portfolio_row_and_grants_nothing(): void
    {
        $this->buildSalesKitWorld(true, false);
        $this->assertSame(0, DB::table('tblmenumaster_g2g')->where('access_link', self::KIT)->count());
        DB::table('tblgroupwise_rights_g2g')->insert(['menu_id' => 479, 'profile_id' => 16, 'can_view' => 1, 'sub_institute_id' => '6']);
        $rightsBefore = DB::table('tblgroupwise_rights_g2g')->count();

        $this->migration(self::MENU_FILE)->up();

        $row = DB::table('tblmenumaster_g2g')->where('access_link', self::KIT)->get();
        $this->assertCount(1, $row);
        $sibling = DB::table('tblmenumaster_g2g')->where('id', 479)->first();
        $this->assertSame(
            ['Sales Kit', (int) $sibling->parent_id, (int) $sibling->level, (int) $sibling->sort_order + 1, 1, null, 'FolderOpen', 'page'],
            [$row[0]->menu_name, (int) $row[0]->parent_id, (int) $row[0]->level, (int) $row[0]->sort_order, (int) $row[0]->status, $row[0]->sub_institute_id, $row[0]->icon, $row[0]->page_type]
        );
        $this->assertSame([476, 3, 4], [(int) $row[0]->parent_id, (int) $row[0]->level, (int) $row[0]->sort_order]);
        $this->assertGreaterThan(479, (int) $row[0]->id, 'the id is not pinned: the table gave the next one');
        $this->assertSame($rightsBefore, DB::table('tblgroupwise_rights_g2g')->count(), 'the migration grants nothing');
    }

    public function test_the_menu_migration_is_idempotent_on_the_link_and_does_nothing_without_a_portfolio_row(): void
    {
        $this->buildSalesKitWorld(true, false);
        $migration = $this->migration(self::MENU_FILE);

        $migration->up();
        $migration->up();
        $this->assertSame(1, DB::table('tblmenumaster_g2g')->where('access_link', self::KIT)->count(), 'a second run adds no second row');

        DB::table('tblmenumaster_g2g')->where('access_link', self::KIT)->delete();
        DB::table('tblmenumaster_g2g')->where('access_link', '/portfolio')->delete();
        $migration->up();
        $this->assertSame(0, DB::table('tblmenumaster_g2g')->where('access_link', self::KIT)->count(), 'nowhere to put it, so no row');

        Schema::drop('tblmenumaster_g2g');
        $migration->up();   // a host with no menu table at all is skipped without an error
        $this->assertFalse(Schema::hasTable('tblmenumaster_g2g'));
    }

    public function test_the_menu_down_removes_the_row_and_its_own_rights_and_nothing_else(): void
    {
        $this->buildSalesKitWorld();
        $kit = $this->kitMenuId();
        DB::table('tblgroupwise_rights_g2g')->insert([
            ['menu_id' => $kit, 'profile_id' => 16, 'can_view' => 1, 'sub_institute_id' => '6'],
            ['menu_id' => $kit, 'profile_id' => 17, 'can_view' => 1, 'sub_institute_id' => null],
            ['menu_id' => 479, 'profile_id' => 16, 'can_view' => 1, 'sub_institute_id' => '6'],
        ]);

        $this->migration(self::MENU_FILE)->down();

        $this->assertSame(0, DB::table('tblmenumaster_g2g')->where('access_link', self::KIT)->count());
        $this->assertSame(0, DB::table('tblgroupwise_rights_g2g')->where('menu_id', $kit)->count(), 'rights on a row that is gone grant nothing, and a reused id must not inherit them');
        $this->assertSame(1, DB::table('tblgroupwise_rights_g2g')->where('menu_id', 479)->count(), 'the Product Portfolio rights are not touched');
        $this->assertSame(1, DB::table('tblmenumaster_g2g')->where('access_link', '/portfolio')->count());
        $this->migration(self::MENU_FILE)->down();   // a second down() is harmless
    }
}
