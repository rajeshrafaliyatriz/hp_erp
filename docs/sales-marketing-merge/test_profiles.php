<?php

require __DIR__ . '/../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Http\Controllers\user\tblmenumasterG2gController;

// Test dry-run: check what roles exist in tbluserprofilemaster
$profiles = DB::table('tbluserprofilemaster')
    ->select('id', 'name', 'role_key', 'sub_institute_id')
    ->limit(10)
    ->get();

echo "Found " . count($profiles) . " sample profiles." . PHP_EOL;
foreach ($profiles as $p) {
    echo "Profile: {$p->id} | {$p->name} | {$p->role_key} | Tenant: {$p->sub_institute_id}" . PHP_EOL;
}

