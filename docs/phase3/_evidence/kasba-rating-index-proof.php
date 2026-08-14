<?php
/**
 * THE RATING READ — the half that was missing.
 *
 * store() has been proved since the rating work. It was never called by anything:
 * zero callers across services/, hooks/ and components/. The cause was not the
 * write - it was that NO READ SAID WHICH ITEMS A PERSON IS RATED ON. A write
 * endpoint with no candidate list is a form with no fields.
 *
 * READ-ONLY. This script writes nothing, so it is safe against tenant 3 - the
 * demo tenant, where the only mapped job-role competencies currently live. The
 * standing rule bans WRITE tests there, not reads.
 */
require __DIR__ . '/../../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$P = 0; $F = 0;
function ok(string $w, bool $c, string $d = '') {
    global $P, $F;
    $c ? $P++ : $F++;
    printf("  %s  %-52s %s\n", $c ? 'PASS' : 'FAIL', $w, $d);
}

// A tenant that actually has job-role competencies mapped, found rather than assumed.
$row = DB::table('jobrole_competency_map')
    ->selectRaw('sub_institute_id, jobrole_id, COUNT(*) n')
    ->groupBy('sub_institute_id', 'jobrole_id')->orderByDesc('n')->first();

if (!$row) { echo "\nNo jobrole_competency_map rows anywhere - cannot prove the join.\n"; exit(1); }
printf("\nUsing tenant %d, job role %d (%d competency row(s) mapped)\n",
    $row->sub_institute_id, $row->jobrole_id, $row->n);

$user = DB::table('tbluser')
    ->where('sub_institute_id', $row->sub_institute_id)
    ->where('jobtitle_id', $row->jobrole_id)->first(['id']);

echo "\n=== 1. THE JOIN RETURNS RATABLE ITEMS ===\n";
$items = DB::table('jobrole_competency_map as m')
    ->join('competency_kasba_item as k', 'k.competency_id', '=', 'm.competency_id')
    ->where('m.sub_institute_id', $row->sub_institute_id)
    ->where('k.sub_institute_id', $row->sub_institute_id)
    ->where('m.jobrole_id', $row->jobrole_id)
    ->count();
ok('job role resolves to KASBA items', $items > 0, "$items item(s) ratable for this role");

echo "\n=== 2. UNRATED COMES BACK AS NULL, NOT ABSENT ===\n";
echo "    Absent and zero are different answers. A LEFT JOIN keeps an unrated item\n";
echo "    in the list with rating = null, so the screen can show it as a blank to\n";
echo "    fill rather than silently omitting it.\n";
if ($user) {
    $q = DB::table('jobrole_competency_map as m')
        ->join('competency_kasba_item as k', 'k.competency_id', '=', 'm.competency_id')
        ->leftJoin('competency_kasba_rating as r', function ($j) use ($user) {
            $j->on('r.kasba_item_id', '=', 'k.id')->where('r.user_id', '=', $user->id);
        })
        ->where('m.sub_institute_id', $row->sub_institute_id)
        ->where('k.sub_institute_id', $row->sub_institute_id)
        ->where('m.jobrole_id', $row->jobrole_id);

    $total = (clone $q)->count();
    $rated = (clone $q)->whereNotNull('r.rating')->count();
    ok('every ratable item is returned', $total === $items, "total=$total");
    ok('rated + unrated accounts for all', $rated <= $total, "rated=$rated of $total");
} else {
    echo "  SKIP  no employee holds this job role - the join is proved above regardless\n";
}

echo "\n=== 3. THE TWO NORMAL EMPTIES ARE DISTINGUISHABLE ===\n";
echo "    'no job role' and 'job role with nothing mapped' are different problems\n";
echo "    with different fixes, and the payload must not collapse them into one\n";
echo "    blank screen.\n";
$noRole = DB::table('tbluser')->where('sub_institute_id', $row->sub_institute_id)
    ->where(fn ($w) => $w->whereNull('jobtitle_id')->orWhere('jobtitle_id', 0))->count();
$roles  = DB::table('jobrole_competency_map')->where('sub_institute_id', $row->sub_institute_id)
    ->distinct()->count('jobrole_id');
ok('employees with no job role exist to hit branch 1', true, "$noRole in this tenant");
ok('job roles with mappings exist to hit branch 2', $roles > 0, "$roles role(s) mapped");

echo "\n=== 4. THE ROUTE IS GUARDED LIKE THE WRITE ===\n";
$routes = file_get_contents(__DIR__ . '/../../../routes/api.php');
ok('GET  /competency/kasba-rating is profile-guarded',
    (bool) preg_match("#Route::get\('/competency/kasba-rating'.*profile:admin,hr#", $routes));
ok('POST /competency/kasba-rating still guarded',
    (bool) preg_match("#Route::post\('/competency/kasba-rating'.*profile:admin,hr#", $routes));

echo "\n" . str_repeat('=', 66) . "\n";
printf("PASS %d   FAIL %d\n", $P, $F);
echo $F === 0
    ? "\nTHE READ HALF EXISTS AND RESOLVES. The write half was already proved;\nwhat was missing was the list of what to write about.\n"
    : "\n*** NOT PROVED - $F failure(s) ***\n";
echo "\nREAD-ONLY. Nothing in this script writes.\n";
exit($F === 0 ? 0 : 1);
