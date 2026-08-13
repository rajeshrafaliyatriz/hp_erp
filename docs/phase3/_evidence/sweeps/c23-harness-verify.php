<?php
/**
 * C23 HARNESS VERIFICATION — does the shared-kernel run agree with an isolated one?
 *
 * WHY THIS EXISTS. G-HARNESS-01 showed that reusing one HTTP kernel across
 * requests in a single process caches the first request's resolved identity and
 * reuses it. C23 issues TWO requests per route in one process, across ~900
 * routes, and C24's release gate rests on its verdicts.
 *
 * I argued C23 was probably unaffected because "its calls carry no token".
 * THAT ARGUMENT WAS WRONG - c23-tenant-guard.php:34 defines TOKEN_A and
 * call_route() sends it both as a parameter and as a Bearer header. So the
 * question is open and is settled here by measurement.
 *
 * METHOD. For a sample of routes with recorded verdicts, re-run the SAME
 * baseline/attack pair with ONE REQUEST PER PROCESS, and compare the verdict to
 * the recorded one. Agreement across every verdict class - especially FAIL and
 * PASS - is what clears the guard. A disagreement invalidates it.
 *
 * Usage:  php c23-harness-verify.php <uri> <tenant>      (one request, one process)
 */
require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Http\Request;

const TOKEN_A = '4554|slYeN3HOca8AMIt2bz1bcl31nkdOOKm80HWZ6MPRe7c12925';

$uri    = $argv[1];
$tenant = $argv[2];

$params = [
    'token'            => TOKEN_A,
    'user_id'          => 198,
    'type'             => 'API',
    'syear'            => date('Y'),
    'sub_institute_id' => $tenant,
];

$req = Request::create('/' . ltrim($uri, '/'), 'GET', $params);
$req->headers->set('Authorization', 'Bearer ' . explode('|', TOKEN_A, 2)[1]);
$req->headers->set('Accept', 'application/json');

try {
    $res  = $kernel->handle($req);
    $code = $res->getStatusCode();
    $body = (string) $res->getContent();
} catch (Throwable $e) {
    $code = -1;
    $body = 'EXCEPTION: ' . $e->getMessage();
}

/* stdout is consumed by the comparer: status, length, and a hash of the body */
echo json_encode(['status' => $code, 'len' => strlen($body), 'sha' => sha1($body)]), "\n";
