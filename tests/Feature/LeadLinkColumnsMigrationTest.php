<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The second migration of slice 6: product_master_id and org_account_id on crm_leads, each with an index that starts with the tenant. It must be additive
 * (every existing row keeps NULL), guarded (a second run changes nothing, a host without the table skips it) and its down() must remove only what up()
 * made. The widening of `description` is MariaDB-only and was verified on the dev database (Docs/agentic-crm/_evidence/s6/task3-*).
 */
class LeadLinkColumnsMigrationTest extends TestCase
{
    private const FILE = 'database/migrations/2026_12_11_100100_add_product_and_org_account_to_crm_leads.php';

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('crm_leads', function ($t) {
            $t->id();
            $t->string('last_name');
            $t->text('description')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->unsignedBigInteger('source_company_opportunity_id')->nullable();
            $t->index('source_company_opportunity_id', 'crm_leads_source_company_opportunity_id_index');
        });
        DB::table('crm_leads')->insert([['last_name' => 'Verma', 'description' => 'kept', 'sub_institute_id' => 6], ['last_name' => 'Rao', 'description' => null, 'sub_institute_id' => 7]]);
    }

    private function migration(): object
    {
        return require base_path(self::FILE);
    }

    public function test_up_adds_two_nullable_columns_and_the_tenant_led_indexes_and_changes_no_row(): void
    {
        $this->migration()->up();

        $this->assertTrue(Schema::hasColumns('crm_leads', ['product_master_id', 'org_account_id']));
        $this->assertTrue(Schema::hasIndex('crm_leads', 'crm_leads_tenant_product'));
        $this->assertTrue(Schema::hasIndex('crm_leads', 'crm_leads_tenant_org_account'));
        $indexes = collect(Schema::getIndexes('crm_leads'))->keyBy('name');
        $this->assertSame(['sub_institute_id', 'product_master_id'], $indexes['crm_leads_tenant_product']['columns']);
        $this->assertSame(['sub_institute_id', 'org_account_id'], $indexes['crm_leads_tenant_org_account']['columns']);

        $rows = DB::table('crm_leads')->orderBy('id')->get();
        $this->assertSame(['Verma', 'Rao'], $rows->pluck('last_name')->all());
        $this->assertSame('kept', $rows[0]->description);
        $this->assertSame([null, null], $rows->pluck('product_master_id')->all());
        $this->assertSame([null, null], $rows->pluck('org_account_id')->all());
    }

    public function test_a_second_run_changes_nothing(): void
    {
        $migration = $this->migration();
        $migration->up();
        $columns = Schema::getColumnListing('crm_leads');
        $indexes = collect(Schema::getIndexes('crm_leads'))->pluck('name')->sort()->values()->all();

        $migration->up();

        $this->assertSame($columns, Schema::getColumnListing('crm_leads'));
        $this->assertSame($indexes, collect(Schema::getIndexes('crm_leads'))->pluck('name')->sort()->values()->all());
    }

    public function test_down_removes_only_what_up_made(): void
    {
        $before = Schema::getColumnListing('crm_leads');
        $indexesBefore = collect(Schema::getIndexes('crm_leads'))->pluck('name')->sort()->values()->all();
        $migration = $this->migration();
        $migration->up();

        $migration->down();

        $this->assertSame($before, Schema::getColumnListing('crm_leads'));
        $this->assertSame($indexesBefore, collect(Schema::getIndexes('crm_leads'))->pluck('name')->sort()->values()->all(), 'the index that was there before is still there');
        $this->assertSame(['kept', null], DB::table('crm_leads')->orderBy('id')->pluck('description')->all());
        $migration->down();                                                // a second down: nothing to remove, no error
    }

    public function test_a_host_without_the_table_skips_it(): void
    {
        Schema::drop('crm_leads');
        $migration = $this->migration();

        $migration->up();
        $migration->down();

        $this->assertFalse(Schema::hasTable('crm_leads'));
    }
}
