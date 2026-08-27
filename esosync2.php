<?php
/**
 * Copy tenant 6's classifications from dev to live.
 *
 * ── WHY COPY RATHER THAN RE-RUN ─────────────────────────────────────────────
 *
 * Re-running costs the money again AND produces different answers — temperature
 * is 0.2, not 0. Two databases disagreeing about whether a task can be automated
 * is worse than either answer being wrong, because nobody could tell which to
 * trust.
 *
 * ── MATCHED ON ID, VERIFIED ON TEXT ─────────────────────────────────────────
 *
 * Tenant 6's task ids happen to align across the two databases (verified 19/19
 * on the first role). But nothing enforces that, and a copy keyed on a silently
 * diverged id would attach verdicts to the WRONG TASKS while looking completely
 * normal on screen. So every row is matched by id and then CHECKED against the
 * task text; a mismatch is reported and skipped rather than written.
 */
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

const TENANT = 6;

$COPY = [
    'execution_mode_current', 'execution_mode_target', 'digital_input', 'rule_clarity',
    'judgment_required', 'error_consequence', 'ai_executability_score', 'risk_class',
    'automation_rationale', 'human_effort_current_min', 'human_effort_target_min',
    'classification_status', 'model', 'classified_at', 'classified_by',
    'reviewed_by', 'reviewed_at', 'review_note',
];

$source = DB::connection('mysql')->table('jobrole_task_execution as e')
    ->join('s_user_jobrole_task as t', 't.id', '=', 'e.user_jobrole_task_id')
    ->where('e.sub_institute_id', TENANT)
    ->get(array_merge(['e.user_jobrole_task_id', 't.task as dev_task', 'e.catalogue_task_id'],
        array_map(fn ($c) => "e.$c", $COPY)));

printf("dev  : %d classified row(s)\n", $source->count());

// Live's task text, keyed by id, so a diverged id is caught rather than trusted.
$liveText = DB::connection('live')->table('s_user_jobrole_task')
    ->where('sub_institute_id', TENANT)
    ->pluck('task', 'id');

printf("live : %d task row(s) to match against\n\n", $liveText->count());

$written = 0; $mismatched = []; $missing = 0;
$existing = DB::connection('live')->table('jobrole_task_execution')
    ->where('sub_institute_id', TENANT)->pluck('id', 'user_jobrole_task_id');

DB::connection('live')->beginTransaction();

foreach ($source as $row) {
    $taskId = (int) $row->user_jobrole_task_id;
    $liveTask = $liveText->get($taskId);

    if ($liveTask === null) {
        $missing++;
        continue;
    }

    // The verification that makes id-matching safe.
    if (mb_strtolower(trim((string) $liveTask)) !== mb_strtolower(trim((string) $row->dev_task))) {
        $mismatched[] = $taskId;
        continue;
    }

    $values = [];
    foreach ($COPY as $c) {
        $values[$c] = $row->$c;
    }
    $values['catalogue_task_id'] = $row->catalogue_task_id;
    $values['updated_at'] = now();

    if ($existing->has($taskId)) {
        DB::connection('live')->table('jobrole_task_execution')
            ->where('id', $existing->get($taskId))->update($values);
    } else {
        DB::connection('live')->table('jobrole_task_execution')->insert(
            $values + ['sub_institute_id' => TENANT, 'user_jobrole_task_id' => $taskId,
                       'created_at' => now()]
        );
    }
    $written++;
}

DB::connection('live')->commit();

printf("copied     : %d row(s)\n", $written);
printf("id missing : %d (task exists on dev, not on live)\n", $missing);
printf("text differ: %d %s\n", count($mismatched),
    $mismatched ? '  <- SKIPPED, ids diverged: ' . implode(',', array_slice($mismatched, 0, 5)) : '');

echo "\n=== BOTH DATABASES ===\n";
foreach ([['dev', 'mysql'], ['live', 'live']] as [$l, $c]) {
    $x = DB::connection($c)->table('jobrole_task_execution')->where('sub_institute_id', TENANT)
        ->selectRaw("COUNT(*) n,
            SUM(classification_status='Approved') appr,
            SUM(classification_status='AI-proposed') prop,
            COUNT(DISTINCT execution_mode_target) modes")->first();
    printf("  %-5s %d rows, %d approved, %d proposed, %d distinct modes\n",
        $l, $x->n, $x->appr, $x->prop, $x->modes);
}

// Do the two sides agree, row for row?
$fp = function (string $conn) {
    return DB::connection($conn)->table('jobrole_task_execution as e')
        ->join('s_user_jobrole_task as t', 't.id', '=', 'e.user_jobrole_task_id')
        ->where('e.sub_institute_id', TENANT)
        ->orderByRaw('TRIM(LOWER(t.task)), e.execution_mode_target')
        ->get(['t.task', 'e.execution_mode_target', 'e.risk_class', 'e.ai_executability_score', 'e.classification_status'])
        ->map(fn ($r) => mb_strtolower(trim($r->task)) . '|' . $r->execution_mode_target . '|'
            . $r->risk_class . '|' . $r->ai_executability_score . '|' . $r->classification_status)
        ->sort()->values()->all();
};
$d = $fp('mysql'); $lv = $fp('live');
printf("\n  verdicts identical: %s  (dev %d, live %d)\n",
    $d === $lv ? 'PASS' : '*** DIFFER ***', count($d), count($lv));
if ($d !== $lv) {
    foreach (array_slice(array_diff($d, $lv), 0, 3) as $x) { echo "    only dev : " . mb_substr($x, 0, 90) . "\n"; }
    foreach (array_slice(array_diff($lv, $d), 0, 3) as $x) { echo "    only live: " . mb_substr($x, 0, 90) . "\n"; }
}
