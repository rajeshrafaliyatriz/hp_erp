<?php
/**
 * C23 RE-RUN, SCOPED TO THE 43 ACTIONABLE FAILS.
 *
 * **A FAIL list is a photograph, not a fact.** The full sweep ran 2026-08-06 and
 * three of three spot-checked entries had already expired. This re-runs THE SAME
 * PROPERTY against only the routes that sweep named as FAIL and that are not
 * behind the 51.
 *
 * The property, unchanged: the same user calls each route twice, once with their
 * own `sub_institute_id` and once with another tenant's. **A different response
 * means the route honoured the caller's tenant claim rather than the token's.**
 *
 * WHY SCOPED: the full 912-route sweep exceeded the time budget in-process. The
 * question here is narrower - are these 43 still failing - and answering it does
 * not require re-testing 869 routes that were not in question.
 *
 * KNOWN-POSITIVE FIRST: the harness token must still resolve, or every route
 * returns 401, every result reads UNTESTABLE, and **a sweep that reached nothing
 * would look exactly like a sweep that found nothing.**
 *
 * Reads only. Writes nothing but its own result file.
 */
require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as RouteFacade;
use Laravel\Sanctum\PersonalAccessToken;

const TENANT_A = 7;
const TENANT_B = 3;
const TOKEN_A  = '4554|slYeN3HOca8AMIt2bz1bcl31nkdOOKm80HWZ6MPRe7c12925';

// ── KNOWN-POSITIVE: the token must resolve, or nothing below means anything ──
$pat = PersonalAccessToken::findToken(TOKEN_A);
if (!$pat) {
    exit("REFUSING: the harness token no longer resolves. Every route would return\n"
       . "401 and the sweep would report zero failures - indistinguishable from\n"
       . "everything being fixed.\n");
}
printf("token resolves: user %d, tenant %d\n\n", $pat->tokenable_id,
    DB::table('tbluser')->where('id', $pat->tokenable_id)->value('sub_institute_id'));

// ── the routes the old sweep called FAIL, minus those behind the 51 ─────────
$old = json_decode(file_get_contents(__DIR__ . '/c23-result-FULL-912.json'), true);
$blockedCtrl = ['CompetencyDashboardController', 'EmployeeDirectoryAnalyticsController', 'HrmsLeaveController'];

$targets = [];
foreach ($old as $row) {
    if (!in_array('FAIL', array_map(fn ($x) => strtoupper((string) $x), $row), true)) continue;
    $action = (string) $row[0];
    foreach ($blockedCtrl as $b) if (str_contains($action, $b)) continue 2;
    $targets[$action] = (string) $row[1];   // action => uri
}
printf("targets: %d actionable FAIL routes (3 blocked ones excluded)\n\n", count($targets));

function call_route($kernel, string $uri, array $extra): array
{
    $req = Illuminate\Http\Request::create('/' . ltrim($uri, '/'), 'GET', array_merge([
        'token' => TOKEN_A, 'type' => 'API', 'syear' => '2025',
    ], $extra));
    $req->headers->set('Accept', 'application/json');
    $req->headers->set('Authorization', 'Bearer ' . explode('|', TOKEN_A, 2)[1]);
    try {
        $res = $kernel->handle($req);
        return [$res->getStatusCode(), (string) $res->getContent()];
    } catch (Throwable $e) {
        return [0, 'EXCEPTION: ' . $e->getMessage()];
    }
}

// only routes that still exist and accept GET
$live = [];
foreach (RouteFacade::getRoutes() as $r) {
    if (in_array('GET', $r->methods(), true)) $live[$r->uri()] = true;
}

$now = ['LEAK' => [], 'REFUSED' => [], 'ERROR' => [], 'OTHER' => [],
        'PASS' => [], 'UNTESTABLE' => [], 'GONE' => [], 'VACUOUS' => []];

foreach ($targets as $action => $uri) {
    if (!isset($live[$uri])) { $now['GONE'][] = $action; continue; }

    [$s1, $b1] = call_route($kernel, $uri, ['sub_institute_id' => TENANT_A]);
    [$s2, $b2] = call_route($kernel, $uri, ['sub_institute_id' => TENANT_B]);

    if ($s1 === 401 || $s1 === 403)      { $now['UNTESTABLE'][] = $action; continue; }
    if (strlen($b1) < 3)                 { $now['VACUOUS'][] = $action; continue; }

    // ── THREE OUTCOMES, THREE VERDICTS ─────────────────────────────────────
    //
    // The original property asked one question - "did the response differ?" -
    // and scored a LEAK and a REFUSAL identically. credentialStatus answers 200
    // for its own tenant and 403 for another; that is CORRECT and it scored
    // FAIL. Working the list unsplit would have "fixed" a route that was right.
    //
    // This is G-SEC-11's inverted signal one layer down, and it is the second
    // time that exact confusion cost a verdict, so the split lives in the
    // harness rather than in whoever reads its output.
    if ($s1 === $s2 && $b1 === $b2)                  { $now['PASS'][] = $action; }
    elseif ($s2 === 403 || $s2 === 401)              { $now['REFUSED'][] = $action; }
    elseif ($s1 >= 500 || $s2 >= 500)                { $now['ERROR'][] = $action; }
    elseif ($s1 === 200 && $s2 === 200)              { $now['LEAK'][] = $action; }
    else                                             { $now['OTHER'][] = $action; }
}

echo "RESULT vs the 2026-08-06 sweep\n";
foreach (['LEAK', 'REFUSED', 'ERROR', 'OTHER', 'PASS', 'UNTESTABLE', 'VACUOUS', 'GONE'] as $k) {
    printf("  %-11s %d\n", $k, count($now[$k]));
}

printf("\n  WAS FAIL, NOW PASSES (fixed since the sweep): %d\n", count($now['PASS']));
foreach (array_slice($now['PASS'], 0, 12) as $a) echo "    $a\n";

printf("
  LEAK - both 200, DIFFERENT DATA. The real remainder: %d
", count($now['LEAK']));
foreach ($now['LEAK'] as $a) echo "    $a
";

printf("
  REFUSED - one 200, one 4xx. CORRECT, not a defect: %d
", count($now['REFUSED']));
foreach ($now['REFUSED'] as $a) echo "    $a
";

// A route that 5xx-es for BOTH callers reads as PASS to a differential property:
// identical failure is identical. Reported separately so it cannot hide.
printf("
  ERROR - 5xx for both callers, broken for everyone: %d
", count($now['ERROR']));
foreach ($now['ERROR'] as $a) echo "    $a
";

file_put_contents(__DIR__ . '/c23-rerun-43-result.json', json_encode($now, JSON_PRETTY_PRINT));
echo "\nwritten: c23-rerun-43-result.json\n";
