<?php
/**
 * EVERY ROUTE, DOES ITS METHOD EXIST?
 *
 * Asked because O-05's residue turned up `earlyGoingHrmsAttendanceReportCreate`
 * routed at `routes/hrms.php:105` and absent from all of `app/`. A route whose
 * action does not exist fatals on every call - and NOTHING in this codebase
 * would report it until somebody clicked it.
 *
 * Reflection, not grep: `__call`, inherited methods and traits all count as
 * existing, and a text search would score every one of them a false positive.
 */
require __DIR__ . '/../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$routes = app('router')->getRoutes();
$missClass = $missMethod = $ok = $closure = 0;
$bad = [];

foreach ($routes as $r) {
    $action = $r->getAction();
    $uses = $action['uses'] ?? null;
    if (!is_string($uses)) { $closure++; continue; }
    if (strpos($uses, '@') === false) { $closure++; continue; }
    [$class, $method] = explode('@', $uses, 2);

    if (!class_exists($class)) {
        $missClass++;
        $bad[] = ['CLASS MISSING', $class, $method, $r->uri(), implode('|', $r->methods())];
        continue;
    }
    // __call / __callStatic make a class answer to anything - not a defect
    if (method_exists($class, $method) || method_exists($class, '__call')) { $ok++; continue; }

    $missMethod++;
    $bad[] = ['METHOD MISSING', $class, $method, $r->uri(), implode('|', $r->methods())];
}

printf("routes registered      : %d\n", count($routes));
printf("closure / invokable    : %d\n", $closure);
printf("controller@method OK   : %d\n", $ok);
printf("CLASS MISSING          : %d\n", $missClass);
printf("METHOD MISSING         : %d\n", $missMethod);
echo "\n";

foreach ($bad as $b) {
    printf("  %-14s %-52s @%-42s %s %s\n",
        $b[0], substr(strrchr('\\'.$b[1], '\\'), 1), $b[2], $b[4], $b[3]);
}

echo "\nEVERY ROW ABOVE FATALS ON EVERY CALL.\n";

// ---- SPLIT BY CAUSE. 197 is a pattern, not 197 defects (R6). ----
$RESOURCE = ['index','create','store','show','edit','update','destroy'];
$byCause = ['RESOURCE VERB' => [], 'BESPOKE NAME' => []];
foreach ($bad as $b) {
    $byCause[in_array($b[2], $RESOURCE, true) ? 'RESOURCE VERB' : 'BESPOKE NAME'][] = $b;
}
echo "\n=== SPLIT BY CAUSE ===\n";
foreach ($byCause as $k => $v) printf("  %-14s %d\n", $k, count($v));
echo "\n=== BESPOKE NAMES - somebody wrote a route MEANING a method ===\n";
foreach ($byCause['BESPOKE NAME'] as $b)
    printf("  %-46s @%-40s %s %s\n", class_basename($b[1]), $b[2], $b[4], $b[3]);
