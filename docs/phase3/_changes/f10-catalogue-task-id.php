<?php
/**
 * F-10 — `s_user_jobrole_task.catalogue_task_id`, the capability chain's last
 * name-join turned into a key.
 *
 * TIME-SENSITIVE, AND THAT IS WHY IT IS FIRST. The backfill is only possible
 * while the titles still resolve. Every tenant edit between the measurement and
 * the migration is a row whose origin can no longer be recovered — the title
 * correspondence is the ONLY record that the tenant copy came from the catalogue
 * at all.
 *
 * THREE PHASES, and by default this script runs the first two and STOPS:
 *
 *   --add       add the column, nullable, unread. No data changes.
 *   (default)   MEASURE the resolve rate AT THIS MOMENT and print the unmatched
 *               report. Writes nothing.
 *   --backfill  write the ids. Only after the unmatched report has been seen.
 *
 * THE RATE IS RE-MEASURED, NEVER RE-USED. An earlier run measured 100% across a
 * 22,629-row sample. If it has fallen since, THE DIFFERENCE IS ROWS THAT HAVE
 * BEEN EDITED, and that is itself the finding.
 *
 * HELD, NEVER GUESSED (F-07b's rule): anything that does not resolve keeps its
 * text and a NULL id. Nothing is dropped; the title column stays.
 */
require __DIR__ . '/../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

$doAdd      = in_array('--add', $argv, true);
$doBackfill = in_array('--backfill', $argv, true);

$norm = fn ($s) => mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $s)));

/* ---- PHASE 1: the column ------------------------------------------------ */
$hasCol = Schema::hasColumn('s_user_jobrole_task', 'catalogue_task_id');
printf("column catalogue_task_id exists : %s\n", $hasCol ? 'yes' : 'no');

if ($doAdd && !$hasCol) {
    Schema::table('s_user_jobrole_task', function (Blueprint $t) {
        // NULLABLE AND UNINDEXED-BY-DEFAULT on purpose. A NOT NULL column would
        // demand a value for rows that may never resolve, and F-07b's ruling is
        // that unmatched is HELD, not invented. No foreign key either: the
        // catalogue is global and the tenant table is not, so a hard constraint
        // would couple two tables with different lifecycles.
        $t->unsignedBigInteger('catalogue_task_id')->nullable()->after('id');
        $t->index('catalogue_task_id', 'idx_ujt_catalogue_task');
    });
    $hasCol = true;
    echo "COLUMN ADDED (nullable, indexed, no FK)\n";
} elseif ($doAdd) {
    echo "column already present - nothing added\n";
}

/* ---- PHASE 2: MEASURE NOW, and print the unmatched report --------------- */
echo "\n=== RESOLVE RATE AT THIS MOMENT (not the rate measured earlier) ===\n";

$cat = [];
foreach (DB::table('s_jobrole_task')->select('id', 'task')->whereNotNull('task')->cursor() as $r) {
    $k = $norm($r->task);
    if ($k === '') continue;
    // AMBIGUITY IS NOT A MATCH. If two catalogue rows share a title, a tenant row
    // carrying that title cannot be attributed to either, and inventing one would
    // be exactly the guess F-07b forbids.
    if (isset($cat[$k])) { $cat[$k] = false; continue; }
    $cat[$k] = (int) $r->id;
}
$ambiguous = count(array_filter($cat, fn ($v) => $v === false));
printf("catalogue titles           : %d   of which AMBIGUOUS (shared by 2+ rows): %d\n",
    count($cat), $ambiguous);

$total = $resolved = $unresolved = 0;
$missByTenant = [];
$samples = [];
foreach (DB::table('s_user_jobrole_task')
    ->select('id', 'sub_institute_id', 'task')
    ->whereNotNull('task')->where('task', '!=', '')->cursor() as $r) {
    $total++;
    $k = $norm($r->task);
    $hit = $cat[$k] ?? null;
    if (is_int($hit)) { $resolved++; continue; }
    $unresolved++;
    $missByTenant[$r->sub_institute_id] = ($missByTenant[$r->sub_institute_id] ?? 0) + 1;
    if (count($samples) < 8) {
        $samples[] = sprintf('tenant %-6s #%-8s %s%s',
            $r->sub_institute_id, $r->id, mb_substr((string) $r->task, 0, 44),
            isset($cat[$k]) ? '   [AMBIGUOUS TITLE]' : '');
    }
}

printf("\ntenant task rows           : %d\n", $total);
printf("  RESOLVE to one catalogue row : %d   (%.4f%%)\n", $resolved, $total ? 100 * $resolved / $total : 0);
printf("  DO NOT RESOLVE               : %d\n", $unresolved);

if ($unresolved) {
    echo "\n--- UNMATCHED REPORT, by tenant ---\n";
    arsort($missByTenant);
    foreach ($missByTenant as $t => $n) printf("   tenant %-8s %d row(s)\n", $t, $n);
    echo "\n--- samples ---\n";
    foreach ($samples as $x) echo "   $x\n";
    echo "\nTHESE KEEP THEIR TEXT AND A NULL id. Nothing is dropped.\n";
} else {
    echo "\nUNMATCHED REPORT: EMPTY. Every tenant task row resolves to exactly one\n";
    echo "catalogue row, so no row loses its origin in this migration.\n";
}

/* ---- PHASE 3: the write, only on demand -------------------------------- */
if (!$doBackfill) {
    echo "\nNOTHING WRITTEN. Pass --backfill to write the ids, after the unmatched\n";
    echo "report above has been seen.\n";
    exit(0);
}
if (!$hasCol) { echo "\nREFUSING: the column does not exist. Run with --add first.\n"; exit(1); }

$before = DB::table('s_user_jobrole_task')->whereNotNull('catalogue_task_id')->count();
$written = 0;
DB::table('s_user_jobrole_task')
    ->whereNull('catalogue_task_id')->whereNotNull('task')->where('task', '!=', '')
    ->orderBy('id')
    ->chunkById(2000, function ($rows) use (&$written, $cat, $norm) {
        foreach ($rows as $r) {
            $hit = $cat[$norm($r->task)] ?? null;
            if (!is_int($hit)) continue;                 // HELD, not guessed
            DB::table('s_user_jobrole_task')->where('id', $r->id)
                ->update(['catalogue_task_id' => $hit]);
            $written++;
        }
    });
$after = DB::table('s_user_jobrole_task')->whereNotNull('catalogue_task_id')->count();

printf("\nBACKFILL: wrote %d id(s).  populated %d -> %d of %d rows\n", $written, $before, $after, $total);
printf("TO REVERSE:  UPDATE s_user_jobrole_task SET catalogue_task_id = NULL;\n");
printf("             ALTER TABLE s_user_jobrole_task DROP COLUMN catalogue_task_id;\n");
