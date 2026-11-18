<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$opps = DB::connection('mysql')->table('crm_opportunities')
    ->where('sub_institute_id', 6)
    ->whereNull('deleted_at')
    ->orderBy('sales_stage')
    ->get();

foreach ($opps as $o) {
    echo sprintf(
        "[%-25s] %-40s | Amt: ₹%9.2f | Prob: %3d%% | FC: %-9s | Date: %s | Next: %s\n",
        $o->sales_stage,
        substr($o->name, 0, 40),
        (float) $o->amount,
        (int) $o->probability,
        $o->forecast_category,
        $o->closing_date,
        substr($o->next_step, 0, 50)
    );
}
