<?php

require __DIR__ . '/../../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$signals = DB::table('crm_signal_inbox')
    ->where('sub_institute_id', 6)
    ->get(['id', 'signal_key', 'title', 'source_url', 'classification', 'relevance_score', 'status']);

foreach ($signals as $s) {
    echo "ID: {$s->id} | Key: " . substr($s->signal_key, 0, 16) . "... | Title: {$s->title} | Classification: {$s->classification} | Score: {$s->relevance_score} | Status: {$s->status}\n";
    echo "   URL: {$s->source_url}\n";
}
