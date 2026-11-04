<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsOutreachWorld;
use Tests\TestCase;

/**
 * The five migrations of slice 10 (the gate's two tables, the two product pointers, the two D19 indexes), run from their real files on sqlite. The DDL for
 * MariaDB is raw and cannot run here; it was run on the dev database one file at a time and its SHOW CREATE TABLE is saved in _evidence/s10/. What this proves:
 * every file is guarded (a second run changes nothing), each down() removes what up() made and nothing else, an index that already exists under another name is
 * not duplicated, a host without the table skips, and the file names sort after the newest existing file and avoid the other tracks' ranges.
 */
class OutreachGateMigrationsTest extends TestCase
{
    use BuildsOutreachWorld;

    private const FILES = [
        'checks' => '2026_12_11_150000_create_g2g_outreach_gate_checks_table',
        'claims' => '2026_12_11_150100_create_g2g_product_claims_table',
        'playbooks' => '2026_12_11_150200_add_product_master_id_to_gtm_playbooks',
        'messages' => '2026_12_11_150300_add_product_master_id_to_gtm_outreach_messages',
        'indexes' => '2026_12_11_150400_add_delivery_indexes_to_gtm_outreach_messages',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildOutreachWorld(false);
    }

    private function migration(string $key): object
    {
        return require base_path('database/migrations/' . self::FILES[$key] . '.php');
    }

    /** @return array<string, list<string>> */
    private function indexes(string $table): array
    {
        $out = [];
        foreach (Schema::getIndexes($table) as $index) {
            $out[$index['name']] = $index['columns'];
        }

        return $out;
    }

    public function test_the_files_sort_after_the_newest_existing_one_and_stay_out_of_the_other_tracks_ranges(): void
    {
        $names = array_map(fn ($f) => basename($f, '.php'), glob(base_path('database/migrations/*.php')));
        sort($names);
        foreach (self::FILES as $name) {
            $this->assertContains($name, $names);
            $this->assertStringStartsWith('2026_12_11_15', $name);
            $this->assertGreaterThan('2026_12_11_140200', $name, 'sorts after the newest file of the other tracks');
        }
        $own = array_values(self::FILES);
        $this->assertSame($own, array_values(array_intersect($names, $own)), 'they run in the order they are listed');
        $this->assertCount(5, array_unique($own));
    }

    public function test_the_checks_table_has_the_columns_the_gate_writes_and_the_message_index(): void
    {
        $this->migration('checks')->up();

        $this->assertSame(
            ['id', 'sub_institute_id', 'message_id', 'message_table', 'run_id', 'check_key', 'passed', 'detail', 'checked_at', 'created_at'],
            Schema::getColumnListing('g2g_outreach_gate_checks')
        );
        $this->assertContains(['sub_institute_id', 'message_table', 'message_id'], array_values($this->indexes('g2g_outreach_gate_checks')));
    }

    public function test_the_claims_table_is_unique_per_organisation_product_and_normalised_sentence(): void
    {
        $this->migration('claims')->up();

        $this->assertSame(
            ['id', 'sub_institute_id', 'product_master_id', 'claim', 'claim_hash', 'source_url', 'status', 'approved_by', 'approved_at', 'created_by', 'created_at', 'updated_at'],
            Schema::getColumnListing('g2g_product_claims')
        );
        $row = ['sub_institute_id' => 1, 'product_master_id' => 5, 'claim' => 'a', 'claim_hash' => sha1('a'), 'status' => 'proposed'];
        DB::table('g2g_product_claims')->insert($row);
        // the same sentence for another organisation, or for another product, is a different row
        DB::table('g2g_product_claims')->insert(['sub_institute_id' => 2] + $row);
        DB::table('g2g_product_claims')->insert(['product_master_id' => 6] + $row);
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('g2g_product_claims')->insert($row);
    }

    public function test_every_file_is_guarded_so_a_second_run_changes_nothing(): void
    {
        foreach (array_keys(self::FILES) as $key) {
            $this->migration($key)->up();
        }
        $snapshot = fn () => [
            'tables' => collect(Schema::getTableListing())->sort()->values()->all(),
            'messages' => Schema::getColumnListing('gtm_outreach_messages'), 'playbooks' => Schema::getColumnListing('gtm_playbooks'),
            'message_indexes' => $this->indexes('gtm_outreach_messages'), 'playbook_indexes' => $this->indexes('gtm_playbooks'),
        ];
        $first = $snapshot();

        foreach (array_keys(self::FILES) as $key) {
            $this->migration($key)->up();
        }

        $this->assertSame($first, $snapshot());
    }

    public function test_the_product_pointers_are_nullable_columns_indexed_with_the_organisation_and_down_removes_only_them(): void
    {
        $messagesBefore = Schema::getColumnListing('gtm_outreach_messages');
        $playbooksBefore = Schema::getColumnListing('gtm_playbooks');
        $indexesBefore = $this->indexes('gtm_outreach_messages');
        $this->migration('playbooks')->up();
        $this->migration('messages')->up();

        $this->assertSame([...$messagesBefore, 'product_master_id'], Schema::getColumnListing('gtm_outreach_messages'));
        $this->assertSame([...$playbooksBefore, 'product_master_id'], Schema::getColumnListing('gtm_playbooks'));
        $this->assertSame(['sub_institute_id', 'product_master_id'], $this->indexes('gtm_outreach_messages')['gtm_outreach_tenant_product']);
        $this->assertSame(['sub_institute_id', 'product_master_id'], $this->indexes('gtm_playbooks')['gtm_playbooks_tenant_product']);

        // existing rows keep NULL; the column accepts a value
        $contact = $this->makeGtmContact(self::T);
        $m = $this->makeGtmMessage(self::T, $contact);
        $this->assertNull(DB::table('gtm_outreach_messages')->where('id', $m->id)->value('product_master_id'));

        $this->migration('messages')->down();
        $this->migration('playbooks')->down();
        $this->assertSame($messagesBefore, Schema::getColumnListing('gtm_outreach_messages'));
        $this->assertSame($playbooksBefore, Schema::getColumnListing('gtm_playbooks'));
        $this->assertSame($indexesBefore, $this->indexes('gtm_outreach_messages'), 'the table is exactly as it was');
        $this->migration('messages')->down();   // and a second down() is harmless
        $this->assertSame($messagesBefore, Schema::getColumnListing('gtm_outreach_messages'));
    }

    public function test_the_delivery_indexes_are_created_where_absent_and_down_drops_only_the_two_it_made(): void
    {
        $before = $this->indexes('gtm_outreach_messages');
        $this->assertNotContains(['provider_message_id'], array_values($before), 'D19: no index on the Message-ID before');

        $this->migration('indexes')->up();
        $after = $this->indexes('gtm_outreach_messages');
        $this->assertSame(['provider_message_id'], $after['gtm_outreach_provider_message_idx']);
        $this->assertSame(['status', 'scheduled_for'], $after['gtm_outreach_status_scheduled_idx']);
        $this->assertCount(count($before) + 2, $after);

        $this->migration('indexes')->down();
        $this->assertSame($before, $this->indexes('gtm_outreach_messages'));
    }

    public function test_an_index_that_already_exists_under_another_name_is_not_duplicated_and_down_leaves_it_alone(): void
    {
        Schema::table('gtm_outreach_messages', function ($t) {
            $t->index('provider_message_id', 'someone_elses_message_id_idx');
            $t->index(['status', 'scheduled_for', 'attempts'], 'someone_elses_due_idx');
        });
        $before = $this->indexes('gtm_outreach_messages');

        $this->migration('indexes')->up();
        $this->assertSame($before, $this->indexes('gtm_outreach_messages'), 'both are already covered (the second by an index that STARTS with the wanted columns)');

        $this->migration('indexes')->down();
        $this->assertSame($before, $this->indexes('gtm_outreach_messages'), 'down() never touches an index it did not create');
    }

    public function test_a_host_without_the_tables_skips_and_nothing_throws(): void
    {
        Schema::drop('gtm_outreach_messages');
        Schema::drop('gtm_playbooks');

        foreach (['playbooks', 'messages', 'indexes'] as $key) {
            $this->migration($key)->up();
            $this->migration($key)->down();
        }

        $this->assertFalse(Schema::hasTable('gtm_outreach_messages'));
        $this->assertFalse(Schema::hasTable('gtm_playbooks'));
    }

    public function test_the_checks_and_claims_tables_go_away_with_down_and_come_back_with_up(): void
    {
        foreach (['checks', 'claims'] as $key) {
            $this->migration($key)->up();
        }
        $this->migration('checks')->down();
        $this->migration('claims')->down();
        $this->assertFalse(Schema::hasTable('g2g_outreach_gate_checks'));
        $this->assertFalse(Schema::hasTable('g2g_product_claims'));
        $this->migration('checks')->down();   // a second down() is harmless
        $this->migration('checks')->up();
        $this->assertTrue(Schema::hasTable('g2g_outreach_gate_checks'));
    }

    // ---- added after the mutation proofs (task 7): nullability and defaults the earlier tests never looked at ----

    public function test_the_organisation_column_is_never_null_and_an_undecided_claim_is_only_ever_proposed(): void
    {
        $this->migration('checks')->up();
        $this->migration('claims')->up();
        $column = fn (string $table, string $name) => collect(Schema::getColumns($table))->firstWhere('name', $name);

        $this->assertFalse($column('g2g_outreach_gate_checks', 'sub_institute_id')['nullable'], 'DB-RULES: 0 means shared, NULL is never used');
        $this->assertFalse($column('g2g_product_claims', 'sub_institute_id')['nullable']);

        // a row written without a status is a PROPOSAL: the table can never approve anything on its own
        DB::table('g2g_product_claims')->insert(['sub_institute_id' => 1, 'product_master_id' => 5, 'claim' => 'a b c', 'claim_hash' => sha1('a b c')]);
        $this->assertSame('proposed', DB::table('g2g_product_claims')->value('status'));
        $this->assertNull(DB::table('g2g_product_claims')->value('approved_by'));
        $this->assertNull(DB::table('g2g_product_claims')->value('approved_at'));
    }

    public function test_the_product_pointers_have_no_default_so_an_old_row_is_not_pointed_at_product_zero(): void
    {
        $this->migration('playbooks')->up();
        $this->migration('messages')->up();

        foreach (['gtm_playbooks', 'gtm_outreach_messages'] as $table) {
            $pointer = collect(Schema::getColumns($table))->firstWhere('name', 'product_master_id');
            $this->assertTrue($pointer['nullable'], $table);
            $this->assertNull($pointer['default'], $table . ': NULL, never 0');
        }
    }
}
