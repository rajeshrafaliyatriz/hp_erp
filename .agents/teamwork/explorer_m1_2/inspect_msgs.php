<?php

require __DIR__ . '/../../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$msgs = DB::table('gtm_outreach_messages')->get();
foreach ($msgs as $m) {
    echo "ID: {$m->id} | SubInst: {$m->sub_institute_id} | Account: {$m->account_id} | Contact: {$m->contact_id} | Status: {$m->status} | Subject: {$m->subject}\n";
}
