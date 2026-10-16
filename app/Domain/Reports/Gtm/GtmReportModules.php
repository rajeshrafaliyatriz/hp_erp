<?php

namespace App\Domain\Reports\Gtm;

use App\Domain\Reports\ReportFieldDefinition;
use App\Domain\Reports\ReportJoinDefinition;
use App\Domain\Reports\ReportModuleDefinition;

/**
 * Sales & Marketing unification, Phase 6: GTM's reportable modules, registered
 * here and nowhere else - ReportModuleRegistry only merges this array in, same
 * convention as CrmReportModules/TaskManagementReportModules.
 *
 * Covers the four GTM tables the federation plan (Docs/sales-marketing-merge/
 * DECISIONS.md) keeps genuinely separate from CRM's own tables: Accounts,
 * Deals, Campaigns, Renewals. Every one carries its own `sub_institute_id`
 * (confirmed directly against the live schema, not assumed) - the hard
 * requirement CrmReportModules' own docblock states for anything registered
 * as a standalone PRIMARY module here.
 *
 * `stage`/`kind`/`status`/`forecast_category` etc. are plain varchar fields,
 * not `picklist()` - GTM has no `gtm_picklist_values` table the way CRM has
 * `crm_picklist_values`; its fixed value sets (GtmDeal::STAGES, AccountStage,
 * ...) live in PHP constants, not an admin-editable DB table, so there is no
 * module/field pair for `picklist()`'s optional lookup to resolve against.
 * Still groupable: a fixed-set string column groups and filters correctly
 * either way, it just isn't admin-renameable from a Rights-style screen.
 *
 * DELIBERATELY NOT REGISTERED: `gtm_segments` (a saved filter, not a
 * reportable fact table), `gtm_activities`/`gtm_outreach_messages`/
 * `gtm_playbooks`/`gtm_workflows` (left for a later pass if ever needed -
 * out of this phase's scope, which only covers the four tables the plan
 * named), `gtm_contacts` (no sub_institute_id-scoped reporting value beyond
 * what Accounts already gives — out of scope here too, same reasoning).
 */
final class GtmReportModules
{
    /** @return ReportModuleDefinition[] */
    public static function definitions(): array
    {
        return [
            self::accounts(),
            self::deals(),
            self::campaigns(),
            self::renewals(),
        ];
    }

    /** Every module's own owner join - reused verbatim since alwaysJoins from different modules never coexist in one query. */
    private static function ownerJoin(): array
    {
        return ['table' => 'tbluser', 'alias' => 'u_owner', 'localColumn' => 'owner_user_id', 'foreignColumn' => 'id'];
    }

    private static function ownerField(): ReportFieldDefinition
    {
        return ReportFieldDefinition::userRef('owner_user_id', 'Owner', "TRIM(CONCAT_WS(' ', u_owner.first_name, u_owner.middle_name, u_owner.last_name))");
    }

    private static function accounts(): ReportModuleDefinition
    {
        return new ReportModuleDefinition(
            key: 'gtm.accounts',
            label: 'GTM Accounts',
            table: 'gtm_accounts',
            alias: 'gacc',
            tenantColumn: 'sub_institute_id',
            softDeleteColumn: 'deleted_at',
            primaryKey: 'id',
            fields: [
                ReportFieldDefinition::varchar('name', 'Account', 'gacc.name', defaultColumn: true),
                ReportFieldDefinition::varchar('domain', 'Domain', 'gacc.domain', groupable: false),
                ReportFieldDefinition::varchar('industry', 'Industry', 'gacc.industry'),
                ReportFieldDefinition::varchar('stage', 'Stage', 'gacc.stage', defaultColumn: true),
                ReportFieldDefinition::varchar('source', 'Source', 'gacc.source'),
                ReportFieldDefinition::numeric('icp_fit_score', 'ICP Fit Score', 'gacc.icp_fit_score'),
                self::ownerField(),
            ],
            alwaysJoins: [self::ownerJoin()],
        );
    }

    private static function deals(): ReportModuleDefinition
    {
        return new ReportModuleDefinition(
            key: 'gtm.deals',
            label: 'GTM Deals',
            table: 'gtm_deals',
            alias: 'gdl',
            tenantColumn: 'sub_institute_id',
            softDeleteColumn: 'deleted_at',
            primaryKey: 'id',
            fields: [
                ReportFieldDefinition::varchar('name', 'Deal', 'gdl.name', defaultColumn: true),
                ReportFieldDefinition::varchar('stage', 'Stage', 'gdl.stage', defaultColumn: true),
                ReportFieldDefinition::numeric('amount', 'Amount', 'gdl.amount', defaultColumn: true),
                ReportFieldDefinition::varchar('currency', 'Currency', 'gdl.currency', groupable: false),
                ReportFieldDefinition::date('expected_close_date', 'Expected Close', 'gdl.expected_close_date'),
                ReportFieldDefinition::date('closed_at', 'Closed On', 'gdl.closed_at'),
                ReportFieldDefinition::varchar('forecast_category', 'Forecast Category', 'gdl.forecast_category'),
                self::ownerField(),
            ],
            joins: [
                'gtm.accounts' => ReportJoinDefinition::direct('gtm.accounts', 'account_id', 'id'),
            ],
            alwaysJoins: [self::ownerJoin()],
        );
    }

    private static function campaigns(): ReportModuleDefinition
    {
        return new ReportModuleDefinition(
            key: 'gtm.campaigns',
            label: 'GTM Campaigns',
            table: 'gtm_campaigns',
            alias: 'gcam',
            tenantColumn: 'sub_institute_id',
            softDeleteColumn: 'deleted_at',
            primaryKey: 'id',
            fields: [
                ReportFieldDefinition::varchar('name', 'Campaign', 'gcam.name', defaultColumn: true),
                ReportFieldDefinition::varchar('kind', 'Kind', 'gcam.kind', defaultColumn: true),
                ReportFieldDefinition::varchar('status', 'Status', 'gcam.status', defaultColumn: true),
                ReportFieldDefinition::varchar('industry_pack_slug', 'Industry Pack', 'gcam.industry_pack_slug'),
                ReportFieldDefinition::date('starts_on', 'Starts On', 'gcam.starts_on'),
                ReportFieldDefinition::date('ends_on', 'Ends On', 'gcam.ends_on'),
                self::ownerField(),
            ],
            alwaysJoins: [self::ownerJoin()],
        );
    }

    private static function renewals(): ReportModuleDefinition
    {
        return new ReportModuleDefinition(
            key: 'gtm.renewals',
            label: 'GTM Renewals',
            table: 'gtm_renewals',
            alias: 'gren',
            tenantColumn: 'sub_institute_id',
            softDeleteColumn: 'deleted_at',
            primaryKey: 'id',
            fields: [
                ReportFieldDefinition::varchar('name', 'Renewal', 'gren.name', defaultColumn: true),
                ReportFieldDefinition::varchar('status', 'Status', 'gren.status', defaultColumn: true),
                ReportFieldDefinition::numeric('amount', 'Amount', 'gren.amount', defaultColumn: true),
                ReportFieldDefinition::varchar('currency', 'Currency', 'gren.currency', groupable: false),
                ReportFieldDefinition::date('renewal_date', 'Renewal Date', 'gren.renewal_date'),
                ReportFieldDefinition::date('closed_at', 'Closed On', 'gren.closed_at'),
                self::ownerField(),
            ],
            joins: [
                'gtm.accounts' => ReportJoinDefinition::direct('gtm.accounts', 'account_id', 'id'),
                'gtm.deals' => ReportJoinDefinition::direct('gtm.deals', 'deal_id', 'id'),
            ],
            alwaysJoins: [self::ownerJoin()],
        );
    }
}
