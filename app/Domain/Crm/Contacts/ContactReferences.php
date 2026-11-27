<?php

namespace App\Domain\Crm\Contacts;

/**
 * Every place a CRM contact id (crm_contacts.id) is stored, in ONE reviewed list (S18, 25 Nov 2026, contract Docs/agentic-crm/slices/S18-CONTACTS-INTO-ORGANIZATIONS-CONTRACT.md
 * section 2). It is the contact twin of App\Domain\OrgAccounts\OrganisationReferences and works the same way: merging two contacts must move every one of these from the removed
 * contact to the survivor, ContactMerger does exactly that by walking this list, and tests/Unit/ContactReferencesScanTest.php reads every migration and fails when a column that looks
 * like a contact reference is neither listed here nor in notContactReferences() with a reason. A new table that points at a contact is therefore a one-line change here, and forgetting it is a
 * red test rather than a ghost record on a soft-deleted duplicate.
 *
 * Four kinds of reference:
 *
 *   direct       a plain column holding the contact id (crm_quotes.contact_id). `self_reference` marks crm_contacts.reports_to_id, the tree on the contacts table itself.
 *   junction     a link table whose rows are (parent id, contact id) with a unique key on the pair (crm_opportunity_contacts). It has no tenant column, so it is
 *                scoped through its parent row. When the survivor is already on the same parent, the removed contact's row would collide, so it is deleted and counted instead of moved.
 *   polymorphic  an id column plus a type column that says what the id is (crm_email_links.linked_id + linked_type). The value that means "contact" differs by table because each writer
 *                chose its own case; the values are the same ones OrganisationReferences documents, with "contact" in place of "organization":
 *                  'CONTACT'      crm_email_links, document_library_links, task_management_calendar_events
 *                  'contact'      crm_campaign_targets, crm_outreach_approvals, crm_sms_log, crm_knowledge_sources, crm_entity_relationships, crm_consent_preferences, g2g_entity_facts
 *                  'crm_contacts' g2g_custom_field_values.record_table carries the TABLE NAME of the record
 *
 * Tenant scope: a table with sub_institute_id is only ever touched where that column equals the caller's tenant. crm_campaign_targets and the two junctions have none and are scoped
 * through their parent (`scope.via`).
 *
 * `unique` lists the OTHER columns of a unique key that includes (type, id): if the survivor already has a row with the same values, the removed contact's row would collide, so it is
 * deleted and counted instead of moved. crm_entity_relationships has no unique key in its migration but two identical edges are the same fact twice, so it is deduplicated the same way;
 * `pair` names the other end of such an edge so an edge that linked the two merged contacts (which would become a contact related to itself) is dropped.
 *
 * @phpstan-type Scope string|array{via: string, parent: string, parent_key: string, parent_tenant: string}
 */
final class ContactReferences
{
    private const TENANT = 'sub_institute_id';

    /** The value every writer of a contact's type column uses, by case convention (see the class comment). */
    public const UPPER = ['CONTACT'];

    public const LOWER = ['contact'];

    /**
     * @return list<array{table: string, column: string, scope: string, self_reference?: bool}>
     */
    public static function direct(): array
    {
        $rows = [];
        foreach ([
            ['crm_quotes', 'contact_id'],
            ['crm_sales_orders', 'contact_id'],
            ['crm_invoices', 'contact_id'],
            ['crm_service_contracts', 'contact_id'],
            ['crm_tickets', 'contact_id'],
            ['crm_leads', 'converted_contact_id'],
            ['crm_contact_comments', 'contact_id'],
        ] as [$table, $column]) {
            $rows[] = ['table' => $table, 'column' => $column, 'scope' => self::TENANT];
        }

        // The reporting tree: people who reported to the removed contact now report to the survivor. The survivor itself may have reported to the removed one, which must be cleared first
        // or the blanket move would make it report to itself.
        $rows[] = ['table' => 'crm_contacts', 'column' => 'reports_to_id', 'scope' => self::TENANT, 'self_reference' => true];

        return $rows;
    }

    /**
     * @return list<array{table: string, column: string, parent: string, scope: array{via: string, parent: string, parent_key: string, parent_tenant: string}}>
     */
    public static function junctions(): array
    {
        return [
            ['table' => 'crm_opportunity_contacts', 'column' => 'contact_id', 'parent' => 'opportunity_id',
                'scope' => ['via' => 'opportunity_id', 'parent' => 'crm_opportunities', 'parent_key' => 'id', 'parent_tenant' => self::TENANT]],
        ];
    }

    /**
     * @return list<array{table: string, id: string, type: string, values: list<string>, unique: list<string>, scope: string|array{via: string, parent: string, parent_key: string, parent_tenant: string}, pair?: array{id: string, type: string}}>
     */
    public static function polymorphic(): array
    {
        return [
            ['table' => 'crm_campaign_targets', 'id' => 'target_id', 'type' => 'target_type', 'values' => self::LOWER, 'unique' => ['campaign_id'],
                'scope' => ['via' => 'campaign_id', 'parent' => 'crm_campaigns', 'parent_key' => 'id', 'parent_tenant' => self::TENANT]],
            ['table' => 'crm_email_links', 'id' => 'linked_id', 'type' => 'linked_type', 'values' => self::UPPER, 'unique' => ['email_id'], 'scope' => self::TENANT],
            ['table' => 'document_library_links', 'id' => 'linked_id', 'type' => 'linked_type', 'values' => self::UPPER, 'unique' => ['document_id'], 'scope' => self::TENANT],
            ['table' => 'task_management_calendar_events', 'id' => 'linked_id', 'type' => 'linked_type', 'values' => self::UPPER, 'unique' => [], 'scope' => self::TENANT],
            ['table' => 'crm_sms_log', 'id' => 'related_id', 'type' => 'related_type', 'values' => self::LOWER, 'unique' => [], 'scope' => self::TENANT],
            ['table' => 'crm_outreach_approvals', 'id' => 'target_id', 'type' => 'target_type', 'values' => self::LOWER, 'unique' => [], 'scope' => self::TENANT],
            ['table' => 'crm_knowledge_sources', 'id' => 'entity_id', 'type' => 'entity_type', 'values' => self::LOWER, 'unique' => [], 'scope' => self::TENANT],
            ['table' => 'crm_consent_preferences', 'id' => 'entity_id', 'type' => 'entity_type', 'values' => self::LOWER, 'unique' => ['channel'], 'scope' => self::TENANT],
            // The facts ledger: unique on (tenant, entity_type, entity_id, field, value_hash). FactRepoint moves a fact with its evidence first (the contact merge calls it before this list is
            // walked); what is left here is a safety net that drops a repeat the survivor already holds.
            ['table' => 'g2g_entity_facts', 'id' => 'entity_id', 'type' => 'entity_type', 'values' => self::LOWER, 'unique' => ['field', 'value_hash'], 'scope' => self::TENANT],
            // An edge has two ends and either may be the contact; each end is its own entry.
            ['table' => 'crm_entity_relationships', 'id' => 'from_id', 'type' => 'from_type', 'values' => self::LOWER, 'unique' => ['to_type', 'to_id', 'relationship_type'],
                'scope' => self::TENANT, 'pair' => ['id' => 'to_id', 'type' => 'to_type']],
            ['table' => 'crm_entity_relationships', 'id' => 'to_id', 'type' => 'to_type', 'values' => self::LOWER, 'unique' => ['from_type', 'from_id', 'relationship_type'],
                'scope' => self::TENANT, 'pair' => ['id' => 'from_id', 'type' => 'from_type']],
            // The "type" here is the table name of the record the answer belongs to. Unique on (record_table, record_id, field_id).
            ['table' => 'g2g_custom_field_values', 'id' => 'record_id', 'type' => 'record_table', 'values' => ['crm_contacts'], 'unique' => ['field_id'], 'scope' => self::TENANT],
        ];
    }

    /**
     * Every (table, column) this registry accounts for: the id columns and the type columns. The migration scan compares what it finds in the schema with this.
     *
     * @return list<array{table: string, column: string}>
     */
    public static function columns(): array
    {
        $columns = [];
        foreach (self::direct() as $d) {
            $columns[] = ['table' => $d['table'], 'column' => $d['column']];
        }
        foreach (self::junctions() as $j) {
            $columns[] = ['table' => $j['table'], 'column' => $j['column']];
        }
        foreach (self::polymorphic() as $p) {
            $columns[] = ['table' => $p['table'], 'column' => $p['id']];
            $columns[] = ['table' => $p['table'], 'column' => $p['type']];
        }

        return $columns;
    }

    /** A column named like a contact id: contact_id, crm_contact_id, converted_contact_id, ... and the tree column on the contacts table itself. */
    public const DIRECT_PATTERN = '/^(?:[a-z0-9_]*contact_id|reports_to_id)$/';

    /** A polymorphic type column. Whether its values include a contact is not visible from the schema, so every one must be registered or allow-listed with the reason it cannot hold one. */
    public const TYPE_PATTERN = '/^(?:(?:linked|entity|related|target|from|to)_type|record_table)$/';

    public static function looksLikeReference(string $column): bool
    {
        return preg_match(self::DIRECT_PATTERN, $column) === 1 || preg_match(self::TYPE_PATTERN, $column) === 1;
    }

    /**
     * The (table, column) pairs of a schema that look like a contact reference and are neither registered here nor allow-listed in notContactReferences(): "table.column", sorted.
     * Fed with the migrations' column definitions by the scan test and with information_schema by `php artisan crm:contacts-audit`.
     *
     * @param  iterable<array{table: string, column: string}>  $found
     * @return list<string>
     */
    public static function unregistered(iterable $found): array
    {
        $known = self::notContactReferences();
        foreach (self::columns() as $c) {
            $known["{$c['table']}.{$c['column']}"] = 'registry';
        }

        $missing = [];
        foreach ($found as $f) {
            $key = "{$f['table']}.{$f['column']}";
            if (self::looksLikeReference($f['column']) && ! isset($known[$key])) {
                $missing[$key] = $key;
            }
        }
        sort($missing);

        return array_values($missing);
    }

    /**
     * Columns whose NAME looks like a contact reference (or a polymorphic type column) but which are not a reference to crm_contacts. One line each, saying what the column points at
     * instead. The scan test (migrations) and `php artisan crm:contacts-audit` (the real schema) both read this list.
     *
     * @return array<string, string> "table.column" => reason
     */
    public static function notContactReferences(): array
    {
        $gtmContact = 'points at gtm_contacts, the GTM contact, not a CRM contact';
        $gone = 'the table was dropped with the Purchase Orders and Vendors modules (2026_12_27_090300); its create migration stays as history and the drop migration recreates it empty in down(), so no row is left to move';

        return [
            'crm_purchase_orders.contact_id' => $gone,
            'crm_vendor_contacts.contact_id' => $gone,
            'gtm_activities.contact_id' => $gtmContact,
            'gtm_outreach_messages.contact_id' => $gtmContact,
            'gtm_campaign_members.contact_id' => $gtmContact . ' (0 = the account as a whole)',
            'gtm_inbound_events.contact_id' => $gtmContact,
            'g2g_audit_log.entity_type' => 'append-only audit trail of what happened to the removed record; rewriting it would falsify the history',
            'g2g_event.entity_type' => 'append-only event store (replayed by id); the removed contact\'s events stay with it',
            'gtm_workflow_runs.entity_type' => 'copy of the triggering event\'s entity type on a finished workflow run: history of the run, not a live link',
            'ai_audit_logs.related_type' => 'AI audit trail; holds AI table names (ai_conversations, ai_generated_reports), never a CRM record',
            'ai_usage_events.related_type' => 'AI usage meter; holds the AI/LMS subject of a call (quiz_marking, ai_conversations), never a CRM record',
            'g2g_agent_proposals.entity_type' => 'with entity_id, an informational copy of the CRM record an agent proposal was made for; applying a proposal re-derives its target from the subject identity and never finds a record by this pair, and a proposal is history of what the agent proposed, which a merge does not rewrite',
        ];
    }
}
