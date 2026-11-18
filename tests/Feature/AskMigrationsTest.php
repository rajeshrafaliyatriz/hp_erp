<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The two migrations of the Ask chat (slice 8): what up() makes, that running it twice changes nothing, that down() removes exactly what up() made, and
 * that the two branches of each (raw MariaDB DDL for the servers, Blueprint for sqlite) describe the same columns and keys, so the tests that run on
 * sqlite stand for the database the product runs on. The raw DDL itself ran on dev (SHOW CREATE TABLE in _evidence/s8/).
 */
class AskMigrationsTest extends TestCase
{
    private const FILES = [
        'g2g_agent_conversations' => '2026_12_11_120000_create_g2g_agent_conversations_table',
        'g2g_agent_messages' => '2026_12_11_120100_create_g2g_agent_messages_table',
    ];

    private function migration(string $file): object
    {
        return require base_path("database/migrations/{$file}.php");
    }

    /** @return list<string> */
    private function columns(string $table): array
    {
        return array_map('strtolower', Schema::getColumnListing($table));
    }

    private function rawDdl(string $file): string
    {
        $source = (string) file_get_contents(base_path("database/migrations/{$file}.php"));
        $this->assertSame(1, preg_match('/CREATE TABLE `[a-z0-9_]+` \((.*?)\)\s*ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci/s', $source, $m), "{$file}: the raw DDL states the engine and utf8mb4");

        return $m[1];
    }

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

    public function test_down_removes_only_the_table_it_made(): void
    {
        $conversations = $this->migration(self::FILES['g2g_agent_conversations']);
        $messages = $this->migration(self::FILES['g2g_agent_messages']);
        $conversations->up();
        $messages->up();

        $messages->down();
        $this->assertTrue(Schema::hasTable('g2g_agent_conversations'), 'down() of the messages leaves the conversations');
        $messages->up();
        $conversations->down();
        $this->assertTrue(Schema::hasTable('g2g_agent_messages'), 'down() of the conversations leaves the messages');
    }

    public function test_up_leaves_a_table_that_already_has_rows_alone(): void
    {
        foreach (self::FILES as $file) {
            $this->migration($file)->up();
        }
        DB::table('g2g_agent_conversations')->insert(['sub_institute_id' => 6, 'user_id' => 9, 'title' => 'kept', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('g2g_agent_messages')->insert(['sub_institute_id' => 6, 'conversation_id' => 1, 'role' => 'user', 'content' => 'kept too', 'created_at' => now()]);

        foreach (self::FILES as $file) {
            $this->migration($file)->up();
        }

        $this->assertSame('kept', DB::table('g2g_agent_conversations')->value('title'), 'a repeated up() never touches the rows');
        $this->assertSame('kept too', DB::table('g2g_agent_messages')->value('content'));
    }

    public function test_every_table_has_the_tenant_column_not_null_and_exactly_the_columns_the_code_writes(): void
    {
        foreach (self::FILES as $file) {
            $this->migration($file)->up();
        }

        $expected = [
            'g2g_agent_conversations' => ['id', 'sub_institute_id', 'user_id', 'title', 'context_type', 'context_id', 'created_at', 'updated_at', 'archived_at'],
            'g2g_agent_messages' => ['id', 'sub_institute_id', 'conversation_id', 'role', 'content', 'citations', 'status', 'run_id', 'created_at'],
        ];
        foreach ($expected as $table => $columns) {
            $this->assertEqualsCanonicalizing($columns, $this->columns($table), $table);
            $byName = collect(Schema::getColumns($table))->keyBy('name');
            $this->assertFalse($byName['sub_institute_id']['nullable'], "{$table}.sub_institute_id is NOT NULL (0 means shared)");
        }
        $conversation = collect(Schema::getColumns('g2g_agent_conversations'))->keyBy('name');
        $this->assertFalse($conversation['user_id']['nullable'], 'a conversation always has an owner');
        foreach (['context_type', 'context_id', 'archived_at'] as $optional) {
            $this->assertTrue($conversation[$optional]['nullable'], "g2g_agent_conversations.{$optional} is optional");
        }
        $message = collect(Schema::getColumns('g2g_agent_messages'))->keyBy('name');
        foreach (['citations', 'status', 'run_id'] as $optional) {
            $this->assertTrue($message[$optional]['nullable'], "g2g_agent_messages.{$optional} is optional (a question has none of them)");
        }
        $this->assertFalse($message['content']['nullable']);
        $this->assertFalse($message['role']['nullable']);
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

    public function test_the_keys_are_the_ones_the_slice_specifies(): void
    {
        foreach (self::FILES as $file) {
            $this->migration($file)->up();
        }
        $columnsOf = fn (string $table) => array_map(fn ($i) => $i['columns'], array_values(array_filter(Schema::getIndexes($table), fn ($i) => ! $i['primary'])));

        $this->assertSame([['sub_institute_id', 'user_id', 'updated_at']], $columnsOf('g2g_agent_conversations'), 'the list of a person\'s conversations, newest first');
        $this->assertSame([['conversation_id', 'id']], $columnsOf('g2g_agent_messages'), 'the messages of one conversation in order');
    }

    public function test_the_raw_ddl_of_both_files_follows_the_database_rules(): void
    {
        foreach (self::FILES as $file) {
            $ddl = $this->rawDdl($file);
            $source = (string) file_get_contents(base_path("database/migrations/{$file}.php"));

            $this->assertStringNotContainsString('FOREIGN KEY', $ddl, "{$file}: no foreign keys");
            $this->assertStringNotContainsString(' JSON', $ddl, "{$file}: no JSON column (the live host is MariaDB 10.1)");
            $this->assertStringContainsString('`sub_institute_id` BIGINT UNSIGNED NOT NULL', $ddl, "{$file}: the tenant column");
            $this->assertStringNotContainsString('CURRENT_TIMESTAMP', $ddl, "{$file}: no timestamp that MariaDB would give an ON UPDATE clause");
            $this->assertStringContainsString('SHOW TABLES LIKE', $source, "{$file}: guarded by a literal existence check (a bound SHOW ... LIKE fails on MariaDB 10.1)");
            $this->assertDoesNotMatchRegularExpression('/SHOW [A-Z ]+ LIKE \?/', $source, "{$file}: never the bound SHOW ... LIKE ? form");
            $this->assertStringNotContainsString('->change()', $source, "{$file}: no ->change()");
        }
    }

    public function test_the_ask_migrations_sort_after_every_slice_before_them_and_are_not_renamed(): void
    {
        $names = array_map(fn ($p) => basename($p, '.php'), glob(base_path('database/migrations/*.php')) ?: []);
        sort($names);

        foreach (self::FILES as $file) {
            $this->assertContains($file, $names, 'the file exists under this name (it has run on dev under it: never rename)');
        }
        $positions = array_map(fn ($file) => array_search($file, $names, true), array_values(self::FILES));
        $this->assertGreaterThan(array_search('2026_12_10_090400_add_created_via_to_crm_organization_comments', $names, true), min($positions), 'after the agent gateway migrations');
        $this->assertGreaterThan(array_search('2026_12_11_100100_add_product_and_org_account_to_crm_leads', $names, true), min($positions), 'after the leads migrations (S6a)');
        $this->assertGreaterThan(array_search('2026_12_11_090200_add_linkedin_url_to_crm_contacts_and_organizations', $names, true), min($positions), 'after the facts migrations (S4a)');
    }
}
