<?php
/**
 * HOW MANY VALIDATORS ACCEPT VALUES THEIR COLUMN'S ENUM DOES NOT?
 *
 * A COUNT, NOT A SWEEP. Asked because `talent_job_postings.status` turned out to
 * be unwritable: the validator says `in:active,inactive`, the column is
 * enum('Active','Draft','Closed','Inactive'). Lowercase passes the validator and
 * MySQL TRUNCATES it - HTTP 200, NO ROW. Capitalised fails the validator. No
 * value satisfies both.
 *
 * METHOD: every enum column in the schema, matched against every `'field' =>
 * '...in:a,b,c...'` rule in app/. A value the validator admits and the enum does
 * not is a write that cannot succeed.
 *
 * CANDIDATES, NOT FINDINGS (R6). A controller may validate a field that is
 * stored in a different table, and the same field name recurs across modules.
 * Each row below still has to be read.
 */
require __DIR__ . '/../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// ---- every enum column -----------------------------------------------------
$enums = [];
foreach (DB::select("SELECT TABLE_NAME t, COLUMN_NAME c, COLUMN_TYPE ct
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND DATA_TYPE = 'enum'") as $r) {
    preg_match_all("/'([^']*)'/", $r->ct, $m);
    $enums[$r->c][] = ['table' => $r->t, 'values' => $m[1]];
}
printf("enum columns in the schema : %d distinct names\n", count($enums));

// ---- every in: rule --------------------------------------------------------
$rules = [];
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app')));
foreach ($files as $f) {
    if (!$f->isFile() || $f->getExtension() !== 'php') continue;
    $src = file_get_contents($f->getPathname());
    if (!preg_match_all("/'([a-z_]+)'\s*=>\s*'([^']*\bin:[^']*)'/i", $src, $m, PREG_SET_ORDER)) continue;
    foreach ($m as $hit) {
        if (!preg_match('/\bin:([^|]+)/', $hit[2], $vm)) continue;
        $rules[] = ['field' => $hit[1], 'values' => array_map('trim', explode(',', $vm[1])),
                    'file' => str_replace(base_path() . DIRECTORY_SEPARATOR, '', $f->getPathname())];
    }
}
printf("in: rules found in app/    : %d\n\n", count($rules));

// ---- compare ---------------------------------------------------------------
$hits = [];
foreach ($rules as $rule) {
    if (!isset($enums[$rule['field']])) continue;
    foreach ($enums[$rule['field']] as $col) {
        $allowed = $col['values'];
        $bad = [];
        foreach ($rule['values'] as $v) {
            if ($v === '') continue;
            if (in_array($v, $allowed, true)) continue;                 // exact match
            $ci = false;
            foreach ($allowed as $a) if (strcasecmp($a, $v) === 0) { $ci = true; break; }
            $bad[] = $v . ($ci ? ' (CASE ONLY)' : '');
        }
        if ($bad) $hits[] = ['field' => $rule['field'], 'table' => $col['table'],
            'bad' => $bad, 'enum' => $allowed, 'file' => $rule['file']];
    }
}

printf("=== VALIDATOR ADMITS A VALUE THE ENUM REJECTS: %d candidate(s) ===\n", count($hits));
$shown = [];
foreach ($hits as $h) {
    $k = $h['table'] . '.' . $h['field'];
    if (isset($shown[$k])) continue;
    $shown[$k] = true;
    printf("\n  %-46s %s\n", $k, basename($h['file']));
    printf("    enum      : %s\n", implode(', ', $h['enum']));
    printf("    REJECTED  : %s\n", implode(', ', $h['bad']));
}
printf("\ndistinct table.column pairs affected: %d\n", count($shown));
