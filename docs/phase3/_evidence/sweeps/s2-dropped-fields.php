<?php
/**
 * S-2 (rewrite): the UI sends a field this ENDPOINT silently drops.
 *
 * KNOWN-POSITIVE (R16), named up front and asserted at the end:
 *   POST /competency/competencies -> CompetencyController@store
 *   The Command Center sends competency_type and jobrole; the validator accepts
 *   neither and the insert writes neither. (competency-library.md 2.1)
 *   IF THIS SWEEP DOES NOT FIND THAT, ITS OUTPUT IS NOT QUOTED.
 *
 * Why v1 failed, recorded so it is not repeated: it asked "is this key accepted
 * ANYWHERE in the backend?". `competency_type` IS accepted - by
 * skillLibraryController, a different endpoint. A global check is structurally
 * incapable of finding a per-endpoint drop. S-2 must be per-endpoint.
 *
 * R10 - what this measures:
 *   property : "every field the UI posts to an endpoint is stored or refused"
 *   proxy    : "a snake_case key in a frontend file that names this endpoint,
 *               absent from that controller method's validator / insert / input reads"
 *   gap      : passes proxy but fails property - a key renamed server-side.
 *              fails proxy but passes property - a key read inside a helper this
 *              script does not follow, or a literal that belongs to a different
 *              call in the same file.
 *   -> every hit is a CANDIDATE (R6).
 */

require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require_once 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Route as R;

const FE = 'C:/Users/MILAN/Downloads/g2gv0';
const BE = 'C:/Users/MILAN/Downloads/hp_erp/app';

/** every frontend .ts/.tsx, read once */
$feFiles = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(FE, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = str_replace('\\', '/', $f->getPathname());
    if (str_contains($p, '/node_modules/') || str_contains($p, '/.next/')) continue;
    if (!preg_match('/\.(ts|tsx)$/', $p)) continue;
    $feFiles[$p] = file_get_contents($p);
}

/** what a controller method actually accepts */
function accepted(string $file, string $method): ?array {
    $path = null;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BE, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) if ($f->getFilename() === $file) { $path = $f->getPathname(); break; }
    if (!$path) return null;
    $src = file_get_contents($path);
    // isolate the method body by brace matching
    if (!preg_match('/function\s+' . preg_quote($method, '/') . '\s*\(/', $src, $m, PREG_OFFSET_CAPTURE)) return null;
    $i = strpos($src, '{', $m[0][1]); if ($i === false) return null;
    $d = 0; $end = $i;
    for ($j = $i; $j < strlen($src); $j++) {
        if ($src[$j] === '{') $d++; elseif ($src[$j] === '}') { $d--; if ($d === 0) { $end = $j; break; } }
    }
    $body = substr($src, $i, $end - $i);
    $keys = [];
    foreach (['/[\'"]([a-z][a-z0-9_]{2,40})[\'"]\s*=>\s*[\'"](?:required|nullable|sometimes|integer|string|numeric|boolean|date|array|in:|exists:)/',
              '/[\'"]([a-z][a-z0-9_]{2,40})[\'"]\s*=>\s*\$request->/',
              '/\$request->(?:input|get|has|filled|boolean)\(\s*[\'"]([a-z][a-z0-9_]{2,40})[\'"]/',
              '/[\'"]([a-z][a-z0-9_]{2,40})[\'"]\s*=>/'] as $re) {
        if (preg_match_all($re, $body, $mm)) $keys = array_merge($keys, $mm[1]);
    }
    return array_values(array_unique($keys));
}

$rows = [];
foreach (R::getRoutes() as $route) {
    $verbs = array_intersect($route->methods(), ['POST', 'PUT', 'PATCH']);
    if (!$verbs) continue;
    $action = $route->getActionName();
    if ($action === 'Closure' || !str_contains($action, '@')) continue;
    [$ctrl, $method] = explode('@', $action);
    $short = substr(strrchr($ctrl, '\\'), 1);

    $uri = '/' . ltrim($route->uri(), '/');
    $needle = preg_replace('#^/api#', '', $uri);           // frontend paths usually omit /api
    if (str_contains($needle, '{') || strlen($needle) < 8) continue;

    // frontend files naming this exact endpoint
    $sent = [];
    foreach ($feFiles as $p => $txt) {
        if (!str_contains($txt, $needle)) continue;
        if (preg_match_all('/(?:^|[\s,{])([a-z][a-z0-9_]{3,40})\s*:/m', $txt, $mm)) {
            foreach ($mm[1] as $k) if (str_contains($k, '_')) $sent[$k] = basename($p);
        }
    }
    if (!$sent) continue;

    $acc = accepted($short . '.php', $method);
    if ($acc === null) continue;
    $orphan = array_diff(array_keys($sent), $acc);
    if ($orphan) $rows[] = ['endpoint' => implode('/', $verbs) . ' ' . $uri,
                            'handler' => "$short@$method",
                            'accepted' => count($acc),
                            'dropped' => array_values($orphan)];
}

usort($rows, fn($a, $b) => count($b['dropped']) <=> count($a['dropped']));
file_put_contents('C:/Users/MILAN/Downloads/hp_erp/docs/phase3/_evidence/sweeps/s2-result.json',
    json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

// ---- R16 GATE: the known-positive must be detected ----
$kp = null;
foreach ($rows as $r) if (str_contains($r['endpoint'], '/competency/competencies') && str_contains($r['handler'], 'CompetencyController@store')) $kp = $r;
echo "=== R16 KNOWN-POSITIVE GATE ===\n";
if ($kp && (in_array('competency_type', $kp['dropped']) || in_array('jobrole', $kp['dropped']))) {
    echo "DETECTED: {$kp['endpoint']} drops: " . implode(', ', $kp['dropped']) . "\n";
    echo "-> sweep has demonstrated sensitivity. Output below is quotable as CANDIDATES.\n\n";
} else {
    echo "*** NOT DETECTED. The sweep has NO demonstrated sensitivity.\n";
    echo "*** Per R16 its output is NOT quoted. Found instead: " . ($kp ? implode(',', $kp['dropped']) : 'endpoint not matched at all') . "\n\n";
}
printf("endpoints where the UI sends a key the handler never reads: %d\n\n", count($rows));
foreach (array_slice($rows, 0, 15) as $r)
    printf("  %-52s %-38s drops %d: %s\n", $r['endpoint'], $r['handler'], count($r['dropped']),
        implode(', ', array_slice($r['dropped'], 0, 5)));
