<?php
// READ-ONLY, on LIVE (db3) and the shared bucket, after the fourth rollout. For every simulation, for the version that is played AND every kept (newer) version:
// every file its specification names has a registered row that the simulation's organisation can reach, the file is on the bucket, its first bytes are what
// the row says it is, and the bytes the specification was stored with are the ones tested on dev.
use App\Services\Lms\SimulationStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

chdir('C:/Users/MILAN/Downloads/hp_erp');
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$dev = DB::connection();                       // the application host: where Pendulum Lab and Ramp Lab were built and tested
DB::setDefaultConnection('live');
$live = DB::connection('live');
$store = new SimulationStore();
$disk = Storage::disk(SimulationStore::ASSET_DISK);
$bad = 0;
$devTested = ['pendulum-lab' => 11, 'ramp-lab' => 12];

echo 'live ', $live->getConfig('host'), ' / ', $live->getDatabaseName(), '; asset rows (not removed): ', $live->table('g2g_simulation_asset')->whereNull('deleted_at')->count(), PHP_EOL, PHP_EOL;
foreach ($live->table('g2g_simulation')->whereNull('deleted_at')->orderBy('id')->get() as $sim) {
    $versions = $live->table('g2g_simulation_version')->where('simulation_id', $sim->id)->orderBy('version')->get(['version', 'sha256', 'note']);
    echo sprintf('#%d org %s %s (%s) plays v%d of [%s]', $sim->id, var_export($sim->sub_institute_id, true), $sim->slug, $sim->status, $sim->current_version, $versions->pluck('version')->implode(',')), PHP_EOL;
    foreach ($versions as $v) {
        if ($v->version < $sim->current_version) {
            continue;   // older versions are history, not served
        }
        $json = $store->specJson($sim, (int) $v->version);
        $hashes = $store->assetHashes(json_decode($json));
        $rows = $hashes === [] ? collect() : $store->assetRows($sim, $hashes);
        $missingRow = array_values(array_filter($hashes, fn ($h) => ! $rows->has($h)));
        $onDisk = 0;
        $wrong = [];
        foreach ($rows as $h => $row) {
            if (! $disk->exists($row->storage_path)) {
                $wrong[] = substr($h, 0, 10) . ' not on the bucket';
                continue;
            }
            $s = $disk->readStream($row->storage_path);
            $head = is_resource($s) ? (string) fread($s, 64) : '';
            is_resource($s) && fclose($s);
            $type = $store->assetType($head);
            if ($type === null || $type['kind'] !== $row->kind || $type['mime'] !== $row->mime) {
                $wrong[] = substr($h, 0, 10) . " first bytes say " . json_encode($type['kind'] ?? null) . ", row says {$row->kind} {$row->mime}";
                continue;
            }
            $onDisk++;
        }
        $line = sprintf('   v%d%s: %d named, %d registered and reachable by org %s, %d on the bucket with the right first bytes, %d missing%s',
            $v->version, $v->version == $sim->current_version ? ' (played)' : ' (kept)', count($hashes), $rows->count(), var_export($sim->sub_institute_id, true), $onDisk, count($missingRow) + count($wrong),
            $missingRow || $wrong ? '  <-- ' . implode('; ', array_merge(array_map(fn ($h) => substr($h, 0, 10) . ' no row', $missingRow), $wrong)) : '');
        echo $line, PHP_EOL;
        $bad += count($missingRow) + count($wrong);
        if (isset($devTested[$sim->slug]) && $v->version > 1) {
            $devSha = $dev->table('g2g_simulation_version')->where('simulation_id', $devTested[$sim->slug])->orderByDesc('version')->value('sha256');
            $same = $devSha === $v->sha256;
            echo sprintf('        stored sha256 %s  tested on dev %s  => %s', substr($v->sha256, 0, 16), substr((string) $devSha, 0, 16), $same ? 'IDENTICAL' : 'DIFFERENT'), PHP_EOL;
            $bad += $same ? 0 : 1;
        }
    }
}
echo PHP_EOL, $bad === 0 ? 'INTEGRITY OK' : "PROBLEMS: $bad", PHP_EOL;
