<?php
/**
 * THE `if ($type == "API")` SIGNATURE — probed as BEHAVIOUR, not read as code.
 *
 * 46 controllers gate their token check on a request field. Reading 46 files
 * would produce candidates; calling the endpoints produces facts — and since the
 * whole defect is that the CALLER decides whether checks run, the caller is the
 * right instrument.
 *
 * GET routes only. Nothing here writes.
 *
 * Run as:  php unauth-probe.php <offset> <limit>
 *
 * CHUNKED ON PURPOSE. The first version ran every route in one process and died
 * with "Allowed memory size of 536870912 bytes exhausted" before printing
 * anything — one unauthenticated endpoint returns a result set large enough to
 * exhaust 512MB, which is a finding in itself and not a harness problem to code
 * around silently. Chunking means one fatal costs one chunk, not the whole run.
 *
 * No token is sent, so there is no identity to cache and G-HARNESS-01 does not
 * apply: that leak is a cached RESOLVED IDENTITY, and an anonymous request
 * resolves none. Stated rather than assumed.
 */
require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Route;

$offset = (int) ($argv[1] ?? 0);
$limit  = (int) ($argv[2] ?? 10);
$outFile = 'C:/Users/MILAN/Downloads/hp_erp/docs/phase3/_evidence/unauth-probe-results.jsonl';

/* controllers whose token check is gated on $type */
$base = 'C:/Users/MILAN/Downloads/hp_erp/app/Http/Controllers/';
$sig  = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base)) as $f) {
    if ($f->getExtension() !== 'php') continue;
    $src = file_get_contents($f->getPathname());
    if (!preg_match('/type\s*==\s*["\']API["\']/', $src)) continue;
    if (!preg_match('/findToken|Token not provided|PersonalAccessToken/', $src)) continue;
    $sig[str_replace([$base, '/', '.php'], ['', '\\', ''], str_replace('\\', '/', $f->getPathname()))] = true;
}

$targets = [];
foreach (Route::getRoutes() as $r) {
    if (!in_array('GET', $r->methods(), true)) continue;
    $a = $r->getActionName();
    if (!str_contains($a, '@')) continue;
    $short = str_replace('App\\Http\\Controllers\\', '', explode('@', $a)[0]);
    if (!isset($sig[$short])) continue;
    if (str_contains($r->uri(), '{')) continue;          // needs a real id
    $hasAuth = false;
    foreach ($r->gatherMiddleware() as $m) {
        if (is_string($m) && preg_match('/auth|sanctum|profile|token/i', $m)) $hasAuth = true;
    }
    if ($hasAuth) continue;                              // guarded at the route
    $targets[] = ['uri' => $r->uri(), 'action' => $short];
}

if ($offset === -1) { printf("%d\n", count($targets)); exit; }

foreach (array_slice($targets, $offset, $limit) as $i => $t) {
    // Written BEFORE the call, so a fatal leaves a record of which route caused it.
    file_put_contents($outFile, json_encode($t + ['status' => 'STARTED']) . "\n", FILE_APPEND);

    $req = Illuminate\Http\Request::create('/' . ltrim($t['uri'], '/'), 'GET', ['sub_institute_id' => 1]);
    $req->headers->set('Accept', 'application/json');

    try {
        $resp = $kernel->handle($req);
        $code = $resp->getStatusCode();
        $len  = strlen($resp->getContent());
    } catch (Throwable $e) {
        $code = -1;
        $len  = 0;
    }

    file_put_contents($outFile, json_encode($t + ['status' => $code, 'len' => $len]) . "\n", FILE_APPEND);
}
