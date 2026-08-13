<?php
require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\Route;

$base = 'C:/Users/MILAN/Downloads/hp_erp/app/Http/Controllers/';
$sig  = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base));
foreach ($it as $f) {
    if ($f->getExtension() !== 'php') continue;
    $src = file_get_contents($f->getPathname());
    if (!preg_match('/type\s*==\s*["\']API["\']/', $src)) continue;
    if (!preg_match('/findToken|Token not provided|PersonalAccessToken/', $src)) continue;
    $rel = str_replace([$base, '/', '\\\\', '.php'], ['', '\\', '\\', ''], str_replace('\\', '/', $f->getPathname()));
    $sig[$rel] = true;
}

$n = 0; $noMw = [];
foreach (Route::getRoutes() as $r) {
    $a = $r->getActionName();
    if (!str_contains($a, '@')) continue;
    $short = str_replace('App\\Http\\Controllers\\', '', explode('@', $a)[0]);
    if (!isset($sig[$short])) continue;
    $n++;
    $hasAuth = false;
    foreach ($r->gatherMiddleware() as $m) {
        if (is_string($m) && preg_match('/auth|sanctum|profile|token/i', $m)) $hasAuth = true;
    }
    if (!$hasAuth) $noMw[] = [implode('|', $r->methods()), $r->uri(), $short];
}

printf("controllers with the signature : %d\n", count($sig));
printf("routes reaching them           : %d\n", $n);
printf("*** with NO auth middleware    : %d ***\n\n", count($noMw));

$byC = [];
foreach ($noMw as $x) $byC[$x[2]] = ($byC[$x[2]] ?? 0) + 1;
arsort($byC);
foreach (array_slice($byC, 0, 20, true) as $c => $k) printf("  %-58s %d\n", $c, $k);
