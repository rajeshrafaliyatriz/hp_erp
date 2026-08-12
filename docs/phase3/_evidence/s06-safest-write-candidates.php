<?php
/**
 * Pick the SAFEST mutating route among the 338 that carry only [api].
 *
 * READ ONLY. This script chooses; it does not call anything.
 *
 * "Safest" is defined before looking, so the definition cannot be bent to fit a
 * convenient candidate:
 *   1. it MUTATES            - a non-mutating route cannot answer the question
 *   2. it is NOT DESTRUCTIVE - no delete/forceDelete/truncate anywhere in the body
 *   3. ONE table, ONE insert - so a row that lands can be identified and removed
 *   4. no side effects       - no Mail, no Notification, no Http, no Storage,
 *                              no queue dispatch: nothing that leaves the database
 *   5. no file upload        - multipart writes leave artefacts outside the DB
 *   6. it takes a tenant     - so it can be pointed at 999999 and nowhere else
 */
require __DIR__ . '/../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$MUT  = '/->(insert|insertGetId|updateOrInsert|updateOrCreate|firstOrCreate|save|create)\s*\(/i';
$DEST = '/->(delete|forceDelete|truncate)\s*\(/i';
$SIDE = '/\b(Mail::|Notification::|Http::|Storage::|dispatch\(|Bus::|Queue::|curl_|file_put_contents|shell_exec)/i';
$FILE = '/->(file|hasFile)\s*\(|UploadedFile/i';

$rows = [];
foreach (app('router')->getRoutes() as $r) {
    if (!in_array('POST', $r->methods(), true)) continue;
    if ($r->gatherMiddleware() !== ['api']) continue;
    if (strpos($r->uri(), '{') !== false) continue;          // no path params to guess
    $uses = $r->getAction()['uses'] ?? null;
    if (!is_string($uses) || strpos($uses, '@') === false) continue;
    [$c, $m] = explode('@', $uses, 2);
    if (!class_exists($c) || !method_exists($c, $m)) continue;

    try { $rm = new ReflectionMethod($c, $m); } catch (Throwable $e) { continue; }
    $f = $rm->getFileName();
    if (!$f || !is_file($f)) continue;
    $lines = file($f);
    $body = implode('', array_slice($lines, $rm->getStartLine() - 1, $rm->getEndLine() - $rm->getStartLine() + 1));

    if (!preg_match($MUT, $body)) continue;
    if (preg_match($DEST, $body)) continue;
    if (preg_match($SIDE, $body)) continue;
    if (preg_match($FILE, $body)) continue;

    preg_match_all("/DB::table\(\s*'([a-z_]+)'\s*\)/i", $body, $tm);
    $tables = array_values(array_unique($tm[1]));
    $inserts = preg_match_all($MUT, $body);
    $takesTenant = (bool) preg_match('/sub_institute_id/', $body);

    if (count($tables) !== 1 || $inserts !== 1 || !$takesTenant) continue;

    // 7. DOES THE CONTROLLER GUARD ITSELF?
    //
    // Added after the first pick turned out to call guardApiToken() and
    // guardAdmin() in its own body. Testing a route that authenticates itself
    // would prove nothing: it would refuse, and refusing is what it is supposed
    // to do. THE ROUTES THAT COULD ACCEPT AN ANONYMOUS WRITE ARE THE ONES WITH
    // NO GUARD IN EITHER LAYER, and those are the only honest subject.
    $guarded = (bool) preg_match(
        '/guardApiToken|guardAdmin|resolveApiIdentity|apiTenantId|competencyContext|\$this->guard\(/',
        $body
    );

    // 8. IS CONTAINMENT EVEN POSSIBLE?
    //
    // THE ROUTES WHERE CONTAINMENT IS ACHIEVABLE ARE, BY CONSTRUCTION, THE ONES
    // MOST LIKELY TO BE LEAKY. A route that resolves its tenant from the TOKEN
    // cannot be pointed at a disposable tenant by a request field - which is the
    // correct behaviour this engagement installed across twenty controllers. It
    // is therefore safe to attack and IMPOSSIBLE TO CONTAIN.
    //
    // The first probe ignored this and wrote a row into TENANT 1 while its
    // cleanup watched tenant 999999. So this is a selection criterion, not a
    // coincidence: only a route that takes sub_institute_id FROM THE REQUEST can
    // be aimed at 999999 and cleaned up by that key.
    $requestScoped = (bool) preg_match(
        '/\->(input|get|query|post)\(\s*.sub_institute_id|\->sub_institute_id/',
        $body
    );

    $rows[] = [
        'uri'    => $r->uri(),
        'action' => class_basename($c) . '@' . $m,
        'table'  => $tables[0],
        'lines'  => $rm->getEndLine() - $rm->getStartLine(),
        'guarded' => $guarded,
        'contained' => $requestScoped,
    ];
}

$unguarded = array_values(array_filter($rows, fn ($x) => !$x['guarded'] && $x['contained']));
printf("candidates meeting the six conditions : %d\n", count($rows));
printf("  of those, GUARDED IN THE CONTROLLER : %d\n", count($rows) - count($unguarded));
printf("  UNGUARDED **AND** CONTAINABLE       : %d   <- the only valid subjects\n\n", count($unguarded));
usort($unguarded, fn ($a, $b) => $a['lines'] <=> $b['lines']);
foreach (array_slice($unguarded, 0, 10) as $x) {
    printf("  %-40s %-44s -> %-26s %d lines\n", $x['uri'], $x['action'], $x['table'], $x['lines']);
}
