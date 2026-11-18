<?php
// READ-ONLY. For every registered asset on LIVE: is the object on the bucket the file its row says (sha256 of the whole object, streamed), and how big is it?
use App\Services\Lms\SimulationStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

chdir('C:/Users/MILAN/Downloads/hp_erp');
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$live = DB::connection('live');
$disk = Storage::disk(SimulationStore::ASSET_DISK);
echo 'disk driver config (no secrets): bucket=', config('filesystems.disks.' . SimulationStore::ASSET_DISK . '.bucket'), ' endpoint=', config('filesystems.disks.' . SimulationStore::ASSET_DISK . '.endpoint'), ' root=', var_export(config('filesystems.disks.' . SimulationStore::ASSET_DISK . '.root'), true), PHP_EOL;
$cols = array_map(fn ($c) => $c->Field, $live->select('SHOW COLUMNS FROM g2g_simulation_asset'));
echo 'columns: ', implode(',', $cols), PHP_EOL, PHP_EOL;
$bad = 0;
$t0 = microtime(true);
foreach ($live->table('g2g_simulation_asset')->whereNull('deleted_at')->orderBy('id')->get() as $r) {
    $a = (array) $r;
    $dbSize = $a['bytes'] ?? $a['size_bytes'] ?? $a['size'] ?? null;
    try {
        $exists = $disk->exists($r->storage_path);
        $size = $exists ? $disk->size($r->storage_path) : null;
        $sha = null;
        if ($exists) {
            $s = $disk->readStream($r->storage_path);
            $ctx = hash_init('sha256');
            $n = 0;
            while (is_resource($s) && ! feof($s)) {
                $chunk = fread($s, 1 << 20);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                hash_update($ctx, $chunk);
                $n += strlen($chunk);
            }
            is_resource($s) && fclose($s);
            $sha = hash_final($ctx);
        }
        $ok = $exists && $sha === $r->sha256 && ($dbSize === null || (int) $dbSize === (int) $size);
        $bad += $ok ? 0 : 1;
        echo sprintf("#%-3d %-7s %-9s %9s B on bucket, row says %9s B, read %9s B, sha %s %s", $r->id, $r->kind, var_export($r->sub_institute_id, true), $size ?? '-', $dbSize ?? '?', $n ?? '-', $sha === null ? 'n/a' : ($sha === $r->sha256 ? 'MATCHES' : 'DIFFERENT'), $ok ? '' : '  <-- PROBLEM'), ' ', mb_substr((string) $r->name, 0, 28), PHP_EOL;
    } catch (\Throwable $e) {
        $bad++;
        echo "#{$r->id} ERROR ", $e->getMessage(), PHP_EOL;
    }
}
echo PHP_EOL, $bad === 0 ? 'ALL OBJECTS MATCH THEIR ROWS' : "PROBLEMS: $bad", sprintf(' (%.0f s)', microtime(true) - $t0), PHP_EOL;
