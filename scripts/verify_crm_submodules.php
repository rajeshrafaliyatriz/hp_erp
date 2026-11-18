<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$conn = 'mysql';

$counts = [
    '1. Products (ScholarClone SKUs)' => DB::connection($conn)->table('crm_products')
        ->where('sub_institute_id', 6)->whereNotNull('sku')->count(),
    '2. Price Books' => DB::connection($conn)->table('crm_price_books')
        ->where('sub_institute_id', 6)->count(),
    '2. Price Book Items' => DB::connection($conn)->table('crm_price_book_items')
        ->join('crm_price_books', 'crm_price_books.id', '=', 'crm_price_book_items.price_book_id')
        ->where('crm_price_books.sub_institute_id', 6)->count(),
    '3. Marketing Campaigns' => DB::connection($conn)->table('crm_campaigns')
        ->where('sub_institute_id', 6)->count(),
    '4. Leads (Total)' => DB::connection($conn)->table('crm_leads')
        ->where('sub_institute_id', 6)->whereNull('deleted_at')->count(),
    '4. Leads (Hot Rating, Score 40)' => DB::connection($conn)->table('crm_leads')
        ->where('sub_institute_id', 6)->where('rating', 'Hot')->where('lead_score', 40)->count(),
    '5. Organizations (Total Accounts)' => DB::connection($conn)->table('crm_organizations')
        ->where('sub_institute_id', 6)->whereNull('deleted_at')->count(),
    '5. Organizations (Customer Type)' => DB::connection($conn)->table('crm_organizations')
        ->where('sub_institute_id', 6)->where('account_type', 'Customer')->count(),
    '6. Contacts (Total)' => DB::connection($conn)->table('crm_contacts')
        ->where('sub_institute_id', 6)->whereNull('deleted_at')->count(),
    '6. Contacts (Opted In Consent)' => DB::connection($conn)->table('crm_contacts')
        ->where('sub_institute_id', 6)->where('consent_status', 'opted_in')->count(),
    '7. Opportunities (Total Deals)' => DB::connection($conn)->table('crm_opportunities')
        ->where('sub_institute_id', 6)->whereNull('deleted_at')->count(),
    '8. Quotes (Multi-item CPQ)' => DB::connection($conn)->table('crm_quotes')
        ->where('sub_institute_id', 6)->whereNull('deleted_at')->count(),
    '8. Quote Line Items' => DB::connection($conn)->table('crm_quote_line_items')
        ->join('crm_quotes', 'crm_quotes.id', '=', 'crm_quote_line_items.quote_id')
        ->where('crm_quotes.sub_institute_id', 6)->count(),
    '9. Sales Orders' => DB::connection($conn)->table('crm_sales_orders')
        ->where('sub_institute_id', 6)->whereNull('deleted_at')->count(),
    '9. Sales Order Line Items' => DB::connection($conn)->table('crm_sales_order_line_items')
        ->join('crm_sales_orders', 'crm_sales_orders.id', '=', 'crm_sales_order_line_items.sales_order_id')
        ->where('crm_sales_orders.sub_institute_id', 6)->count(),
    '9. Invoices' => DB::connection($conn)->table('crm_invoices')
        ->where('sub_institute_id', 6)->whereNull('deleted_at')->count(),
    '9. Invoice Line Items' => DB::connection($conn)->table('crm_invoice_line_items')
        ->join('crm_invoices', 'crm_invoices.id', '=', 'crm_invoice_line_items.invoice_id')
        ->where('crm_invoices.sub_institute_id', 6)->count(),
    '9. Invoice Payments (Settlements)' => DB::connection($conn)->table('crm_invoice_payments')
        ->join('crm_invoices', 'crm_invoices.id', '=', 'crm_invoice_payments.invoice_id')
        ->where('crm_invoices.sub_institute_id', 6)->count(),
    '10. Market Signals (Signal Inbox)' => DB::connection($conn)->table('crm_signal_inbox')
        ->where('sub_institute_id', 6)->count(),
    '10. Signal Topics (Monitoring)' => DB::connection($conn)->table('crm_signal_topics')
        ->where('sub_institute_id', 6)->count(),
];

echo "================ CRM SUBMODULE COUNTS FOR TENANT 6 ================\n";
foreach ($counts as $k => $v) {
    printf("%-40s : %d\n", $k, $v);
}

echo "\n================ PIPELINE STAGES DISTRIBUTION ================\n";
$stages = DB::connection($conn)->table('crm_opportunities')
    ->where('sub_institute_id', 6)
    ->whereNull('deleted_at')
    ->groupBy('sales_stage')
    ->select('sales_stage', DB::raw('count(*) as count'), DB::raw('sum(amount) as total_amount'))
    ->get();

foreach ($stages as $s) {
    printf("%-30s : %2d deals (₹%12.2f)\n", $s->sales_stage, $s->count, (float)$s->total_amount);
}

echo "\n================ FINANCIAL RECONCILIATION & VARIANCE AUDIT ================\n";
$finAudit = DB::connection($conn)->select("
    SELECT 
        so.id as so_id, 
        so.so_no,
        so.total as so_total, 
        inv.id as inv_id, 
        inv.invoice_no,
        inv.total as inv_total, 
        inv.amount_paid,
        (SELECT COUNT(*) FROM crm_sales_order_line_items WHERE sales_order_id = so.id) as so_items,
        (SELECT COUNT(*) FROM crm_invoice_line_items WHERE invoice_id = inv.id) as inv_items,
        p.amount as payment_amount,
        p.method,
        p.reference
    FROM crm_sales_orders so
    JOIN crm_invoices inv ON inv.sales_order_id = so.id
    LEFT JOIN crm_invoice_payments p ON p.invoice_id = inv.id
    WHERE so.sub_institute_id = 6
");

$zeroVariance = true;
foreach ($finAudit as $fa) {
    $diffSoInv = abs((float)$fa->so_total - (float)$fa->inv_total);
    $diffInvPaid = abs((float)$fa->inv_total - (float)$fa->amount_paid);
    $diffPayment = abs((float)$fa->inv_total - (float)$fa->payment_amount);
    
    printf("SO #%d (%s: ₹%.2f, %d items) <-> INV #%d (%s: ₹%.2f, %d items) | Paid: ₹%.2f | Payment: ₹%.2f (%s, %s)\n",
        $fa->so_id, $fa->so_no, (float)$fa->so_total, $fa->so_items,
        $fa->inv_id, $fa->invoice_no, (float)$fa->inv_total, $fa->inv_items,
        (float)$fa->amount_paid, (float)$fa->payment_amount, $fa->method, $fa->reference
    );
    
    if ($diffSoInv >= 0.001 || $diffInvPaid >= 0.001 || $diffPayment >= 0.001) {
        $zeroVariance = false;
        echo "  --> VARIANCE DETECTED!\n";
    }
}

if ($zeroVariance && count($finAudit) > 0) {
    echo ">> AUDIT SUCCESS: 100% ZERO-VARIANCE VERIFIED ACROSS ALL SALES ORDERS, INVOICES, AND PAYMENTS!\n";
}

echo "\n================ FIELD COMPLETENESS AUDIT ================\n";
$incompleteLeads = DB::connection($conn)->table('crm_leads')
    ->where('sub_institute_id', 6)
    ->whereNull('deleted_at')
    ->where(function($q) {
        $q->whereNull('first_name')
          ->orWhereNull('last_name')
          ->orWhereNull('company')
          ->orWhereNull('email')
          ->orWhereNull('phone')
          ->orWhereNull('mobile')
          ->orWhereNull('street')
          ->orWhereNull('city')
          ->orWhereNull('state')
          ->orWhereNull('postal_code')
          ->orWhereNull('website')
          ->orWhereNull('annual_revenue')
          ->orWhereNull('number_of_emp')
          ->orWhereNull('lead_source')
          ->orWhereNull('industry')
          ->orWhereNull('rating');
    })
    ->count();

$incompleteOrgs = DB::connection($conn)->table('crm_organizations')
    ->where('sub_institute_id', 6)
    ->whereNull('deleted_at')
    ->where(function($q) {
        $q->whereNull('name')
          ->orWhereNull('phone')
          ->orWhereNull('email')
          ->orWhereNull('website')
          ->orWhereNull('billing_street')
          ->orWhereNull('billing_city')
          ->orWhereNull('billing_state')
          ->orWhereNull('billing_code')
          ->orWhereNull('shipping_street')
          ->orWhereNull('shipping_city')
          ->orWhereNull('annual_revenue')
          ->orWhereNull('employees')
          ->orWhereNull('ownership')
          ->orWhereNull('industry')
          ->orWhereNull('account_type');
    })
    ->count();

$incompleteContacts = DB::connection($conn)->table('crm_contacts')
    ->where('sub_institute_id', 6)
    ->whereNull('deleted_at')
    ->where(function($q) {
        $q->whereNull('first_name')
          ->orWhereNull('last_name')
          ->orWhereNull('title')
          ->orWhereNull('department')
          ->orWhereNull('email')
          ->orWhereNull('phone')
          ->orWhereNull('mobile')
          ->orWhereNull('mailing_street')
          ->orWhereNull('mailing_city')
          ->orWhereNull('consent_status');
    })
    ->count();

printf("Incomplete Leads: %d\n", $incompleteLeads);
printf("Incomplete Organizations: %d\n", $incompleteOrgs);
printf("Incomplete Contacts: %d\n", $incompleteContacts);

if ($incompleteLeads === 0 && $incompleteOrgs === 0 && $incompleteContacts === 0) {
    echo ">> AUDIT SUCCESS: 100% FIELD COMPLETENESS CONFIRMED (0 NULL FIELDS)!\n";
}
