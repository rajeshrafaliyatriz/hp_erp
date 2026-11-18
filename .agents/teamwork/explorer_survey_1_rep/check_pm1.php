<?php

require __DIR__ . '/../../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$pm1 = DB::table('g2g_product_master')->where('id', 1)->first();
print_r($pm1);

$outreach = DB::table('gtm_outreach_messages')->where('sub_institute_id', 6)->get();
print_r($outreach);
