<?php
/**
 * F-07b — THE BACKFILL, approved scope only.
 *
 * THREE tenant-scoped mappings. The two tenantless tables are NOT touched:
 * their values match canonical rows in up to 10 tenants at once, so a "match"
 * there is a coin flip, not a resolution.
 *
 * WHAT THIS DOES NOT DO:
 *   - creates no canonical rows. 5,088 orphan strings are NOT master data.
 *   - deletes nothing. The orphans are the evidence.
 *   - drops no text column. They stay until the ids are proven (requirement 4).
 *
 * Unmatched stays NULL - held, not guessed.
 *
 * Pass 1 is EXACT within tenant (binary). Pass 2 is the 417 recoverable, matched
 * case-insensitively after trimming, and reported separately so the two are never
 * conflated.
 */
require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

/** source, text col, id col, canonical, canonical col */
$MAPS = [
    ['s_user_skill_jobrole', 'jobrole', 'jobrole_id', 's_user_jobrole', 'jobrole'],
    ['s_user_skill_jobrole', 'skill',   'skill_id',   's_users_skills', 'title'],
    ['s_user_jobrole_task',  'jobrole', 'jobrole_id', 's_user_jobrole', 'jobrole'],
];

$dryRun = ($argv[1] ?? '') !== '--apply';
printf("MODE: %s\n\n", $dryRun ? 'DRY RUN (no writes)' : 'APPLY');

$totals = ['rows' => 0, 'exact' => 0, 'recovered' => 0, 'held' => 0];

foreach ($MAPS as [$src, $txt, $idc, $canon, $ccol]) {

    $before = (int) DB::table($src)->whereNotNull($idc)->count();
    $rows   = (int) DB::table($src)->count();

    if (!$dryRun) {
        // PASS 1 — exact, within tenant.
        //
        // The join is written on the PLAIN columns so the existing indexes are
        // usable, and BINARY is applied as a RESIDUAL FILTER in the WHERE. The
        // first version put BINARY in the ON clause, which defeats every index:
        // it ran for three minutes and populated zero rows before being stopped.
        DB::statement("
            UPDATE `$src` s
            JOIN `$canon` k
              ON k.`$ccol` = s.`$txt`
             AND k.sub_institute_id = s.sub_institute_id
            SET s.`$idc` = k.id
            WHERE s.`$idc` IS NULL
              AND BINARY k.`$ccol` = BINARY s.`$txt`
        ");
    }
    $afterExact = (int) DB::table($src)->whereNotNull($idc)->count();

    if (!$dryRun) {
        // PASS 2 — the recoverable. The same indexed join, WITHOUT the binary
        // filter, so it picks up exactly the case-insensitive matches pass 1
        // rejected. Reported separately so exact and recovered are never
        // conflated.
        DB::statement("
            UPDATE `$src` s
            JOIN `$canon` k
              ON k.`$ccol` = s.`$txt`
             AND k.sub_institute_id = s.sub_institute_id
            SET s.`$idc` = k.id
            WHERE s.`$idc` IS NULL
        ");
    }
    $afterAll = (int) DB::table($src)->whereNotNull($idc)->count();
    $held     = $rows - $afterAll;

    printf("%s.%s -> %s\n", $src, $txt, $idc);
    printf("  rows                : %s\n", number_format($rows));
    printf("  populated before    : %s\n", number_format($before));
    printf("  pass 1 exact        : +%s\n", number_format($afterExact - $before));
    printf("  pass 2 recovered    : +%s  (case/whitespace only)\n", number_format($afterAll - $afterExact));
    printf("  HELD as NULL        : %s\n\n", number_format($held));

    $totals['rows']      += $rows;
    $totals['exact']     += $afterExact - $before;
    $totals['recovered'] += $afterAll - $afterExact;
    $totals['held']      += $held;
}

echo str_repeat('=', 62), "\n";
printf("rows in scope   : %s\n", number_format($totals['rows']));
printf("exact           : %s\n", number_format($totals['exact']));
printf("recovered       : %s\n", number_format($totals['recovered']));
printf("HELD as NULL    : %s\n", number_format($totals['held']));
