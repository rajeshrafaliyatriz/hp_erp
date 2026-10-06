<?php
/**
 * Q3: ROUTED METHODS THAT RETURN NOTHING USEFUL.
 *
 * KNOWN-POSITIVES, NAMED BEFORE THE PATTERN RUNS - and they are instances the
 * pattern is MEANT TO FIND, not merely files that satisfy it:
 *
 *   HrmsController.php:1947          getHolidays   `return  $request;`
 *   CustomModuleController.php:804   menuLevel2    `return $request;`
 *
 * Both were found by accident, both had never returned data, and nobody noticed.
 * If this pattern does not report BOTH, it SKIPS - a number from an instrument
 * that cannot find the two cases we already know about is worthless.
 *
 * R16, AMENDED: the known-positive must be an instance the pattern is meant to
 * find, and the test is that it WOULD BE A REAL FINDING if reported. Q4's bell
 * "passed" by being a notification file with no fetch - which proved it was
 * findable, not that the pattern found hardcoded data.
 *
 * READ ONLY.
 */
require __DIR__ . '/../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

require_once __DIR__ . '/_lib.php';
if ($fault = stripCommentsSelfTest()) {
    fwrite(STDERR, "REFUSING: the shared comment stripper is unsound - $fault\n");
    exit(1);
}

/** Shapes that return the caller's own input, or nothing at all. */
$SHAPES = [
    'returns $request'        => '/return\s+\$request\s*;/',
    'returns $request->all()' => '/return\s+\$request->all\(\)\s*;/',
    'json($request)'          => '/return\s+response\(\)->json\(\s*\$request\s*[,)]/',
    'returns null/void'       => '/return\s*(null)?\s*;\s*\}\s*$/',
    'empty body'              => '/\{\s*\}\s*$/',
];

$rows = [];
foreach (app('router')->getRoutes() as $r) {
    $uses = $r->getAction()['uses'] ?? null;
    if (!is_string($uses) || !str_contains($uses, '@')) continue;
    [$c, $m] = explode('@', $uses, 2);
    if (!class_exists($c) || !method_exists($c, $m)) continue;
    try { $rm = new ReflectionMethod($c, $m); } catch (Throwable $e) { continue; }
    $f = $rm->getFileName();
    if (!$f || !is_file($f)) continue;
    $body = implode('', array_slice(file($f), $rm->getStartLine() - 1,
        $rm->getEndLine() - $rm->getStartLine() + 1));

    // STRIP COMMENTS VIA THE SHARED HELPER. This script is the reason _lib.php
    // exists: its first run matched a line somebody had already DISABLED and
    // reported 47 echo-the-request endpoints when the real number is 2.
    //
    // The inline copy that replaced it was ALSO mangled - a heredoc turned its
    // \n into a literal newline mid-pattern. Importing removes both failure
    // modes at once: nothing to forget, and nothing to re-type.
    $body = stripComments($body);

    foreach ($SHAPES as $name => $re) {
        if (preg_match($re, $body)) {
            $rows[] = ['file' => basename($f), 'line' => $rm->getStartLine(),
                       'action' => class_basename($c) . '@' . $m,
                       'uri' => implode('|', $r->methods()) . ' ' . $r->uri(),
                       'shape' => $name];
            break;
        }
    }
}

/* ---- THE GATE: both known-positives, or nothing is reported --------------- */
$kp1 = false; $kp2 = false;
foreach ($rows as $x) {
    if ($x['action'] === 'HrmsController@getHolidays') $kp1 = true;
    if ($x['action'] === 'CustomModuleController@menuLevel2') $kp2 = true;
}
if (!$kp1 || !$kp2) {
    echo "SKIPPED - the pattern did not report its known-positives.\n";
    printf("   getHolidays found : %s\n", $kp1 ? 'yes' : 'NO');
    printf("   menuLevel2 found  : %s\n", $kp2 ? 'yes' : 'NO');
    echo "   A count from an instrument that cannot find the two cases we already\n";
    echo "   know about would be worthless. Nothing is reported.\n";
    exit(0);
}

echo "KNOWN-POSITIVES: getHolidays yes, menuLevel2 yes - the gate passes.\n";
printf("ROUTED METHODS RETURNING NOTHING USEFUL : %d\n\n", count($rows));

$byShape = [];
foreach ($rows as $x) $byShape[$x['shape']] = ($byShape[$x['shape']] ?? 0) + 1;
arsort($byShape);
foreach ($byShape as $s => $n) printf("  %-24s %d\n", $s, $n);

echo "\n=== EVERY ONE THAT RETURNS THE CALLER'S OWN INPUT ===\n";
foreach ($rows as $x) {
    if (!str_contains($x['shape'], 'request')) continue;
    printf("  %-46s %-38s %s\n", $x['uri'], $x['action'], $x['shape']);
}
