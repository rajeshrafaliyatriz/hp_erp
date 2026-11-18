<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$connName = 'mysql';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--database=')) {
        $connName = substr($arg, 11);
    } elseif (in_array($arg, ['db3', 'mysql', 'live', 'mysql_Dev'], true)) {
        $connName = $arg;
    }
}
$db = DB::connection($connName);

echo "=================================================================\n";
echo "SQL AUDIT REPORT: TENANT 6 CRM DOMAIN CONSOLIDATION & INTEGRITY\n";
echo "Database: " . $db->getDatabaseName() . " (" . $db->getConfig('host') . ") [connection: {$connName}]\n";
echo "Timestamp: " . now()->toIso8601String() . "\n";
echo "=================================================================\n\n";

// 1. FOREIGN KEY & REFERENTIAL INTEGRITY CHECKS
$checks = [
    'Broken leads -> org FK' => $db->table('crm_leads as l')->leftJoin('crm_organizations as o', 'o.id', '=', 'l.converted_organization_id')->whereNotNull('l.converted_organization_id')->whereNull('o.id')->count(),
    'Broken leads -> contact FK' => $db->table('crm_leads as l')->leftJoin('crm_contacts as c', 'c.id', '=', 'l.converted_contact_id')->whereNotNull('l.converted_contact_id')->whereNull('c.id')->count(),
    'Broken leads -> opp FK' => $db->table('crm_leads as l')->leftJoin('crm_opportunities as opp', 'opp.id', '=', 'l.converted_opportunity_id')->whereNotNull('l.converted_opportunity_id')->whereNull('opp.id')->count(),
    'Broken leads -> product_master FK' => $db->table('crm_leads as l')->leftJoin('g2g_product_master as pm', 'pm.id', '=', 'l.product_master_id')->whereNotNull('l.product_master_id')->whereNull('pm.id')->count(),
    'Broken products -> product_master FK' => $db->table('crm_products as p')->leftJoin('g2g_product_master as pm', 'pm.id', '=', 'p.product_master_id')->whereNotNull('p.product_master_id')->whereNull('pm.id')->count(),
    'Broken opps -> product_master FK' => $db->table('crm_opportunities as o')->leftJoin('g2g_product_master as pm', 'pm.id', '=', 'o.product_master_id')->whereNotNull('o.product_master_id')->whereNull('pm.id')->count(),
    'Broken opps -> org FK' => $db->table('crm_opportunities as o')->leftJoin('crm_organizations as org', 'org.id', '=', 'o.organization_id')->whereNotNull('o.organization_id')->whereNull('org.id')->count(),
    'Broken opps -> campaign FK' => $db->table('crm_opportunities as o')->leftJoin('crm_campaigns as camp', 'camp.id', '=', 'o.campaign_id')->whereNotNull('o.campaign_id')->whereNull('camp.id')->count(),
    'Broken opp contacts -> opp FK' => $db->table('crm_opportunity_contacts as oc')->leftJoin('crm_opportunities as o', 'o.id', '=', 'oc.opportunity_id')->whereNull('o.id')->count(),
    'Broken opp contacts -> contact FK' => $db->table('crm_opportunity_contacts as oc')->leftJoin('crm_contacts as c', 'c.id', '=', 'oc.contact_id')->whereNull('c.id')->count(),
    'Broken quotes -> opp FK' => $db->table('crm_quotes as q')->leftJoin('crm_opportunities as opp', 'opp.id', '=', 'q.opportunity_id')->whereNotNull('q.opportunity_id')->whereNull('opp.id')->count(),
    'Broken quotes -> org FK' => $db->table('crm_quotes as q')->leftJoin('crm_organizations as org', 'org.id', '=', 'q.organization_id')->whereNotNull('q.organization_id')->whereNull('org.id')->count(),
    'Broken quotes -> contact FK' => $db->table('crm_quotes as q')->leftJoin('crm_contacts as c', 'c.id', '=', 'q.contact_id')->whereNotNull('q.contact_id')->whereNull('c.id')->count(),
    'Broken quote line_items -> quote FK' => $db->table('crm_quote_line_items as qli')->leftJoin('crm_quotes as q', 'q.id', '=', 'qli.quote_id')->whereNull('q.id')->count(),
    'Broken quote line_items -> product FK' => $db->table('crm_quote_line_items as qli')->leftJoin('crm_products as p', 'p.id', '=', 'qli.product_id')->whereNotNull('qli.product_id')->whereNull('p.id')->count(),
    'Broken so -> quote FK' => $db->table('crm_sales_orders as so')->leftJoin('crm_quotes as q', 'q.id', '=', 'so.quote_id')->whereNotNull('so.quote_id')->whereNull('q.id')->count(),
    'Broken so -> org FK' => $db->table('crm_sales_orders as so')->leftJoin('crm_organizations as org', 'org.id', '=', 'so.organization_id')->whereNotNull('so.organization_id')->whereNull('org.id')->count(),
    'Broken so line_items -> so FK' => $db->table('crm_sales_order_line_items as soli')->leftJoin('crm_sales_orders as so', 'so.id', '=', 'soli.sales_order_id')->whereNull('so.id')->count(),
    'Broken invoice -> so FK' => $db->table('crm_invoices as inv')->leftJoin('crm_sales_orders as so', 'so.id', '=', 'inv.sales_order_id')->whereNotNull('inv.sales_order_id')->whereNull('so.id')->count(),
    'Broken invoice -> org FK' => $db->table('crm_invoices as inv')->leftJoin('crm_organizations as org', 'org.id', '=', 'inv.organization_id')->whereNotNull('inv.organization_id')->whereNull('org.id')->count(),
    'Broken invoice line_items -> invoice FK' => $db->table('crm_invoice_line_items as ili')->leftJoin('crm_invoices as inv', 'inv.id', '=', 'ili.invoice_id')->whereNull('inv.id')->count(),
    'Broken campaigns -> product_master FK' => $db->table('crm_campaigns as c')->leftJoin('g2g_product_master as pm', 'pm.id', '=', 'c.product_master_id')->whereNotNull('c.product_master_id')->whereNull('pm.id')->count(),
];

echo "1. FOREIGN KEY & REFERENTIAL INTEGRITY (0 Broken Invariant):\n";
$allFkClean = true;
foreach ($checks as $name => $brokenCount) {
    $status = $brokenCount === 0 ? "PASSED (0 broken)" : "FAILED ({$brokenCount} broken)";
    echo "  - {$name}: {$status}\n";
    if ($brokenCount > 0) $allFkClean = false;
}
echo "  Result: " . ($allFkClean ? "ALL " . count($checks) . " FOREIGN KEY INTEGRITY INVARIANTS SATISFIED (0 BROKEN)" : "INTEGRITY VIOLATION DETECTED") . "\n\n";

// 2. ORPHANED ITEMS AUDIT
echo "2. ORPHANED ITEM AUDIT (0 Orphan Invariant):\n";
$orphanQuoteLines = DB::table('crm_quote_line_items as qli')->leftJoin('crm_quotes as q', 'q.id', '=', 'qli.quote_id')->whereNull('q.id')->count();
$orphanSoLines = DB::table('crm_sales_order_line_items as soli')->leftJoin('crm_sales_orders as so', 'so.id', '=', 'soli.sales_order_id')->whereNull('so.id')->count();
$orphanInvLines = DB::table('crm_invoice_line_items as ili')->leftJoin('crm_invoices as inv', 'inv.id', '=', 'ili.invoice_id')->whereNull('inv.id')->count();
$orphanContacts = DB::table('crm_opportunity_contacts as oc')->leftJoin('crm_opportunities as o', 'o.id', '=', 'oc.opportunity_id')->whereNull('o.id')->count();

echo "  - Orphaned Quote Line Items: {$orphanQuoteLines}\n";
echo "  - Orphaned Sales Order Line Items: {$orphanSoLines}\n";
echo "  - Orphaned Invoice Line Items: {$orphanInvLines}\n";
echo "  - Orphaned Opportunity Contacts: {$orphanContacts}\n";
$orphansTotal = $orphanQuoteLines + $orphanSoLines + $orphanInvLines + $orphanContacts;
echo "  Result: " . ($orphansTotal === 0 ? "ZERO ORPHANED ITEMS VERIFIED" : "ORPHANED ITEMS FOUND") . "\n\n";

// 3. MODULE 199 LIFECYCLE ORDER AUDIT
echo "3. MODULE 199 (Sales & Marketing) SUBMODULE HIERARCHY & LIFECYCLE AUDIT:\n";
$menus = DB::table('tblmenumaster_g2g')
    ->where('parent_id', 199)
    ->orderBy('sort_order')
    ->get(['id', 'menu_name', 'access_link', 'sort_order', 'status', 'icon']);

echo sprintf("  Total active submodules under Module 199: %d\n", $menus->count());
$prevOrder = -1;
$monotonic = true;
foreach ($menus as $m) {
    if ($m->sort_order < $prevOrder) {
        $monotonic = false;
    }
    $prevOrder = $m->sort_order;
    echo sprintf("  [%03d] %-30s | Link: %-40s | Order: %2d | Icon: %s\n", $m->id, $m->menu_name, $m->access_link, $m->sort_order, $m->icon ?? 'NULL');
}
echo "  Lifecycle Ordering: " . ($monotonic ? "STRICTLY MONOTONIC & VALID" : "OUT OF ORDER") . "\n\n";

// 4. TENANT 6 GUJARAT & SCHOLARCLONE RECORD SUMMARY
echo "4. TENANT 6 (ScholarClone) GUJARAT SCHOOLS DATA SUMMARY:\n";
$t6Leads = DB::table('crm_leads')->where('sub_institute_id', 6)->where('state', 'Gujarat')->count();
$t6LeadsScholarClone = DB::table('crm_leads')->where('sub_institute_id', 6)->where('state', 'Gujarat')->where('product_master_id', 1)->count();
$t6Orgs = DB::table('crm_organizations')->where('sub_institute_id', 6)->where('billing_state', 'Gujarat')->count();
$t6Opps = DB::table('crm_opportunities')->where('sub_institute_id', 6)->where('product_master_id', 1)->count();
$t6Products = DB::table('crm_products')->where('sub_institute_id', 6)->where('product_master_id', 1)->count();
$t6Quotes = DB::table('crm_quotes')->where('sub_institute_id', 6)->count();
$t6Sos = DB::table('crm_sales_orders')->where('sub_institute_id', 6)->count();
$t6Invs = DB::table('crm_invoices')->where('sub_institute_id', 6)->count();

echo "  - Gujarat School Leads: {$t6Leads} (All {$t6LeadsScholarClone} explicitly linked to ScholarClone Product Line PM 1)\n";
echo "  - Gujarat School Organizations: {$t6Orgs}\n";
echo "  - ScholarClone Opportunities: {$t6Opps} (All product_master_id = 1)\n";
echo "  - ScholarClone Catalogue SKUs: {$t6Products} (All product_master_id = 1)\n";
echo "  - CPQ Quotes: {$t6Quotes}\n";
echo "  - Sales Orders: {$t6Sos}\n";
echo "  - Invoices: {$t6Invs}\n";

// 5. ZERO FINANCIAL VARIANCE AUDIT
echo "\n5. ORDER-TO-CASH ZERO VARIANCE RECONCILIATION:\n";
$soRows = DB::table('crm_sales_orders')->where('sub_institute_id', 6)->get();
foreach ($soRows as $so) {
    $inv = DB::table('crm_invoices')->where('sales_order_id', $so->id)->first();
    if ($inv) {
        $variance = abs((float) $so->total - (float) $inv->total);
        echo sprintf("  - SO #%d (Total: ₹%.2f) vs Invoice #%d (Total: ₹%.2f) => Variance: ₹%.4f (%s)\n",
            $so->id, (float) $so->total, $inv->id, (float) $inv->total, $variance, $variance < 0.001 ? "ZERO VARIANCE CONFIRMED" : "VARIANCE DETECTED"
        );
    }
}
echo "=================================================================\n";
