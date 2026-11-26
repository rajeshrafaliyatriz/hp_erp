<?php

namespace Tests\Unit;

use App\Domain\Crm\Contacts\ContactReferences;
use PHPUnit\Framework\TestCase;

/**
 * The guard behind the contact merge (S18): reads every migration, finds every column that looks like a reference to a CRM contact (contact_id, crm_contact_id, converted_contact_id,
 * reports_to_id, and every polymorphic type column), and fails when one is neither in ContactReferences nor in its notContactReferences() allow-list with a reason. So a table added later
 * that points at a contact cannot be forgotten: the merge would leave its rows on the soft-deleted duplicate, and this test is what stops that reaching a release. The GTM tables'
 * contact_id points at gtm_contacts, not at crm_contacts: they are on the allow-list, with that reason. (`php artisan crm:contacts-audit` runs the same check against the real schema of a host,
 * through information_schema.)
 *
 * Plain PHPUnit, no database: it only reads files.
 */
class ContactReferencesScanTest extends TestCase
{
    private static function migrations(): string
    {
        return dirname(__DIR__, 2) . '/database/migrations';
    }

    /**
     * Every column definition in the migration files of a directory: a Blueprint call (->unsignedBigInteger('x'), ->string('x'), ...) or a raw `x` BIGINT/VARCHAR/... inside CREATE TABLE /
     * ALTER TABLE; its table is the nearest Schema::create/table or CREATE/ALTER TABLE above it in the file.
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

    /** @return list<array{table: string, column: string}> */
    private function pairs(string $dir): array
    {
        return array_map(fn ($d) => ['table' => $d['table'], 'column' => $d['column']], $this->definitions($dir));
    }

    public function test_every_contact_reference_in_the_migrations_is_registered_or_allow_listed(): void
    {
        $missing = ContactReferences::unregistered($this->pairs(self::migrations()));

        $this->assertSame([], $missing, "These columns look like references to a CRM contact but are not in App\\Domain\\Crm\\Contacts\\ContactReferences "
            . "(merging two contacts would leave their rows on the removed one) nor in its notContactReferences() allow-list. Register them, or allow-list them with the reason they are not:\n  "
            . implode("\n  ", $missing));
    }

    public function test_the_allow_list_has_no_stale_or_contradictory_entries(): void
    {
        $defined = [];
        foreach ($this->pairs(self::migrations()) as $p) {
            $defined["{$p['table']}.{$p['column']}"] = true;
        }
        $registered = array_map(fn ($r) => "{$r['table']}.{$r['column']}", ContactReferences::columns());

        foreach (ContactReferences::notContactReferences() as $key => $reason) {
            $this->assertArrayHasKey($key, $defined, "allow-list entry {$key} matches no column in the migrations: remove it");
            $this->assertTrue(ContactReferences::looksLikeReference(explode('.', $key)[1]), "{$key} would never be flagged: it does not need an allow-list entry");
            $this->assertNotContains($key, $registered, "{$key} is both registered and allow-listed");
            $this->assertNotSame('', trim($reason), "{$key} needs a one-line reason");
        }
        foreach (['gtm_activities.contact_id', 'gtm_outreach_messages.contact_id', 'gtm_campaign_members.contact_id', 'gtm_inbound_events.contact_id'] as $gtm) {
            $this->assertStringContainsString('gtm_contacts', ContactReferences::notContactReferences()[$gtm] ?? '', "{$gtm} points at the GTM contact table and says so");
        }
    }

    public function test_every_registered_column_is_really_defined_in_the_migrations(): void
    {
        $defined = [];
        foreach ($this->pairs(self::migrations()) as $p) {
            $defined["{$p['table']}.{$p['column']}"] = true;
        }

        foreach (ContactReferences::columns() as $r) {
            $this->assertArrayHasKey("{$r['table']}.{$r['column']}", $defined, "{$r['table']}.{$r['column']} is in the registry but no migration defines it (a typo would make the merge skip it silently)");
        }
        foreach (ContactReferences::polymorphic() as $p) {
            $this->assertNotSame([], $p['values'], "{$p['table']}.{$p['type']} lists no contact value");
            foreach ($p['unique'] as $column) {
                $this->assertArrayHasKey("{$p['table']}.{$column}", $defined, "{$p['table']}.{$column} (part of its unique key) is not defined in the migrations");
            }
        }
        foreach (ContactReferences::junctions() as $j) {
            $this->assertArrayHasKey("{$j['table']}.{$j['parent']}", $defined, "{$j['table']}.{$j['parent']} (the other half of its unique key) is not defined in the migrations");
        }
    }

    public function test_the_registry_still_lists_every_reference_known_when_it_was_written(): void
    {
        $registered = array_map(fn ($r) => "{$r['table']}.{$r['column']}", ContactReferences::columns());
        // Written by hand from the contract (S18 section 2) and the migrations: the registry is walked by the merge, so a reference lost from it is a ghost record, and the list above would still be green.
        foreach (['crm_quotes.contact_id', 'crm_sales_orders.contact_id', 'crm_purchase_orders.contact_id', 'crm_invoices.contact_id', 'crm_service_contracts.contact_id', 'crm_tickets.contact_id',
            'crm_leads.converted_contact_id', 'crm_opportunity_contacts.contact_id', 'crm_vendor_contacts.contact_id', 'crm_contact_comments.contact_id', 'crm_email_links.linked_id',
            'document_library_links.linked_id', 'task_management_calendar_events.linked_id', 'crm_campaign_targets.target_id', 'crm_outreach_approvals.target_id', 'crm_sms_log.related_id',
            'crm_consent_preferences.entity_id', 'crm_entity_relationships.from_id', 'crm_entity_relationships.to_id', 'crm_knowledge_sources.entity_id', 'g2g_entity_facts.entity_id',
            'g2g_custom_field_values.record_id', 'crm_contacts.reports_to_id'] as $known) {
            $this->assertContains($known, $registered, "{$known} must stay in ContactReferences");
        }

        $values = [];
        foreach (ContactReferences::polymorphic() as $p) {
            foreach ($p['values'] as $v) {
                $values["{$p['table']}.{$p['id']}"][] = $v;
            }
        }
        // the exact value each table stores for a contact: upper case or lower case, read from its writer
        $this->assertSame(['CONTACT'], $values['crm_email_links.linked_id']);
        $this->assertSame(['CONTACT'], $values['document_library_links.linked_id']);
        $this->assertSame(['CONTACT'], $values['task_management_calendar_events.linked_id']);
        $this->assertSame(['contact'], $values['crm_campaign_targets.target_id']);
        $this->assertSame(['contact'], $values['crm_sms_log.related_id']);
        $this->assertSame(['contact'], $values['g2g_entity_facts.entity_id']);
        $this->assertSame(['crm_contacts'], $values['g2g_custom_field_values.record_id']);
    }

    public function test_the_scan_names_the_table_and_column_when_a_registry_entry_is_missing_or_a_new_column_appears(): void
    {
        // a column the registry does not list is reported by table and column
        $this->assertSame(['crm_widgets.contact_id'], ContactReferences::unregistered([['table' => 'crm_widgets', 'column' => 'contact_id']]));
        $this->assertSame(['crm_widgets.linked_type', 'crm_widgets.primary_contact_id'], ContactReferences::unregistered([
            ['table' => 'crm_widgets', 'column' => 'primary_contact_id'], ['table' => 'crm_widgets', 'column' => 'linked_type'], ['table' => 'crm_widgets', 'column' => 'linked_id'],
            ['table' => 'crm_quotes', 'column' => 'contact_id'],       // registered
            ['table' => 'gtm_activities', 'column' => 'contact_id'],   // allow-listed
        ]));

        // dropping an entry from the registry would be caught: the same column is then "unregistered"
        $withoutQuotes = array_values(array_filter(ContactReferences::columns(), fn ($r) => ! ($r['table'] === 'crm_quotes' && $r['column'] === 'contact_id')));
        $this->assertNotContains('crm_quotes.contact_id', array_map(fn ($r) => "{$r['table']}.{$r['column']}", $withoutQuotes));
    }

    public function test_the_scan_catches_a_new_migration_with_an_unregistered_contact_column(): void
    {
        $dir = sys_get_temp_dir() . '/contact-scan-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/2027_01_01_000000_create_crm_widgets_table.php', <<<'PHP'
<?php
return new class extends Illuminate\Database\Migrations\Migration {
    public function up(): void
    {
        Schema::create('crm_widgets', function ($table) {
            $table->id();
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->unsignedBigInteger('crm_contact_id')->nullable();
            $table->string('linked_type', 20);
            $table->unsignedBigInteger('linked_id');
        });
        DB::statement('ALTER TABLE `crm_gadgets` ADD COLUMN `signer_contact_id` BIGINT UNSIGNED NULL');
    }
};
PHP);
        try {
            $text = implode("\n", ContactReferences::unregistered($this->pairs($dir)));
        } finally {
            array_map('unlink', glob($dir . '/*.php') ?: []);
            rmdir($dir);
        }

        $this->assertStringContainsString('crm_widgets.contact_id', $text);
        $this->assertStringContainsString('crm_widgets.crm_contact_id', $text);
        $this->assertStringContainsString('crm_widgets.linked_type', $text, 'a new polymorphic type column is named too');
        $this->assertStringContainsString('crm_gadgets.signer_contact_id', $text, 'raw ALTER TABLE ... ADD COLUMN is read as well');
        $this->assertStringNotContainsString('crm_widgets.linked_id', $text, 'plain id columns are not candidates on their own');
    }
}
