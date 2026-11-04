<?php
// Joins the result files of the two mutation passes into one table (evidence file) and prints the counts.
$S = 'C:/Users/MILAN/AppData/Local/Temp/claude/c--Users-MILAN-Downloads-hp-erp/7e204d2f-9146-4ff7-82c9-07d5c31d4568/scratchpad/s10';
$read = function (string $file) use ($S): array {
    $out = [];
    foreach (is_file("$S/$file") ? file("$S/$file", FILE_IGNORE_NEW_LINES) : [] as $l) {
        $c = explode("\t", $l);
        if (count($c) >= 7) {
            $out[$c[0]] = ['area' => $c[1], 'desc' => $c[2], 'verdict' => $c[3], 'killer' => $c[4], 'detail' => $c[5], 'restored' => $c[6]];
        }
    }

    return $out;
};
$order = [];
$pass1 = [];
foreach (['results-m1.tsv', 'results-m2-gate-order.tsv', 'results-m3-records.tsv', 'results-m3b.tsv', 'results-m4-http.tsv', 'results-m5-nosend.tsv', 'results-m6-migrations.tsv'] as $f) {
    foreach ($read($f) as $id => $r) {
        // the m3 first file also holds the SUP mutants cut short by the file lock; the rerun (m3b) replaces them
        if ($f === 'results-m3-records.tsv' && str_starts_with($id, 'SUP')) {
            continue;
        }
        if (! isset($pass1[$id])) {
            $order[] = $id;
        }
        $pass1[$id] = $r;
    }
}
$pass2 = [];
foreach (glob("$S/pass2-*.tsv") as $f) {
    $pass2 += $read(basename($f));
}
$short = fn (string $k) => preg_replace('~^Outreach(Gate|Mailers)?~', '', explode(' with data set', $k)[0]);

$EQUIVALENT = [
    'CFG-05' => 'redundant: \bguarantee[sd]?\b already matches "guaranteed"',
    'CFG-14' => 'redundant: \d+%  already matches "100%"',
    'NRM-06' => 'redundant: PHP /u makes \s match the no-break space, and curly quotes and dashes are dropped as punctuation anyway',
    'ACC-06' => 'MenuRight::can() uses the organisation only to PREFER a row and falls back to the first row of the profile (read in source, app/Support/MenuRight.php:50)',
    'CCT-07' => 'ProductClaims::decide() rejects any status outside approved/retired with the same 422',
    'PCL-05' => 'the row was already found under the organisation one statement earlier; the scoped update is defence in depth',
];
$lines = [];
$counts = ['total' => 0, 'killed1' => 0, 'survived1' => 0, 'killed2' => 0, 'equivalent' => 0, 'control' => 0, 'invalid1' => 0];
foreach ($order as $id) {
    $a = $pass1[$id];
    $b = $pass2[$id] ?? null;
    $counts['total']++;
    $note = '';
    if ($a['verdict'] === 'KILLED') {
        $counts['killed1']++;
        $final = 'KILLED';
    } elseif ($a['verdict'] === 'INVALID') {
        $counts['invalid1']++;
        $final = $b ? $b['verdict'] : 'INVALID';
        if ($b && $b['verdict'] === 'KILLED') {
            $counts['killed2']++;
        }
        $note = 'pass 1: the mutant file could not be created (folder missing in the runner); rerun in pass 2';
    } else {
        $counts['survived1']++;
        if ($id === 'NS-19') {
            $counts['control']++;
            $final = 'SURVIVED (expected: a control)';
            $note = 'CONTROL: words in a comment must not fail the scan';
        } elseif ($b && $b['verdict'] === 'KILLED') {
            $counts['killed2']++;
            $final = 'KILLED';
        } elseif (isset($EQUIVALENT[$id])) {
            $counts['equivalent']++;
            $final = 'EQUIVALENT';
            $note = $EQUIVALENT[$id];
        } else {
            $final = 'OPEN: ' . ($b['verdict'] ?? 'not rerun');
        }
    }
    $lines[] = implode(' | ', [
        $id, $a['area'], $a['desc'],
        'pass 1: ' . $a['verdict'] . ($a['killer'] !== '' ? ' by ' . $short($a['killer']) : ''),
        'final: ' . $final . ($b && $b['killer'] !== '' ? ' by ' . $short($b['killer']) : ''),
        $note,
    ]);
}
foreach ($pass2 as $id => $b) {
    if (! isset($pass1[$id])) {
        $counts['total']++;
        $lines[] = implode(' | ', [$id, $b['area'], $b['desc'], 'new in pass 2', 'final: ' . $b['verdict'] . ($b['killer'] !== '' ? ' by ' . $short($b['killer']) : ''), 'written for the sentence splitter after its fix']);
        if ($b['verdict'] === 'KILLED') {
            $counts['killed2']++;
        }
    }
}
file_put_contents("$S/mutation-table.txt", implode("\n", $lines) . "\n");
echo json_encode($counts) . "\n";
