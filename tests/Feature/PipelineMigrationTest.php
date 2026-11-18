<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsFactsWorld;
use Tests\Support\BuildsLeadsWorld;
use Tests\Support\BuildsPipelineWorld;
use Tests\TestCase;

/**
 * The migration of slice 11 (2026_12_11_130000): two nullable columns and one index on crm_opportunities. Run from the real file on sqlite: it adds exactly
 * those, touches no existing row, can be run twice, and its down() removes only what up() made and leaves the table and its data as they were. (The MariaDB
 * statements are exercised on the dev database; the evidence is under Docs/agentic-crm/_evidence/s11/.)
 */
class PipelineMigrationTest extends TestCase
{
    use BuildsFactsWorld;
    use BuildsLeadsWorld;
    use BuildsPipelineWorld;

    private const FILE = 'database/migrations/2026_12_11_130000_add_product_and_loss_reason_to_crm_opportunities.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildPipelineWorld(false);   // the opportunity tables as they are today, without this migration
    }

    private function migration(): object
    {
        return require base_path(self::FILE);
    }

    /** @return list<string> */
    private function columns(): array
    {
        return array_map(fn ($c) => $c['name'], Schema::getColumns('crm_opportunities'));
    }

    public function test_up_adds_the_two_columns_and_the_index_and_nothing_else(): void
    {
        $before = $this->columns();
        $this->assertNotContains('product_master_id', $before);
        $this->assertNotContains('loss_reason_code', $before);

        $this->migration()->up();

        $this->assertSame(['product_master_id', 'loss_reason_code'], array_values(array_diff($this->columns(), $before)));
        $this->assertTrue(Schema::hasIndex('crm_opportunities', 'crm_opportunities_tenant_product'));
        $byName = collect(Schema::getColumns('crm_opportunities'))->keyBy('name');
        $this->assertTrue($byName['product_master_id']['nullable']);
        $this->assertTrue($byName['loss_reason_code']['nullable']);
        $this->assertSame('varchar', $byName['loss_reason_code']['type_name'], 'a short string (VARCHAR(30) on MariaDB: see the SHOW COLUMNS evidence)');
        $indexed = collect(Schema::getIndexes('crm_opportunities'))->firstWhere('name', 'crm_opportunities_tenant_product');
        $this->assertSame(['sub_institute_id', 'product_master_id'], $indexed['columns'], 'led by the tenant');
    }

    public function test_existing_rows_are_untouched_and_get_nulls(): void
    {
        $id = $this->makeOpportunity(self::T, ['name' => 'Old deal', 'sales_stage' => 'Prospecting', 'amount' => '123.00']);
        $before = (array) DB::table('crm_opportunities')->find($id);

        $this->migration()->up();

        $after = (array) DB::table('crm_opportunities')->find($id);
        $this->assertSame($before, array_diff_key($after, ['product_master_id' => 1, 'loss_reason_code' => 1]), 'every old value is as it was');
        $this->assertNull($after['product_master_id']);
        $this->assertNull($after['loss_reason_code']);
    }

    public function test_it_can_be_run_twice(): void
    {
        $this->migration()->up();
        $once = Schema::getColumns('crm_opportunities');
        $indexesOnce = Schema::getIndexes('crm_opportunities');

        $this->migration()->up();

        $this->assertEquals($once, Schema::getColumns('crm_opportunities'));
        $this->assertEquals($indexesOnce, Schema::getIndexes('crm_opportunities'));
    }

    public function test_down_removes_what_up_made_and_keeps_the_data(): void
    {
        $before = $this->columns();
        $indexesBefore = array_column(Schema::getIndexes('crm_opportunities'), 'name');
        $id = $this->makeOpportunity(self::T, ['name' => 'Survives', 'amount' => '55.00']);
        $this->migration()->up();
        DB::table('crm_opportunities')->where('id', $id)->update(['product_master_id' => 3, 'loss_reason_code' => 'price']);

        $this->migration()->down();

        $this->assertSame($before, $this->columns(), 'the table is back to its old columns');
        $this->assertSame($indexesBefore, array_column(Schema::getIndexes('crm_opportunities'), 'name'), 'and its old indexes');
        $this->assertSame('Survives', DB::table('crm_opportunities')->find($id)->name, 'no row was lost');
        $this->migration()->down();   // a second down() does nothing
        $this->assertSame($before, $this->columns());
    }

    public function test_it_does_nothing_on_a_host_without_the_table(): void
    {
        Schema::dropIfExists('crm_opportunity_products');
        Schema::dropIfExists('crm_opportunity_stage_history');
        Schema::dropIfExists('crm_opportunity_contacts');
        Schema::drop('crm_opportunities');

        $this->migration()->up();
        $this->migration()->down();

        $this->assertFalse(Schema::hasTable('crm_opportunities'));
    }

    public function test_it_sorts_after_every_other_migration_and_collides_with_none(): void
    {
        $names = array_map('basename', glob(base_path('database/migrations/*.php')));
        sort($names);
        $mine = basename(self::FILE);

        // Later than the newest file there was when it was written (the Ask tables, 2026_12_11_12*); other tracks add files after it, so "the very newest" is not asserted.
        $this->assertGreaterThan('2026_12_11_120100_create_g2g_agent_messages_table.php', $mine);
        $this->assertCount(1, array_filter($names, fn ($n) => str_starts_with($n, '2026_12_11_1300')), 'nobody else uses its timestamp');
        $this->assertSame(1, substr_count(file_get_contents(base_path(self::FILE)), 'class extends Migration'));
    }

    public function test_it_uses_no_foreign_key_and_no_json_column_and_no_bound_show_columns(): void
    {
        $source = file_get_contents(base_path(self::FILE));
        $code = preg_replace('~/\*.*?\*/~s', '', $source);

        $this->assertStringNotContainsString('->foreign(', $code);
        $this->assertStringNotContainsString('->json(', $code);
        $this->assertStringNotContainsString('ColumnExists::has', $code);
        $this->assertStringNotContainsString('SHOW COLUMNS', $code);
        $this->assertStringContainsString('information_schema.COLUMNS', $code, 'the guard asks information_schema, which works on the live MariaDB 10.1');
    }
}
