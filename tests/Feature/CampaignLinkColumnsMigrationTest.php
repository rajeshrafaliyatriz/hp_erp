<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The three migrations of slice 9: product_master_id on crm_campaigns; product_master_id and crm_campaign_id on gtm_campaigns; campaign_id on crm_leads. Each
 * must be additive (every existing row keeps NULL and keeps its other values), guarded (a second run changes nothing, a host without the table skips it), carry
 * an index that starts with the organisation, and its down() must remove only what up() made. (The MariaDB statements ran on the dev database one file at a time:
 * Docs/agentic-crm/_evidence/s9/.)
 */
class CampaignLinkColumnsMigrationTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string, 2: array<string, string>}> file, table, column => index */
    public static function migrations(): array
    {
        return [
            'crm_campaigns' => ['2026_12_11_140000_add_product_master_id_to_crm_campaigns', 'crm_campaigns', ['product_master_id' => 'crm_campaigns_tenant_product']],
            'gtm_campaigns' => ['2026_12_11_140100_add_product_and_crm_campaign_to_gtm_campaigns', 'gtm_campaigns', ['product_master_id' => 'gtm_campaigns_tenant_product', 'crm_campaign_id' => 'gtm_campaigns_tenant_crm_campaign']],
            'crm_leads' => ['2026_12_11_140200_add_campaign_id_to_crm_leads', 'crm_leads', ['campaign_id' => 'crm_leads_tenant_campaign']],
        ];
    }

    private function table(string $table): void
    {
        Schema::create($table, function ($t) {
            $t->id();
            $t->string('name');
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->index('sub_institute_id', 'old_tenant_index');
        });
        DB::table($table)->insert([['name' => 'kept one', 'sub_institute_id' => 6], ['name' => 'kept two', 'sub_institute_id' => 7]]);
    }

    private function migration(string $file): object
    {
        return require base_path("database/migrations/{$file}.php");
    }

    /** @return list<string> */
    private function indexNames(string $table): array
    {
        return collect(Schema::getIndexes($table))->pluck('name')->sort()->values()->all();
    }

    #[DataProvider('migrations')]
    public function test_up_adds_nullable_columns_and_the_organisation_led_indexes_and_changes_no_row(string $file, string $table, array $columns): void
    {
        $this->table($table);

        $this->migration($file)->up();

        $this->assertTrue(Schema::hasColumns($table, array_keys($columns)));
        $indexes = collect(Schema::getIndexes($table))->keyBy('name');
        foreach ($columns as $column => $index) {
            $this->assertTrue(Schema::hasIndex($table, $index));
            $this->assertSame(['sub_institute_id', $column], $indexes[$index]['columns'], 'the organisation comes first');
        }
        $rows = DB::table($table)->orderBy('id')->get();
        $this->assertSame(['kept one', 'kept two'], $rows->pluck('name')->all());
        $this->assertSame([6, 7], $rows->pluck('sub_institute_id')->map(fn ($v) => (int) $v)->all());
        foreach (array_keys($columns) as $column) {
            $this->assertSame([null, null], $rows->pluck($column)->all(), "every existing row keeps NULL in {$column}");
        }
    }

    #[DataProvider('migrations')]
    public function test_a_second_run_changes_nothing(string $file, string $table, array $columns): void
    {
        $this->table($table);
        $migration = $this->migration($file);
        $migration->up();
        $before = [Schema::getColumnListing($table), $this->indexNames($table)];

        $migration->up();

        $this->assertSame($before, [Schema::getColumnListing($table), $this->indexNames($table)]);
    }

    #[DataProvider('migrations')]
    public function test_down_removes_only_what_up_made(string $file, string $table, array $columns): void
    {
        $this->table($table);
        $before = [Schema::getColumnListing($table), $this->indexNames($table)];
        $migration = $this->migration($file);
        $migration->up();

        $migration->down();

        $this->assertSame($before, [Schema::getColumnListing($table), $this->indexNames($table)], 'the column and the index that were there before are still there');
        $this->assertSame(['kept one', 'kept two'], DB::table($table)->orderBy('id')->pluck('name')->all());
        $migration->down();                                          // a second down: nothing to remove, no error
    }

    #[DataProvider('migrations')]
    public function test_a_host_without_the_table_skips_it(string $file, string $table, array $columns): void
    {
        $migration = $this->migration($file);

        $migration->up();
        $migration->down();

        $this->assertFalse(Schema::hasTable($table));
    }

    public function test_the_three_files_sort_after_the_newest_neighbours_and_collide_with_no_other_track(): void
    {
        $files = collect(glob(base_path('database/migrations/2026_12_11_14*.php')))->map(fn ($p) => basename($p, '.php'))->sort()->values()->all();

        $this->assertSame(array_column(self::migrations(), 0), $files, 'exactly the three files of this slice carry the 2026_12_11_14 prefix');
        $this->assertGreaterThan('2026_12_11_130000', $files[0]);
    }
}
