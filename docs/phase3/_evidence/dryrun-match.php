<?php
/**
 * S1 — dry-run match report for the s_skill_matrix normalisation.
 * READ ONLY. Nothing is written.
 *
 * Parses the three formats found in knowledge/ability/behaviour/attitude and
 * tries to resolve each item_label against its KASBA library by title.
 */
require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require_once 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$LIB = [
    'knowledge' => 's_user_knowledge',
    'ability'   => 's_user_ability',
    'attitude'  => 's_user_attitude',
    'behaviour' => 's_user_behaviour',
];

// Load each library's titles, normalised, for matching.
$index = [];
foreach ($LIB as $dim => $table) {
    $index[$dim] = [];
    foreach (DB::table($table)->select('id', 'title')->get() as $r) {
        $key = mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $r->title)));
        if ($key !== '') {
            $index[$dim][$key] = $r->id;
        }
    }
}

/** Return [label => rating] from any of the three observed encodings. */
function parseBlob($raw): array
{
    $raw = trim((string) $raw);
    if ($raw === '') return [];

    $j = json_decode($raw, true);
    if (is_array($j)) {
        $out = [];
        foreach ($j as $k => $v) {
            if (is_string($k)) {
                $out[$k] = is_scalar($v) ? (string) $v : null;
            } else {
                // JSON array, not object - positional, no label
                $out['#' . $k] = is_scalar($v) ? (string) $v : null;
            }
        }
        return $out;
    }

    if (preg_match('/^[0-9,\s]+$/', $raw)) {
        // "1,1,1,1" - ratings with no labels at all
        $vals = array_values(array_filter(array_map('trim', explode(',', $raw)), fn($v) => $v !== ''));
        $out = [];
        foreach ($vals as $i => $v) $out['#' . $i] = $v;
        return $out;
    }

    return ['__UNPARSED__' => $raw];
}

$totals = [];
$failSamples = [];

foreach (array_keys($LIB) as $dim) {
    $t = ['rows' => 0, 'items' => 0, 'labelled' => 0, 'matched' => 0,
          'unmatched' => 0, 'positional' => 0, 'unparsed' => 0];

    $rows = DB::table('s_skill_matrix')
        ->whereNotNull($dim)->where($dim, '!=', '')
        ->select('id', $dim)->get();

    foreach ($rows as $r) {
        $t['rows']++;
        foreach (parseBlob($r->$dim) as $label => $rating) {
            $t['items']++;
            if ($label === '__UNPARSED__') { $t['unparsed']++; continue; }
            if (str_starts_with($label, '#'))  { $t['positional']++; continue; }

            $t['labelled']++;
            $key = mb_strtolower(trim(preg_replace('/\s+/', ' ', $label)));
            if (isset($index[$dim][$key])) {
                $t['matched']++;
            } else {
                $t['unmatched']++;
                if (count($failSamples[$dim] ?? []) < 20) {
                    $failSamples[$dim][] = mb_substr($label, 0, 95);
                }
            }
        }
    }
    $totals[$dim] = $t;
}

echo "S1 — DRY-RUN MATCH REPORT (read only, nothing written)\n";
echo str_repeat('=', 96) . "\n";
printf("%-11s %6s %8s %9s %9s %9s %11s %9s\n",
    'dimension', 'rows', 'items', 'labelled', 'MATCHED', 'unmatched', 'positional', 'unparsed');
echo str_repeat('-', 96) . "\n";

$g = ['items'=>0,'labelled'=>0,'matched'=>0,'unmatched'=>0,'positional'=>0,'unparsed'=>0];
foreach ($totals as $dim => $t) {
    printf("%-11s %6d %8d %9d %9d %9d %11d %9d\n",
        $dim, $t['rows'], $t['items'], $t['labelled'], $t['matched'],
        $t['unmatched'], $t['positional'], $t['unparsed']);
    foreach (array_keys($g) as $k) $g[$k] += $t[$k];
}
echo str_repeat('-', 96) . "\n";
printf("%-11s %6s %8d %9d %9d %9d %11d %9d\n",
    'TOTAL', '', $g['items'], $g['labelled'], $g['matched'],
    $g['unmatched'], $g['positional'], $g['unparsed']);

$rate = $g['labelled'] > 0 ? round($g['matched'] / $g['labelled'] * 100, 1) : 0;
echo "\nMATCH RATE (of labelled items): {$rate}%\n";

echo "\nlibrary sizes available to match against:\n";
foreach ($LIB as $dim => $table) {
    printf("  %-11s %-20s %d rows\n", $dim, $table, DB::table($table)->count());
}

foreach ($failSamples as $dim => $samples) {
    echo "\nsample UNMATCHED labels — {$dim} (" . count($samples) . " of {$totals[$dim]['unmatched']}):\n";
    foreach ($samples as $s) echo "   - {$s}\n";
}
