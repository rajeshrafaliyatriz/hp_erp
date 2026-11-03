<?php

namespace Tests\Feature;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The five migrations of the agent gateway (slice 7, thin): what up() makes, that running it twice changes nothing, that down() removes exactly what up()
 * made, and that the two branches of each (raw MariaDB DDL for the servers, Blueprint for sqlite) describe the same columns and keys, so the tests that run
 * on sqlite stand for the database the product runs on. The raw DDL itself ran on dev (SHOW CREATE TABLE in _evidence/s7-agent-gateway/).
 */
class AgentMigrationsTest extends TestCase
{
    private const FILES = [
        'g2g_agent_runs' => '2026_12_10_090000_create_g2g_agent_runs_table',
        'g2g_agent_proposals' => '2026_12_10_090100_create_g2g_agent_proposals_table',
        'g2g_agent_tool_calls' => '2026_12_10_090200_create_g2g_agent_tool_calls_table',
        'g2g_agent_autonomy' => '2026_12_10_090300_create_g2g_agent_autonomy_table',
    ];

    private const COMMENTS = '2026_12_10_090400_add_created_via_to_crm_organization_comments';

    private function migration(string $file): object
    {
        return require base_path("database/migrations/{$file}.php");
    }

    /** @return list<string> */
    private function columns(string $table): array
    {
        return array_map('strtolower', Schema::getColumnListing($table));
    }

    private function commentsTable(): void
    {
        foreach (['2026_11_20_090100_create_crm_organizations_table', '2026_11_24_084000_create_crm_entity_comments_tables'] as $migration) {
            $this->migration($migration)->up();
        }
    }

    private function rawDdl(string $file): string
    {
        $source = (string) file_get_contents(base_path("database/migrations/{$file}.php"));
        $this->assertSame(1, preg_match('/CREATE TABLE `[a-z0-9_]+` \((.*?)\)\s*ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci/s', $source, $m), "{$file}: the raw DDL states the engine and utf8mb4");

        return $m[1];
    }

    // ---- the four new tables ----

    public function test_each_table_is_created_by_up_and_removed_by_down_and_both_can_be_repeated(): void
    {
        foreach (self::FILES as $table => $file) {
            $m = $this->migration($file);
            $this->assertFalse(Schema::hasTable($table), "{$table} is not there before");

            $m->up();
            $this->assertTrue(Schema::hasTable($table), "{$table} created");
            $columns = $this->columns($table);
            $m->up();
            $this->assertSame($columns, $this->columns($table), "{$table}: a second up() changes nothing");

            $m->down();
            $this->assertFalse(Schema::hasTable($table), "{$table} removed");
            $m->down();
            $this->assertFalse(Schema::hasTable($table), "{$table}: a second down() is harmless");
        }
    }

    public function test_up_leaves_a_table_that_already_has_rows_alone(): void
    {
        foreach (self::FILES as $table => $file) {
            $this->migration($file)->up();
        }
        DB::table('g2g_agent_autonomy')->insert(['sub_institute_id' => 6, 'action_key' => '*', 'level' => 'off']);

        foreach (self::FILES as $file) {
            $this->migration($file)->up();
        }

        $this->assertSame(1, DB::table('g2g_agent_autonomy')->count(), 'a repeated up() never touches the rows');
        $this->assertSame('off', DB::table('g2g_agent_autonomy')->value('level'));
    }

    public function test_every_table_has_the_tenant_column_not_null_and_the_columns_the_code_writes(): void
    {
        foreach (self::FILES as $file) {
            $this->migration($file)->up();
        }

        $expected = [
            'g2g_agent_runs' => ['id', 'sub_institute_id', 'user_id', 'agent_key', 'subject_type', 'subject_id', 'trigger_source', 'status', 'started_at', 'finished_at', 'provider', 'model',
                'input_tokens', 'output_tokens', 'cost', 'cost_currency', 'tool_calls', 'error_code', 'error_message', 'correlation_id', 'brief', 'brief_evidence', 'created_at', 'updated_at'],
            'g2g_agent_proposals' => ['id', 'sub_institute_id', 'run_id', 'subject_type', 'subject_id', 'action', 'entity_type', 'entity_id', 'payload', 'payload_hash', 'evidence', 'reason', 'status',
                'applied_via', 'decided_by', 'decided_at', 'decision_note', 'applied_at', 'result_ref', 'error', 'created_at', 'updated_at'],
            'g2g_agent_tool_calls' => ['id', 'sub_institute_id', 'run_id', 'seq', 'tool', 'args_hash', 'args', 'outcome', 'deny_reason', 'proposal_id', 'result_ref', 'latency_ms', 'created_at'],
            'g2g_agent_autonomy' => ['id', 'sub_institute_id', 'action_key', 'level', 'updated_by', 'updated_at'],
        ];
        foreach ($expected as $table => $columns) {
            $this->assertEqualsCanonicalizing($columns, $this->columns($table), $table);
            $tenant = collect(Schema::getColumns($table))->firstWhere('name', 'sub_institute_id');
            $this->assertFalse($tenant['nullable'], "{$table}.sub_institute_id is NOT NULL (0 means shared)");
        }
    }

    public function test_the_two_branches_of_each_migration_describe_the_same_columns(): void
    {
        foreach (self::FILES as $table => $file) {
            $this->migration($file)->up();
            preg_match_all('/^\s*`([a-z0-9_]+)`\s+[A-Z]/m', $this->rawDdl($file), $m);

            $this->assertEqualsCanonicalizing($m[1], $this->columns($table), "{$table}: the raw MariaDB DDL and the Blueprint branch must list the same columns");
        }
    }

    public function test_the_two_branches_of_each_migration_describe_the_same_keys(): void
    {
        foreach (self::FILES as $table => $file) {
            $this->migration($file)->up();
            preg_match_all('/^\s*(UNIQUE KEY|KEY) `([a-z0-9_]+)`/m', $this->rawDdl($file), $m, PREG_SET_ORDER);
            $raw = [];
            foreach ($m as [, $kind, $name]) {
                $raw[$name] = $kind === 'UNIQUE KEY';
            }
            $built = [];
            foreach (Schema::getIndexes($table) as $index) {
                if (! $index['primary']) {
                    $built[$index['name']] = (bool) $index['unique'];
                }
            }

            ksort($raw);
            ksort($built);
            $this->assertSame($raw, $built, "{$table}: keys (name => unique) of the raw DDL and of the Blueprint branch");
        }
    }

    // ---- the keys that carry the product's promises ----

    public function test_the_same_proposal_cannot_be_stored_twice_for_one_subject_but_can_for_another_subject_or_organisation(): void
    {
        $this->migration(self::FILES['g2g_agent_proposals'])->up();
        $row = fn (array $over = []) => $over + ['sub_institute_id' => 1, 'run_id' => 1, 'subject_type' => 'org_account', 'subject_id' => 10, 'action' => 'add_note',
            'payload' => '{}', 'payload_hash' => str_repeat('a', 40), 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()];

        DB::table('g2g_agent_proposals')->insert($row());

        foreach ([['status' => 'dismissed'], ['status' => 'applied'], ['run_id' => 2]] as $variant) {
            try {
                DB::table('g2g_agent_proposals')->insert($row($variant));
                $this->fail('the same proposal for the same subject was stored twice: ' . json_encode($variant));
            } catch (UniqueConstraintViolationException) {
                $this->addToAssertionCount(1);
            }
        }
        // Another subject, another action, another hash and another organisation are different proposals.
        foreach ([['subject_id' => 11], ['action' => 'add_task'], ['payload_hash' => str_repeat('b', 40)], ['sub_institute_id' => 2]] as $variant) {
            DB::table('g2g_agent_proposals')->insert($row($variant));
        }
        $this->assertSame(5, DB::table('g2g_agent_proposals')->count());
    }

    public function test_a_setting_has_one_value_per_organisation_and_action(): void
    {
        $this->migration(self::FILES['g2g_agent_autonomy'])->up();
        DB::table('g2g_agent_autonomy')->insert(['sub_institute_id' => 6, 'action_key' => 'add_note', 'level' => 'approval']);

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('g2g_agent_autonomy')->insert(['sub_institute_id' => 6, 'action_key' => 'add_note', 'level' => 'alone']);
    }

    public function test_the_same_setting_for_another_organisation_or_action_is_fine(): void
    {
        $this->migration(self::FILES['g2g_agent_autonomy'])->up();
        foreach ([[6, 'add_note'], [7, 'add_note'], [6, 'add_task'], [0, '*']] as [$tenant, $action]) {
            DB::table('g2g_agent_autonomy')->insert(['sub_institute_id' => $tenant, 'action_key' => $action, 'level' => 'approval']);
        }

        $this->assertSame(4, DB::table('g2g_agent_autonomy')->count());
    }

    // ---- the column on the organisation comments ----

    public function test_created_via_is_added_nullable_after_created_by_and_every_existing_note_stays_a_persons(): void
    {
        $this->commentsTable();
        $org = DB::table('crm_organizations')->insertGetId(['account_no' => 'ORG-1', 'name' => 'A', 'sub_institute_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('crm_organization_comments')->insert(['organization_id' => $org, 'sub_institute_id' => 1, 'comment' => 'written before', 'created_by' => 5, 'created_at' => now(), 'updated_at' => now()]);
        $before = array_map(fn ($r) => (array) $r, DB::table('crm_organization_comments')->get()->all());
        $this->assertNotContains('created_via', $this->columns('crm_organization_comments'));

        $m = $this->migration(self::COMMENTS);
        $m->up();

        $this->assertContains('created_via', $this->columns('crm_organization_comments'));
        $column = collect(Schema::getColumns('crm_organization_comments'))->firstWhere('name', 'created_via');
        $this->assertTrue($column['nullable']);
        $after = array_map(fn ($r) => (array) $r, DB::table('crm_organization_comments')->get()->all());
        $this->assertNull($after[0]['created_via'], 'a note written before the column existed is a person\'s (NULL)');
        unset($after[0]['created_via']);
        $this->assertSame($before, $after, 'nothing else of the existing note changed');

        $columns = $this->columns('crm_organization_comments');
        $m->up();
        $this->assertSame($columns, $this->columns('crm_organization_comments'), 'a second up() changes nothing');
    }

    public function test_down_drops_only_created_via_and_leaves_the_notes(): void
    {
        $this->commentsTable();
        $m = $this->migration(self::COMMENTS);
        $columnsBefore = $this->columns('crm_organization_comments');
        $org = DB::table('crm_organizations')->insertGetId(['account_no' => 'ORG-1', 'name' => 'A', 'sub_institute_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $m->up();
        DB::table('crm_organization_comments')->insert(['organization_id' => $org, 'sub_institute_id' => 1, 'comment' => 'by the agent', 'created_by' => 5, 'created_via' => 'agent', 'created_at' => now(), 'updated_at' => now()]);

        $m->down();

        $this->assertSame($columnsBefore, $this->columns('crm_organization_comments'), 'exactly the columns that were there before up()');
        $this->assertSame('by the agent', DB::table('crm_organization_comments')->value('comment'), 'the note stays');
        $m->down();
        $this->assertSame($columnsBefore, $this->columns('crm_organization_comments'), 'a second down() is harmless');
    }

    public function test_the_comments_migration_does_nothing_on_a_host_without_the_table(): void
    {
        $m = $this->migration(self::COMMENTS);
        $this->assertFalse(Schema::hasTable('crm_organization_comments'));

        $m->up();
        $m->down();

        $this->assertFalse(Schema::hasTable('crm_organization_comments'), 'it never creates the comments table');
    }

    public function test_the_agent_migrations_sort_after_every_other_migration_before_them_and_are_not_renamed(): void
    {
        $names = array_map(fn ($p) => basename($p, '.php'), glob(base_path('database/migrations/*.php')) ?: []);
        sort($names);
        $mine = array_values(self::FILES) + [4 => self::COMMENTS];
        $positions = array_map(fn ($n) => array_search($n, $names, true), $mine);

        $this->assertNotContains(false, $positions, 'all five files exist under these names (they have run on dev under them: never rename)');
        $this->assertSame($positions, array_values(array_unique($positions)));
        $this->assertGreaterThan(array_search('2026_12_09_090100_widen_crm_email_columns_to_191', $names, true), min($positions), 'they come after the organisation 360 migrations they build on');
    }
}
