<?php
/**
 * THE `if ($type == "API")` SIGNATURE — probed as BEHAVIOUR, not read as code.
 *
 * 44 controllers gate their token check on a request field. Reading 44 files
 * would produce candidates; calling the endpoints produces facts, and the whole
 * point of the signature is that the CALLER decides whether checks run - so the
 * caller is the right instrument.
 *
 * GET routes only. Nothing here writes.
 *
 * No token is sent, so there is no identity to cache and G-HARNESS-01 does not
 * apply: the leak is a cached RESOLVED IDENTITY, and an anonymous request
 * resolves none. Stated rather than assumed.
 */
require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Route;

/* the 44 controllers whose token check is gated on $type */
$signature = [];
foreach (glob('C:/Users/MILAN/Downloads/hp_erp/app/Http/Controllers/**/*.php', GLOB_BRACE) as $f) {}
$out = [];
exec('grep -rl "type\s*==\s*[\"\']API[\"\']" C:/Users/MILAN/Downloads/hp_erp/app/Http/Controllers/', $out);
foreach ($out as $f) {
    $src = file_get_contents(trim($f));
    if (preg_match('/type\s*==\s*["\']API["\']/', $src)
        && preg_match('/findToken|Token not provided|PersonalAccessToken/', $src)) {
        $signature[] = str_replace(['C:/Users/MILAN/Downloads/hp_erp/app/Http/Controllers/', '/', '.php'], ['', '\\', ''], trim($f));
    }
}

$rows = [];
foreach (Route::getRoutes() as $route) {
    if (!in_array('GET', $route->methods(), true)) continue;
    $action = $route->getActionName();
    if (!str_contains($action, '@')) continue;
    [$class] = explode('@', $action);
    $short = str_replace('App\\Http\\Controllers\\', '', $class);

    $hit = false;
    foreach ($signature as $s) if ($short === $s) { $hit = true; break; }
    if (!$hit) continue;

    $uri = $route->uri();
    if (str_contains($uri, '{')) continue;             // needs a real id; skip

    $mw = implode(',', $route->gatherMiddleware());
    $rows[] = ['uri' => $uri, 'action' => $short, 'mw' => $mw];
}

printf("controllers carrying the signature : %d\n", count($signature));
printf("GET routes reaching them (no {id}) : %d\n\n", count($rows));

$open = [];
foreach ($rows as $r) {
    $req = Illuminate\Http\Request::create('/' . ltrim($r['uri'], '/'), 'GET', ['sub_institute_id' => 1]);
    $req->headers->set('Accept', 'application/json');
    try {
        $resp = $kernel->handle($req);
        $code = $resp->getStatusCode();
        $len  = strlen($resp->getContent());
    } catch (Throwable $e) {
        $code = -1; $len = 0;
    }
    if ($code === 200 && $len > 40) {
        $open[] = $r + ['len' => $len];
    }
}

printf("*** ANSWERED 200 WITH NO TOKEN AND NO type: %d ***\n\n", count($open));
usort($open, fn($a, $b) => $b['len'] <=> $a['len']);
foreach ($open as $o) {
    printf("  %-52s %-46s %7d bytes  mw=[%s]\n", $o['uri'], $o['action'], $o['len'], $o['mw']);
}
