<?php
// Quick CRM module count checker for Tenant 6
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$t = 6;
$tables = [
    'crm_leads'       => ['sub_institute_id' => $t],
    'crm_organizations'=> ['sub_institute_id' => $t],
    'crm_contacts'    => ['sub_institute_id' => $t],
    'crm_opportunities'=> ['sub_institute_id' => $t],
    'crm_quotes'      => ['sub_institute_id' => $t],
    'crm_sales_orders'=> ['sub_institute_id' => $t],
    'crm_invoices'    => ['sub_institute_id' => $t],
    'crm_products'    => ['sub_institute_id' => $t],
    'crm_price_books' => ['sub_institute_id' => $t],
    'crm_campaigns'   => ['sub_institute_id' => $t],
    'crm_signal_inbox'=> ['sub_institute_id' => $t],
];

echo "\n=== Tenant {$t} CRM Module Counts ===\n";
foreach ($tables as $table => $where) {
    $q = DB::table($table);
    foreach ($where as $col => $val) {
        $q->where($col, $val);
    }
    // Try soft-delete aware
    try {
        $cnt = (clone $q)->whereNull('deleted_at')->count();
    } catch (\Throwable $e) {
        $cnt = $q->count();
    }
    printf("%-30s %d\n", $table . ':', $cnt);
}

// Check data quality issues
echo "\n=== Data Quality Checks ===\n";

// Empty billing_street on SOs
$emptyBillingSOs = DB::table('crm_sales_orders')
    ->where('sub_institute_id', $t)
    ->whereNull('deleted_at')
    ->where(function($q) {
        $q->whereNull('billing_street')->orWhere('billing_street', '');
    })->count();
echo "SOs with empty billing_street:  {$emptyBillingSOs}\n";

$emptyBillingINVs = DB::table('crm_invoices')
    ->where('sub_institute_id', $t)
    ->whereNull('deleted_at')
    ->where(function($q) {
        $q->whereNull('billing_street')->orWhere('billing_street', '');
    })->count();
echo "INVs with empty billing_street: {$emptyBillingINVs}\n";

// Contacts without org
$contactsNoOrg = DB::table('crm_contacts')
    ->where('sub_institute_id', $t)
    ->whereNull('deleted_at')
    ->whereNull('organization_id')
    ->count();
echo "Contacts without organization:  {$contactsNoOrg}\n";

// Leads without product_master_id
try {
    $leadsNoPM = DB::table('crm_leads')
        ->where('sub_institute_id', $t)
        ->whereNull('deleted_at')
        ->whereNull('product_master_id')
        ->count();
    echo "Leads without product_master_id:{$leadsNoPM}\n";
} catch (\Throwable $e) {
    echo "Leads PM check: column may not exist - " . $e->getMessage() . "\n";
}

// Opportunities by stage
echo "\n=== Opportunity Stage Distribution ===\n";
$stages = DB::table('crm_opportunities')
    ->where('sub_institute_id', $t)
    ->whereNull('deleted_at')
    ->selectRaw('sales_stage, COUNT(*) as cnt')
    ->groupBy('sales_stage')
    ->get();
foreach ($stages as $s) {
    printf("  %-30s %d\n", $s->sales_stage . ':', $s->cnt);
}

echo "\n=== DONE ===\n";
