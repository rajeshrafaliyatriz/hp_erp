<?php
/**
 * Full backup of tblmenumaster_g2g as replayable INSERTs, before any nav change.
 * Written as a file (not a bash -r one-liner) because backticks in the SQL are
 * command substitution to the shell, which silently corrupts the output.
 */
require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require_once 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$rows = DB::table('tblmenumaster_g2g')->orderBy('id')->get();
$bt = chr(96); // backtick

$out  = "-- Backup of tblmenumaster_g2g\n";
$out .= "-- Taken: " . date('Y-m-d H:i:s') . "\n";
$out .= "-- Rows:  " . count($rows) . "\n";
$out .= "--\n-- FULL RESTORE:\n";
$out .= "--   START TRANSACTION;\n";
$out .= "--   DELETE FROM {$bt}tblmenumaster_g2g{$bt};\n";
$out .= "--   <replay every INSERT below>\n";
$out .= "--   COMMIT;\n\n";

foreach ($rows as $r) {
    $cols = array_keys((array) $r);
    $vals = [];
    foreach ((array) $r as $v) {
        $vals[] = is_null($v) ? 'NULL' : "'" . addslashes((string) $v) . "'";
    }
    $out .= 'INSERT INTO ' . $bt . 'tblmenumaster_g2g' . $bt . ' ('
          . $bt . implode($bt . ',' . $bt, $cols) . $bt . ') VALUES ('
          . implode(',', $vals) . ");\n";
}

$path = 'C:/Users/MILAN/Downloads/hp_erp/docs/phase3/_changes/backup-tblmenumaster_g2g-2026-08-05.sql';
file_put_contents($path, $out);

printf("backup written: %d rows, %d bytes\n", count($rows), strlen($out));
printf("verify: %d INSERT statements\n", substr_count($out, 'INSERT INTO'));
