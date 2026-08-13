<?php
/**
 * S-06 MEASURE. THE WRITE HALF, COUNTED BEFORE IT IS COSTED.
 *
 * NOTHING HERE TOUCHES THE DATABASE. Not one query, not one write. This is a
 * census of the ROUTE TABLE and of controller SOURCE, read statically, because
 * the condition on S-06 is "no blind writes against the shared database" and a
 * census that writes to find out what writes is the thing it is measuring.
 *
 * C23's read half could be run 912 times because a GET that returns the wrong
 * tenant's rows is a leak you can observe by comparing two responses. A write
 * has no such property: it is not idempotent, it mutates shared state, and the
 * database is at 202.47.117.220 with real tenants on it.
 */
require __DIR__ . '/../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$WRITE = ['POST', 'PUT', 'PATCH', 'DELETE'];
$rows = [];

foreach (app('router')->getRoutes() as $r) {
    $verbs = array_values(array_intersect($r->methods(), $WRITE));
    if (!$verbs) continue;
    $uses = $r->getAction()['uses'] ?? null;
    if (!is_string($uses) || strpos($uses, '@') === false) continue;
    [$class, $method] = explode('@', $uses, 2);
    $rows[] = ['verb' => $verbs[0], 'uri' => $r->uri(), 'class' => $class, 'method' => $method,
               'mw' => implode(',', $r->gatherMiddleware())];
}

printf("WRITE ROUTES (POST/PUT/PATCH/DELETE)          : %d\n", count($rows));

// ---- 1. does the target method even exist? -------------------------------
$missing = array_values(array_filter($rows, fn ($x) =>
    !class_exists($x['class']) || (!method_exists($x['class'], $x['method']) && !method_exists($x['class'], '__call'))));
printf("  of those, METHOD DOES NOT EXIST            : %d   (cannot be tested; they fatal)\n", count($missing));

// ---- 2. by verb ----------------------------------------------------------
$byVerb = [];
foreach ($rows as $x) $byVerb[$x['verb']] = ($byVerb[$x['verb']] ?? 0) + 1;
ksort($byVerb);
echo "\nBY VERB\n";
foreach ($byVerb as $v => $c) printf("  %-8s %4d\n", $v, $c);

// ---- 3. authentication ---------------------------------------------------
$unauth = array_values(array_filter($rows, fn ($x) =>
    strpos($x['mw'], 'auth') === false));
printf("\nNO auth MIDDLEWARE ON THE ROUTE              : %d\n", count($unauth));

// ---- 4. what the method body actually does -------------------------------
// Static read of the source. A method that never names a write verb cannot
// mutate directly - it may still delegate, which is why this is a CANDIDATE
// split (R6) and not a verdict.
$src = [];
$reads = function ($class) use (&$src) {
    if (isset($src[$class])) return $src[$class];
    try { $f = (new ReflectionClass($class))->getFileName(); }
    catch (Throwable $e) { return $src[$class] = ''; }
    return $src[$class] = ($f && is_file($f)) ? file_get_contents($f) : '';
};
$bodyOf = function ($class, $method) use ($reads) {
    try { $rm = new ReflectionMethod($class, $method); }
    catch (Throwable $e) { return null; }
    $file = $rm->getFileName();
    if (!$file || !is_file($file)) return null;
    $lines = file($file);
    return implode('', array_slice($lines, $rm->getStartLine() - 1, $rm->getEndLine() - $rm->getStartLine() + 1));
};

$MUT = '/->(insert|update|delete|save|create|forceDelete|truncate|upsert|insertGetId|updateOrCreate|firstOrCreate)\s*\(/i';
$DESTRUCTIVE = '/->(delete|forceDelete|truncate)\s*\(/i';

$cats = ['NO METHOD' => 0, 'NO MUTATION FOUND' => 0, 'MUTATES' => 0, 'DESTRUCTIVE' => 0, 'UNREADABLE' => 0];
$destructive = [];
foreach ($rows as $x) {
    if (!class_exists($x['class']) || !method_exists($x['class'], $x['method'])) { $cats['NO METHOD']++; continue; }
    $b = $bodyOf($x['class'], $x['method']);
    if ($b === null) { $cats['UNREADABLE']++; continue; }
    if (preg_match($DESTRUCTIVE, $b)) { $cats['DESTRUCTIVE']++; $destructive[] = $x; continue; }
    if (preg_match($MUT, $b)) { $cats['MUTATES']++; continue; }
    $cats['NO MUTATION FOUND']++;
}

// R29 KNOWN-POSITIVE AND KNOWN-NEGATIVE for both patterns.
$kp = "\$q->update(['a'=>1]);";
$kn = "\$q->where('deleted', 1)->get();";
if (!preg_match($MUT, $kp) || preg_match($MUT, $kn)) { echo "\nPATTERN FAILED ITS KNOWN CASES - the split above is void\n"; exit(1); }
if (!preg_match($DESTRUCTIVE, "\$q->delete();") || preg_match($DESTRUCTIVE, "\$q->select('deleted_at');")) {
    echo "\nDESTRUCTIVE PATTERN FAILED ITS KNOWN CASES - void\n"; exit(1);
}

echo "\nWHAT THE BODY DOES  (static; a CANDIDATE split, not a verdict - R6)\n";
foreach ($cats as $k => $v) printf("  %-20s %4d\n", $k, $v);
echo "  patterns passed their known-positive AND known-negative\n";

echo "\nDESTRUCTIVE ROUTES - the ones no test may call blind\n";
$seen = [];
foreach ($destructive as $x) {
    $k = $x['class'] . '@' . $x['method'];
    if (isset($seen[$k])) continue;
    $seen[$k] = 1;
}
printf("  %d routes across %d distinct methods\n", count($destructive), count($seen));
