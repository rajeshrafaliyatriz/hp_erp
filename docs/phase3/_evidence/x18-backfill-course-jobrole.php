<?php
/**
 * X-18 — BACKFILL `course_jobrole_map` FROM `sub_std_map.jobrole`.
 *
 * F-07b discipline, unchanged:
 *   - resolve by (name, tenant), BOTH sides tenant-scoped
 *   - write only the UNAMBIGUOUS
 *   - HOLD anything ambiguous or unmatched, with its text intact
 *   - DELETE NOTHING
 *
 * Approved 2026-08-11: write 71, hold course 144 (a duplicate job-role name).
 */
require __DIR__ . '/../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "\n================ X-18 BACKFILL ================\n\n";

$before = DB::table('course_jobrole_map')->count();
printf("course_jobrole_map before: %d rows\n\n", $before);

// Every candidate pair, exactly as measured when the split was reported.
$pairs = DB::table('sub_std_map as c')
    ->join('s_user_jobrole as j', function ($q) {
        $q->on('j.jobrole', '=', 'c.jobrole')->on('j.sub_institute_id', '=', 'c.sub_institute_id');
    })
    ->whereNull('c.deleted_at')->whereNull('j.deleted_at')
    ->whereNotNull('c.jobrole')->where('c.jobrole', '!=', '')
    ->get(['c.id as course_id', 'c.sub_institute_id as tenant', 'c.jobrole as name',
           'j.id as jobrole_id', 'c.display_name']);

$byCourse = [];
foreach ($pairs as $p) $byCourse[$p->course_id][] = $p;

// PREDICTION, STATED BEFORE THE WRITE (R19 - the number to reconcile against).
$predictUnambiguous = 71;
$predictHeld = 1;

$unambiguous = []; $held = [];
foreach ($byCourse as $courseId => $rows) {
    count($rows) === 1 ? ($unambiguous[] = $rows[0]) : ($held[$courseId] = $rows);
}

printf("candidates : %d join rows over %d distinct courses\n", $pairs->count(), count($byCourse));
printf("unambiguous: %d  (predicted %d)\n", count($unambiguous), $predictUnambiguous);
printf("HELD       : %d  (predicted %d)\n\n", count($held), $predictHeld);

if (count($unambiguous) !== $predictUnambiguous || count($held) !== $predictHeld) {
    echo "REFUSING TO WRITE — the split moved since it was approved.\n";
    echo "Triz approved 71 written / 1 held. Writing a different set would be\n";
    echo "writing something nobody agreed to.\n";
    exit(1);
}

// ── WRITE ───────────────────────────────────────────────────────────────────
$written = 0; $perTenant = [];
foreach ($unambiguous as $row) {
    $exists = DB::table('course_jobrole_map')
        ->where('sub_institute_id', $row->tenant)
        ->where('course_id', $row->course_id)
        ->where('jobrole_id', $row->jobrole_id)
        ->exists();
    if ($exists) continue;

    DB::table('course_jobrole_map')->insert([
        'sub_institute_id' => $row->tenant,
        'course_id'        => $row->course_id,
        'jobrole_id'       => $row->jobrole_id,
        'created_at'       => now(),
        'updated_at'       => now(),
    ]);
    $written++;
    $perTenant[$row->tenant] = ($perTenant[$row->tenant] ?? 0) + 1;
}

echo "ROWS WRITTEN PER TENANT (against the prediction of $predictUnambiguous total)\n";
ksort($perTenant);
foreach ($perTenant as $t => $n) {
    $name = DB::table('institute_detail')->where('sub_institute_id', $t)->value('organization_name') ?? '(unnamed)';
    printf("   tenant %-4d %-26s %d\n", $t, mb_substr((string) $name, 0, 25), $n);
}
printf("   TOTAL %d\n\n", $written);

// ── THE HELD ────────────────────────────────────────────────────────────────
echo "HELD — NOT WRITTEN, TEXT INTACT, NOTHING DELETED\n";
foreach ($held as $courseId => $rows) {
    $names = array_unique(array_map(fn ($r) => $r->name, $rows));
    printf("   course %-5d tenant %-3d \"%s\"\n", $courseId, $rows[0]->tenant,
        mb_substr((string) $rows[0]->display_name, 0, 46));
    printf("      sub_std_map.jobrole = %s  (STILL SET - the text is the record)\n", var_export($rows[0]->name, true));
    printf("      matches job-role ids: %s\n", implode(', ', array_map(fn ($r) => $r->jobrole_id, $rows)));
    printf("      distinct names among them: %d -> %s\n", count($names),
        count($names) === 1 ? 'DUPLICATE NAME. A human must say which role this course means.' : 'genuinely different names');
}

// ── RECONCILE ───────────────────────────────────────────────────────────────
$after = DB::table('course_jobrole_map')->count();
printf("\ncourse_jobrole_map after : %d rows (before %d, written %d)\n", $after, $before, $written);
printf("reconciles: %s\n", $after - $before === $written ? 'YES' : 'NO — INVESTIGATE');

// Nothing was removed from the source.
$stillNamed = DB::table('sub_std_map')->whereNull('deleted_at')
    ->whereNotNull('jobrole')->where('jobrole', '!=', '')->count();
printf("sub_std_map rows still carrying a jobrole name: %d (unchanged - nothing was cleared)\n", $stillNamed);

// Cross-tenant safety: the write must not have created a single cross-tenant row.
$cross = DB::table('course_jobrole_map as m')
    ->join('s_user_jobrole as j', 'j.id', '=', 'm.jobrole_id')
    ->whereColumn('j.sub_institute_id', '!=', 'm.sub_institute_id')->count();
printf("cross-tenant rows created: %d (must be 0)\n", $cross);

echo "\n";
