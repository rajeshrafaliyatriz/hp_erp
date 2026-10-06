<?php
/**
 * THE FIFTH VERDICT, applied to G-SEC-29's whole confirmed set.
 *
 * **A differential property assumes the world holds still between the two calls.**
 * Where the endpoint is what moves it, the property measures its own footprint and
 * returns LEAK forever on correct code - AuditController@export grew by exactly one
 * row per call, with the tenant held constant, and would have been "fixed".
 *
 * This calls each route TWICE WITH THE SAME TENANT. Nothing else. If the two
 * responses differ, the cross-tenant comparison that produced its verdict was
 * meaningless and the route needs reading, not editing.
 *
 * Includes the three already fixed: if any of those was self-mutating rather than
 * leaking, the fix went onto something that was not broken.
 */
require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

const TOKEN_A = '4554|slYeN3HOca8AMIt2bz1bcl31nkdOOKm80HWZ6MPRe7c12925';
const TENANT  = 7;

$uriOf = [];
foreach (json_decode(file_get_contents(__DIR__ . '/c23-result-FULL-912.json'), true) as $r) $uriOf[$r[0]] = $r[1];
$set = json_decode(file_get_contents(__DIR__ . '/c23-rerun-43-result.json'), true);
$targets = array_merge($set['LEAK'] ?? [], $set['REFUSED'] ?? [], $set['ERROR'] ?? []);

function once($kernel, string $uri): array
{
    $req = Illuminate\Http\Request::create('/' . ltrim($uri, '/'), 'GET', [
        'token' => TOKEN_A, 'type' => 'API', 'syear' => '2025', 'sub_institute_id' => TENANT,
    ]);
    $req->headers->set('Accept', 'application/json');
    $req->headers->set('Authorization', 'Bearer ' . explode('|', TOKEN_A, 2)[1]);
    try { $res = $kernel->handle($req); return [$res->getStatusCode(), (string) $res->getContent()]; }
    catch (Throwable $e) { return [0, 'EX']; }
}

$stable = []; $mutating = [];
foreach ($targets as $a) {
    $uri = $uriOf[$a] ?? null; if (!$uri) continue;
    [$s1, $b1] = once($kernel, $uri);
    [$s2, $b2] = once($kernel, $uri);
    if ($s1 === $s2 && $b1 !== $b2) { $mutating[] = [$a, strlen($b1), strlen($b2)]; }
    else { $stable[] = $a; }
}

printf("tested %d routes, same tenant, twice each\n\n", count($stable) + count($mutating));
printf("  STABLE        %d  - the cross-tenant verdict stands\n", count($stable));
printf("  SELF-MUTATING %d  - the property could not judge these\n\n", count($mutating));
foreach ($mutating as [$a, $l1, $l2]) printf("    %-52s %db -> %db\n", $a, $l1, $l2);
