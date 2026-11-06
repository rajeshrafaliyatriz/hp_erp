<?php

require __DIR__ . '/../../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$tables = [
    'crm_signal_inbox',
    'g2g_org_accounts',
    'crm_organizations',
    'crm_contacts',
    'crm_leads',
    'gtm_outreach_messages'
];

$specs = [];
foreach ($tables as $t) {
    $cols = DB::select("SHOW FULL COLUMNS FROM `$t`");
    $specs[$t] = array_map(function($c) {
        return [
            'Field' => $c->Field,
            'Type' => $c->Type,
            'Null' => $c->Null,
            'Key' => $c->Key,
            'Default' => $c->Default,
            'Extra' => $c->Extra,
        ];
    }, $cols);
}

file_put_contents(__DIR__ . '/table_specs.json', json_encode($specs, JSON_PRETTY_PRINT));
echo "Saved table specs to table_specs.json\n";
