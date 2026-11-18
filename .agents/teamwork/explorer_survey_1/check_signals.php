<?php
require __DIR__ . '/../../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$count = DB::table('crm_signal_inbox')->count();
echo "Total rows in crm_signal_inbox: $count\n";

$rows = DB::table('crm_signal_inbox')->take(5)->get();
foreach ($rows as $r) {
    echo "Row: " . json_encode($r) . "\n";
}

$tenant6Count = DB::table('crm_signal_inbox')->where('sub_institute_id', 6)->count();
echo "Tenant 6 rows in crm_signal_inbox: $tenant6Count\n";
