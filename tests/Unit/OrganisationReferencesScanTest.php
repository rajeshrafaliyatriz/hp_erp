<?php

namespace Tests\Unit;

use App\Domain\OrgAccounts\OrganisationReferences;
use PHPUnit\Framework\TestCase;

/**
 * The guard behind the organisation merge (slice 3, decision D6): reads every migration, finds every column that looks like a reference to a CRM organisation,
 * and fails when one is neither in OrganisationReferences nor in the allow-list below with a reason. So a table added later that points at an organisation
 * cannot be forgotten: the merge would leave its rows on the removed duplicate, and this test is what stops that reaching a release.
 *
 * What counts as "looks like a reference":
 *   - a column named like organization_id (crm_organization_id, converted_organization_id, organisation_id, ...), or linked_account_id, or any account_id /
 *     *_account_id (the gtm_* tables use account_id for the GTM account, which is NOT a CRM organisation: those are allow-listed, one reason each);
 *   - a polymorphic type column (linked_type, entity_type, related_type, target_type, from_type, to_type, record_table). Whether its values include an
 *     organisation is not visible from the schema, so every one must be registered or allow-listed with the reason it cannot hold an organisation.
 *
 * Plain PHPUnit, no database: it only reads files.
 */
class OrganisationReferencesScanTest extends TestCase
{
    /**
     * Found in the migrations, not a reference to crm_organizations. Each reason is one line and says what the column points at instead.
     *
     * @var array<string, string> "table.column" => reason
     */
    private const ALLOWED = [
        'gtm_contacts.account_id' => 'points at gtm_accounts, the GTM account, not a CRM organisation',
        'gtm_activities.account_id' => 'points at gtm_accounts, the GTM account, not a CRM organisation',
        'gtm_deals.account_id' => 'points at gtm_accounts, the GTM account, not a CRM organisation',
        'gtm_outreach_messages.account_id' => 'points at gtm_accounts, the GTM account, not a CRM organisation',
        'gtm_campaign_members.account_id' => 'points at gtm_accounts, the GTM account, not a CRM organisation',
        'gtm_renewals.account_id' => 'points at gtm_accounts, the GTM account, not a CRM organisation',
        'gtm_health_snapshots.account_id' => 'points at gtm_accounts, the GTM account, not a CRM organisation',
        'g2g_org_accounts.gtm_account_id' => 'the identity row\'s pointer to gtm_accounts (the GTM account), not a CRM organisation',
        'g2g_audit_log.entity_type' => 'append-only audit trail of what happened to the removed record; rewriting it would falsify the history',
        'g2g_event.entity_type' => 'append-only event store (replayed by id); the removed organisation\'s events stay with it',
        'gtm_workflow_runs.entity_type' => 'copy of the triggering event\'s entity type on a finished workflow run: history of the run, not a live link',
        'ai_audit_logs.related_type' => 'AI audit trail; holds AI table names (ai_conversations, ai_generated_reports), never a CRM record',
        'ai_usage_events.related_type' => 'AI usage meter; holds the AI/LMS subject of a call (quiz_marking, ai_conversations), never a CRM record',
        'g2g_agent_proposals.entity_type' => 'with entity_id, an informational copy of the CRM record an agent proposal was made for; applying a proposal re-derives its target from the subject identity (g2g_org_accounts) and never finds a record by this pair, and a proposal is history of what the agent proposed, which a merge does not rewrite',
    ];

    private const DIRECT = '/^(?:[a-z0-9_]*organi[sz]ation_id|linked_account_id|account_id|[a-z0-9_]+_account_id)$/';

    private const TYPE = '/^(?:(?:linked|entity|related|target|from|to)_type|record_table)$/';

    /** References that no naming pattern can find because the column is not named after an organisation: the tree on the organisations table itself. */
    private const ALWAYS = ['crm_organizations.parent_id'];

    private static function migrations(): string
    {
        return dirname(__DIR__, 2) . '/database/migrations';
    }

    /**
     * Every column definition in the migration files of a directory, keyed "table.column". A definition is a Blueprint call (->unsignedBigInteger('x'),
     * ->string('x'), ...) or a raw `x` BIGINT/VARCHAR/... inside CREATE TABLE / ALTER TABLE; its table is the nearest Schema::create/table or CREATE/ALTER TABLE
     * above it in the file.
     *
     * @return list<array{table: string, column: string, file: string}>
     */
    private function definitions(string $dir): array
    {
        $found = [];
        foreach (glob($dir . '/*.php') ?: [] as $path) {
            $source = (string) file_get_contents($path);
            $tables = [];
            preg_match_all('/Schema::(?:create|table)\(\s*[\'"]([a-z0-9_]+)[\'"]/', $source, $m, PREG_OFFSET_CAPTURE);
            foreach ($m[1] as [$name, $offset]) {
                $tables[] = [$offset, $name];
            }
            preg_match_all('/(?:CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?|ALTER\s+TABLE)\s+`?([a-z0-9_]+)`?/i', $source, $m, PREG_OFFSET_CAPTURE);
            foreach ($m[1] as [$name, $offset]) {
                $tables[] = [$offset, $name];
            }
            usort($tables, fn ($a, $b) => $a[0] <=> $b[0]);

            $columns = [];
            preg_match_all('/->(?:unsignedBigInteger|bigInteger|unsignedInteger|integer|unsignedSmallInteger|smallInteger|foreignId|string|char|text|mediumText)\(\s*[\'"]([a-z0-9_]+)[\'"]/', $source, $m, PREG_OFFSET_CAPTURE);
            foreach ($m[1] as [$name, $offset]) {
                $columns[] = [$offset, $name];
            }
            preg_match_all('/`([a-z0-9_]+)`\s+(?:BIGINT|INT|SMALLINT|TINYINT|VARCHAR|CHAR|TEXT)\b/i', $source, $m, PREG_OFFSET_CAPTURE);
            foreach ($m[1] as [$name, $offset]) {
                $columns[] = [$offset, $name];
            }

            foreach ($columns as [$offset, $column]) {
                $table = '(no table found)';
                foreach ($tables as [$tableOffset, $name]) {
                    if ($tableOffset <= $offset) {
                        $table = $name;
                    }
                }
                $found[] = ['table' => $table, 'column' => $column, 'file' => basename($path)];
            }
        }

        return $found;
    }

    /**
     * The definitions by "table.column" (a column is usually defined in a create migration and mentioned again by later ones; the first file is kept for messages).
     *
     * @return array<string, array{table: string, column: string, file: string}>
     */
    private function definitionsByKey(string $dir): array
    {
        $keyed = [];
        foreach ($this->definitions($dir) as $d) {
            $keyed["{$d['table']}.{$d['column']}"] ??= $d;
        }

        return $keyed;
    }

    /**
     * Columns whose name looks like an organisation reference, plus quoted mentions of an organization_id-style name in a file that never defines it (a loop or an
     * array-driven migration the definitions pass cannot attach to a table): those are keyed "(file).column" so they cannot slip through unseen.
     *
     * @return array<string, array{table: string, column: string, file: string}>
     */
    private function candidates(string $dir): array
    {
        $defined = $this->definitions($dir);
        $candidates = [];
        foreach ($this->definitionsByKey($dir) as $key => $d) {
            if (preg_match(self::DIRECT, $d['column']) || preg_match(self::TYPE, $d['column']) || in_array($key, self::ALWAYS, true)) {
                $candidates[$key] = $d;
            }
        }

        foreach (glob($dir . '/*.php') ?: [] as $path) {
            $source = (string) file_get_contents($path);
            preg_match_all('/[\'"`]([a-z0-9_]*organi[sz]ation_id)[\'"`]/', $source, $m);
            foreach (array_unique($m[1]) as $column) {
                $definedHere = array_filter($defined, fn ($d) => $d['file'] === basename($path) && $d['column'] === $column);
                if ($definedHere === []) {
                    $candidates['(' . basename($path) . ").{$column}"] = ['table' => '(' . basename($path) . ')', 'column' => $column, 'file' => basename($path)];
                }
            }
        }

        return $candidates;
    }

    /**
     * @param  list<array{table: string, column: string}>  $registry
     * @param  array<string, string>  $allowed
     * @return list<string> "table.column  (file)" of every candidate that is neither registered nor allow-listed
     */
    private function unregistered(string $dir, array $registry, array $allowed): array
    {
        $known = $allowed;
        foreach ($registry as $r) {
            $known["{$r['table']}.{$r['column']}"] = 'registry';
        }

        $missing = [];
        foreach ($this->candidates($dir) as $key => $d) {
            if (! isset($known[$key])) {
                $missing[] = "{$key}  ({$d['file']})";
            }
        }
        sort($missing);

        return $missing;
    }

    public function test_every_organisation_reference_in_the_migrations_is_registered_or_allow_listed(): void
    {
        $missing = $this->unregistered(self::migrations(), OrganisationReferences::columns(), self::ALLOWED);

        $this->assertSame([], $missing, "These columns look like references to a CRM organisation but are not in App\\Domain\\OrgAccounts\\OrganisationReferences "
            . "(merging two organisations would leave their rows on the removed one) nor in this test's allow-list. Register them, or allow-list them with the reason they are not:\n  "
            . implode("\n  ", $missing));
    }

    public function test_the_allow_list_has_no_stale_or_contradictory_entries(): void
    {
        $candidates = $this->candidates(self::migrations());
        $registered = array_map(fn ($r) => "{$r['table']}.{$r['column']}", OrganisationReferences::columns());

        foreach (self::ALLOWED as $key => $reason) {
            $this->assertArrayHasKey($key, $candidates, "allow-list entry {$key} matches no column in the migrations: remove it");
            $this->assertNotContains($key, $registered, "{$key} is both registered and allow-listed");
            $this->assertNotSame('', trim($reason), "{$key} needs a one-line reason");
        }
    }

    public function test_every_registered_column_is_really_defined_in_the_migrations(): void
    {
        $defined = $this->definitionsByKey(self::migrations());

        foreach (OrganisationReferences::columns() as $r) {
            $this->assertArrayHasKey("{$r['table']}.{$r['column']}", $defined, "{$r['table']}.{$r['column']} is in the registry but no migration defines it (a typo would make the merge skip it silently)");
        }
        foreach (OrganisationReferences::polymorphic() as $p) {
            $this->assertNotSame([], $p['values'], "{$p['table']}.{$p['type']} lists no organisation value");
            foreach ($p['unique'] as $column) {
                $this->assertArrayHasKey("{$p['table']}.{$column}", $defined, "{$p['table']}.{$column} (part of its unique key) is not defined in the migrations");
            }
        }
    }

    public function test_the_scan_names_the_table_and_column_when_a_registry_entry_is_missing(): void
    {
        $registry = array_values(array_filter(OrganisationReferences::columns(), fn ($r) => ! ($r['table'] === 'crm_quotes' && $r['column'] === 'organization_id')));

        $missing = $this->unregistered(self::migrations(), $registry, self::ALLOWED);

        $this->assertSame(1, count(array_filter($missing, fn ($line) => str_starts_with($line, 'crm_quotes.organization_id'))), 'dropping crm_quotes.organization_id from the registry must be reported by table and column');
    }

    public function test_the_scan_catches_a_new_migration_with_an_unregistered_organisation_column(): void
    {
        $dir = sys_get_temp_dir() . '/org-scan-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/2027_01_01_000000_create_crm_widgets_table.php', <<<'PHP'
<?php
return new class extends Illuminate\Database\Migrations\Migration {
    public function up(): void
    {
        Schema::create('crm_widgets', function ($table) {
            $table->id();
            $table->unsignedBigInteger('organisation_id')->nullable();
            $table->string('linked_type', 20);
            $table->unsignedBigInteger('linked_id');
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('sub_institute_id');
        });
    }
};
PHP);
        file_put_contents($dir . '/2027_01_02_000000_add_second_link.php', <<<'PHP'
<?php
return new class extends Illuminate\Database\Migrations\Migration {
    public function up(): void
    {
        DB::statement('ALTER TABLE `crm_gadgets` ADD COLUMN `source_organization_id` BIGINT UNSIGNED NULL');
        foreach (['crm_a', 'crm_b'] as $table) {
            Schema::table($table, fn ($t) => $t->unsignedBigInteger($column = 'looped_organization_id'));
        }
    }
};
PHP);

        try {
            $missing = $this->unregistered($dir, OrganisationReferences::columns(), self::ALLOWED);
        } finally {
            array_map('unlink', glob($dir . '/*.php') ?: []);
            rmdir($dir);
        }

        $text = implode("\n", $missing);
        $this->assertStringContainsString('crm_widgets.organisation_id', $text, 'a new direct column is named by table and column');
        $this->assertStringContainsString('crm_widgets.linked_type', $text, 'a new polymorphic type column is named too');
        $this->assertStringContainsString('crm_widgets.account_id', $text);
        $this->assertStringContainsString('crm_gadgets.source_organization_id', $text, 'raw ALTER TABLE ... ADD COLUMN is read as well');
        $this->assertStringContainsString('looped_organization_id', $text, 'a column the scan cannot attach to a table is still reported');
        $this->assertStringNotContainsString('crm_widgets.linked_id', $text, 'plain id columns are not candidates on their own');
    }
}
