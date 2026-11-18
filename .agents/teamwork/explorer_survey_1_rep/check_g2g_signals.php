<?php

require __DIR__ . '/../../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$cols = DB::select("SHOW COLUMNS FROM g2g_signals");
echo "=== COLUMNS OF g2g_signals ===\n";
foreach ($cols as $c) {
    echo sprintf("%-30s %-20s %-10s %-10s\n", $c->Field, $c->Type, $c->Null, $c->Key);
}
