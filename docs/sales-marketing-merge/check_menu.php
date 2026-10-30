<?php

require __DIR__ . '/../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$rootId = DB::table('tblmenumaster_g2g')->where('access_link', '/module/crm')->value('id');
echo "Root ID: " . $rootId . PHP_EOL;

$children = DB::table('tblmenumaster_g2g')->where('parent_id', $rootId)->orderBy('sort_order')->get();
echo "--- Children of Root ({$rootId}) ---" . PHP_EOL;
foreach ($children as $c) {
    echo "ID: {$c->id} | Name: {$c->menu_name} | Link: {$c->access_link} | Level: {$c->level} | Sort: {$c->sort_order} | Status: {$c->status}" . PHP_EOL;
    $grandChildren = DB::table('tblmenumaster_g2g')->where('parent_id', $c->id)->orderBy('sort_order')->get();
    foreach ($grandChildren as $gc) {
        echo "   -> ID: {$gc->id} | Name: {$gc->menu_name} | Link: {$gc->access_link} | Sort: {$gc->sort_order} | Status: {$gc->status}" . PHP_EOL;
    }
}

