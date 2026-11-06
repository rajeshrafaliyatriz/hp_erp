<?php
require __DIR__ . '/../../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$tablesToCheck = ['crm_signal_inbox', 'g2g_signals', 'crm_organizations', 'g2g_org_accounts', 'crm_contacts', 'crm_leads', 'gtm_outreach_messages'];

foreach ($tablesToCheck as $tbl) {
    echo "=== TABLE: $tbl ===\n";
    try {
        $cols = DB::select("DESCRIBE $tbl");
        foreach ($cols as $c) {
            echo "  {$c->Field} | {$c->Type} | Null:{$c->Null} | Key:{$c->Key} | Default:{$c->Default}\n";
        }
    } catch (\Throwable $e) {
        echo "  Table error: " . $e->getMessage() . "\n";
    }
}
