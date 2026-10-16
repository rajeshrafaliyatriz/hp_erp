<?php

namespace App\Domain\Reports\Signals;

use App\Domain\Reports\ReportFieldDefinition;
use App\Domain\Reports\ReportModuleDefinition;

/**
 * Sales & Marketing unification, Phase 6: Signals' reportable modules,
 * registered here and nowhere else - ReportModuleRegistry only merges this
 * array in, same convention as CrmReportModules/GtmReportModules.
 *
 * Covers the three tables the plan named: `g2g_signals` (Engine A - the
 * per-department HR/org-health insight feed, now actually wired to a UI as
 * of Phase 5), `g2g_company_opportunities` (Engine B - the org-wide AI
 * sales-lead feed), and `g2g_research_runs` (the scheduled/manual research
 * run log behind Engine B). All three carry their own `sub_institute_id`
 * (confirmed directly against the live schema) and none carry a
 * `deleted_at` (confirmed too) - `softDeleteColumn: null` throughout.
 *
 * DELIBERATELY NOT REGISTERED: `g2g_companies`/`g2g_ingestion_*`/
 * `g2g_product_offers`/`g2g_partners`/`g2g_opportunity_matches` - out of
 * this phase's narrower scope (only the three tables the plan named), left
 * for a later pass if ever needed. `g2g_companies` is instead folded into
 * `signals.company_opportunities` as a display-only joined field (company
 * name), same pattern CRM uses for its own `assigned_to` display.
 */
final class SignalsReportModules
{
    /** @return ReportModuleDefinition[] */
    public static function definitions(): array
    {
        return [
            self::orgSignals(),
            self::companyOpportunities(),
            self::researchRuns(),
        ];
    }

    private static function orgSignals(): ReportModuleDefinition
    {
        return new ReportModuleDefinition(
            key: 'signals.org_signals',
            label: 'Org Signals',
            table: 'g2g_signals',
            alias: 'sig',
            tenantColumn: 'sub_institute_id',
            softDeleteColumn: null,
            primaryKey: 'id',
            fields: [
                ReportFieldDefinition::varchar('title', 'Title', 'sig.title', defaultColumn: true),
                ReportFieldDefinition::varchar('signal_type', 'Type', 'sig.signal_type', defaultColumn: true),
                ReportFieldDefinition::varchar('priority', 'Priority', 'sig.priority', defaultColumn: true),
                ReportFieldDefinition::varchar('status', 'Status', 'sig.status', defaultColumn: true),
                ReportFieldDefinition::varchar('origin', 'Origin', 'sig.origin'),
                ReportFieldDefinition::date('generated_at', 'Generated On', 'sig.generated_at'),
                ReportFieldDefinition::varchar('department', 'Department', 'd_sig.department'),
            ],
            alwaysJoins: [
                ['table' => 'hrms_departments', 'alias' => 'd_sig', 'localColumn' => 'department_id', 'foreignColumn' => 'id'],
            ],
        );
    }

    private static function companyOpportunities(): ReportModuleDefinition
    {
        return new ReportModuleDefinition(
            key: 'signals.company_opportunities',
            label: 'Signal Leads',
            table: 'g2g_company_opportunities',
            alias: 'copp',
            tenantColumn: 'sub_institute_id',
            softDeleteColumn: null,
            primaryKey: 'id',
            fields: [
                ReportFieldDefinition::varchar('title', 'Title', 'copp.title', defaultColumn: true),
                ReportFieldDefinition::varchar('category', 'Category', 'copp.category', defaultColumn: true),
                ReportFieldDefinition::varchar('priority', 'Priority', 'copp.priority', defaultColumn: true),
                ReportFieldDefinition::varchar('qualification', 'Qualification', 'copp.qualification'),
                ReportFieldDefinition::varchar('pipeline_status', 'Pipeline Status', 'copp.pipeline_status', defaultColumn: true),
                ReportFieldDefinition::varchar('review_status', 'Review Status', 'copp.review_status'),
                ReportFieldDefinition::numeric('score_total', 'Score', 'copp.score_total'),
                ReportFieldDefinition::date('first_discovered_at', 'Discovered On', 'copp.first_discovered_at'),
                ReportFieldDefinition::varchar('company_name', 'Company', 'c_copp.name', defaultColumn: true),
                ReportFieldDefinition::varchar('company_industry', 'Company Industry', 'c_copp.industry'),
            ],
            alwaysJoins: [
                ['table' => 'g2g_companies', 'alias' => 'c_copp', 'localColumn' => 'company_id', 'foreignColumn' => 'id'],
            ],
        );
    }

    private static function researchRuns(): ReportModuleDefinition
    {
        return new ReportModuleDefinition(
            key: 'signals.research_runs',
            label: 'Signal Research Runs',
            table: 'g2g_research_runs',
            alias: 'rrun',
            tenantColumn: 'sub_institute_id',
            softDeleteColumn: null,
            primaryKey: 'id',
            fields: [
                ReportFieldDefinition::date('report_date', 'Report Date', 'rrun.report_date', defaultColumn: true),
                ReportFieldDefinition::varchar('status', 'Status', 'rrun.status', defaultColumn: true),
                ReportFieldDefinition::varchar('trigger', 'Trigger', 'rrun.trigger'),
                ReportFieldDefinition::varchar('product_key', 'Product', 'rrun.product_key'),
                ReportFieldDefinition::numeric('opportunities_qualified', 'Qualified', 'rrun.opportunities_qualified', defaultColumn: true),
                ReportFieldDefinition::numeric('opportunities_new', 'New', 'rrun.opportunities_new'),
                ReportFieldDefinition::numeric('opportunities_rejected', 'Rejected', 'rrun.opportunities_rejected'),
                ReportFieldDefinition::varchar('search_provider', 'Search Provider', 'rrun.search_provider'),
                ReportFieldDefinition::varchar('ai_provider', 'AI Provider', 'rrun.ai_provider'),
            ],
        );
    }
}
