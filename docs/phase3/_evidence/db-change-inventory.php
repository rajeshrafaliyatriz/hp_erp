<?php
/**
 * WHAT ACTUALLY CHANGED IN THE SHARED DATABASE IN THE LAST TWO WEEKS.
 *
 * A COMMITTED MIGRATION IS NOT A CHANGED DATABASE. The git log says what was
 * written; the `migrations` table says what was RUN. Where they disagree, the
 * database is the authority - the artefact describes the code, the run describes
 * the behaviour.
 *
 * READ-ONLY. This script writes nothing.
 */
require __DIR__ . '/../../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "\nHOST " . config('database.connections.mysql.host')
   . "   DB " . config('database.connections.mysql.database') . "\n";

echo "\n=== 1. MIGRATIONS THAT RAN, by batch ===\n";
$ran = DB::table('migrations')->where('migration', '>=', '2026_07_29')->orderBy('id')->get();
$byBatch = [];
foreach ($ran as $r) { $byBatch[$r->batch][] = $r->migration; }
foreach ($byBatch as $batch => $list) {
    printf("  batch %-4s %d migration(s)\n", $batch, count($list));
    foreach ($list as $m) { echo "        $m\n"; }
}
printf("  TOTAL RAN (>= 2026_07_29): %d\n", count($ran));

echo "\n=== 2. WRITTEN BUT NOT RUN ===\n";
$files = glob(__DIR__ . '/../../../database/migrations/2026_0[78]_*.php');
$onDisk = array_map(fn ($p) => str_replace('.php', '', basename($p)), $files);
$ranNames = $ran->pluck('migration')->all();
$pending = array_values(array_diff($onDisk, $ranNames));
if ($pending) {
    foreach ($pending as $p) { echo "  PENDING  $p\n"; }
    echo "  -> these exist in git and NOT in this database.\n";
} else {
    echo "  none - every migration written since 2026-07-29 has run here.\n";
}

echo "\n=== 3. TABLES CREATED, AND WHAT IS IN THEM ===\n";
echo "    A created table is structure. A POPULATED one is a data change.\n";
$tables = [
    'g2g_event', 'g2g_audit_log', 'task_status_history', 'competency_kasba_rating',
    'tenant_readiness_gate', 'course_competency_map', 'jobrole_competency_map',
    'jobrole_task_competency_map', 'competency_kasba_item', 'tblgroupwise_rights_g2g',
    'task_option_sets', 'competency_approvals',
];
foreach ($tables as $t) {
    try {
        $n = DB::table($t)->count();
        printf("  %-32s %8d row(s)%s\n", $t, $n, $n === 0 ? '   (structure only)' : '');
    } catch (\Throwable $e) {
        printf("  %-32s   ABSENT\n", $t);
    }
}

echo "\n=== 4. DATA WRITES MADE BY _changes SCRIPTS ===\n";
echo "    These are rows we PUT THERE, not schema. This is the part a backup\n";
echo "    restores and a migration does not.\n";
try {
    $menus = DB::table('tblmenumaster_g2g')->whereIn('id', [224,225,226,227,228,229])
        ->orderBy('id')->get(['id','menu_name']);
    foreach ($menus as $m) { printf("  menu %-4s %s\n", $m->id, $m->menu_name); }
    printf("  menus 224-229 present: %d of 6\n", count($menus));
} catch (\Throwable $e) { echo "  menu table read failed: " . $e->getMessage() . "\n"; }

try {
    $rights = DB::table('tblgroupwise_rights_g2g')->whereIn('menu_id', [224,225,226,227,228,229])
        ->selectRaw('menu_id, COUNT(*) n')->groupBy('menu_id')->orderBy('menu_id')->get();
    foreach ($rights as $r) { printf("  rights rows for menu %-4s %d\n", $r->menu_id, $r->n); }
} catch (\Throwable $e) { echo "  rights read failed: " . $e->getMessage() . "\n"; }

echo "\n=== 5. THE BACKFILL — the largest single data change ===\n";
try {
    $keyed = DB::table('s_user_jobrole_task')->whereNotNull('catalogue_task_id')->count();
    $null  = DB::table('s_user_jobrole_task')->whereNull('catalogue_task_id')->count();
    printf("  s_user_jobrole_task.catalogue_task_id keyed %d   NULL %d   total %d\n", $keyed, $null, $keyed + $null);
} catch (\Throwable $e) { echo "  catalogue_task_id ABSENT from task: " . $e->getMessage() . "\n"; }

echo "\n=== 6. PERSONAL ACCESS TOKENS — did our proofs leave any behind? ===\n";
try {
    $left = DB::table('personal_access_tokens')->where('name', 'like', '%proof%')
        ->orWhere('name', 'like', '%g2g-sec%')->count();
    printf("  proof tokens still present: %d  %s\n", $left, $left === 0 ? '(all cleaned up)' : '*** LEFTOVER ***');
} catch (\Throwable $e) { echo "  token read failed\n"; }

echo "\nREAD-ONLY. Nothing in this script writes.\n";
