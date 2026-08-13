<?php
/**
 * X-01 (item 4b) — BACKUP BEFORE POPULATING THE RIGHTS MATRIX.
 *
 * Same shape as the G-NAV-01 template: backup first, then a restore script that
 * puts the rows back byte-for-byte.
 *
 * Writes two files into docs/phase3/_changes/:
 *   X-01-backup-tblgroupwise_rights_g2g-<date>.sql   full INSERT dump
 *   X-01-restore.sql                                  TRUNCATE + restore
 *
 * Run:  php docs/phase3/_changes/X-01-backup-rights.php
 */
require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require_once 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$TABLE = 'tblgroupwise_rights_g2g';
$DIR   = 'C:/Users/MILAN/Downloads/hp_erp/docs/phase3/_changes';
$stamp = date('Y-m-d');
$backup = "$DIR/X-01-backup-{$TABLE}-{$stamp}.sql";

$cols = collect(DB::select("SHOW COLUMNS FROM `$TABLE`"))->pluck('Field')->all();
$rows = DB::table($TABLE)->orderBy('id')->get();

$out = "-- X-01 backup of `$TABLE` taken {$stamp}\n"
     . "-- " . count($rows) . " rows. Restore with X-01-restore.sql\n"
     . "-- Columns: " . implode(', ', $cols) . "\n\n";

foreach ($rows as $r) {
    $vals = [];
    foreach ($cols as $c) {
        $v = $r->$c;
        $vals[] = $v === null ? 'NULL' : "'" . addslashes((string) $v) . "'";
    }
    $out .= "INSERT INTO `$TABLE` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $vals) . ");\n";
}
file_put_contents($backup, $out);

file_put_contents("$DIR/X-01-restore.sql",
    "-- X-01 ROLLBACK. Restores `$TABLE` to its pre-population state.\n"
  . "-- " . count($rows) . " rows, taken {$stamp}.\n"
  . "--\n"
  . "-- Usage:\n"
  . "--   mysql <db> < X-01-restore.sql\n"
  . "--   then source the backup file named below.\n"
  . "--\n"
  . "START TRANSACTION;\n"
  . "DELETE FROM `$TABLE`;\n"
  . "-- now source: " . basename($backup) . "\n"
  . "COMMIT;\n");

printf("backed up %d rows -> %s\n", count($rows), basename($backup));
printf("restore script    -> X-01-restore.sql\n");
printf("backup file bytes : %d\n", filesize($backup));
