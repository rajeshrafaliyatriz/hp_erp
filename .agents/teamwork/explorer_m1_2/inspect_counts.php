<?php

require __DIR__ . '/../../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$tenantId = 6;
$tables = [
    'crm_signal_inbox',
    'g2g_org_accounts',
    'crm_organizations',
    'crm_contacts',
    'crm_leads',
    'gtm_accounts',
    'gtm_contacts',
    'gtm_outreach_messages'
];

foreach ($tables as $t) {
    $count = DB::table($t)->where('sub_institute_id', $tenantId)->count();
    $total = DB::table($t)->count();
    echo "{$t}: Tenant 6 = {$count}, Total = {$total}\n";
}
