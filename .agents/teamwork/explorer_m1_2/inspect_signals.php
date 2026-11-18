<?php

require __DIR__ . '/../../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$signals = DB::table('crm_signal_inbox')->get();
foreach ($signals as $s) {
    echo "ID: {$s->id} | Key: {$s->signal_key} | Source: {$s->source_name} | URL: {$s->source_url} | Title: {$s->title}\n";
}
