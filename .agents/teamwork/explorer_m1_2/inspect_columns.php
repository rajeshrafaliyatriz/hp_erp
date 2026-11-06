<?php

require __DIR__ . '/../../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Support\ColumnExists;

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
    echo "=== TABLE {$t} ===\n";
    $cols = ColumnExists::all($t);
    echo implode(', ', $cols) . "\n\n";
}
