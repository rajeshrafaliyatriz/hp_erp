<?php

require __DIR__ . '/../../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$conn = 'mysql';

echo "=== COLUMNS crm_sales_order_line_items ===" . PHP_EOL;
$cols = DB::connection($conn)->select("SHOW COLUMNS FROM crm_sales_order_line_items");
foreach ($cols as $col) {
    echo $col->Field . " (" . $col->Type . ")" . PHP_EOL;
}

echo PHP_EOL . "=== COLUMNS crm_invoice_line_items ===" . PHP_EOL;
$cols2 = DB::connection($conn)->select("SHOW COLUMNS FROM crm_invoice_line_items");
foreach ($cols2 as $col) {
    echo $col->Field . " (" . $col->Type . ")" . PHP_EOL;
}
