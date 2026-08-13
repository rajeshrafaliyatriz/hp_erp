<?php
/**
 * F-10a's CLOSING REPORT. One statement, printed beside its numbers.
 *
 * A single-query count is only a snapshot IF THE QUERY REALLY IS ONE STATEMENT.
 * Two reads of a table being written disagree by exactly the number of rows that
 * moved between them - it happened here, 85,662 against 85,663, and it read as a
 * lost row. So the SQL is shown, not just its output.
 *
 * SUCCESS IS THIS QUERY MATCHING THE PREDICTION. Not an exit code, not an absent
 * error, not a finished-looking log.
 */
require __DIR__ . '/../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

const SQL = "SELECT COUNT(*) total,
       SUM(catalogue_task_id IS NOT NULL) keyed,
       SUM(catalogue_task_id IS NULL)     nulls,
       SUM(task IS NULL OR task = '')     empty_task
  FROM s_user_jobrole_task";

echo "THE QUERY, so a reader can see it is one statement:\n";
echo "  " . preg_replace('/\s+/', ' ', SQL) . "\n\n";

$r = DB::selectOne(SQL);
$PRED_KEYED = 80064;
$PRED_AMBIG = 5470;
$PRED_ABSENT = 126;
$PRED_EMPTY = 3;

printf("ONE-INSTANT SNAPSHOT\n");
printf("  total       %d\n  keyed       %d\n  NULL        %d\n  empty task  %d\n",
    $r->total, $r->keyed, $r->nulls, $r->empty_task);
printf("  keyed + NULL = %d   %s\n\n", $r->keyed + $r->nulls,
    ($r->keyed + $r->nulls) === (int) $r->total ? '= total, consistent' : '!= total');

printf("AGAINST PREDICTED\n");
printf("  PREDICTED keyed   %d\n  ACTUAL keyed      %d\n  DIVERGENCE        %+d\n",
    $PRED_KEYED, $r->keyed, $r->keyed - $PRED_KEYED);
printf("  NULL expected     %d  (%d ambiguous + %d no-catalogue + %d empty-task)\n",
    $PRED_AMBIG + $PRED_ABSENT + $PRED_EMPTY, $PRED_AMBIG, $PRED_ABSENT, $PRED_EMPTY);
printf("  NULL actual       %d\n", $r->nulls);

if ((int) $r->keyed === $PRED_KEYED) {
    echo "\nF-10a CLOSES. Prediction and outcome agree exactly.\n";
} elseif ($r->keyed < $PRED_KEYED) {
    printf("\nF-10a INCOMPLETE - %d row(s) still to write. NOT a divergence yet.\n",
        $PRED_KEYED - $r->keyed);
} else {
    echo "\n*** DIVERGENCE FLAGGED, NOT RECONCILED. More rows keyed than predicted.\n";
    echo "*** Do not adjust the number - find out why they differ.\n";
}
