<?php
/**
 * F-07b — THE UNMATCHED REPORT.
 *
 * This is the deliverable, not a safety check before a migration. G-DATA-06 has
 * argued from principle since Gate C that 283,126 rows resolving by string is the
 * phase headline. This is the EMPIRICAL MEASURE of what that cost.
 *
 * NOTHING IS DROPPED. NOTHING IS WRITTEN. This script only reads.
 *
 * METHOD. Classifying 283k rows one by one is wasteful and hides the shape, so
 * DISTINCT text values are classified first and then weighted by how many rows
 * carry them. Both numbers are reported: a value appearing once and a value
 * appearing 4,000 times are different problems.
 *
 * REASONS, in the order they are tested - first match wins, so each is the
 * CHEAPEST explanation that fits:
 *   EXACT          binary-identical to a canonical row, within tenant
 *   CASE           differs only by letter case
 *   WHITESPACE     differs only by leading/trailing/internal whitespace
 *   NEAR-MISS      same after stripping punctuation and collapsing spaces
 *   NO COUNTERPART nothing resembling it exists
 *
 * Three of those four are RECOVERABLE. A single "unmatched" count would hide
 * that, which is why the report is by reason.
 */
require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

/** source table, text column, canonical table, canonical column, source has tenant? */
$PAIRS = [
    ['s_user_skill_jobrole', 'jobrole', 's_user_jobrole',  'jobrole', true],
    ['s_user_skill_jobrole', 'skill',   's_users_skills',  'title',   true],
    ['s_user_jobrole_task',  'jobrole', 's_user_jobrole',  'jobrole', true],
    ['s_jobrole_skills',     'jobrole', 's_user_jobrole',  'jobrole', false],
    ['s_jobrole_skills',     'skill',   's_users_skills',  'title',   false],
    ['s_jobrole_task',       'jobrole', 's_user_jobrole',  'jobrole', false],
];

$norm      = fn ($s) => strtolower(trim((string) $s));
$collapse  = fn ($s) => preg_replace('/\s+/', ' ', strtolower(trim((string) $s)));
$stripPunc = fn ($s) => preg_replace('/[^a-z0-9]+/', '', strtolower((string) $s));

$grand = ['EXACT' => 0, 'CASE' => 0, 'WHITESPACE' => 0, 'NEAR-MISS' => 0, 'NO COUNTERPART' => 0];
$grandRows = $grand;

foreach ($PAIRS as [$src, $col, $canon, $ccol, $hasTenant]) {

    /* distinct source values, with the row weight behind each */
    $q = DB::table($src)->select($col . ' as v', DB::raw('COUNT(*) as n'));
    if ($hasTenant) $q->addSelect('sub_institute_id as t')->groupBy($col, 'sub_institute_id');
    else            $q->groupBy($col);
    $values = $q->get();

    /* canonical index, per tenant when the source can be scoped */
    $canonRows = DB::table($canon)->select($ccol . ' as v', 'sub_institute_id as t')->get();

    $exact = []; $ci = []; $collapsed = []; $punc = [];
    foreach ($canonRows as $c) {
        $key = $hasTenant ? $c->t : '*';
        $exact[$key][(string) $c->v]              = true;
        $ci[$key][$norm($c->v)]                   = true;
        $collapsed[$key][$collapse($c->v)]        = true;
        $punc[$key][$stripPunc($c->v)]            = true;
    }
    /* When the source has no tenant, every canonical value from every tenant is
       a candidate - which is itself part of the finding, not a convenience. */
    if (!$hasTenant) {
        $flat = function (array $idx): array {
            $merged = [];
            foreach ($idx as $per) { $merged += $per; }
            return ['*' => $merged];
        };
        $exact     = $flat($exact);
        $ci        = $flat($ci);
        $collapsed = $flat($collapsed);
        $punc      = $flat($punc);
    }

    $tally = ['EXACT' => 0, 'CASE' => 0, 'WHITESPACE' => 0, 'NEAR-MISS' => 0, 'NO COUNTERPART' => 0];
    $rows  = $tally;
    $examples = [];

    foreach ($values as $v) {
        $key = $hasTenant ? ($v->t ?? '') : '*';
        $raw = (string) $v->v;
        $n   = (int) $v->n;

        $reason = match (true) {
            $raw === '' || $raw === null                   => 'NO COUNTERPART',
            isset($exact[$key][$raw])                      => 'EXACT',
            isset($ci[$key][$norm($raw)])                  => 'CASE',
            isset($collapsed[$key][$collapse($raw)])       => 'WHITESPACE',
            isset($punc[$key][$stripPunc($raw)])           => 'NEAR-MISS',
            default                                        => 'NO COUNTERPART',
        };

        $tally[$reason]++;
        $rows[$reason] += $n;
        if ($reason !== 'EXACT' && count($examples[$reason] ?? []) < 2) {
            $examples[$reason][] = mb_substr($raw, 0, 46) . " (×$n)";
        }
    }

    printf("%s.%s  ->  %s.%s   %s\n", $src, $col, $canon, $ccol,
        $hasTenant ? '[tenant-scoped]' : '*** SOURCE HAS NO sub_institute_id - matched across ALL tenants ***');
    printf("  %-16s %10s %12s\n", 'reason', 'distinct', 'rows');
    foreach ($tally as $r => $c) {
        printf("  %-16s %10d %12s\n", $r, $c, number_format($rows[$r]));
        $grand[$r] += $c; $grandRows[$r] += $rows[$r];
    }
    foreach ($examples as $r => $ex) {
        foreach ($ex as $e) printf("      %-14s e.g. %s\n", $r, $e);
    }
    echo "\n";
}

echo str_repeat('=', 74), "\n";
printf("TOTAL   %-16s %10s %12s\n", '', 'distinct', 'rows');
$totRows = array_sum($grandRows);
foreach ($grand as $r => $c) {
    printf("        %-16s %10d %12s   %5.1f%% of rows\n", $r, $c, number_format($grandRows[$r]),
        $totRows ? 100 * $grandRows[$r] / $totRows : 0);
}
printf("        %-16s %10d %12s\n", 'ALL', array_sum($grand), number_format($totRows));

$recoverable = $grandRows['CASE'] + $grandRows['WHITESPACE'] + $grandRows['NEAR-MISS'];
printf("\nRECOVERABLE (case + whitespace + near-miss): %s rows\n", number_format($recoverable));
printf("LOST        (no counterpart)               : %s rows\n", number_format($grandRows['NO COUNTERPART']));
