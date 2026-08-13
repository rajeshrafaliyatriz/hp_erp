<?php
/**
 * WHAT ELSE AUTHENTICATES THAT THE DETECTOR CANNOT NAME?
 *
 * Asked BEFORE re-counting, because the lesson of the 338 is that THE DETECTOR'S
 * VOCABULARY WAS THE POPULATION. My filter looked for guardApiToken, guardAdmin,
 * resolveApiIdentity, apiTenantId and competencyContext. jobroleskillcontroller
 * refuses with 401 "Token not provided" via a PersonalAccessToken lookup none of
 * those names cover - and the import had been visible for three turns.
 *
 * So this does not add PersonalAccessToken and re-run. It DISCOVERS the
 * vocabulary: every [api]-only write route's body is scanned for anything that
 * could plausibly reject a caller, and the distinct mechanisms are counted.
 *
 * READ ONLY. Nothing is called, nothing is written.
 */
require __DIR__ . '/../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

/** Anything that could plausibly authenticate or reject. Deliberately wide. */
$MECHANISMS = [
    'PersonalAccessToken'   => '/PersonalAccessToken::|PersonalAccessToken\s*::/',
    'findToken'             => '/->findToken\(|::findToken\(/',
    'guardApiToken'         => '/guardApiToken\s*\(/',
    'guardAdmin'            => '/guardAdmin\s*\(/',
    'resolveApiIdentity'    => '/resolveApiIdentity\s*\(/',
    'apiTenantId'           => '/apiTenantId\s*\(/',
    'competencyContext'     => '/competencyContext\s*\(/',
    '$this->guard('         => '/\$this->guard\s*\(/',
    'auth()->'              => '/\bauth\s*\(\s*\)\s*->/',
    'Auth::'                => '/\bAuth::/',
    '$request->user()'      => '/\$request->user\s*\(\s*\)/',
    'Sanctum'               => '/Sanctum/',
    'abort(401|403)'        => '/abort\s*\(\s*(401|403)/',
    'json(...,401|403)'     => '/->json\([^;]*?,\s*(401|403)\s*\)/s',
    'unauthori(s|z)ed text' => '/[Uu]nauthori[sz]ed|Token not provided|Invalid token/',
];

$rows = [];
foreach (app('router')->getRoutes() as $r) {
    if (!array_intersect($r->methods(), ['POST', 'PUT', 'PATCH', 'DELETE'])) continue;
    if ($r->gatherMiddleware() !== ['api']) continue;
    $uses = $r->getAction()['uses'] ?? null;
    if (!is_string($uses) || !str_contains($uses, '@')) continue;
    [$c, $m] = explode('@', $uses, 2);
    if (!class_exists($c) || !method_exists($c, $m)) continue;
    try { $rm = new ReflectionMethod($c, $m); } catch (Throwable $e) { continue; }
    $f = $rm->getFileName();
    if (!$f || !is_file($f)) continue;

    $lines = file($f);
    $body  = implode('', array_slice($lines, $rm->getStartLine() - 1, $rm->getEndLine() - $rm->getStartLine() + 1));
    // A guard may live in a helper the method calls, or in the constructor, so
    // the WHOLE FILE is the honest scope for "does this controller authenticate".
    $whole = implode('', $lines);

    // FOLLOW THE TRAITS. The three leave controllers matched NOTHING - not one of
    // twelve mechanisms, not even a 401 string - and all three authenticate, via
    // ResolvesLeaveContext::leaveContext() -> resolveApiIdentity(). THE GUARD WAS
    // ONE FILE AWAY. Reading the controller file only is the same scope error as
    // reading the method body only, one level up.
    try {
        foreach ((new ReflectionClass($c))->getTraits() as $tr) {
            $tf = $tr->getFileName();
            if ($tf && is_file($tf)) $whole .= file_get_contents($tf);
        }
        $parent = get_parent_class($c);
        if ($parent) {
            $pf = (new ReflectionClass($parent))->getFileName();
            if ($pf && is_file($pf)) $whole .= file_get_contents($pf);
        }
    } catch (Throwable $e) {}

    $found = [];
    foreach ($MECHANISMS as $name => $re) {
        if (preg_match($re, $whole)) $found[] = $name;
    }
    $rows[] = ['uri' => $r->uri(), 'class' => class_basename($c), 'method' => $m,
               'found' => $found, 'body' => $body];
}

printf("[api]-only write routes                 : %d\n\n", count($rows));

echo "=== MECHANISMS PRESENT, BY FREQUENCY ===\n";
$freq = [];
foreach ($rows as $x) foreach ($x['found'] as $n) $freq[$n] = ($freq[$n] ?? 0) + 1;
arsort($freq);
foreach ($freq as $n => $c) printf("  %-24s %d routes\n", $n, $c);

$none = array_values(array_filter($rows, fn ($x) => !$x['found']));
printf("\n=== NO RECOGNISED MECHANISM ANYWHERE IN THE FILE : %d ===\n", count($none));

// The OLD detector's vocabulary, for the struck-through comparison.
$OLD = ['guardApiToken', 'guardAdmin', 'resolveApiIdentity', 'apiTenantId', 'competencyContext', '$this->guard('];
$oldUnguarded = array_values(array_filter($rows, function ($x) use ($OLD) {
    return !array_intersect($x['found'], $OLD);
}));
printf("=== unguarded BY THE OLD VOCABULARY ONLY          : %d ===\n", count($oldUnguarded));

echo "\n=== THE ROUTES WITH NOTHING AT ALL (first 12) ===\n";
foreach (array_slice($none, 0, 12) as $x)
    printf("  %-40s %s@%s\n", $x['uri'], $x['class'], $x['method']);
