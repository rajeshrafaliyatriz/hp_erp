<?php
/**
 * DOES A TENANT'S TASK ROW CORRESPOND TO THE GLOBAL CATALOGUE ROW THE MAP POINTS AT?
 *
 * `jobrole_task_competency_map.jobrole_task_id` -> `s_jobrole_task` (GLOBAL,
 * 55,961 rows, no tenant column). An employee's actual tasks live in
 * `s_user_jobrole_task` (85,663 rows, tenant-scoped).
 *
 * A MAPPING ONTO THE GLOBAL CATALOGUE ONLY REACHES AN EMPLOYEE IF THEIR TENANT'S
 * TASK ROWS CORRESPOND TO IT. Nothing has confirmed they do, and building a
 * screen first risks a third table filled for nobody.
 *
 * THREE POSSIBLE ANSWERS, and they are not equivalent:
 *   BY KEY    s_user_jobrole_task carries the catalogue id -> the path is keyed
 *   BY TITLE  the two share task text -> THE PATH IS NAME-JOINED AT ITS LAST LINK,
 *             which is the defect this phase began with
 *   NOT AT ALL -> writer 2 fills a table golden thread 2 cannot read
 *
 * READ ONLY.
 */
require __DIR__ . '/../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// ---- 1. IS THERE A KEY AT ALL? ------------------------------------------
$cols = array_map(fn ($c) => $c->Field, DB::select('DESCRIBE s_user_jobrole_task'));
$keyish = array_values(array_filter($cols, fn ($c) => preg_match('/task_id|catalogue|source_id|ref/i', $c)));
printf("s_user_jobrole_task columns that could hold a catalogue key : %s\n",
    $keyish ? implode(', ', $keyish) : 'NONE');
printf("   (its columns: %s)\n\n", implode(', ', $cols));

// ---- 2. TITLE CORRESPONDENCE, per tenant that actually has rows ---------
$tenants = DB::table('s_user_jobrole_task')
    ->select('sub_institute_id')->selectRaw('COUNT(*) n')
    ->whereNotNull('task')->where('task', '!=', '')
    ->groupBy('sub_institute_id')->orderByDesc('n')->limit(6)->get();

// The global catalogue's task text, normalised once.
$cat = [];
foreach (DB::table('s_jobrole_task')->select('task')->whereNotNull('task')->cursor() as $r) {
    $k = mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $r->task)));
    if ($k !== '') $cat[$k] = true;
}
printf("global catalogue distinct task titles : %d\n\n", count($cat));

printf("%-10s %10s %10s %10s   %s\n", 'TENANT', 'TASK ROWS', 'MATCH', 'RATE', 'sample miss');
$totalRows = $totalHit = 0;
foreach ($tenants as $t) {
    $rows = DB::table('s_user_jobrole_task')
        ->where('sub_institute_id', $t->sub_institute_id)
        ->whereNotNull('task')->where('task', '!=', '')
        ->limit(4000)->pluck('task');
    $hit = 0; $miss = null;
    foreach ($rows as $x) {
        $k = mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $x)));
        if (isset($cat[$k])) { $hit++; }
        elseif ($miss === null) { $miss = mb_substr((string) $x, 0, 40); }
    }
    $n = count($rows);
    $totalRows += $n; $totalHit += $hit;
    printf("%-10s %10d %10d %9.1f%%   %s\n", $t->sub_institute_id, $n, $hit,
        $n ? 100 * $hit / $n : 0, $miss ?? '-');
}
printf("\nSAMPLED TOTAL  %d rows, %d match by title  (%.1f%%)\n",
    $totalRows, $totalHit, $totalRows ? 100 * $totalHit / $totalRows : 0);

// ---- 3. KNOWN-POSITIVE for the matcher ---------------------------------
// If a row taken FROM the catalogue does not match itself, normalisation is
// broken and every rate above is meaningless.
$probe = DB::table('s_jobrole_task')->whereNotNull('task')->where('task', '!=', '')->value('task');
$pk = mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $probe)));
printf("\nKNOWN-POSITIVE: a catalogue row matches itself : %s\n",
    isset($cat[$pk]) ? 'yes' : 'NO - the normaliser is broken, ignore every rate above');
