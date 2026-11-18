<?php
// READ-ONLY: SHOW CREATE TABLE / SHOW COLUMNS of the tables named on the command line, and the migrations rows that match 2026_12_11_10.
// usage: php probe_show.php create|columns <table> [<table> ...]
chdir('C:/Users/MILAN/Downloads/hp_erp');
require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

DB::statement('SET SESSION TRANSACTION READ ONLY');
echo 'connection ', DB::getDefaultConnection(), ', database ', DB::selectOne('SELECT DATABASE() d')->d, ', server ', DB::selectOne('SELECT VERSION() v')->v, "\n";
$mode = $argv[1];
foreach (array_slice($argv, 2) as $table) {
    echo "\n== {$table} ({$mode})\n";
    if ($mode === 'create') {
        echo ((array) DB::selectOne("SHOW CREATE TABLE `{$table}`"))['Create Table'], "\n";
    } else {
        foreach (DB::select("SHOW COLUMNS FROM `{$table}`") as $c) {
            echo '  ', str_pad($c->Field, 34), str_pad($c->Type, 28), ' null=', $c->Null, ' key=', $c->Key, ' default=', var_export($c->Default, true), ' ', $c->Extra, "\n";
        }
    }
    echo 'rows: ', DB::table($table)->count(), "\n";
}
echo "\nmigrations rows for this slice:\n";
foreach (DB::select("SELECT migration, batch FROM migrations WHERE migration LIKE '2026_12_11_10%' ORDER BY id") as $m) {
    echo '  ', $m->migration, '  batch ', $m->batch, "\n";
}
