<?php
/**
 * F-10: does (jobrole, task) identify exactly one catalogue row?
 *
 * The title alone resolves 67.17% because 6,018 of 42,209 catalogue titles are
 * shared by two or more rows. A title repeated ACROSS ROLES is exactly what a
 * role-scoped catalogue produces, so the pair is the natural candidate key.
 *
 * READ ONLY. Nothing is written.
 */
require __DIR__ . '/../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$norm = fn ($s) => mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $s)));

// ---- catalogue keyed on (jobrole, task), and on the triple ---------------
$pair = $triple = [];
foreach (DB::table('s_jobrole_task')
    ->select('id', 'jobrole', 'task', 'critical_work_function')
    ->whereNotNull('task')->cursor() as $r) {
    $t = $norm($r->task);
    if ($t === '') continue;
    $p = $norm($r->jobrole) . '|' . $t;
    $q = $p . '|' . $norm($r->critical_work_function);
    $pair[$p]   = isset($pair[$p])   ? false : (int) $r->id;
    $triple[$q] = isset($triple[$q]) ? false : (int) $r->id;
}
printf("catalogue distinct (jobrole,task) keys          : %d   ambiguous: %d\n",
    count($pair), count(array_filter($pair, fn ($v) => $v === false)));
printf("catalogue distinct (jobrole,task,function) keys : %d   ambiguous: %d\n\n",
    count($triple), count(array_filter($triple, fn ($v) => $v === false)));

// ---- resolve every tenant row -------------------------------------------
$n = $byPair = $byTriple = $ambPair = $absentPair = 0;
$absentSamples = [];
foreach (DB::table('s_user_jobrole_task')
    ->select('id', 'sub_institute_id', 'jobrole', 'task', 'critical_work_function')
    ->whereNotNull('task')->where('task', '!=', '')->cursor() as $r) {
    $n++;
    $p = $norm($r->jobrole) . '|' . $norm($r->task);
    $q = $p . '|' . $norm($r->critical_work_function);
    $hp = $pair[$p] ?? null;
    if (is_int($hp)) { $byPair++; }
    elseif ($hp === false) {
        $ambPair++;
        if (is_int($triple[$q] ?? null)) $byTriple++;
    } else {
        $absentPair++;
        if (count($absentSamples) < 6) {
            $absentSamples[] = sprintf('tenant %-5s #%-8s role=%-28s %s',
                $r->sub_institute_id, $r->id, mb_substr((string) $r->jobrole, 0, 28),
                mb_substr((string) $r->task, 0, 34));
        }
    }
}

printf("tenant task rows                        : %d\n", $n);
printf("  RESOLVE UNIQUELY on (jobrole, task)   : %d   (%.2f%%)\n", $byPair, 100 * $byPair / $n);
printf("  STILL AMBIGUOUS on the pair           : %d   (%.2f%%)\n", $ambPair, 100 * $ambPair / $n);
printf("     of those, settled by adding\n");
printf("     critical_work_function             : %d\n", $byTriple);
printf("  PAIR ABSENT from the catalogue        : %d   (%.2f%%)\n", $absentPair, 100 * $absentPair / $n);
$keyed = $byPair + $byTriple;
printf("\n  KEYED BY PAIR, OR PAIR+FUNCTION       : %d   (%.2f%%)\n", $keyed, 100 * $keyed / $n);
printf("  UNKEYABLE BY ANY OF THESE             : %d   (%.2f%%)\n", $n - $keyed, 100 * ($n - $keyed) / $n);

if ($absentSamples) {
    echo "\n--- rows whose (jobrole, task) pair is ABSENT - they need a decision, not a rule ---\n";
    foreach ($absentSamples as $x) echo "   $x\n";
}
