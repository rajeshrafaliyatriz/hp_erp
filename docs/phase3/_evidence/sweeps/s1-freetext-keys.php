<?php
/**
 * S-1: free text where a key belongs.
 *
 * Run against the LIVE SCHEMA, not the migrations - the migrations describe 211
 * tables and the database has 357 (02-domain-model 4.1), so any schema question
 * answered from the repo is answered about a different database.
 *
 * R10 - what this measures:
 *   property : "a column naming another entity holds a reference to it"
 *   proxy    : "a textual column whose name matches an entity we own, where that
 *              entity has its own table"
 *   gap      : passes proxy but fails property - a column called `department`
 *              that legitimately holds a free label (a report heading, an
 *              imported source string) reads as a defect.
 *              fails proxy but passes property - an entity reference under a
 *              name this list does not know.
 *
 * Every hit is a CANDIDATE (R6). Populated-row counts are included because an
 * empty column is a schema tidy, not a data problem.
 */

require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require_once 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

/** entity => the table that owns it */
const ENTITIES = [
    'department'    => 'hrms_departments',
    'jobrole'       => 's_user_jobrole',
    'skill'         => 's_users_skills',
    'course'        => 'sub_std_map',
    'certification' => 's_competency_certifications',
];

$db = DB::getDatabaseName();
$cols = DB::select(
    "SELECT TABLE_NAME t, COLUMN_NAME c, DATA_TYPE d
       FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = ?
        AND DATA_TYPE IN ('varchar','char','text','mediumtext','longtext')", [$db]);

// every *_id column that exists, so we can tell "text only" from "text alongside a key"
$ids = [];
foreach (DB::select("SELECT TABLE_NAME t, COLUMN_NAME c FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = ? AND COLUMN_NAME LIKE '%\\_id'", [$db]) as $r) {
    $ids[$r->t][strtolower($r->c)] = true;
}

$rows = [];
foreach ($cols as $col) {
    $t = $col->t; $c = strtolower($col->c);
    if (str_starts_with($t, 'hpbrain_')) continue;          // separate product (Q-C4)
    foreach (ENTITIES as $entity => $owner) {
        // the column NAMES the entity: `department`, `jobrole`, `related_skills`,
        // `course_name`... but is not itself the key
        if (!preg_match('/(^|_)' . $entity . '(s)?($|_)/', $c)) continue;
        if (str_ends_with($c, '_id')) continue;
        $hasKey = isset($ids[$t][$entity . '_id']);
        $rows[] = ['table' => $t, 'column' => $col->c, 'type' => $col->d,
                   'entity' => $entity, 'owner_table' => $owner,
                   'has_matching_id_column' => $hasKey];
    }
}

// populated counts - an empty column is a tidy, not a data problem (R3)
foreach ($rows as &$r) {
    try {
        $r['rows'] = DB::table($r['table'])->count();
        $r['populated'] = $r['rows']
            ? DB::table($r['table'])->whereNotNull($r['column'])->where($r['column'], '<>', '')->count()
            : 0;
    } catch (\Throwable $e) { $r['rows'] = -1; $r['populated'] = -1; }
}
unset($r);

usort($rows, fn($a, $b) => $b['populated'] <=> $a['populated']);
file_put_contents('C:/Users/MILAN/Downloads/hp_erp/docs/phase3/_evidence/sweeps/s1-result.json',
    json_encode($rows, JSON_PRETTY_PRINT));

$pop = array_filter($rows, fn($r) => $r['populated'] > 0);
$noKey = array_filter($pop, fn($r) => !$r['has_matching_id_column']);
printf("text columns naming an owned entity      : %d\n", count($rows));
printf("  of those, POPULATED                    : %d\n", count($pop));
printf("  populated AND no matching *_id column  : %d   <- the S-1 finding\n\n", count($noKey));
printf("%-34s %-26s %-14s %8s %10s %s\n", 'table', 'column', 'entity', 'rows', 'populated', 'id col?');
foreach (array_slice($pop, 0, 30) as $r)
    printf("%-34s %-26s %-14s %8d %10d %s\n", $r['table'], $r['column'], $r['entity'],
        $r['rows'], $r['populated'], $r['has_matching_id_column'] ? 'yes' : '** NO **');
