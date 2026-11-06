<?php
require __DIR__ . '/../../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

foreach (['g2g_org_accounts', 'crm_contacts'] as $tbl) {
    echo "=== TABLE: $tbl ===\n";
    $cols = DB::select("DESCRIBE $tbl");
    foreach ($cols as $c) {
        echo "  {$c->Field} | {$c->Type} | Null:{$c->Null} | Key:{$c->Key} | Default:{$c->Default}\n";
    }
}
