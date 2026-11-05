<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$conn = 'mysql';

echo "=== CHECKING TENANT 6 CRM RECORD DETAILS ===\n";

$leadsNoProd = DB::connection($conn)->table('crm_leads')
    ->where('sub_institute_id', 6)
    ->whereNull('deleted_at')
    ->where(function($q) {
        $q->whereNull('product_master_id')
          ->orWhere('product_master_id', 0);
    })->get(['id', 'company', 'product_master_id', 'campaign_id']);

echo "Leads without product_master_id (" . count($leadsNoProd) . "):\n";
foreach ($leadsNoProd as $l) {
    echo "  Lead #{$l->id}: {$l->company} | PM: " . var_export($l->product_master_id, true) . " | Camp: " . var_export($l->campaign_id, true) . "\n";
}

$leadsNoCamp = DB::connection($conn)->table('crm_leads')
    ->where('sub_institute_id', 6)
    ->whereNull('deleted_at')
    ->where(function($q) {
        $q->whereNull('campaign_id')
          ->orWhere('campaign_id', 0);
    })->get(['id', 'company', 'product_master_id', 'campaign_id']);

echo "Leads without campaign_id (" . count($leadsNoCamp) . "):\n";
foreach ($leadsNoCamp as $l) {
    echo "  Lead #{$l->id}: {$l->company} | Camp: " . var_export($l->campaign_id, true) . "\n";
}

$contactsNoOrg = DB::connection($conn)->table('crm_contacts')
    ->where('sub_institute_id', 6)
    ->whereNull('deleted_at')
    ->where(function($q) {
        $q->whereNull('organization_id')
          ->orWhere('organization_id', '')
          ->orWhere('organization_id', 0);
    })->get(['id', 'first_name', 'last_name', 'organization_id']);

echo "Contacts without organization_id (" . count($contactsNoOrg) . "):\n";
foreach ($contactsNoOrg as $c) {
    echo "  Contact #{$c->id}: {$c->first_name} {$c->last_name} | Org: " . var_export($c->organization_id, true) . "\n";
}

$sos = DB::connection($conn)->table('crm_sales_orders')
    ->where('sub_institute_id', 6)
    ->whereNull('deleted_at')
    ->get();

echo "Sales Orders (" . count($sos) . "):\n";
foreach ($sos as $so) {
    echo "  SO #{$so->id}: {$so->so_no} | Org: {$so->organization_id} | Stage: '{$so->so_stage}' | Street: '{$so->billing_street}' | City: '{$so->billing_city}' | State: '{$so->billing_state}' | Code: '{$so->billing_code}' | Terms: " . (!empty($so->terms_conditions) ? 'YES' : 'NO') . " | Desc: " . (!empty($so->description) ? 'YES' : 'NO') . "\n";
}

$invs = DB::connection($conn)->table('crm_invoices')
    ->where('sub_institute_id', 6)
    ->whereNull('deleted_at')
    ->get();

echo "Invoices (" . count($invs) . "):\n";
foreach ($invs as $inv) {
    echo "  INV #{$inv->id}: {$inv->invoice_no} | SO: {$inv->sales_order_id} | Org: {$inv->organization_id} | Street: '{$inv->billing_street}' | City: '{$inv->billing_city}' | State: '{$inv->billing_state}' | Code: '{$inv->billing_code}' | Terms: " . (!empty($inv->terms_conditions) ? 'YES' : 'NO') . " | Desc: " . (!empty($inv->description) ? 'YES' : 'NO') . "\n";
}
