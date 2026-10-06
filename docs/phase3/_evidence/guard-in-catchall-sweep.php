<?php
/**
 * ONE-LINER SWEEP for the O-03 shape: a TENANT GUARD sitting inside a try whose
 * catch rewrites every failure into one message. A refusal and an outage then
 * return the same bytes.
 *
 * THIS IS A PATTERN, SO IT PRODUCES CANDIDATES, NOT FINDINGS (R6). Each one is a
 * route to measure, not a defect to report. O-03 is the known-positive: the two
 * methods it fixed must appear if run against the pre-fix file, and must NOT
 * appear now that they are fixed - that is the known-negative, and it is the same
 * file, so it discriminates on the thing that actually changed.
 */
$root = 'C:/Users/MILAN/Downloads/hp_erp/app/Http/Controllers';
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

// a guard = any of the known tenant resolvers
$guard = '/(resolveSubInstituteId|resolveApiIdentity|apiTenantId)\s*\(/';

$hits = [];
foreach ($it as $f) {
    if ($f->getExtension() !== 'php') continue;
    $src = file_get_contents($f->getPathname());

    // every try{...}catch(...) block, non-greedy to the first catch
    if (!preg_match_all('/\btry\s*\{(.*?)\}\s*catch\s*\(([^)]*)\)\s*\{(.*?)\n\s{8}\}/s', $src, $m, PREG_SET_ORDER)) continue;

    foreach ($m as $blk) {
        [$all, $body, $caught, $handler] = $blk;
        if (!preg_match($guard, $body)) continue;

        // does the handler DISCARD the exception's own message?
        $echoes = preg_match('/\$e->getMessage\(\)/', $handler);
        // is the guard alone in the try, or does real work follow it?
        $lines = count(array_filter(array_map('trim', explode("\n", $body))));

        if (!$echoes && $lines > 2) {
            $hits[] = sprintf('%s (%d stmts in try, catch discards the message)',
                basename($f->getPathname()), $lines);
        }
    }
}

printf("CANDIDATES (a guard inside a try whose catch rewrites everything): %d\n", count($hits));
foreach (array_unique($hits) as $h) echo '  ' . $h . "\n";
echo "\nO-03's two methods are now FIXED and must NOT appear above.\n";
echo "Their absence is the known-negative: the sweep is looking at the same file\n";
echo "and no longer names them, so it discriminates on the thing that changed.\n";
