<?php

require __DIR__ . '/../../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$tables = ['g2g_org_accounts', 'crm_organizations', 'crm_contacts', 'crm_leads', 'gtm_outreach_messages', 'g2g_product_master'];

foreach ($tables as $t) {
    echo "================================= TABLE $t =================================\n";
    $has = DB::select("SHOW TABLES LIKE '$t'");
    if (empty($has)) {
        echo "TABLE DOES NOT EXIST\n";
        continue;
    }
    $cols = DB::select("SHOW COLUMNS FROM $t");
    foreach ($cols as $c) {
        echo sprintf("%-30s %-20s %-10s %-10s\n", $c->Field, $c->Type, $c->Null, $c->Key);
    }
    
    // Check Tenant 6 row count
    $colNames = array_map(fn($c) => $c->Field, $cols);
    $tenantCol = in_array('sub_institute_id', $colNames) ? 'sub_institute_id' : (in_array('tenant_id', $colNames) ? 'tenant_id' : null);
    if ($tenantCol) {
        $count6 = DB::table($t)->where($tenantCol, 6)->count();
        echo "Tenant 6 ($tenantCol=6) count: $count6\n";
    }
}
