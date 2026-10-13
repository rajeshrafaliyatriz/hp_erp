<?php

namespace App\Domain\Reports\Crm;

use App\Domain\Reports\ReportFieldDefinition;
use App\Domain\Reports\ReportJoinDefinition;
use App\Domain\Reports\ReportModuleDefinition;

/**
 * CRM's reportable modules (Docs/reports-engine/PLAN.md, Phase 3), registered
 * here and nowhere else; ReportModuleRegistry only merges this array in —
 * same convention as TaskManagementReportModules.
 *
 * Schema verified directly against the real migrations (table names, FK
 * columns, soft-delete/tenant-column presence) before writing this, not
 * assumed — see the CRM gap-closure phase's own migrations under
 * database/migrations/2026_11_2*_*crm*.php. Every module below has its own
 * `sub_institute_id` (tenant-scope) column, confirmed — this is a hard
 * requirement for anything registered here, not a detail: the execution
 * engine always scopes a PRIMARY module's query by its own tenantColumn, so
 * a module without one must never be registered as independently selectable.
 *
 * DELIBERATELY NOT REGISTERED: line-item tables (crm_quote_line_items,
 * crm_sales_order_line_items, crm_purchase_order_line_items,
 * crm_invoice_line_items), crm_opportunity_products/contacts,
 * crm_campaign_targets, crm_vendor_contacts, crm_product_bundle_items, and
 * every *_status_history/*_comments table. None of them carry their own
 * `sub_institute_id` — they are reachable only via a parent row that is
 * already tenant-scoped, which is fine for an app query but NOT fine for a
 * report engine's PRIMARY module (the one thing the engine scopes directly).
 * Registering one as a standalone module anyway would mean either faking a
 * tenant column that doesn't exist (a report on it silently errors the
 * moment anyone selects it as primary) or skipping the scope check entirely
 * (a real cross-tenant leak). Neither is acceptable, so "Quotes/SalesOrder/
 * PurchaseOrder/Invoice Detailed Report" are built as richer TABULAR reports
 * on the parent entity itself (more columns + a join to Organization/Vendor/
 * Contact), not a line-item explosion — the same display-only compromise
 * already made for custom fields in Phase 0, for the same reason.
 */
final class CrmReportModules
{
    /** @return ReportModuleDefinition[] */
    public static function definitions(): array
    {
        return [
            self::leads(),
            self::organizations(),
            self::contacts(),
            self::opportunities(),
            self::quotes(),
            self::salesOrders(),
            self::purchaseOrders(),
            self::invoices(),
            self::campaigns(),
            self::tickets(),
            self::products(),
            self::vendors(),
            self::serviceContracts(),
            self::assets(),
            self::emails(),
            self::emailLinks(),
        ];
    }

    /** Every module's own assignee join — reused verbatim since alwaysJoins from different modules never coexist in one query (only the PRIMARY module's own alwaysJoins ever fire). */
    private static function assigneeJoin(): array
    {
        return ['table' => 'tbluser', 'alias' => 'u_assignee', 'localColumn' => 'assigned_to', 'foreignColumn' => 'id'];
    }

    private static function assigneeField(): ReportFieldDefinition
    {
        return ReportFieldDefinition::userRef('assigned_to', 'Assigned To', "TRIM(CONCAT_WS(' ', u_assignee.first_name, u_assignee.middle_name, u_assignee.last_name))");
    }

    private static function leads(): ReportModuleDefinition
    {
        return new ReportModuleDefinition(
            key: 'crm.leads',
            label: 'Leads',
            table: 'crm_leads',
            alias: 'ld',
            tenantColumn: 'sub_institute_id',
            softDeleteColumn: 'deleted_at',
            primaryKey: 'id',
            fields: [
                ReportFieldDefinition::varchar('lead_no', 'Lead No', 'ld.lead_no', groupable: false),
                ReportFieldDefinition::varchar('full_name', 'Lead Name', "TRIM(CONCAT_WS(' ', ld.first_name, ld.last_name))"),
                ReportFieldDefinition::varchar('company', 'Company', 'ld.company'),
                ReportFieldDefinition::varchar('designation', 'Designation', 'ld.designation', groupable: false),
                ReportFieldDefinition::email('email', 'Email', 'ld.email'),
                ReportFieldDefinition::varchar('phone', 'Phone', 'ld.phone', groupable: false),
                ReportFieldDefinition::picklist('lead_source', 'Lead Source', 'ld.lead_source', 'leads', 'lead_source'),
                ReportFieldDefinition::picklist('lead_status', 'Lead Status', 'ld.lead_status', 'leads', 'lead_status'),
                ReportFieldDefinition::picklist('rating', 'Rating', 'ld.rating', 'leads', 'rating'),
                ReportFieldDefinition::picklist('industry', 'Industry', 'ld.industry', 'leads', 'industry'),
                ReportFieldDefinition::numeric('annual_revenue', 'Annual Revenue', 'ld.annual_revenue'),
                self::assigneeField(),
            ],
            alwaysJoins: [self::assigneeJoin()],
        );
    }

    private static function organizations(): ReportModuleDefinition
    {
        return new ReportModuleDefinition(
            key: 'crm.organizations',
            label: 'Organizations',
            table: 'crm_organizations',
            alias: 'org',
            tenantColumn: 'sub_institute_id',
            softDeleteColumn: 'deleted_at',
            primaryKey: 'id',
            fields: [
                ReportFieldDefinition::varchar('account_no', 'Account No', 'org.account_no', groupable: false),
                ReportFieldDefinition::varchar('name', 'Organization', 'org.name'),
                ReportFieldDefinition::picklist('account_type', 'Account Type', 'org.account_type', 'organizations', 'account_type'),
                ReportFieldDefinition::picklist('industry', 'Industry', 'org.industry', 'organizations', 'industry'),
                ReportFieldDefinition::picklist('rating', 'Rating', 'org.rating', 'organizations', 'rating'),
                ReportFieldDefinition::numeric('annual_revenue', 'Annual Revenue', 'org.annual_revenue'),
                self::assigneeField(),
            ],
            alwaysJoins: [self::assigneeJoin()],
        );
    }

    private static function contacts(): ReportModuleDefinition
    {
        return new ReportModuleDefinition(
            key: 'crm.contacts',
            label: 'Contacts',
            table: 'crm_contacts',
            alias: 'ct',
            tenantColumn: 'sub_institute_id',
            softDeleteColumn: 'deleted_at',
            primaryKey: 'id',
            fields: [
                ReportFieldDefinition::varchar('contact_no', 'Contact No', 'ct.contact_no', groupable: false),
                ReportFieldDefinition::varchar('full_name', 'Contact Name', "TRIM(CONCAT_WS(' ', ct.first_name, ct.last_name))"),
                ReportFieldDefinition::email('email', 'Email', 'ct.email'),
                ReportFieldDefinition::varchar('phone', 'Phone', 'ct.phone', groupable: false),
                ReportFieldDefinition::picklist('lead_source', 'Lead Source', 'ct.lead_source', 'contacts', 'lead_source'),
                // Raw FK, not a display join — lets "Contacts without Organizations" filter on is_null without needing the Organizations join at all.
                ReportFieldDefinition::numeric('organization_id', 'Organization Id', 'ct.organization_id', aggregatable: false),
                self::assigneeField(),
            ],
            joins: [
                'crm.organizations' => ReportJoinDefinition::direct('crm.organizations', 'organization_id', 'id'),
                'crm.opportunities' => ReportJoinDefinition::pivot('crm.opportunities', 'crm_opportunity_contacts', 'contact_id', 'opportunity_id'),
            ],
            alwaysJoins: [self::assigneeJoin()],
        );
    }

    private static function opportunities(): ReportModuleDefinition
    {
        return new ReportModuleDefinition(
            key: 'crm.opportunities',
            label: 'Opportunities',
            table: 'crm_opportunities',
            alias: 'opp',
            tenantColumn: 'sub_institute_id',
            softDeleteColumn: 'deleted_at',
            primaryKey: 'id',
            fields: [
                ReportFieldDefinition::varchar('opportunity_no', 'Opportunity No', 'opp.opportunity_no', groupable: false),
                ReportFieldDefinition::varchar('name', 'Opportunity', 'opp.name'),
                ReportFieldDefinition::numeric('amount', 'Amount', 'opp.amount'),
                ReportFieldDefinition::picklist('sales_stage', 'Sales Stage', 'opp.sales_stage', 'opportunities', 'sales_stage'),
                ReportFieldDefinition::numeric('probability', 'Probability', 'opp.probability'),
                ReportFieldDefinition::date('closing_date', 'Closing Date', 'opp.closing_date'),
                ReportFieldDefinition::picklist('lead_source', 'Lead Source', 'opp.lead_source', 'opportunities', 'lead_source'),
                ReportFieldDefinition::picklist('forecast_category', 'Forecast Category', 'opp.forecast_category', 'opportunities', 'forecast_category'),
                self::assigneeField(),
            ],
            joins: [
                'crm.organizations' => ReportJoinDefinition::direct('crm.organizations', 'organization_id', 'id'),
                'crm.campaigns' => ReportJoinDefinition::direct('crm.campaigns', 'campaign_id', 'id'),
            ],
            alwaysJoins: [self::assigneeJoin()],
        );
    }

    private static function quotes(): ReportModuleDefinition
    {
        return new ReportModuleDefinition(
            key: 'crm.quotes',
            label: 'Quotes',
            table: 'crm_quotes',
            alias: 'qt',
            tenantColumn: 'sub_institute_id',
            softDeleteColumn: 'deleted_at',
            primaryKey: 'id',
            fields: [
                ReportFieldDefinition::varchar('quote_no', 'Quote No', 'qt.quote_no', groupable: false),
                ReportFieldDefinition::varchar('subject', 'Subject', 'qt.subject'),
                ReportFieldDefinition::picklist('quote_stage', 'Quote Stage', 'qt.quote_stage', 'quotes', 'quote_stage'),
                ReportFieldDefinition::date('valid_till', 'Valid Till', 'qt.valid_till'),
                ReportFieldDefinition::numeric('total', 'Total', 'qt.total'),
                self::assigneeField(),
            ],
            joins: [
                'crm.organizations' => ReportJoinDefinition::direct('crm.organizations', 'organization_id', 'id'),
                'crm.contacts' => ReportJoinDefinition::direct('crm.contacts', 'contact_id', 'id'),
                'crm.opportunities' => ReportJoinDefinition::direct('crm.opportunities', 'opportunity_id', 'id'),
            ],
            alwaysJoins: [self::assigneeJoin()],
        );
    }

    private static function salesOrders(): ReportModuleDefinition
    {
        return new ReportModuleDefinition(
            key: 'crm.sales_orders',
            label: 'Sales Orders',
            table: 'crm_sales_orders',
            alias: 'so',
            tenantColumn: 'sub_institute_id',
            softDeleteColumn: 'deleted_at',
            primaryKey: 'id',
            fields: [
                ReportFieldDefinition::varchar('so_no', 'Sales Order No', 'so.so_no', groupable: false),
                ReportFieldDefinition::varchar('subject', 'Subject', 'so.subject'),
                ReportFieldDefinition::picklist('so_stage', 'Stage', 'so.so_stage', 'sales_orders', 'so_stage'),
                ReportFieldDefinition::date('due_date', 'Due Date', 'so.due_date'),
                ReportFieldDefinition::numeric('total', 'Total', 'so.total'),
                ReportFieldDefinition::varchar('carrier', 'Carrier', 'so.carrier', groupable: false),
                self::assigneeField(),
            ],
            joins: [
                'crm.organizations' => ReportJoinDefinition::direct('crm.organizations', 'organization_id', 'id'),
                'crm.contacts' => ReportJoinDefinition::direct('crm.contacts', 'contact_id', 'id'),
                'crm.opportunities' => ReportJoinDefinition::direct('crm.opportunities', 'opportunity_id', 'id'),
                'crm.quotes' => ReportJoinDefinition::direct('crm.quotes', 'quote_id', 'id'),
            ],
            alwaysJoins: [self::assigneeJoin()],
        );
    }

    private static function purchaseOrders(): ReportModuleDefinition
    {
        return new ReportModuleDefinition(
            key: 'crm.purchase_orders',
            label: 'Purchase Orders',
            table: 'crm_purchase_orders',
            alias: 'po',
            tenantColumn: 'sub_institute_id',
            softDeleteColumn: 'deleted_at',
            primaryKey: 'id',
            fields: [
                ReportFieldDefinition::varchar('po_no', 'Purchase Order No', 'po.po_no', groupable: false),
                ReportFieldDefinition::varchar('subject', 'Subject', 'po.subject'),
                ReportFieldDefinition::picklist('po_stage', 'Stage', 'po.po_stage', 'purchase_orders', 'po_stage'),
                ReportFieldDefinition::varchar('requisition_no', 'Requisition No', 'po.requisition_no', groupable: false),
                ReportFieldDefinition::varchar('tracking_no', 'Tracking No', 'po.tracking_no', groupable: false),
                ReportFieldDefinition::date('due_date', 'Due Date', 'po.due_date'),
                ReportFieldDefinition::numeric('total', 'Total', 'po.total'),
                self::assigneeField(),
            ],
            joins: [
                'crm.vendors' => ReportJoinDefinition::direct('crm.vendors', 'vendor_id', 'id'),
                'crm.organizations' => ReportJoinDefinition::direct('crm.organizations', 'organization_id', 'id'),
                'crm.contacts' => ReportJoinDefinition::direct('crm.contacts', 'contact_id', 'id'),
                'crm.quotes' => ReportJoinDefinition::direct('crm.quotes', 'quote_id', 'id'),
            ],
            alwaysJoins: [self::assigneeJoin()],
        );
    }

    private static function invoices(): ReportModuleDefinition
    {
        return new ReportModuleDefinition(
            key: 'crm.invoices',
            label: 'Invoices',
            table: 'crm_invoices',
            alias: 'inv',
            tenantColumn: 'sub_institute_id',
            softDeleteColumn: 'deleted_at',
            primaryKey: 'id',
            fields: [
                ReportFieldDefinition::varchar('invoice_no', 'Invoice No', 'inv.invoice_no', groupable: false),
                ReportFieldDefinition::varchar('subject', 'Subject', 'inv.subject'),
                ReportFieldDefinition::picklist('invoice_stage', 'Stage', 'inv.invoice_stage', 'invoices', 'invoice_stage'),
                ReportFieldDefinition::date('invoice_date', 'Invoice Date', 'inv.invoice_date'),
                ReportFieldDefinition::date('due_date', 'Due Date', 'inv.due_date'),
                ReportFieldDefinition::numeric('total', 'Total', 'inv.total'),
                self::assigneeField(),
            ],
            joins: [
                'crm.sales_orders' => ReportJoinDefinition::direct('crm.sales_orders', 'sales_order_id', 'id'),
                'crm.organizations' => ReportJoinDefinition::direct('crm.organizations', 'organization_id', 'id'),
                'crm.contacts' => ReportJoinDefinition::direct('crm.contacts', 'contact_id', 'id'),
            ],
            alwaysJoins: [self::assigneeJoin()],
        );
    }

    private static function campaigns(): ReportModuleDefinition
    {
        return new ReportModuleDefinition(
            key: 'crm.campaigns',
            label: 'Campaigns',
            table: 'crm_campaigns',
            alias: 'cm',
            tenantColumn: 'sub_institute_id',
            softDeleteColumn: 'deleted_at',
            primaryKey: 'id',
            fields: [
                ReportFieldDefinition::varchar('campaign_no', 'Campaign No', 'cm.campaign_no', groupable: false),
                ReportFieldDefinition::varchar('name', 'Campaign', 'cm.name'),
                ReportFieldDefinition::picklist('campaign_type', 'Type', 'cm.campaign_type', 'campaigns', 'campaign_type'),
                ReportFieldDefinition::picklist('campaign_status', 'Status', 'cm.campaign_status', 'campaigns', 'campaign_status'),
                ReportFieldDefinition::numeric('expected_revenue', 'Expected Revenue', 'cm.expected_revenue'),
                ReportFieldDefinition::numeric('budget_cost', 'Budget Cost', 'cm.budget_cost'),
                ReportFieldDefinition::numeric('actual_cost', 'Actual Cost', 'cm.actual_cost'),
                ReportFieldDefinition::date('closing_date', 'Closing Date', 'cm.closing_date'),
                self::assigneeField(),
            ],
            alwaysJoins: [self::assigneeJoin()],
        );
    }

    private static function tickets(): ReportModuleDefinition
    {
        return new ReportModuleDefinition(
            key: 'crm.tickets',
            label: 'Tickets',
            table: 'crm_tickets',
            alias: 'tk',
            tenantColumn: 'sub_institute_id',
            softDeleteColumn: 'deleted_at',
            primaryKey: 'id',
            fields: [
                ReportFieldDefinition::varchar('ticket_no', 'Ticket No', 'tk.ticket_no', groupable: false),
                ReportFieldDefinition::varchar('title', 'Title', 'tk.title'),
                ReportFieldDefinition::picklist('status', 'Status', 'tk.status', 'tickets', 'status'),
                ReportFieldDefinition::picklist('priority', 'Priority', 'tk.priority', 'tickets', 'priority'),
                ReportFieldDefinition::picklist('severity', 'Severity', 'tk.severity', 'tickets', 'severity'),
                ReportFieldDefinition::picklist('category', 'Category', 'tk.category', 'tickets', 'category'),
                ReportFieldDefinition::numeric('hours', 'Hours', 'tk.hours'),
                ReportFieldDefinition::numeric('days', 'Days', 'tk.days'),
                self::assigneeField(),
            ],
            joins: [
                'crm.organizations' => ReportJoinDefinition::direct('crm.organizations', 'organization_id', 'id'),
                'crm.contacts' => ReportJoinDefinition::direct('crm.contacts', 'contact_id', 'id'),
                'crm.products' => ReportJoinDefinition::direct('crm.products', 'product_id', 'id'),
                'crm.assets' => ReportJoinDefinition::direct('crm.assets', 'asset_id', 'id'),
                'crm.service_contracts' => ReportJoinDefinition::direct('crm.service_contracts', 'service_contract_id', 'id'),
            ],
            alwaysJoins: [self::assigneeJoin()],
        );
    }

    private static function products(): ReportModuleDefinition
    {
        return new ReportModuleDefinition(
            key: 'crm.products',
            label: 'Products',
            table: 'crm_products',
            alias: 'pd',
            tenantColumn: 'sub_institute_id',
            softDeleteColumn: 'deleted_at',
            primaryKey: 'id',
            fields: [
                ReportFieldDefinition::varchar('product_no', 'Product No', 'pd.product_no', groupable: false),
                ReportFieldDefinition::varchar('name', 'Product', 'pd.name'),
                ReportFieldDefinition::varchar('sku', 'SKU', 'pd.sku', groupable: false),
                ReportFieldDefinition::picklist('category', 'Category', 'pd.category', 'products', 'category'),
                ReportFieldDefinition::varchar('item_type', 'Item Type', 'pd.item_type'),
                ReportFieldDefinition::varchar('vendor', 'Vendor', 'pd.vendor'),
                ReportFieldDefinition::numeric('unit_price', 'Unit Price', 'pd.unit_price'),
                ReportFieldDefinition::numeric('cost_price', 'Cost Price', 'pd.cost_price'),
                ReportFieldDefinition::numeric('qty_in_stock', 'Qty In Stock', 'pd.qty_in_stock'),
                ReportFieldDefinition::numeric('reorder_level', 'Reorder Level', 'pd.reorder_level'),
                ReportFieldDefinition::varchar('is_active', 'Active', 'pd.is_active'),
                self::assigneeField(),
            ],
            alwaysJoins: [self::assigneeJoin()],
        );
    }

    private static function vendors(): ReportModuleDefinition
    {
        return new ReportModuleDefinition(
            key: 'crm.vendors',
            label: 'Vendors',
            table: 'crm_vendors',
            alias: 'vd',
            tenantColumn: 'sub_institute_id',
            softDeleteColumn: 'deleted_at',
            primaryKey: 'id',
            fields: [
                ReportFieldDefinition::varchar('vendor_no', 'Vendor No', 'vd.vendor_no', groupable: false),
                ReportFieldDefinition::varchar('name', 'Vendor', 'vd.name'),
                ReportFieldDefinition::varchar('phone', 'Phone', 'vd.phone', groupable: false),
                ReportFieldDefinition::email('email', 'Email', 'vd.email'),
                ReportFieldDefinition::varchar('category', 'Category', 'vd.category'),
                ReportFieldDefinition::varchar('is_active', 'Active', 'vd.is_active'),
                self::assigneeField(),
            ],
            alwaysJoins: [self::assigneeJoin()],
        );
    }

    private static function serviceContracts(): ReportModuleDefinition
    {
        return new ReportModuleDefinition(
            key: 'crm.service_contracts',
            label: 'Service Contracts',
            table: 'crm_service_contracts',
            alias: 'sct',
            tenantColumn: 'sub_institute_id',
            softDeleteColumn: 'deleted_at',
            primaryKey: 'id',
            fields: [
                ReportFieldDefinition::varchar('contract_no', 'Contract No', 'sct.contract_no', groupable: false),
                ReportFieldDefinition::varchar('subject', 'Subject', 'sct.subject'),
                ReportFieldDefinition::date('start_date', 'Start Date', 'sct.start_date'),
                ReportFieldDefinition::date('end_date', 'End Date', 'sct.end_date'),
                ReportFieldDefinition::date('due_date', 'Due Date', 'sct.due_date'),
                ReportFieldDefinition::picklist('tracking_unit', 'Tracking Unit', 'sct.tracking_unit', 'service_contracts', 'tracking_unit'),
                ReportFieldDefinition::numeric('total_units', 'Total Units', 'sct.total_units'),
                ReportFieldDefinition::numeric('used_units', 'Used Units', 'sct.used_units'),
                ReportFieldDefinition::numeric('progress', 'Progress', 'sct.progress'),
                ReportFieldDefinition::picklist('contract_status', 'Status', 'sct.contract_status', 'service_contracts', 'contract_status'),
                ReportFieldDefinition::picklist('contract_type', 'Type', 'sct.contract_type', 'service_contracts', 'contract_type'),
                ReportFieldDefinition::picklist('priority', 'Priority', 'sct.priority', 'service_contracts', 'priority'),
                self::assigneeField(),
            ],
            joins: [
                'crm.organizations' => ReportJoinDefinition::direct('crm.organizations', 'organization_id', 'id'),
                'crm.contacts' => ReportJoinDefinition::direct('crm.contacts', 'contact_id', 'id'),
            ],
            alwaysJoins: [self::assigneeJoin()],
        );
    }

    private static function assets(): ReportModuleDefinition
    {
        return new ReportModuleDefinition(
            key: 'crm.assets',
            label: 'Assets',
            table: 'crm_assets',
            alias: 'ast',
            tenantColumn: 'sub_institute_id',
            softDeleteColumn: 'deleted_at',
            primaryKey: 'id',
            fields: [
                ReportFieldDefinition::varchar('asset_no', 'Asset No', 'ast.asset_no', groupable: false),
                ReportFieldDefinition::varchar('asset_name', 'Asset', 'ast.asset_name'),
                ReportFieldDefinition::varchar('serial_number', 'Serial No', 'ast.serial_number', groupable: false),
                ReportFieldDefinition::varchar('tag_number', 'Tag No', 'ast.tag_number', groupable: false),
                ReportFieldDefinition::date('date_sold', 'Date Sold', 'ast.date_sold'),
                ReportFieldDefinition::date('date_in_service', 'Date In Service', 'ast.date_in_service'),
                ReportFieldDefinition::varchar('status', 'Status', 'ast.status'),
                self::assigneeField(),
            ],
            joins: [
                'crm.products' => ReportJoinDefinition::direct('crm.products', 'product_id', 'id'),
                'crm.organizations' => ReportJoinDefinition::direct('crm.organizations', 'organization_id', 'id'),
                'crm.invoices' => ReportJoinDefinition::direct('crm.invoices', 'invoice_id', 'id'),
            ],
            alwaysJoins: [self::assigneeJoin()],
        );
    }

    private static function emails(): ReportModuleDefinition
    {
        return new ReportModuleDefinition(
            key: 'crm.emails',
            label: 'Emails',
            table: 'crm_emails',
            alias: 'em',
            tenantColumn: 'sub_institute_id',
            softDeleteColumn: null, // confirmed: append-only, a sent/queued email is a real event, never soft-deleted
            primaryKey: 'id',
            fields: [
                ReportFieldDefinition::varchar('subject', 'Subject', 'em.subject', groupable: false),
                ReportFieldDefinition::varchar('status', 'Status', 'em.status'), // Draft|Queued|Sent|Failed
                ReportFieldDefinition::date('sent_at', 'Sent At', 'em.sent_at'),
                ReportFieldDefinition::varchar('to_address', 'To', 'em.to_address', groupable: false),
                self::assigneeField(),
            ],
            alwaysJoins: [self::assigneeJoin()],
        );
    }

    /**
     * The real "Email Reports" data source — crm_email_links is the
     * polymorphic pivot (linked_type/linked_id) that ties a crm_emails row
     * back to whichever of the 5 "hot" entities (Lead/Contact/Organization/
     * Opportunity/Ticket) it was sent against (HasCrmEmailLinks). Grouping/
     * filtering by `linked_type` here is what "Contacts Email Report" etc.
     * actually need — joined to crm.emails for the email's own subject/
     * status/sent_at columns.
     */
    private static function emailLinks(): ReportModuleDefinition
    {
        return new ReportModuleDefinition(
            key: 'crm.email_links',
            label: 'Email Links',
            table: 'crm_email_links',
            alias: 'eml',
            tenantColumn: 'sub_institute_id',
            softDeleteColumn: null, // confirmed: no deleted_at, no updated_at even — created_at only
            primaryKey: 'id',
            fields: [
                ReportFieldDefinition::varchar('linked_type', 'Linked To', 'eml.linked_type'),
                ReportFieldDefinition::numeric('linked_id', 'Linked Record Id', 'eml.linked_id', aggregatable: false),
            ],
            joins: [
                'crm.emails' => ReportJoinDefinition::direct('crm.emails', 'email_id', 'id'),
            ],
        );
    }
}
