<?php
/**
 * C23 - TENANT RESOLUTION PROPERTY GUARD (read half)
 *
 * Tests the PROPERTY, not a proxy (R10):
 *
 *   A route that resolves the tenant from the TOKEN must return the SAME
 *   response no matter what sub_institute_id the caller puts in the request.
 *
 * So each GET route is called twice as the SAME tenant-A user:
 *     baseline : sub_institute_id = A   (the caller's real tenant)
 *     attack   : sub_institute_id = B   (someone else's tenant)
 *
 * Identical body  -> PASS   (the request-supplied tenant was ignored)
 * Different body  -> FAIL   (the route honoured the caller's tenant claim)
 * Cannot call     -> UNTESTABLE, never PASS. That distinction is the whole
 *                    lesson of C22: a checker that cannot see something must
 *                    say so, not score it clean.
 *
 * Routes are taken from Laravel's own router, not from a regex over the route
 * files - the authoritative list, after the 52-route and 2-of-7-files misses.
 *
 * READ HALF ONLY. GET/HEAD verbs. Nothing is written.
 */

require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require_once 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Http\Request;

const TENANT_A = 7;      // caller's real tenant
const TENANT_B = 3;      // the tenant being impersonated
const TOKEN_A  = '4554|slYeN3HOca8AMIt2bz1bcl31nkdOOKm80HWZ6MPRe7c12925';  // user 198, Employee

$only   = $argv[1] ?? null;          // optional controller-name filter
$limit  = (int)($argv[2] ?? 0);

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

/**
 * C28 - CONTENT-BASED detection.
 *
 * The differential test ("does the response change?") has a fatal gap, stated
 * under R10: a route with NO tenant scoping at all returns EVERYONE's rows to
 * EVERYONE, produces two identical responses, and passes cleanly. Given R11's
 * record, that is exactly where more will hide.
 *
 * So we also scan every response for strings that exist ONLY in tenant B. A hit
 * is a leak whether or not the two responses differ.
 *
 * Markers are long, distinctive job-role and skill titles. Personal first names
 * were deliberately EXCLUDED: tenant 3 contains a user called "Healthcare",
 * which would match any healthcare content and manufacture false positives.
 */
$MARKERS = [];
foreach (json_decode(file_get_contents(
    'C:/Users/MILAN/Downloads/hp_erp/docs/phase3/_evidence/sweeps/c28-markers.json'), true) as $k => $vals) {
    if ($k === 'user_first_name') continue;          // too short / too common
    foreach ($vals as $v) if (strlen($v) >= 12) $MARKERS[] = $v;
}

function leaked_markers(string $body, array $markers): array {
    $hits = [];
    foreach ($markers as $m) if (stripos($body, $m) !== false) $hits[] = $m;
    return $hits;
}

function call_route($kernel, string $uri, array $extra): array {
    $params = array_merge([
        'token'     => TOKEN_A,
        'user_id'   => 198,
        'type'      => 'API',
        'syear'     => date('Y'),
    ], $extra);
    $req = Request::create('/' . ltrim($uri, '/'), 'GET', $params);
    $req->headers->set('Authorization', 'Bearer ' . explode('|', TOKEN_A, 2)[1]);
    $req->headers->set('Accept', 'application/json');
    try {
        $res = $kernel->handle($req);
        return [$res->getStatusCode(), (string) $res->getContent()];
    } catch (\Throwable $e) {
        return [-1, 'EXCEPTION: ' . $e->getMessage()];
    }
}

$rows = [];
foreach (RouteFacade::getRoutes() as $route) {
    if (!in_array('GET', $route->methods(), true)) continue;
    $action = $route->getActionName();
    if ($action === 'Closure') { $rows[] = ['closure', $route->uri(), 'UNTESTABLE', 'closure route, no controller']; continue; }
    [$ctrl, $method] = array_pad(explode('@', $action), 2, '');
    $short = substr(strrchr($ctrl, '\\') ?: $ctrl, 1) ?: $ctrl;
    if ($only && stripos($short, $only) === false) continue;

    $uri = $route->uri();
    if (str_contains($uri, '{')) {
        // A path parameter we cannot fabricate honestly. Reported, not scored.
        $rows[] = [$short.'@'.$method, $uri, 'UNTESTABLE', 'requires path parameter'];
        continue;
    }

    [$s1, $b1] = call_route($kernel, $uri, ['sub_institute_id' => TENANT_A]);
    [$s2, $b2] = call_route($kernel, $uri, ['sub_institute_id' => TENANT_B]);

    if ($s1 === -1 || $s2 === -1)          $v = ['UNTESTABLE', 'exception: ' . substr($b1 === '' ? $b2 : $b1, 0, 70)];
    elseif ($s1 >= 500 || $s2 >= 500)      $v = ['UNTESTABLE', "server error ($s1/$s2)"];
    elseif ($s1 === 401 || $s1 === 403)    $v = ['UNTESTABLE', "not authenticated ($s1) - token rejected"];
    elseif ($s1 >= 300 && $s1 < 400)       $v = ['UNTESTABLE', "redirect ($s1) - html/session route"];
    // R4/R10: "identical response" is a PROXY for "tenant came from the token".
    // It fails vacuously when the route returns nothing tenant-scoped at all -
    // a Blade view shell, an empty list, an error. Those must NOT score PASS.
    // C28 runs FIRST: a marker in the baseline response means the route leaks
    // tenant B's data even when nobody asked for it - the no-scoping case the
    // differential test cannot see.
    elseif ($m = leaked_markers($b1, $MARKERS))
                                           $v = ['LEAK-NOSCOPE', 'tenant B data present with NO impersonation: ' . implode(' | ', array_slice($m, 0, 2))];
    elseif ($m = leaked_markers($b2, $MARKERS))
                                           $v = ['FAIL', 'tenant B data returned when impersonating: ' . implode(' | ', array_slice($m, 0, 2))];
    elseif ($b1 === $b2 && (stripos($b1, '<html') !== false || stripos($b1, '<!doctype') !== false))
                                           $v = ['VACUOUS', 'identical, but it is an HTML view shell - no tenant data in the response'];
    elseif ($b1 === $b2 && strlen($b1) < 40)
                                           $v = ['VACUOUS', 'identical, but response is empty/trivial (' . strlen($b1) . ' bytes)'];
    elseif ($b1 === $b2)                   $v = ['PASS', 'identical JSON payload; supplied tenant ignored'];
    else {
        $d = abs(strlen($b1) - strlen($b2));
        $v = ['FAIL', sprintf('response CHANGED with tenant B (%d vs %d bytes, delta %d)', strlen($b1), strlen($b2), $d)];
    }
    $rows[] = [$short.'@'.$method, $uri, $v[0], $v[1]];
    if ($limit && count($rows) >= $limit) break;
}

$tally = array_count_values(array_column($rows, 2));
echo "\n=== C23 read-half guard ===\n";
foreach (['LEAK-NOSCOPE','FAIL','PASS','VACUOUS','UNTESTABLE'] as $k) printf("%-11s %d\n", $k, $tally[$k] ?? 0);
echo "\n--- FAILURES (the worklist) ---\n";
foreach ($rows as $r) if ($r[2] === 'LEAK-NOSCOPE') printf("  [NOSCOPE] %-38s %-40s %s
", $r[0], $r[1], $r[3]);
foreach ($rows as $r) if ($r[2] === 'FAIL') printf("  %-46s %-44s %s\n", $r[0], $r[1], $r[3]);
file_put_contents('C:/Users/MILAN/Downloads/hp_erp/docs/phase3/_evidence/sweeps/c23-result.json',
    json_encode($rows, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo "\nfull results -> c23-result.json (" . count($rows) . " rows)\n";
