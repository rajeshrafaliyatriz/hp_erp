<?php
/*
 * Mutation runner (slice 6a). usage: php mutate.php <mutations file> <output file>
 *
 * The mutations file returns a list of ['id', 'file' (relative to hp_erp), 'find', 'replace', 'tests' (list of test files relative to hp_erp), 'why'].
 * For each distinct test set the runner FIRST proves the baseline is green (a command error would otherwise look like a 100% kill), then for each mutation:
 * the anchor must occur exactly once, the file is changed, phpunit runs, the named failures are read from the output, the file is restored and compared
 * byte for byte (md5 against the copy taken before) - a failed restore stops the run.
 */
chdir('C:/Users/MILAN/Downloads/hp_erp');
$mutations = require $argv[1];
$out = [];
$log = function (string $line) use (&$out) {
    echo $line, "\n";
    $out[] = $line;
};

function runTests(array $tests): array
{
    $cmd = 'php vendor/bin/phpunit --colors=never ' . implode(' ', array_map('escapeshellarg', $tests)) . ' 2>&1';
    exec($cmd, $lines, $code);
    $text = implode("\n", $lines);
    $failed = [];
    foreach ($lines as $l) {
        if (preg_match('/^\d+\) (\S+)/', $l, $m)) {
            $failed[] = $m[1];
        }
    }

    return ['code' => $code, 'text' => $text, 'failed' => array_values(array_unique($failed)), 'ok' => str_contains($text, 'OK (') && $code === 0, 'summary' => trim((string) end($lines))];
}

$baselines = [];
$log('Mutation run ' . date('Y-m-d H:i:s') . ' (local clock)');
foreach ($mutations as $m) {
    $key = implode('|', $m['tests']);
    if (! isset($baselines[$key])) {
        $b = runTests($m['tests']);
        $baselines[$key] = $b['ok'];
        $log('BASELINE ' . ($b['ok'] ? 'GREEN' : 'NOT GREEN - ABORT') . ' for ' . implode(' ', $m['tests']) . ' :: ' . $b['summary']);
        if (! $b['ok']) {
            file_put_contents($argv[2], implode("\n", $out) . "\n");
            fwrite(STDERR, $b['text'] . "\n");
            exit(2);
        }
    }
}

$killed = 0;
foreach ($mutations as $m) {
    $path = $m['file'];
    $original = file_get_contents($path);
    $hash = md5($original);
    $count = substr_count($original, $m['find']);
    if ($count !== 1) {
        $log("{$m['id']}: ANCHOR FOUND {$count} TIMES in {$path} - skipped (a mutation must change exactly one place)");
        continue;
    }
    $mutated = str_replace($m['find'], $m['replace'], $original);
    file_put_contents($path, $mutated);
    try {
        $r = runTests($m['tests']);
    } finally {
        file_put_contents($path, $original);
    }
    if (md5_file($path) !== $hash) {
        $log("{$m['id']}: RESTORE FAILED for {$path} - STOP");
        file_put_contents($argv[2], implode("\n", $out) . "\n");
        exit(3);
    }
    $dead = ! $r['ok'] && $r['failed'] !== [];
    $killed += $dead ? 1 : 0;
    $log(($dead ? 'KILLED  ' : 'SURVIVED') . " {$m['id']} :: {$m['why']}");
    $log('         file ' . $path . ' restored byte for byte (md5 ' . $hash . ')');
    $log('         result: ' . $r['summary']);
    if ($dead) {
        $log('         caught by: ' . implode(', ', array_slice($r['failed'], 0, 6)) . (count($r['failed']) > 6 ? ' (+' . (count($r['failed']) - 6) . ' more)' : ''));
    } elseif (! $r['ok']) {
        $log('         NOT A KILL: the run failed without a named test failure: ' . substr($r['text'], 0, 400));
    }
}
$log("Killed {$killed} of " . count($mutations));
file_put_contents($argv[2], implode("\n", $out) . "\n");
