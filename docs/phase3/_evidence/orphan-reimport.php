<?php
/**
 * THE ORPHAN RE-IMPORT — dry run by default.
 *
 * F-07b established that all three orphan sets are ONE DEFECT: an import created
 * RELATIONSHIPS without creating the tenant's own CANONICAL COPIES. 100% of
 * orphan job-role names and 99.8% of orphan skill names exist in a library.
 *
 * SO THIS CREATES THE MISSING CANONICAL ROWS, FROM THE LIBRARY ROW — never from
 * the orphan text. Copying a library row into a tenant that should already have
 * it is not the same as manufacturing master data from an unvalidated string,
 * which is the thing that was ruled out.
 *
 * IT ADDS. IT DELETES NOTHING — not the orphan rows, and not an orphan that still
 * fails to resolve afterwards.
 *
 * Usage:  php orphan-reimport.php            dry run, reports only
 *         php orphan-reimport.php --apply    creates the rows
 */
require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$apply = ($argv[1] ?? '') === '--apply';
printf("MODE: %s\n\n", $apply ? '*** APPLY ***' : 'DRY RUN (nothing written)');

/**
 * Orphan (tenant, name) pairs for one source column, with the library row that
 * would supply the canonical copy.
 */
function orphans(string $src, string $col, string $idc, string $canon, string $ccol, string $lib, string $lcol): array
{
    // distinct (tenant, name) that failed to resolve
    $rows = DB::table($src)
        ->whereNull($idc)
        ->whereNotNull($col)
        ->where($col, '!=', '')
        ->select('sub_institute_id as t', $col . ' as name', DB::raw('COUNT(*) as n'))
        ->groupBy('sub_institute_id', $col)
        ->get();

    // names present in the LIBRARY (the only permitted source of a new row)
    $inLib = DB::table($lib)->select($lcol . ' as v')->distinct()->pluck('v')
        ->mapWithKeys(fn ($v) => [(string) $v => true])->all();

    // names the tenant ALREADY has canonically (so we never duplicate)
    $have = [];
    foreach (DB::table($canon)->select('sub_institute_id as t', $ccol . ' as v')->get() as $c) {
        $have[$c->t . '|' . $c->v] = true;
    }

    $out = [];
    foreach ($rows as $r) {
        if (isset($have[$r->t . '|' . $r->name])) continue;          // already there
        if (!isset($inLib[(string) $r->name])) continue;             // NOT in a library -> never created
        $out[] = ['tenant' => $r->t, 'name' => $r->name, 'rows' => (int) $r->n];
    }

    return $out;
}

$plans = [
    'job roles' => [
        'targets' => ['s_user_jobrole', 'jobrole'],
        'library' => ['s_jobrole', 'jobrole'],
        'sources' => [
            ['s_user_skill_jobrole', 'jobrole', 'jobrole_id'],
            ['s_user_jobrole_task',  'jobrole', 'jobrole_id'],
        ],
    ],
    'skills' => [
        'targets' => ['s_users_skills', 'title'],
        'library' => ['s_jobrole_skills', 'skill'],
        'sources' => [
            ['s_user_skill_jobrole', 'skill', 'skill_id'],
        ],
    ],
];

$grandCreate = 0;

foreach ($plans as $label => $plan) {
    [$canon, $ccol] = $plan['targets'];
    [$lib, $lcol]   = $plan['library'];

    // union the sources - the same missing name can orphan rows in two tables
    $seen = [];
    foreach ($plan['sources'] as [$src, $col, $idc]) {
        foreach (orphans($src, $col, $idc, $canon, $ccol, $lib, $lcol) as $o) {
            $k = $o['tenant'] . '|' . $o['name'];
            $seen[$k] = ['tenant' => $o['tenant'], 'name' => $o['name'],
                         'rows' => ($seen[$k]['rows'] ?? 0) + $o['rows']];
        }
    }

    $byTenant = [];
    foreach ($seen as $o) $byTenant[$o['tenant']][] = $o;
    ksort($byTenant);

    printf("=== %s -> %s (from library %s) ===\n", strtoupper($label), $canon, $lib);
    printf("  %-8s %14s %16s\n", 'tenant', 'rows to create', 'orphan rows freed');

    $sub = 0;
    foreach ($byTenant as $t => $list) {
        $freed = array_sum(array_column($list, 'rows'));
        printf("  %-8s %14d %16d\n", $t, count($list), $freed);
        $sub += count($list);
    }
    printf("  %-8s %14d\n\n", 'TOTAL', $sub);
    $grandCreate += $sub;

    foreach ($byTenant as $t => $list) {
        printf("  sample, tenant %s (10 of %d):\n", $t, count($list));
        foreach (array_slice($list, 0, 10) as $o) {
            printf("     %-58s (%d orphan rows)\n", mb_substr($o['name'], 0, 56), $o['rows']);
        }
        echo "\n";
    }

    if ($apply) {
        $made = 0;
        foreach ($seen as $o) {
            $libRow = (array) DB::table($lib)->where($lcol, $o['name'])->first();
            if (!$libRow) continue;                                   // cannot happen; belt and braces

            // Build the canonical row FROM THE LIBRARY ROW, keeping only columns
            // the target actually has. Never from the orphan text.
            $cols = array_map(fn ($c) => $c->Field, DB::select("DESCRIBE `$canon`"));
            $payload = [];
            foreach ($libRow as $k => $v) {
                if ($k === 'id' || $v === null) continue;
                if (in_array($k, $cols, true)) $payload[$k] = $v;
            }
            $payload[$ccol]            = $o['name'];
            $payload['sub_institute_id'] = $o['tenant'];
            $payload['created_at']     = now();
            $payload['updated_at']     = now();

            DB::table($canon)->insert($payload);
            $made++;
        }
        printf("  CREATED %d canonical rows in %s\n\n", $made, $canon);
    }
}

printf("%s\n", str_repeat('=', 60));
printf("canonical rows that would be created: %d\n", $grandCreate);
printf("orphan rows deleted: 0  (nothing is deleted, ever)\n");
