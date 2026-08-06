<?php
/**
 * C34 - STRUCTURAL no-scoping detection. No unique string required.
 *
 * The gap this closes (R10, stated when the C23 guard was built): a route with NO
 * tenant scoping returns EVERYONE's rows to EVERYONE, produces two identical
 * responses under the differential test, and passes cleanly. C28 tried to close it
 * with content markers and failed - essentially no title in tenant 3's library is
 * unique to it, because every tenant is seeded from the same global libraries.
 *
 * This test needs no unique string:
 *
 *   call the route as tenant A (own id)  ->  body A
 *   call the route as tenant B (own id)  ->  body B
 *
 *   bodies DIFFER    -> each tenant sees its own data. SCOPED.
 *   bodies IDENTICAL -> both tenants see the same rows. NO SCOPING (candidate).
 *
 * KNOWN-POSITIVE (R16): `GET /api/skills` - already proven by the C23 guard to
 * ignore tenant scoping (297,582 bytes for a foreign tenant vs 84,363 own).
 * If C34 does not flag it, C34's output is not quoted.
 *
 * R10 - the gap that remains:
 *   passes proxy, fails property : n/a
 *   FAILS PROXY, PASSES PROPERTY : a route that legitimately serves GLOBAL
 *     reference data (s_jobrole, master_skills, s_jobrole_skills - which C33
 *     proved have no tenant column at all) SHOULD return identical bodies to both
 *     tenants. Those are correct, not defects, and must be separated by hand.
 */

require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require_once 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Route as R;
use Illuminate\Http\Request;

const A = ['tenant' => 7, 'user' => 198, 'tok' => '4554|slYeN3HOca8AMIt2bz1bcl31nkdOOKm80HWZ6MPRe7c12925'];
const B = ['tenant' => 3, 'user' => 6,   'tok' => '4556|810NdPEIGaVQe5JlXbQrYsW37Tb35ygblECybfys924c32ae'];

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$only = $argv[1] ?? null;

function call($kernel, string $uri, array $who): array {
    $req = Request::create('/' . ltrim($uri, '/'), 'GET', [
        'token' => $who['tok'], 'user_id' => $who['user'],
        'sub_institute_id' => $who['tenant'], 'type' => 'API', 'syear' => date('Y'),
    ]);
    $req->headers->set('Authorization', 'Bearer ' . explode('|', $who['tok'], 2)[1]);
    $req->headers->set('Accept', 'application/json');
    try { $r = $kernel->handle($req); return [$r->getStatusCode(), (string) $r->getContent()]; }
    catch (\Throwable $e) { return [-1, 'EX']; }
}

$rows = [];
foreach (R::getRoutes() as $route) {
    if (!in_array('GET', $route->methods(), true)) continue;
    $act = $route->getActionName();
    if ($act === 'Closure' || !str_contains($act, '@')) continue;
    [$c, $m] = explode('@', $act);
    $short = substr(strrchr($c, '\\'), 1);
    if ($only && stripos($short, $only) === false) continue;
    $uri = $route->uri();
    if (str_contains($uri, '{')) continue;

    [$sa, $ba] = call($kernel, $uri, A);
    [$sb, $bb] = call($kernel, $uri, B);

    if ($sa === -1 || $sb === -1 || $sa >= 500 || $sb >= 500) { $v = ['UNTESTABLE', "status $sa/$sb"]; }
    elseif ($sa >= 300 || $sb >= 300)                          { $v = ['UNTESTABLE', "redirect/auth $sa/$sb"]; }
    elseif (strlen($ba) < 40 || stripos($ba, '<html') !== false || stripos($ba, '<!doctype') !== false)
                                                               { $v = ['VACUOUS', 'no data payload']; }
    elseif ($ba !== $bb)                                       { $v = ['SCOPED', sprintf('bodies differ (%d vs %d bytes)', strlen($ba), strlen($bb))]; }
    else                                                       { $v = ['NO-SCOPING', sprintf('IDENTICAL body for both tenants (%d bytes)', strlen($ba))]; }
    $rows[] = [$short . '@' . $m, $uri, $v[0], $v[1]];
}

$tal = array_count_values(array_column($rows, 2));
echo "\n=== C34 structural no-scoping test ===\n";
foreach (['NO-SCOPING', 'SCOPED', 'VACUOUS', 'UNTESTABLE'] as $k) printf("%-12s %d\n", $k, $tal[$k] ?? 0);

$kp = null;
foreach ($rows as $r) if ($r[1] === 'api/skills') $kp = $r;
echo "\n=== R16 KNOWN-POSITIVE GATE: GET api/skills ===\n";
echo $kp ? ($kp[2] === 'NO-SCOPING' ? "DETECTED as NO-SCOPING -> sensitivity demonstrated.\n"
          : "*** NOT DETECTED - scored {$kp[2]} ({$kp[3]}). Output NOT quoted per R16.\n")
         : "*** route not reached at all. Output NOT quoted per R16.\n";

echo "\n--- NO-SCOPING candidates ---\n";
foreach ($rows as $r) if ($r[2] === 'NO-SCOPING') printf("  %-44s %-40s %s\n", $r[0], $r[1], $r[3]);
file_put_contents('C:/Users/MILAN/Downloads/hp_erp/docs/phase3/_evidence/sweeps/c34-result.json',
    json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "\n" . count($rows) . " routes -> c34-result.json\n";
