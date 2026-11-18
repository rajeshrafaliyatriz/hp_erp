<?php

require __DIR__ . '/../../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Support\ColumnExists;

$checks = [
    ['crm_signal_inbox', 'signal_key', true],
    ['crm_signal_inbox', 'non_existent_column', false],
    ['gtm_outreach_messages', 'product_master_id', true],
    ['gtm_outreach_messages', 'lead_id', false],
    ['g2g_org_accounts', 'crm_organization_id', true],
    ['crm_organizations', 'linkedin_url', true],
    ['crm_contacts', 'reports_to_id', true],
    ['crm_leads', 'lead_score_basis', true],
    ['gtm_accounts', 'icp_fit_score', true],
    ['gtm_contacts', 'role_in_deal', true],
];

foreach ($checks as [$table, $col, $expected]) {
    $actual = ColumnExists::has($table, $col);
    $status = ($actual === $expected) ? 'OK' : 'MISMATCH';
    echo "{$table}.{$col} => actual: " . ($actual ? 'true' : 'false') . ", expected: " . ($expected ? 'true' : 'false') . " [{$status}]\n";
}
