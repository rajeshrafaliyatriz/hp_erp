<?php
/**
 * X-07b — THE SIX READINESS METRICS, COMPUTED. NO TABLE, NO WRITE.
 *
 * §4 specifies six gates with thresholds, hysteresis and remedies, and nothing
 * has ever computed one. G-EVT-03 is why this runs before the migration: a
 * consumer can be the right KIND of thing and still not do the work, and a gate
 * that declares a state nobody computed would be that finding a fourth time - in
 * the item that governs several others.
 *
 * SO: prove each metric is COMPUTABLE first. A metric that cannot be computed is
 * an aspirational gate, and that is knowable before a schema change rather than
 * after.
 *
 * FIVE HAVE A PRIOR MEASUREMENT taken this phase. Each computed value is printed
 * beside its known one. A DISAGREEMENT IS NOT A ROUNDING ISSUE - it is either a
 * wrong metric or a changed system, and both matter before the number becomes a
 * gate.
 *
 * READ ONLY. Writes nothing, creates nothing, alters nothing.
 */
require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$DB = env('DB_DATABASE');

/** Does a column exist? A metric resting on a guessed column is not computed. */
function col(string $table, string $column): bool
{
    static $cache = [];
    $k = $table . '.' . $column;
    return $cache[$k] ??= DB::table('information_schema.columns')
        ->where('table_schema', env('DB_DATABASE'))
        ->where('table_name', $table)->where('column_name', $column)->exists();
}

function pct(int $num, int $den): ?float
{
    return $den === 0 ? null : round($num * 100 / $den, 1);
}

// ── the six, with their §4 thresholds and prior measurements ────────────────
$SPEC = [
    'reporting_coverage'   => ['enable' => 80, 'disable' => 65, 'known' => 'X-16 reporting lines'],
    'task_hygiene'         => ['enable' => 60, 'disable' => 45, 'known' => '8.1% healthy (91.9% overdue)'],
    'capability_coverage'  => ['enable' => 50, 'disable' => 35, 'known' => '~3.0% of employees measured'],
    'jobrole_definition'   => ['enable' => 70, 'disable' => 55, 'known' => null],
    'course_mapping'       => ['enable' => 50, 'disable' => 35, 'known' => 'X-18 wrote 71 course->jobrole rows'],
    'task_competency_link' => ['enable' => 50, 'disable' => 35, 'known' => '67% of tasks carry a link'],
];

/** Tenants with employees. Gates are per tenant. */
$tenants = DB::table('tbluser')->where('sub_institute_id', '>', 0)
    ->select('sub_institute_id', DB::raw('count(*) n'))
    ->groupBy('sub_institute_id')->orderByDesc('n')->limit(4)->get();

function metrics(int $t): array
{
    $out = [];

    // 1. reporting_coverage - employees with a manager
    if (col('tbluser', 'reporting_manager_id')) {
        $den = DB::table('tbluser')->where('sub_institute_id', $t)->count();
        $num = DB::table('tbluser')->where('sub_institute_id', $t)
            ->whereNotNull('reporting_manager_id')->where('reporting_manager_id', '>', 0)->count();
        $out['reporting_coverage'] = [pct($num, $den), "$num/$den employees have a manager"];
    } else {
        $out['reporting_coverage'] = [null, 'HELD: tbluser.reporting_manager_id absent'];
    }

    // 2. task_hygiene - tasks NOT overdue. The gate is stated as a HEALTH
    //    percentage (enable at >=60), so it is the complement of the overdue
    //    figure this phase measured.
    // MEASURED, NOT ASSUMED: `task` has NO due_date/end_date column. The only
    // date that can carry a deadline is task_date, which is what the 91.9%
    // overdue figure must have used. Named here so the basis is visible.
    $due = col('task', 'end_date') ? 'end_date'
         : (col('task', 'due_date') ? 'due_date'
         : (col('task', 'task_date') ? 'task_date' : null));
    $don = col('task', 'status') ? 'status' : null;
    if ($due && $don) {
        $den = DB::table('task')->where('sub_institute_id', $t)->count();
        $overdue = DB::table('task')->where('sub_institute_id', $t)
            ->whereNotNull($due)->where($due, '<', now()->toDateString())
            ->whereNotIn(DB::raw('UPPER(TRIM(status))'), ['COMPLETED', 'COMPLETE', 'DONE', 'CLOSED'])
            ->count();
        $out['task_hygiene'] = [pct($den - $overdue, $den), ($den - $overdue) . "/$den not overdue (using task.$due)"];
    } else {
        $out['task_hygiene'] = [null, 'HELD: no due-date or status column on task'];
    }

    // 3. capability_coverage - employees with at least one KASBA rating
    $den = DB::table('tbluser')->where('sub_institute_id', $t)->count();
    $num = DB::table('competency_kasba_rating')->where('sub_institute_id', $t)
        ->distinct()->count('user_id');
    $out['capability_coverage'] = [pct($num, $den), "$num/$den employees have a measurement"];

    // 4. jobrole_definition - THE SIXTH. No prior measurement.
    //    §4's remedy is "Import the job-role library", so the question is what
    //    fraction of employees have a job role ASSIGNED at all.
    // HELD. s_user_jobrole has NO user_id - it is the tenant's job-role LIBRARY
    // (industries, department, jobrole, job_level), not an assignment table.
    // That matches §4's remedy exactly ("Import the job-role library"), and it
    // means the gate is a COUNT of defined roles with NO DENOMINATOR: §4 gives
    // it a 70%/55% threshold without saying 70% OF WHAT. A percentage cannot be
    // computed from a specification that does not name a population.
    $defined = DB::table('s_user_jobrole')->where('sub_institute_id', $t)->count();
    $out['jobrole_definition'] = [null, "HELD: $defined roles defined, but no denominator in the spec"];

    // 5. course_mapping - courses linked to a competency/jobrole
    if (col('sub_std_map', 'sub_institute_id')) {
        $cden = DB::table('sub_std_map')->where('sub_institute_id', $t)->count();
        // sub_std_map.jobrole is the mapping X-18 wrote into.
        $cnum = col('sub_std_map', 'jobrole')
            ? DB::table('sub_std_map')->where('sub_institute_id', $t)
                ->whereNotNull('jobrole')->where('jobrole', '!=', '')->count()
            : -1;
        $out['course_mapping'] = $cnum < 0
            ? [null, 'HELD: sub_std_map has no jobrole column']
            : [pct($cnum, $cden), "$cnum/$cden courses mapped"];
    } else {
        $out['course_mapping'] = [null, 'HELD: sub_std_map has no tenant column'];
    }

    // 6. task_competency_link - tasks carrying a competency link
    // HELD, AND THIS IS THE SHARPEST ONE. jobrole_task_competency_map keys on
    // jobrole_task_id -> s_jobrole_task, which has NO sub_institute_id: it is a
    // GLOBAL SEED LIBRARY (Q-C1). It cannot be joined to `task`, the tenant's
    // actual work items, at all. So "% of tasks carrying a competency link" is
    // not a statement about `task` - the 67% measured this phase was about
    // JOBROLE-LIBRARY tasks. Two different populations behind one gate name.
    $linked = DB::table('jobrole_task_competency_map')->where('sub_institute_id', $t)
        ->whereNotNull('competency_id')->distinct()->count('jobrole_task_id');
    $tden = DB::table('task')->where('sub_institute_id', $t)->count();
    $out['task_competency_link'] = [null,
        "HELD: $linked library tasks linked; $tden tenant tasks - DIFFERENT POPULATIONS"];

    return $out;
}

foreach ($tenants as $row) {
    $t = (int) $row->sub_institute_id;
    printf("\nTENANT %d  (%d employees)\n", $t, $row->n);
    printf("  %-22s %8s  %-8s %-38s %s\n", 'GATE', 'VALUE', 'STATE', 'BASIS', 'PRIOR MEASUREMENT');
    printf("  %s\n", str_repeat('-', 116));

    foreach (metrics($t) as $gate => [$val, $basis]) {
        $s = $SPEC[$gate];
        // §4: enable at or above the enable point; blocked below the disable
        // point; between them is at_risk. THE STATE IS COMPUTED, NOT DECLARED.
        $state = $val === null ? 'HELD'
               : ($val >= $s['enable'] ? 'ready' : ($val < $s['disable'] ? 'blocked' : 'at_risk'));
        printf("  %-22s %7s  %-8s %-38s %s\n",
            $gate,
            $val === null ? '  --' : $val . '%',
            $state,
            substr($basis, 0, 38),
            $s['known'] ?? '*** NO PRIOR MEASUREMENT ***');
    }
}

echo "\nREAD ONLY: nothing written, nothing created, nothing altered.\n";
