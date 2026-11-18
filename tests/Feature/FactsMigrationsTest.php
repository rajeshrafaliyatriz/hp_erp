<?php

namespace Tests\Feature;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The three migrations of the facts ledger (slice 4): what up() makes, that running it twice changes nothing, that down() removes exactly what up() made,
 * that the two branches of each (raw MariaDB DDL for the servers, Blueprint for sqlite) describe the same columns and keys, and the one key that carries the
 * product's promise: a value exists once per record and field, so a dismissed one cannot be offered again. The raw DDL itself ran on dev (SHOW CREATE TABLE in
 * docs/agentic-crm/_evidence/s4-facts-ledger/).
 */
class FactsMigrationsTest extends TestCase
{
    private const TABLES = [
        'g2g_entity_facts' => '2026_12_11_090000_create_g2g_entity_facts_table',
        'g2g_entity_fact_evidence' => '2026_12_11_090100_create_g2g_entity_fact_evidence_table',
    ];

    private const LINKEDIN = '2026_12_11_090200_add_linkedin_url_to_crm_contacts_and_organizations';

    private function migration(string $file): object
    {
        return require base_path("database/migrations/{$file}.php");
    }

    /** @return list<string> */
    private function columns(string $table): array
    {
        return array_map('strtolower', Schema::getColumnListing($table));
    }

    private function crmTables(): void
    {
        foreach (['2026_11_20_090100_create_crm_organizations_table', '2026_11_20_090200_create_crm_contacts_table'] as $migration) {
            $this->migration($migration)->up();
        }
    }

    private function rawDdl(string $file): string
    {
        $source = (string) file_get_contents(base_path("database/migrations/{$file}.php"));
        $this->assertSame(1, preg_match('/CREATE TABLE `[a-z0-9_]+` \((.*?)\)\s*ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci/s', $source, $m), "{$file}: the raw DDL states the engine and utf8mb4");

        return $m[1];
    }

    public function test_each_table_is_created_by_up_and_removed_by_down_and_both_can_be_repeated(): void
    {
        foreach (self::TABLES as $table => $file) {
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
        foreach (self::TABLES as $file) {
            $this->migration($file)->up();
        }
        DB::table('g2g_entity_fact_evidence')->insert(['sub_institute_id' => 6, 'fact_id' => 1, 'kind' => 'web.cited-claim', 'weight' => 0.4]);

        foreach (self::TABLES as $file) {
            $this->migration($file)->up();
        }

        $this->assertSame(1, DB::table('g2g_entity_fact_evidence')->count(), 'a repeated up() never touches the rows');
    }

    public function test_every_table_has_the_tenant_column_not_null_and_exactly_the_columns_the_code_writes(): void
    {
        foreach (self::TABLES as $file) {
            $this->migration($file)->up();
        }
        $expected = [
            'g2g_entity_facts' => ['id', 'sub_institute_id', 'entity_type', 'entity_id', 'field', 'value', 'value_hash', 'value_kind', 'status', 'band', 'score', 'method', 'source_url',
                'proposed_by', 'decided_by', 'decided_at', 'decision_note', 'column_written', 'supersedes_id', 'created_at', 'updated_at'],
            'g2g_entity_fact_evidence' => ['id', 'sub_institute_id', 'fact_id', 'kind', 'weight', 'source_url', 'quote', 'retrieved_at', 'created_at'],
        ];
        foreach ($expected as $table => $columns) {
            $this->assertEqualsCanonicalizing($columns, $this->columns($table), $table);
            $tenant = collect(Schema::getColumns($table))->firstWhere('name', 'sub_institute_id');
            $this->assertFalse($tenant['nullable'], "{$table}.sub_institute_id is NOT NULL (0 means shared)");
        }
    }

    public function test_the_two_branches_of_each_migration_describe_the_same_columns(): void
    {
        foreach (self::TABLES as $table => $file) {
            $this->migration($file)->up();
            preg_match_all('/^\s*`([a-z0-9_]+)`\s+[A-Z]/m', $this->rawDdl($file), $m);

            $this->assertEqualsCanonicalizing($m[1], $this->columns($table), "{$table}: the raw MariaDB DDL and the Blueprint branch must list the same columns");
        }
    }

    public function test_the_two_branches_of_each_migration_describe_the_same_keys(): void
    {
        foreach (self::TABLES as $table => $file) {
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

    public function test_the_raw_ddl_uses_only_what_the_live_mariadb_10_1_accepts(): void
    {
        foreach (self::TABLES as $file) {
            $source = (string) file_get_contents(base_path("database/migrations/{$file}.php"));
            $ddl = $this->rawDdl($file);

            $this->assertDoesNotMatchRegularExpression('/\bJSON\b/i', $ddl, 'no JSON column on 10.1');
            $this->assertDoesNotMatchRegularExpression('/FOREIGN KEY|REFERENCES/i', $ddl, 'no foreign keys on new tables');
            $this->assertDoesNotMatchRegularExpression('/ON UPDATE/i', $ddl, 'no ON UPDATE clause on a timestamp');
            $this->assertDoesNotMatchRegularExpression('/DEFAULT CURRENT_TIMESTAMP/i', $ddl, 'no first NOT NULL DEFAULT CURRENT_TIMESTAMP column');
            $this->assertStringNotContainsString('SHOW COLUMNS', $source, 'no SHOW COLUMNS ... LIKE ? (error 1064 on live)');
            $this->assertStringNotContainsString('->change()', $source);
            $this->assertMatchesRegularExpression('/`sub_institute_id` BIGINT UNSIGNED NOT NULL/', $ddl);
        }
    }

    // ---- the key that carries the product's promise ----

    private function fact(array $over = []): array
    {
        return $over + ['sub_institute_id' => 6, 'entity_type' => 'contact', 'entity_id' => 10, 'field' => 'email', 'value' => 'a@school.example', 'value_hash' => str_repeat('a', 40),
            'status' => 'proposed', 'band' => 'probable', 'score' => 0.6, 'method' => 'test', 'proposed_by' => 'test', 'created_at' => now(), 'updated_at' => now()];
    }

    public function test_the_same_value_cannot_be_stored_twice_for_one_record_and_field_whatever_its_status(): void
    {
        $this->migration(self::TABLES['g2g_entity_facts'])->up();
        DB::table('g2g_entity_facts')->insert($this->fact(['status' => 'dismissed']));

        foreach (['proposed', 'applied', 'dismissed', 'superseded'] as $status) {
            try {
                DB::table('g2g_entity_facts')->insert($this->fact(['status' => $status]));
                $this->fail("a second row for the same value was stored with status {$status}");
            } catch (UniqueConstraintViolationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(1, DB::table('g2g_entity_facts')->count());
    }

    public function test_another_record_field_value_or_organisation_is_a_different_fact(): void
    {
        $this->migration(self::TABLES['g2g_entity_facts'])->up();
        DB::table('g2g_entity_facts')->insert($this->fact());

        foreach ([['entity_id' => 11], ['entity_type' => 'lead'], ['field' => 'secondary_email'], ['value_hash' => str_repeat('b', 40)], ['sub_institute_id' => 7]] as $variant) {
            DB::table('g2g_entity_facts')->insert($this->fact($variant));
        }

        $this->assertSame(6, DB::table('g2g_entity_facts')->count());
    }

    // ---- linkedin_url on the two CRM tables ----

    public function test_linkedin_url_is_added_nullable_to_both_tables_and_every_existing_row_stays_null(): void
    {
        $this->crmTables();
        DB::table('crm_organizations')->insert(['account_no' => 'ORG-1', 'name' => 'A', 'sub_institute_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('crm_contacts')->insert(['contact_no' => 'CON-1', 'last_name' => 'B', 'sub_institute_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $before = ['crm_organizations' => $this->columns('crm_organizations'), 'crm_contacts' => $this->columns('crm_contacts')];
        $rowsBefore = [DB::table('crm_organizations')->first(), DB::table('crm_contacts')->first()];

        $m = $this->migration(self::LINKEDIN);
        $m->up();

        foreach (['crm_organizations', 'crm_contacts'] as $table) {
            $this->assertEqualsCanonicalizing([...$before[$table], 'linkedin_url'], $this->columns($table), "{$table}: exactly one new column");
            $this->assertTrue(collect(Schema::getColumns($table))->firstWhere('name', 'linkedin_url')['nullable'], "{$table}.linkedin_url is nullable");
            $this->assertNull(DB::table($table)->value('linkedin_url'), "{$table}: an existing row stays NULL");
        }
        $columns = $this->columns('crm_contacts');
        $m->up();
        $this->assertSame($columns, $this->columns('crm_contacts'), 'a second up() changes nothing');
        foreach ([DB::table('crm_organizations')->first(), DB::table('crm_contacts')->first()] as $i => $row) {
            $this->assertEquals(collect($rowsBefore[$i])->all() + ['linkedin_url' => null], (array) $row, 'nothing else of an existing row changed');
        }
    }

    public function test_down_drops_only_linkedin_url_and_keeps_the_rows(): void
    {
        $this->crmTables();
        $before = $this->columns('crm_contacts');
        $m = $this->migration(self::LINKEDIN);
        $m->up();
        DB::table('crm_contacts')->insert(['contact_no' => 'CON-2', 'last_name' => 'C', 'sub_institute_id' => 1, 'linkedin_url' => 'https://www.linkedin.com/in/c', 'created_at' => now(), 'updated_at' => now()]);

        $m->down();

        $this->assertSame($before, $this->columns('crm_contacts'), 'exactly the columns that were there before up()');
        $this->assertSame('C', DB::table('crm_contacts')->value('last_name'), 'the row stays');
        $m->down();
        $this->assertSame($before, $this->columns('crm_contacts'), 'a second down() is harmless');
    }

    public function test_the_linkedin_migration_does_nothing_on_a_host_without_the_tables_and_creates_none(): void
    {
        $m = $this->migration(self::LINKEDIN);

        $m->up();
        $m->down();

        $this->assertFalse(Schema::hasTable('crm_contacts'));
        $this->assertFalse(Schema::hasTable('crm_organizations'));
    }

    public function test_the_linkedin_migration_adds_a_300_character_column_by_raw_ddl_on_the_servers(): void
    {
        $source = (string) file_get_contents(base_path('database/migrations/' . self::LINKEDIN . '.php'));

        $this->assertStringContainsString('ADD COLUMN `linkedin_url` VARCHAR(300) NULL', $source);
        $this->assertStringContainsString('information_schema.COLUMNS', $source, 'guarded by information_schema, not the bound SHOW COLUMNS form');
        $this->assertStringNotContainsString('ColumnExists::has(', $source, 'the comment may name the helper; the code must not call it');
        $this->assertStringNotContainsString('use App\Support\ColumnExists', $source);
    }

    public function test_the_facts_migrations_sort_after_every_other_migration_before_them_and_are_not_renamed(): void
    {
        $names = array_map(fn ($p) => basename($p, '.php'), glob(base_path('database/migrations/*.php')) ?: []);
        sort($names);
        $mine = [...array_values(self::TABLES), self::LINKEDIN];
        $positions = array_map(fn ($n) => array_search($n, $names, true), $mine);

        $this->assertNotContains(false, $positions, 'all three files exist under these names (they ran on dev under them: never rename)');
        $this->assertSame($positions, array_values(array_unique($positions)));
        $this->assertGreaterThan(array_search('2026_12_10_120000_move_signal_review_menu_row_to_the_crm_root', $names, true), min($positions), 'they come after the newest migration that existed when they were written');
    }
}
