<?php

require __DIR__ . '/../../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Support\ColumnExists;

$cols = DB::select('SHOW COLUMNS FROM crm_signal_inbox');
echo "=== COLUMNS OF crm_signal_inbox ===\n";
foreach ($cols as $c) {
    echo sprintf("%-30s %-20s %-10s %-10s\n", $c->Field, $c->Type, $c->Null, $c->Key);
}

echo "\n=== ROW COUNT FOR TENANT 6 ===\n";
$cnt = DB::table('crm_signal_inbox')->where('sub_institute_id', 6)->count();
echo "Count for sub_institute_id=6: $cnt\n";

$allCnt = DB::table('crm_signal_inbox')->count();
echo "Total count: $allCnt\n";

if ($allCnt > 0) {
    echo "\n=== SAMPLE ROWS ===\n";
    $sample = DB::table('crm_signal_inbox')->limit(3)->get();
    print_r($sample);
}
