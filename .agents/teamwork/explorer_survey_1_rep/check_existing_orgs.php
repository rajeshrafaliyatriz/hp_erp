<?php

require __DIR__ . '/../../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$searches = ['VN Softverg', 'Creuto', 'Anjuli', 'Gargi', 'Kathmandu'];

foreach ($searches as $s) {
    $org = DB::table('crm_organizations')
        ->where('sub_institute_id', 6)
        ->where('name', 'LIKE', "%$s%")
        ->get();
    echo "crm_organizations matching '$s': " . count($org) . "\n";
    
    $g2g = DB::table('g2g_org_accounts')
        ->where('sub_institute_id', 6)
        ->where('name', 'LIKE', "%$s%")
        ->get();
    echo "g2g_org_accounts matching '$s': " . count($g2g) . "\n";
}
