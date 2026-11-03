<?php

namespace App\Domain\OrgAccounts;

/**
 * Every place a CRM organisation id (crm_organizations.id) is stored, in ONE reviewed list (slice 3, decision D6,
 * Docs/agentic-crm/slices/S03-organisation-360.md). Merging two organisations must move every one of these from the removed organisation to the survivor,
 * and OrganisationMerger does exactly that by walking this list; tests/Unit/OrganisationReferencesScanTest.php reads every migration and fails when a column
 * that looks like an organisation reference is neither listed here nor in that test's allow-list with a reason. A new table that points at an organisation is
 * therefore a one-line change here, and forgetting it is a red test rather than a ghost record.
 *
 * Three kinds of reference:
 *
 *   direct       a plain column holding the organisation id (crm_quotes.organization_id).
 *   polymorphic  an id column plus a type column that says what the id is (crm_email_links.linked_id + linked_type). The values that mean "organisation"
 *                differ by table because each writer chose its own case, and the stored value was read from the writer, not guessed:
 *                  'ORGANIZATION'   crm_email_links (strtoupper in CrmEmailQueue), document_library_links (CrmOrganizationController::documentsLinkedType),
 *                                   task_management_calendar_events (CalendarController validates the upper-case list)
 *                  'organization'   crm_campaign_targets, crm_outreach_approvals (VALID_TARGET_TYPES), crm_sms_log (RELATED_TABLES keys),
 *                                   crm_knowledge_sources (validated in:organization,...), crm_entity_relationships and crm_consent_preferences (free text, the
 *                                   knowledge graph and the migrations' own comments use lower case)
 *                  'crm_organizations'   g2g_custom_field_values.record_table carries the TABLE NAME of the record, not a type word
 *   identity     g2g_org_accounts.crm_organization_id, the Organisation 360 pointer, which has a unique key and so needs its own handling.
 *
 * Tenant scope: a table with sub_institute_id is only ever touched where that column equals the caller's tenant. crm_campaign_targets has no tenant column, so
 * it is scoped through its parent campaign (`scope.via`).
 *
 * `unique` lists the OTHER columns of a unique key that includes (type, id): if the survivor already has a row with the same values, the removed
 * organisation's row would collide, so it is deleted and counted instead of moved. crm_entity_relationships has no unique key in its migration, but two
 * identical edges for one organisation are the same fact twice, so it is deduplicated the same way; `pair` names the other end of such an edge so an edge that
 * linked the two merged organisations (which would become an organisation related to itself) is dropped.
 *
 * @phpstan-type Scope string|array{via: string, parent: string, parent_key: string, parent_tenant: string}
 */
final class OrganisationReferences
{
    private const TENANT = 'sub_institute_id';

    /**
     * @return list<array{table: string, column: string, scope: string, self_reference?: bool}>
     */
    public static function direct(): array
    {
        $rows = [];
        foreach ([
            ['crm_contacts', 'organization_id'],
            ['crm_leads', 'converted_organization_id'],
            ['crm_opportunities', 'organization_id'],
            ['crm_quotes', 'organization_id'],
            ['crm_sales_orders', 'organization_id'],
            ['crm_purchase_orders', 'organization_id'],
            ['crm_invoices', 'organization_id'],
            ['crm_service_contracts', 'organization_id'],
            ['crm_assets', 'organization_id'],
            ['crm_tickets', 'organization_id'],
            ['crm_organization_comments', 'organization_id'],
            ['gtm_accounts', 'crm_organization_id'],
            ['task_management_projects', 'crm_organization_id'],
            // The signal inbox's account link: an FK to crm_organizations, named "account" because it is Accounts in the UI.
            ['crm_signal_inbox', 'linked_account_id'],
        ] as [$table, $column]) {
            $rows[] = ['table' => $table, 'column' => $column, 'scope' => self::TENANT];
        }

        // The tree: children of the removed organisation now report to the survivor. The survivor itself may have been a child of the removed one, which must be
        // cleared first or the blanket move would parent it to itself.
        $rows[] = ['table' => 'crm_organizations', 'column' => 'parent_id', 'scope' => self::TENANT, 'self_reference' => true];

        return $rows;
    }

    /**
     * @return list<array{table: string, id: string, type: string, values: list<string>, unique: list<string>, scope: string|array{via: string, parent: string, parent_key: string, parent_tenant: string}, pair?: array{id: string, type: string}}>
     */
    public static function polymorphic(): array
    {
        $upper = ['ORGANIZATION'];
        $lower = ['organization'];

        return [
            // No tenant column of its own: a campaign belongs to one tenant, so the target is scoped through it.
            ['table' => 'crm_campaign_targets', 'id' => 'target_id', 'type' => 'target_type', 'values' => $lower, 'unique' => ['campaign_id'],
                'scope' => ['via' => 'campaign_id', 'parent' => 'crm_campaigns', 'parent_key' => 'id', 'parent_tenant' => self::TENANT]],
            ['table' => 'crm_email_links', 'id' => 'linked_id', 'type' => 'linked_type', 'values' => $upper, 'unique' => ['email_id'], 'scope' => self::TENANT],
            ['table' => 'document_library_links', 'id' => 'linked_id', 'type' => 'linked_type', 'values' => $upper, 'unique' => ['document_id'], 'scope' => self::TENANT],
            ['table' => 'task_management_calendar_events', 'id' => 'linked_id', 'type' => 'linked_type', 'values' => $upper, 'unique' => [], 'scope' => self::TENANT],
            ['table' => 'crm_sms_log', 'id' => 'related_id', 'type' => 'related_type', 'values' => $lower, 'unique' => [], 'scope' => self::TENANT],
            ['table' => 'crm_outreach_approvals', 'id' => 'target_id', 'type' => 'target_type', 'values' => $lower, 'unique' => [], 'scope' => self::TENANT],
            ['table' => 'crm_knowledge_sources', 'id' => 'entity_id', 'type' => 'entity_type', 'values' => $lower, 'unique' => [], 'scope' => self::TENANT],
            // The migration documents lead|contact and nothing writes an organisation today; the column is free text, so it is listed to keep a future writer covered.
            ['table' => 'crm_consent_preferences', 'id' => 'entity_id', 'type' => 'entity_type', 'values' => $lower, 'unique' => ['channel'], 'scope' => self::TENANT],
            // An edge has two ends and either may be the organisation; each end is its own entry.
            ['table' => 'crm_entity_relationships', 'id' => 'from_id', 'type' => 'from_type', 'values' => $lower, 'unique' => ['to_type', 'to_id', 'relationship_type'],
                'scope' => self::TENANT, 'pair' => ['id' => 'to_id', 'type' => 'to_type']],
            ['table' => 'crm_entity_relationships', 'id' => 'to_id', 'type' => 'to_type', 'values' => $lower, 'unique' => ['from_type', 'from_id', 'relationship_type'],
                'scope' => self::TENANT, 'pair' => ['id' => 'from_id', 'type' => 'from_type']],
            // The "type" here is the table name of the record the answer belongs to. Unique on (record_table, record_id, field_id).
            ['table' => 'g2g_custom_field_values', 'id' => 'record_id', 'type' => 'record_table', 'values' => ['crm_organizations'], 'unique' => ['field_id'], 'scope' => self::TENANT],
        ];
    }

    /**
     * The Organisation 360 identity row that points at a CRM organisation. One identity per tenant and organisation (unique key), so it cannot simply be updated.
     *
     * @return array{table: string, column: string, scope: string}
     */
    public static function identity(): array
    {
        return ['table' => 'g2g_org_accounts', 'column' => 'crm_organization_id', 'scope' => self::TENANT];
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
        foreach (self::polymorphic() as $p) {
            $columns[] = ['table' => $p['table'], 'column' => $p['id']];
            $columns[] = ['table' => $p['table'], 'column' => $p['type']];
        }
        $identity = self::identity();
        $columns[] = ['table' => $identity['table'], 'column' => $identity['column']];

        return $columns;
    }
}
