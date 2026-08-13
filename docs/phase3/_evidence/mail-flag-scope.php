<?php
/**
 * DOES "EMAIL IS OFF" COVER EVERY SEND SITE, OR ONLY THE NOTIFICATION PATH?
 *
 * MEASURE ONLY. Nothing is fixed, nothing is sent, nothing is written.
 *
 * `signupOtpController@sendOtp` calls `Mail::raw(...)` to any address a caller
 * supplies, is unauthenticated, and consults `G2G_NOTIFY_EMAIL` zero times. If
 * the guarantee only covers the notification path it is not a guarantee - it is a
 * property of one code path, which happens to be the one we built.
 *
 * SCOPE, WIDENED BY TODAY'S LESSON TWICE OVER:
 *   - the whole file, its TRAITS and its PARENT CLASS - because
 *     resolveApiIdentity went from 23 routes to 319 the moment traits were
 *     followed, and the guard was one file away
 *   - every send mechanism, not just Mail:: - the detector's vocabulary is the
 *     population
 */
require __DIR__ . '/../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$SEND = '/\bMail::(raw|send|to|queue|later)\b|\bNotification::(send|route)\b|->notify\s*\(|\bmail\s*\(|\bMailer\b|Mailable\b/';
$FLAG = '/G2G_NOTIFY_EMAIL|notify_email|notifications?\.email|config\([\'"]g2g\.notify/i';

/* ---- collect every send site in app/ ---------------------------------- */
$sites = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app')));
foreach ($it as $f) {
    if (!$f->isFile() || $f->getExtension() !== 'php') continue;
    $path  = $f->getPathname();
    $lines = file($path);
    foreach ($lines as $i => $ln) {
        if (!preg_match($SEND, $ln)) continue;
        if (preg_match('/^\s*(\*|\/\/)/', $ln)) continue;      // comments are not sends
        $sites[] = ['file' => $path, 'line' => $i + 1, 'code' => trim($ln)];
    }
}
printf("send sites found in app/ : %d\n", count($sites));

/* ---- does the file, its traits or its parent consult the flag? --------- */
$classOf = function (string $path): ?string {
    $src = file_get_contents($path);
    if (!preg_match('/namespace\s+([^;]+);/', $src, $ns)) return null;
    if (!preg_match('/(?:final\s+|abstract\s+)?class\s+(\w+)/', $src, $cn)) return null;
    $fq = trim($ns[1]) . '\\' . $cn[1];
    return class_exists($fq) ? $fq : null;
};

$scopeSrc = function (string $path) use ($classOf): string {
    $src = file_get_contents($path);
    $cls = $classOf($path);
    if ($cls) {
        try {
            foreach ((new ReflectionClass($cls))->getTraits() as $tr) {
                $tf = $tr->getFileName();
                if ($tf && is_file($tf)) $src .= file_get_contents($tf);
            }
            $p = get_parent_class($cls);
            if ($p) {
                $pf = (new ReflectionClass($p))->getFileName();
                if ($pf && is_file($pf)) $src .= file_get_contents($pf);
            }
        } catch (Throwable $e) {}
    }
    return $src;
};

/* ---- which files are reachable, and with what authentication? ---------- */
$routed = [];      // class => ['auth' => bool, 'uris' => []]
$AUTH = '/resolveApiIdentity\s*\(|apiTenantId\s*\(|competencyContext\s*\(|leaveContext\s*\(|guardApiToken\s*\(|PersonalAccessToken|Sanctum|\bAuth::|auth\s*\(\s*\)\s*->/';
foreach (app('router')->getRoutes() as $r) {
    $uses = $r->getAction()['uses'] ?? null;
    if (!is_string($uses) || !str_contains($uses, '@')) continue;
    [$c, $m] = explode('@', $uses, 2);
    if (!class_exists($c)) continue;
    try { $rf = (new ReflectionClass($c))->getFileName(); } catch (Throwable $e) { continue; }
    if (!$rf) continue;
    $mw = $r->gatherMiddleware();
    $hasAuthMw = (bool) array_intersect($mw, ['auth', 'auth:sanctum']);
    $routed[$rf]['uris'][] = implode('|', $r->methods()) . ' ' . $r->uri();
    $routed[$rf]['authmw'] = ($routed[$rf]['authmw'] ?? false) || $hasAuthMw;
}

$withFlag = $withoutFlag = [];
$byFile = [];
foreach ($sites as $s) $byFile[$s['file']][] = $s;

foreach ($byFile as $path => $hits) {
    $src  = $scopeSrc($path);
    $has  = (bool) preg_match($FLAG, $src);
    $rec  = ['file' => str_replace(base_path() . DIRECTORY_SEPARATOR, '', $path),
             'sends' => count($hits),
             'routed' => isset($routed[$path]),
             'authmw' => $routed[$path]['authmw'] ?? false,
             'inCodeAuth' => (bool) preg_match($AUTH, $src),
             'uris' => array_slice($routed[$path]['uris'] ?? [], 0, 2)];
    if ($has) $withFlag[] = $rec; else $withoutFlag[] = $rec;
}

printf("files containing a send  : %d\n", count($byFile));
printf("  CONSULT the flag       : %d\n", count($withFlag));
printf("  DO NOT consult it      : %d\n\n", count($withoutFlag));

echo "=== FILES THAT CONSULT THE FLAG ===\n";
foreach ($withFlag as $r) printf("  %-58s %d send(s)\n", $r['file'], $r['sends']);

echo "\n=== DO NOT CONSULT THE FLAG, AND ARE ROUTED ===\n";
$exposed = array_values(array_filter($withoutFlag, fn ($r) => $r['routed']));
foreach ($exposed as $r) {
    printf("  %-52s %d send(s)  auth-in-code:%s\n",
        $r['file'], $r['sends'], $r['inCodeAuth'] ? 'yes' : 'NO');
    foreach ($r['uris'] as $u) printf("        %s\n", $u);
}

$anon = array_values(array_filter($exposed, fn ($r) => !$r['inCodeAuth'] && !$r['authmw']));
printf("\n=== REACHABLE WITHOUT AUTHENTICATION AND NOT FLAG-GUARDED : %d ===\n", count($anon));
foreach ($anon as $r) {
    printf("  %s\n", $r['file']);
    foreach ($r['uris'] as $u) printf("        %s\n", $u);
}

$unrouted = array_values(array_filter($withoutFlag, fn ($r) => !$r['routed']));
printf("\n(not routed, so not caller-triggerable: %d file(s))\n", count($unrouted));
