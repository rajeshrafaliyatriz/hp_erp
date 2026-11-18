<?php
// READ-ONLY on LIVE (db3): can the v4 content (19 files, two new versions) be added for organisation 7?
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

chdir('C:/Users/MILAN/Downloads/hp_erp');
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$live = DB::connection('live');
$dev = DB::connection();
echo 'live host: ', $live->getConfig('host'), ' db ', $live->getDatabaseName(), ' | dev host: ', $dev->getConfig('host'), ' db ', $dev->getDatabaseName(), PHP_EOL;

echo PHP_EOL, '== table shape (columns on dev vs live)', PHP_EOL;
foreach (['g2g_simulation', 'g2g_simulation_version', 'g2g_simulation_asset', 'g2g_simulation_tenant', 'g2g_simulation_progress'] as $t) {
    $a = Schema::connection($dev->getName())->getColumnListing($t);
    $b = Schema::connection('live')->hasTable($t) ? Schema::connection('live')->getColumnListing($t) : null;
    if ($b === null) {
        echo "  $t: MISSING on live", PHP_EOL;
        continue;
    }
    echo "  $t: dev " . count($a) . ' cols, live ' . count($b) . ' cols; only on dev: ' . (implode(',', array_diff($a, $b)) ?: 'none') . '; only on live: ' . (implode(',', array_diff($b, $a)) ?: 'none'), PHP_EOL;
}

echo PHP_EOL, '== the sample files: registered on live?', PHP_EOL;
$seen = [];
foreach (['pendulum-lab', 'ramp-lab'] as $sample) {
    $dir = "D:/g2g-sim-runtime/samples/$sample";
    $manifest = json_decode(file_get_contents("$dir/manifest.json"), true);
    foreach ($manifest['assets'] as $a) {
        $sha = hash_file('sha256', "$dir/{$a['file']}");
        $row = $live->table('g2g_simulation_asset')->where('sha256', $sha)->get(['id', 'sub_institute_id', 'kind', 'deleted_at', 'name']);
        $where = $row->isEmpty() ? 'NOT registered' : $row->map(fn ($r) => "id {$r->id} owner " . var_export($r->sub_institute_id, true) . " {$r->kind}" . ($r->deleted_at ? ' (deleted)' : '') . " \"{$r->name}\"")->implode('; ');
        echo sprintf('  %-13s %-9s %-8s %s%s', $sample, $a['id'], substr($sha, 0, 10), $where, isset($seen[$sha]) ? '  (same file as above)' : ''), PHP_EOL;
        $seen[$sha] = true;
    }
}

echo PHP_EOL, '== simulations on live', PHP_EOL;
foreach ($live->table('g2g_simulation')->orderBy('id')->get(['id', 'sub_institute_id', 'slug', 'status', 'current_version', 'deleted_at']) as $s) {
    $versions = $live->table('g2g_simulation_version')->where('simulation_id', $s->id)->orderBy('version')->pluck('version')->implode(',');
    echo "  #{$s->id} org " . var_export($s->sub_institute_id, true) . " {$s->slug} {$s->status} current v{$s->current_version} (versions: $versions)" . ($s->deleted_at ? ' (removed)' : ''), PHP_EOL;
}
echo '  live asset rows: ', $live->table('g2g_simulation_asset')->whereNull('deleted_at')->count(), PHP_EOL;
