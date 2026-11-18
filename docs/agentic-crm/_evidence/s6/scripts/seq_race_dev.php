<?php
/*
 * Two real processes claiming the same key at the same moment on the DEV database (connection `mysql`, MariaDB), three ways. usage: php seq_race_dev.php
 * It writes only to g2g_number_sequences rows with the key prefix `zz_probe:` and deletes them at the end. It reads nothing else.
 */
chdir('C:/Users/MILAN/Downloads/hp_erp');
require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

if (DB::getDefaultConnection() !== 'mysql') {
    exit("not the dev connection\n");
}
echo 'database: ', DB::selectOne('SELECT DATABASE() d')->d, ' server ', DB::selectOne('SELECT VERSION() v')->v, "\n";
$worker = 'C:/Users/MILAN/AppData/Local/Temp/claude/c--Users-MILAN-Downloads-hp-erp/7e204d2f-9146-4ff7-82c9-07d5c31d4568/scratchpad/seq_race_worker.php';
$stamp = date('His');

foreach ([['claim', 40], ['claim-slow', 40], ['racy-slow', 40]] as [$mode, $claims]) {
    $key = "zz_probe:s6a:{$mode}:{$stamp}";
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'seqrace_' . uniqid();
    mkdir($dir);
    $start = $dir . DIRECTORY_SEPARATOR . 'go';
    $procs = [];
    foreach ([1, 2] as $n) {
        $pipes = [];
        $procs[$n] = [proc_open([PHP_BINARY, $worker, $key, (string) $claims, $mode, $start], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, 'C:/Users/MILAN/Downloads/hp_erp'), $pipes];
    }
    sleep(3);   // let both finish booting Laravel and reach the start line
    touch($start);
    $all = [];
    $per = [];
    foreach ($procs as $n => [$h, $pipes]) {
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        $code = proc_close($h);
        $got = array_values(array_filter(array_map('trim', explode("\n", $out)), 'strlen'));
        $per[$n] = count($got);
        if ($code !== 0 || count($got) !== $claims) {
            echo "  worker {$n} problem: exit {$code}, printed " . count($got) . ' ' . substr($err, 0, 300) . "\n";
        }
        $all = array_merge($all, array_map('intval', $got));
    }
    $dupes = count($all) - count(array_unique($all));
    sort($all);
    $final = DB::table('g2g_number_sequences')->where('seq_key', $key)->value('next_value');
    echo str_pad($mode, 11), ': 2 processes x ', $claims, ' claims; numbers handed out ', count($all), ', distinct ', count(array_unique($all)), ', DUPLICATES ', $dupes,
        '; lowest ', $all[0] ?? '-', ', highest ', end($all) ?: '-', '; counter now ', $final, "\n";
    DB::table('g2g_number_sequences')->where('seq_key', $key)->delete();
    @unlink($start);
    @rmdir($dir);
}
echo 'scratch rows left: ', DB::table('g2g_number_sequences')->where('seq_key', 'like', 'zz_probe:%')->count(), "\n";
echo 'total rows in g2g_number_sequences: ', DB::table('g2g_number_sequences')->count(), "\n";
