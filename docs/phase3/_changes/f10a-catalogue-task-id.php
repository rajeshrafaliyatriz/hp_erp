<?php
/**
 * F-10a — `s_user_jobrole_task.catalogue_task_id`, keyed on (jobrole, task).
 *
 * ── THE ~93.6% CEILING IS THE CATALOGUE'S OWN AMBIGUITY ──────────────────
 *
 * NOT a shortfall of this backfill. `s_jobrole_task` contains 3,127 DUPLICATE
 * (jobrole, task) pairs — two or more catalogue rows identical on both columns.
 * A tenant row carrying such a pair cannot be attributed to either, and inventing
 * an attribution would be exactly the guess F-07b forbids.
 *
 * A later reader seeing 93.6% must NOT go looking for the missing 6%: it is
 * filed as F-10b, a question about what those duplicates ARE, and it caps any key
 * drawn from these columns until it is answered.
 *
 * ── NULL IS A CORRECT ANSWER FOR 126 ROWS ────────────────────────────────
 *
 * 126 rows have a (jobrole, task) pair absent from the catalogue entirely. Every
 * one sampled is tenant 4, Medical/Surgical Gastroenterology — A TENANT THAT
 * AUTHORED ITS OWN TASKS rather than taking them from the catalogue.
 *
 * THEY HAVE NO CATALOGUE ORIGIN TO RECORD. NULL is the truth about them, not a
 * failure to be fixed. DO NOT "REPAIR" THESE ROWS.
 *
 * ── NOT TIME-SENSITIVE ───────────────────────────────────────────────────
 *
 * This item was marked TIME-SENSITIVE on a theory that tenant edits erode the
 * title correspondence. MEASUREMENT DISPROVED IT: the non-matching rows are one
 * tenant's own authoring and would not have matched on day one either. Nothing
 * here is decaying.
 *
 * ── PHASES ───────────────────────────────────────────────────────────────
 *   --add        add the column (nullable, indexed, no FK). No data changes.
 *   (default)    PREDICT the outcome and write nothing.
 *   --backfill   write the ids, then compare actual against predicted.
 */
require __DIR__ . '/../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

$doAdd      = in_array('--add', $argv, true);
$doBackfill = in_array('--backfill', $argv, true);
$norm = fn ($s) => mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $s)));

$hasCol = Schema::hasColumn('s_user_jobrole_task', 'catalogue_task_id');
printf("column catalogue_task_id exists : %s\n", $hasCol ? 'yes' : 'no');
if ($doAdd && !$hasCol) {
    Schema::table('s_user_jobrole_task', function (Blueprint $t) {
        // Nullable because unmatched is HELD, not invented. No FK because the
        // catalogue is global and this table is tenant-scoped - two lifecycles.
        $t->unsignedBigInteger('catalogue_task_id')->nullable()->after('id');
        $t->index('catalogue_task_id', 'idx_ujt_catalogue_task');
    });
    $hasCol = true;
    echo "COLUMN ADDED (nullable, indexed, no FK)\n";
}

/* ---- the pair index, ambiguity refused --------------------------------- */
$pair = [];
foreach (DB::table('s_jobrole_task')->select('id', 'jobrole', 'task')
    ->whereNotNull('task')->cursor() as $r) {
    $t = $norm($r->task);
    if ($t === '') continue;
    $k = $norm($r->jobrole) . '|' . $t;
    $pair[$k] = isset($pair[$k]) ? false : (int) $r->id;   // false = ambiguous
}

/* ---- PREDICT before writing ------------------------------------------- */
$total = $predKey = $predAmb = $predAbsent = 0;
foreach (DB::table('s_user_jobrole_task')->select('jobrole', 'task')
    ->whereNotNull('task')->where('task', '!=', '')->cursor() as $r) {
    $total++;
    $h = $pair[$norm($r->jobrole) . '|' . $norm($r->task)] ?? null;
    if (is_int($h)) $predKey++;
    elseif ($h === false) $predAmb++;
    else $predAbsent++;
}
printf("\nPREDICTED, before any write:\n");
printf("  rows                 %d\n", $total);
printf("  WILL BE KEYED        %d  (%.2f%%)\n", $predKey, 100 * $predKey / $total);
printf("  held NULL, ambiguous %d  (the catalogue's own duplicates - F-10b)\n", $predAmb);
printf("  held NULL, absent    %d  (tenant-authored; NULL is correct)\n", $predAbsent);

if (!$doBackfill) {
    echo "\nNOTHING WRITTEN. Pass --backfill to apply.\n";
    exit(0);
}
if (!$hasCol) { echo "\nREFUSING: no column. Run --add first.\n"; exit(1); }

/* ---- APPLY ------------------------------------------------------------ */
$written = 0;
DB::table('s_user_jobrole_task')
    ->whereNull('catalogue_task_id')->whereNotNull('task')->where('task', '!=', '')
    ->orderBy('id')
    ->chunkById(2000, function ($rows) use (&$written, $pair, $norm) {
        foreach ($rows as $r) {
            $h = $pair[$norm($r->jobrole) . '|' . $norm($r->task)] ?? null;
            if (!is_int($h)) continue;                    // HELD, never guessed
            DB::table('s_user_jobrole_task')->where('id', $r->id)
                ->update(['catalogue_task_id' => $h]);
            $written++;
        }
    });

$actual = DB::table('s_user_jobrole_task')->whereNotNull('catalogue_task_id')->count();
$nulls  = DB::table('s_user_jobrole_task')->whereNull('catalogue_task_id')->count();

printf("\nACTUAL, after the write:\n");
printf("  wrote                %d\n", $written);
printf("  keyed rows           %d\n", $actual);
printf("  NULL rows            %d\n", $nulls);

/* ---- COMPARE, and FLAG divergence rather than reconciling it ---------- */
$delta = $actual - $predKey;
printf("\nPREDICTED %d keyed, ACTUAL %d keyed, DIVERGENCE %+d\n", $predKey, $actual, $delta);
if ($delta !== 0) {
    echo "*** DIVERGENCE FLAGGED, NOT RECONCILED. The prediction and the write\n";
    echo "*** disagree. Do not adjust the number - find out why they differ.\n";
} else {
    echo "Prediction and outcome agree exactly.\n";
}
printf("\nTO REVERSE:  UPDATE s_user_jobrole_task SET catalogue_task_id = NULL;\n");
printf("             ALTER TABLE s_user_jobrole_task DROP INDEX idx_ujt_catalogue_task;\n");
printf("             ALTER TABLE s_user_jobrole_task DROP COLUMN catalogue_task_id;\n");
