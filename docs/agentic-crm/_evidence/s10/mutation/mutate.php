<?php
/*
 * Mutation runner for track S10a (outreach gate). Plain PHP, no framework. It NEVER sends anything: it edits a source file, runs phpunit (sqlite in memory,
 * MAIL_MAILER=array, see phpunit.xml) and puts the file back byte for byte.
 *
 *   php mutate.php <mutants-file> [id-regex]
 *
 * The mutants file returns a list of arrays:
 *   id, area, desc, edits => [[relative file, old text (must occur exactly once), new text], ...] OR create => [relative file, content],
 *   tests => primary test files (run first with --stop-on-failure); if they all pass, the whole S10a set is run before a mutant is called a survivor.
 *
 * 1. The BASELINE (the whole S10a set, no mutation) must be green, or nothing else runs: a command error would otherwise look like a 100% kill.
 * 2. Each mutated file is linted (php -l) before the run: a mutant that does not parse is INVALID, not killed.
 * 3. Afterwards every file is restored from memory and compared with the sha1 taken before the first mutation.
 */

$root = 'C:/Users/MILAN/Downloads/hp_erp';
$scratch = 'C:/Users/MILAN/AppData/Local/Temp/claude/c--Users-MILAN-Downloads-hp-erp/7e204d2f-9146-4ff7-82c9-07d5c31d4568/scratchpad/s10';

$ALL = [
    'tests/Unit/OutreachGateTextChecksTest.php',
    'tests/Unit/OutreachGateNoSendScanTest.php',
    'tests/Feature/OutreachGateChecksTest.php',
    'tests/Feature/OutreachGateMigrationsTest.php',
    'tests/Feature/OutreachGateRoutesTest.php',
    'tests/Feature/OutreachMailersBaselineTest.php',
    'tests/Feature/OutreachMailersGateTest.php',
];

$mutantsFile = $argv[1] ?? null;
$filter = $argv[2] ?? null;
if ($mutantsFile === null || ! is_file($mutantsFile)) {
    fwrite(STDERR, "usage: php mutate.php <mutants-file> [id-regex]\n");
    exit(2);
}
/*
 * A .txt mutants file is plain text, so backslashes and quotes need no escaping:
 *   ### id | area | description
 *   tests: U,C            (letters below, or ALL)
 *   file: relative/path.php
 *   old: one exact line (everything after "old: ")        or    old<<  ...lines...  >>
 *   new: replacement                                      or    new<<  ...lines...  >>
 *   (repeat file/old/new for a second edit of the same mutant)
 *   create: relative/path.php  followed by  content<<  ...lines...  >>
 */
function parseMutants(string $path, array $letters): array
{
    $lines = preg_split('/\r?\n/', (string) file_get_contents($path));
    $out = [];
    $cur = null;
    $edit = null;
    $widen = true;   // "default-widen: no" before the first mutant: a survivor of the primary tests is NOT re-run on the whole set (used where the mutation adds a real call)
    for ($i = 0; $i < count($lines); $i++) {
        $l = $lines[$i];
        if ($l === 'default-widen: no') {
            $widen = false;
            continue;
        }
        if (str_starts_with($l, '### ')) {
            if ($cur) {
                $out[] = $cur;
            }
            $parts = array_map('trim', explode('|', substr($l, 4), 3));
            $cur = ['id' => $parts[0], 'area' => $parts[1] ?? '', 'desc' => $parts[2] ?? '', 'tests' => null, 'edits' => [], 'widen' => $widen];
            $edit = null;
            continue;
        }
        if ($cur === null || $l === '' || str_starts_with($l, '#')) {
            continue;
        }
        $block = function (string $head) use (&$i, $lines): string {
            $buf = [];
            for ($i++; $i < count($lines) && $lines[$i] !== '>>'; $i++) {
                $buf[] = $lines[$i];
            }

            return implode("\n", $buf);
        };
        if (str_starts_with($l, 'tests: ')) {
            $names = array_map('trim', explode(',', substr($l, 7)));
            $cur['tests'] = in_array('ALL', $names, true) ? null : array_values(array_map(fn ($n) => $letters[$n], $names));
        } elseif (str_starts_with($l, 'file: ')) {
            $cur['edits'][] = [trim(substr($l, 6)), null, null];
            $edit = count($cur['edits']) - 1;
        } elseif (str_starts_with($l, 'create: ')) {
            $cur['create'] = [trim(substr($l, 8)), ''];
            unset($cur['edits']);
        } elseif ($l === 'content<<') {
            $cur['create'][1] = $block('content') . "\n";
        } elseif (str_starts_with($l, 'old: ')) {
            $cur['edits'][$edit][1] = substr($l, 5);
        } elseif ($l === 'old<<') {
            $cur['edits'][$edit][1] = $block('old');
        } elseif ($l === 'new:' || str_starts_with($l, 'new: ')) {
            $cur['edits'][$edit][2] = strlen($l) > 5 ? substr($l, 5) : '';
        } elseif ($l === 'new<<') {
            $cur['edits'][$edit][2] = $block('new');
        } else {
            throw new RuntimeException('mutants file: cannot read line ' . ($i + 1) . ': ' . $l);
        }
    }
    if ($cur) {
        $out[] = $cur;
    }

    return $out;
}

$LETTERS = ['U' => $ALL[0], 'S' => $ALL[1], 'C' => $ALL[2], 'M' => $ALL[3], 'R' => $ALL[4], 'B' => $ALL[5], 'G' => $ALL[6]];
$mutants = str_ends_with($mutantsFile, '.txt') ? parseMutants($mutantsFile, $LETTERS) : require $mutantsFile;
foreach ($mutants as $m) {
    if (! isset($m['create'])) {
        foreach ($m['edits'] as $e) {
            if ($e[1] === null || $e[2] === null) {
                fwrite(STDERR, "mutant {$m['id']} has an edit without old or new\n");
                exit(2);
            }
        }
    }
}
if ($filter !== null) {
    $mutants = array_values(array_filter($mutants, fn ($m) => preg_match('~' . $filter . '~', $m['id']) === 1));
}

function runPhpunit(string $root, string $scratch, array $files, bool $stop, int $limit = 600): array
{
    $out = $scratch . '/last-run.txt';
    @unlink($out);
    $cmd = [PHP_BINARY, 'vendor/bin/phpunit', '--colors=never'];
    if ($stop) {
        $cmd[] = '--stop-on-failure';
    }
    $cmd = array_merge($cmd, $files);
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $out, 'w'], 2 => ['redirect', 1]], $pipes, $root);
    if (! is_resource($p)) {
        return ['verdict' => 'ERROR', 'killer' => '', 'detail' => 'proc_open failed', 'text' => ''];
    }
    fclose($pipes[0]);
    $start = time();
    $timedOut = false;
    while (true) {
        $st = proc_get_status($p);
        if (! $st['running']) {
            break;
        }
        if (time() - $start > $limit) {
            $timedOut = true;
            proc_terminate($p);
            break;
        }
        usleep(200000);
    }
    proc_close($p);
    $text = (string) @file_get_contents($out);
    if ($timedOut) {
        return ['verdict' => 'TIMEOUT', 'killer' => '', 'detail' => "no verdict in {$limit}s (a loop?)", 'text' => $text];
    }
    if (preg_match('/^OK(?:, but[^\n]*)? ?\((\d+) tests?, (\d+) assertions?\)|^OK, but/m', $text, $m)) {
        preg_match('/^OK[^\n]*/m', $text, $line);

        return ['verdict' => 'PASS', 'killer' => '', 'detail' => trim($line[0] ?? 'OK'), 'text' => $text];
    }
    if (preg_match('/^(?:FAILURES|ERRORS)!\s*\n(Tests: [^\n]*)/m', $text, $m) || preg_match('/^(Tests: \d+, Assertions: \d+[^\n]*)/m', $text, $m)) {
        $killer = '';
        $msg = '';
        if (preg_match('/^1\) ([^\n]*)\n([^\n]*)/m', $text, $k)) {
            $killer = trim(str_replace(['Tests\\Feature\\', 'Tests\\Unit\\'], '', $k[1]));
            $msg = trim($k[2]);
        }

        return ['verdict' => 'FAIL', 'killer' => $killer, 'detail' => $m[1] . ' | ' . mb_substr($msg, 0, 160), 'text' => $text];
    }

    return ['verdict' => 'NOVERDICT', 'killer' => '', 'detail' => mb_substr(trim(substr($text, -400)), 0, 400), 'text' => $text];
}

/** A file that another process (an editor's language server, a scanner) holds for a moment is retried, never skipped: an unrestored file would be left broken. */
function writeRetry(string $path, string $bytes): bool
{
    for ($try = 0; $try < 60; $try++) {
        if (@file_put_contents($path, $bytes) !== false) {
            return true;
        }
        usleep(250000);
    }

    return false;
}

function lint(string $root, string $rel): ?string
{
    if (! str_ends_with($rel, '.php')) {
        return null;
    }
    $cmd = [PHP_BINARY, '-l', $root . '/' . $rel];
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $o = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    proc_close($p);

    return str_contains($o, 'No syntax errors') ? null : trim($o);
}

// ---------------------------------------------------------------------------------------------- the originals
$originals = [];     // rel => bytes
$sha = [];
foreach ($mutants as $m) {
    $rels = isset($m['create']) ? [] : array_map(fn ($e) => $e[0], $m['edits']);
    foreach ($rels as $rel) {
        if (! isset($originals[$rel])) {
            $originals[$rel] = file_get_contents($root . '/' . $rel);
            $sha[$rel] = sha1($originals[$rel]);
            @mkdir(dirname($scratch . '/orig/' . $rel), 0777, true);
            file_put_contents($scratch . '/orig/' . $rel, $originals[$rel]);
        }
    }
}

if (($argv[3] ?? '') === '--check') {
    // Read-only: does every anchor occur exactly once, does a created file not exist yet? Nothing is written.
    $bad = 0;
    foreach ($mutants as $m) {
        $mem = [];
        if (isset($m['create'])) {
            if (file_exists($root . '/' . $m['create'][0])) {
                echo "{$m['id']}: create target exists\n";
                $bad++;
            }
            continue;
        }
        foreach ($m['edits'] as [$rel, $old, $new]) {
            $cur = $mem[$rel] ?? file_get_contents($root . '/' . $rel);
            if (str_contains($cur, "\r\n")) {
                $old = str_replace("\n", "\r\n", $old);
                $new = str_replace("\n", "\r\n", $new);
            }
            $n = substr_count($cur, $old);
            if ($n !== 1) {
                echo "{$m['id']}: anchor occurs {$n} times in {$rel}: " . mb_substr($old, 0, 80) . "\n";
                $bad++;
                continue;
            }
            $mem[$rel] = str_replace($old, $new, $cur);
        }
    }
    echo $bad === 0 ? 'ALL ' . count($mutants) . " mutants have clean anchors\n" : "{$bad} problems\n";
    exit($bad === 0 ? 0 : 1);
}

// Guard: a file about to be mutated must be the one in the manifest of known-good files (a previous run that died would otherwise be taken as "the original").
$manifest = $scratch . '/pristine.sha1';
if (is_file($manifest)) {
    $known = [];
    foreach (file($manifest, FILE_IGNORE_NEW_LINES) as $ln) {
        if (preg_match('~^([0-9a-f]{40}) \*\./(.+)$~', $ln, $mm)) {
            $known[$mm[2]] = $mm[1];
        }
    }
    foreach ($originals as $rel => $bytes) {
        if (isset($known[$rel]) && $known[$rel] !== $sha[$rel]) {
            echo "ABORT: {$rel} is not the known-good file (sha1 differs from pristine.sha1). Nothing was mutated.\n";
            exit(5);
        }
    }
}

$current = [];   // rel => true while mutated (for the shutdown restore)
$created = [];
register_shutdown_function(function () use (&$current, &$created, $originals, $root) {
    foreach (array_keys($current) as $rel) {
        writeRetry($root . "/" . $rel, $originals[$rel]);
    }
    foreach (array_keys($created) as $rel) {
        @unlink($root . '/' . $rel);
    }
});

// ---------------------------------------------------------------------------------------------- baseline
$t0 = microtime(true);
$base = runPhpunit($root, $scratch, $ALL, false);
$secs = round(microtime(true) - $t0);
echo "BASELINE ({$secs}s): {$base['verdict']} {$base['detail']}\n";
if ($base['verdict'] !== 'PASS') {
    echo "The baseline is not green: nothing was mutated.\n" . substr($base['text'], -1500) . "\n";
    exit(1);
}

// ---------------------------------------------------------------------------------------------- mutants
$resultsFile = $scratch . '/results.tsv';
foreach ($mutants as $m) {
    $id = $m['id'];
    $touched = [];
    $madeDirs = [];
    $verdict = 'INVALID';
    $killer = '';
    $detail = '';
    $t1 = microtime(true);
    try {
        if (isset($m['create'])) {
            [$rel, $content] = $m['create'];
            if (file_exists($root . '/' . $rel)) {
                throw new RuntimeException("{$rel} already exists");
            }
            $dir = dirname($root . "/" . $rel);
            if (! is_dir($dir)) {
                mkdir($dir, 0777, true);
                $madeDirs[$rel] = $dir;
            }
            writeRetry($root . "/" . $rel, $content);
            $created[$rel] = true;
            $touched[] = $rel;
        } else {
            foreach ($m['edits'] as [$rel, $old, $new]) {
                $cur = file_get_contents($root . '/' . $rel);
                if (str_contains($cur, "\r\n")) {
                    $old = str_replace("\n", "\r\n", $old);
                    $new = str_replace("\n", "\r\n", $new);
                }
                $n = substr_count($cur, $old);
                if ($n !== 1) {
                    throw new RuntimeException("anchor occurs {$n} times in {$rel}: " . mb_substr($old, 0, 70));
                }
                writeRetry($root . "/" . $rel, str_replace($old, $new, $cur));
                $current[$rel] = true;
                $touched[] = $rel;
            }
        }
        foreach ($touched as $rel) {
            if (($err = lint($root, $rel)) !== null) {
                throw new RuntimeException('does not parse: ' . $err);
            }
        }

        $primary = $m['tests'] ?? $ALL;
        $r = runPhpunit($root, $scratch, $primary, true);
        $ranAll = false;
        if ($r['verdict'] === 'PASS' && $primary !== $ALL && ($m['widen'] ?? true)) {
            $r = runPhpunit($root, $scratch, $ALL, true);
            $ranAll = true;
        }
        if ($r['verdict'] === 'FAIL') {
            $verdict = 'KILLED';
            $killer = $r['killer'];
            $detail = $r['detail'] . ($ranAll ? ' [caught only by the wider set]' : '');
        } elseif ($r['verdict'] === 'PASS') {
            $verdict = 'SURVIVED';
            $detail = $r['detail'];
        } else {
            $verdict = $r['verdict'];
            $detail = $r['detail'];
        }
    } catch (Throwable $e) {
        $verdict = 'INVALID';
        $detail = $e->getMessage();
    } finally {
        foreach ($touched as $rel) {
            if (isset($m['create'])) {
                @unlink($root . '/' . $rel);
                unset($created[$rel]);
                if (isset($madeDirs[$rel])) {
                    @rmdir($madeDirs[$rel]);
                    unset($madeDirs[$rel]);
                }
            } else {
                writeRetry($root . "/" . $rel, $originals[$rel]);
                unset($current[$rel]);
            }
        }
    }
    $ok = true;
    foreach ($touched as $rel) {
        if (! isset($m['create']) && sha1_file($root . '/' . $rel) !== $sha[$rel]) {
            $ok = false;
        }
        if (isset($m['create']) && file_exists($root . '/' . $rel)) {
            $ok = false;
        }
    }
    $secs = round(microtime(true) - $t1);
    $line = implode("\t", [$id, $m['area'] ?? '', $m['desc'] ?? '', $verdict, $killer, $detail, $ok ? 'restored' : 'RESTORE-FAILED', "{$secs}s"]);
    echo $line . "\n";
    file_put_contents($resultsFile, $line . "\n", FILE_APPEND);
    if (! $ok) {
        echo "STOP: a file did not restore to its original bytes.\n";
        exit(3);
    }
}

// ---------------------------------------------------------------------------------------------- final cmp
$bad = 0;
foreach ($originals as $rel => $bytes) {
    if (sha1_file($root . '/' . $rel) !== $sha[$rel]) {
        echo "DIFFERS: {$rel}\n";
        $bad++;
    }
}
echo $bad === 0 ? "ALL " . count($originals) . " mutated files are byte for byte what they were (sha1)\n" : "{$bad} FILES DIFFER\n";
exit($bad === 0 ? 0 : 4);
