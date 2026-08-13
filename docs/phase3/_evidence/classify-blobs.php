<?php
/** Classify every s_skill_matrix KASBA blob by encoding, incl. the corrupt char-map form. */
require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require_once 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

function classify($raw): array
{
    $raw = trim((string) $raw);
    if ($raw === '') return ['empty', 0];

    $j = json_decode($raw, true);
    if (is_array($j)) {
        $numeric = 0; $text = 0; $singleChar = 0;
        foreach ($j as $k => $v) {
            if (is_int($k) || ctype_digit((string) $k)) {
                $numeric++;
                if (is_string($v) && mb_strlen($v) <= 1) $singleChar++;
            } else {
                $text++;
            }
        }
        // a char-map: numeric keys whose values are almost all single characters
        if ($numeric > 0 && $text === 0 && $singleChar >= $numeric * 0.8) {
            return ['CORRUPT_charmap', $numeric];
        }
        if ($text > 0 && $numeric === 0) return ['ok_labelled', $text];
        if ($numeric > 0 && $text === 0) return ['numeric_keys', $numeric];
        return ['mixed_keys', $numeric + $text];
    }

    if (preg_match('/^[0-9,\s]+$/', $raw)) {
        return ['csv_ratings_no_labels', count(array_filter(explode(',', $raw), fn($v) => trim($v) !== ''))];
    }
    return ['unparsed', 1];
}

$dims = ['knowledge', 'ability', 'attitude', 'behaviour'];
$grand = [];

foreach ($dims as $dim) {
    $byKind = [];
    foreach (DB::table('s_skill_matrix')->whereNotNull($dim)->where($dim, '!=', '')->select('id', $dim)->get() as $r) {
        [$kind, $n] = classify($r->$dim);
        $byKind[$kind]['rows'] = ($byKind[$kind]['rows'] ?? 0) + 1;
        $byKind[$kind]['items'] = ($byKind[$kind]['items'] ?? 0) + $n;
    }
    $grand[$dim] = $byKind;
}

echo "s_skill_matrix KASBA blob encodings\n";
echo str_repeat('=', 78) . "\n";
printf("%-11s %-24s %6s %9s\n", 'dimension', 'encoding', 'rows', 'items');
echo str_repeat('-', 78) . "\n";
$tot = [];
foreach ($grand as $dim => $kinds) {
    ksort($kinds);
    foreach ($kinds as $kind => $c) {
        printf("%-11s %-24s %6d %9d\n", $dim, $kind, $c['rows'], $c['items']);
        $tot[$kind]['rows'] = ($tot[$kind]['rows'] ?? 0) + $c['rows'];
        $tot[$kind]['items'] = ($tot[$kind]['items'] ?? 0) + $c['items'];
    }
}
echo str_repeat('-', 78) . "\n";
ksort($tot);
foreach ($tot as $kind => $c) {
    printf("%-11s %-24s %6d %9d\n", 'TOTAL', $kind, $c['rows'], $c['items']);
}

echo "\n23 NULL-type rows (S2) — do they carry any KASBA data?\n";
$q = DB::table('s_skill_matrix')->whereNull('type');
printf("  rows with NULL type          : %d\n", (clone $q)->count());
foreach ($dims as $d) {
    printf("    of those, %-10s set : %d\n", $d, (clone $q)->whereNotNull($d)->where($d, '!=', '')->count());
}
printf("    of those, skill_level set  : %d\n", (clone $q)->whereNotNull('skill_level')->count());
printf("    of those, skill_id set     : %d\n", (clone $q)->whereNotNull('skill_id')->count());
$ids = (clone $q)->pluck('skill_id')->filter()->values()->all();
$hit = $ids ? DB::table('s_users_skills')->whereIn('id', $ids)->count() : 0;
printf("    their skill_id resolves in s_users_skills: %d of %d\n", $hit, count($ids));
