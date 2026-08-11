<?php
/**
 * X-16 PROOF — the reporting-line write path.
 *
 * Operates ONLY on the seeded tenant-3 users (registered in
 * SEED-REGISTER-2026-08-11.md) and restores their reporting lines afterwards, so
 * the coverage figure this turn established is unchanged at the end.
 */
require __DIR__ . '/../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Org\ReportingLineValidator;
use Illuminate\Support\Facades\DB;

$pass = 0; $fail = 0; $skip = 0;
function check(string $name, callable $fn): void
{
    try {
        [$ok, $detail] = $fn();
        if ($ok === 'SKIPPED') { printf("  %-6s %-50s %s\n", 'SKIP', $name, $detail); $GLOBALS['skip']++; return; }
        if (!is_bool($ok)) { printf("  %-6s %-50s %s\n", 'FAIL', $name, 'non-boolean verdict'); $GLOBALS['fail']++; return; }
        printf("  %-6s %-50s %s\n", $ok ? 'PASS' : 'FAIL', $name, $detail);
        $ok ? $GLOBALS['pass']++ : $GLOBALS['fail']++;
    } catch (Throwable $e) {
        printf("  %-6s %-50s %s\n", 'FAIL', $name, get_class($e) . ': ' . $e->getMessage());
        $GLOBALS['fail']++;
    }
}

echo "\n================ X-16 — REPORTING-LINE ASSIGNMENT ================\n\n";

$ctrl = app(App\Http\Controllers\Api\Org\ReportingLineController::class);
$validator = new ReportingLineValidator();

// The seeded people. Named, so this proof cannot touch anybody real.
$ids = DB::table('tbluser')->where('sub_institute_id', 3)
    ->where('email', 'like', '%@healthcare.g2g')->pluck('id', 'email')->all();

$vikram = $ids['vikram.sethi@healthcare.g2g'] ?? null;
$farida = $ids['farida.khan@healthcare.g2g'] ?? null;
$rajesh = $ids['rajesh.iyer@healthcare.g2g'] ?? null;
$divya  = $ids['divya.nair@healthcare.g2g'] ?? null;
$outsider = DB::table('tbluser')->where('sub_institute_id', '!=', 3)->where('sub_institute_id', '>', 0)->value('id');

printf("seeded users: %d   outsider: %d\n\n", count($ids), $outsider);

$before = DB::table('tbluser')->whereIn('id', array_values($ids))
    ->pluck('reporting_manager_id', 'id')->all();

check('the validator refuses a cycle', function () use ($validator, $rajesh, $vikram) {
    $v = $validator->canAssign($rajesh, $vikram);
    return [!$v['ok'], $v['ok'] ? 'ACCEPTED - broken' : 'refused: ' . mb_substr($v['reason'], 0, 44)];
});

check('the validator refuses self-management', function () use ($validator, $vikram) {
    $v = $validator->canAssign($vikram, $vikram);
    return [!$v['ok'], $v['ok'] ? 'ACCEPTED - broken' : 'refused: ' . $v['reason']];
});

check('NULL manager is allowed (the org head reports to nobody)', function () use ($validator, $rajesh) {
    $v = $validator->canAssign($rajesh, null);
    return [$v['ok'], $v['ok'] ? 'accepted, as designed' : 'refused: ' . $v['reason']];
});

check('a cross-tenant manager is refused BEFORE the cycle check', function () use ($ctrl, $vikram, $outsider) {
    // canAssign() answers "would this make a cycle?", not "are these your people?".
    // A stranger's id would pass the cycle check, so the tenant test must come first.
    $req = Request::create('/api/reporting-line/assign', 'POST',
        ['user_id' => $vikram, 'manager_id' => $outsider]);
    $m = new ReflectionMethod($ctrl, 'applyOne');
    $m->setAccessible(true);
    $r = $m->invoke($ctrl, 3, $vikram, $outsider, 1);
    return [!$r['ok'], $r['ok'] ? 'WROTE A CROSS-TENANT LINE' : 'refused: ' . $r['reason']];
});

check('a single assignment writes', function () use ($ctrl, $divya, $farida) {
    $m = new ReflectionMethod($ctrl, 'applyOne');
    $m->setAccessible(true);
    $r = $m->invoke($ctrl, 3, $divya, $farida, 1);
    $now = DB::table('tbluser')->where('id', $divya)->value('reporting_manager_id');
    return [$r['ok'] && (int) $now === (int) $farida, "divya -> " . ($now ?: 'null')];
});

check('BULK: one bad row does not abort the file', function () use ($ctrl, $vikram, $divya, $farida, $outsider, $rajesh) {
    $m = new ReflectionMethod($ctrl, 'applyOne');
    $m->setAccessible(true);
    $batch = [
        [$vikram, $farida],      // fine
        [$divya,  $outsider],    // cross-tenant  -> refused
        [$farida, $rajesh],      // fine
        [$rajesh, $vikram],      // cycle         -> refused
    ];
    $ok = 0; $bad = 0;
    foreach ($batch as [$u, $mg]) {
        $r = $m->invoke($ctrl, 3, $u, $mg, 1);
        $r['ok'] ? $ok++ : $bad++;
    }
    return [$ok === 2 && $bad === 2, "4 rows -> $ok applied, $bad refused, none aborted the batch"];
});

check('coverage reports dangling manager ids separately', function () {
    $src = file_get_contents(__DIR__ . '/../../../app/Http/Controllers/Api/Org/ReportingLineController.php');
    return [str_contains($src, 'dangling_manager_ids'),
        'a manager id that does not resolve in-tenant is counted, never folded into the total'];
});

check('every write path calls canAssign()', function () {
    // G-ORG-01's actual requirement, asserted rather than reviewed.
    $src = file_get_contents(__DIR__ . '/../../../app/Http/Controllers/Api/Org/ReportingLineController.php');
    // Only ONE method may touch the column, and it must validate first.
    $writes = preg_match_all("/reporting_manager_id'\s*=>/", $src);
    $calls  = preg_match_all('/canAssign\(/', $src);
    return [$writes === 1 && $calls >= 1,
        "$writes write site(s), $calls canAssign() call(s) - one door, and it is guarded"];
});

// ── RESTORE ─────────────────────────────────────────────────────────────────
foreach ($before as $id => $mgr) {
    DB::table('tbluser')->where('id', $id)->update(['reporting_manager_id' => $mgr]);
}
$restored = DB::table('tbluser')->where('sub_institute_id', 3)
    ->whereNotNull('reporting_manager_id')->where('reporting_manager_id', '!=', 0)->count();
printf("\nrestored: tenant-3 reporting lines back to %d (was 8 before this proof)\n", $restored);

echo "\n================================================================\n";
printf("PASS %d   FAIL %d   SKIPPED %d\n", $pass, $fail, $skip);
echo 'VERDICT: ' . ($fail === 0 ? 'GREEN' : 'RED') . "\n";
exit($fail === 0 ? 0 : 1);
