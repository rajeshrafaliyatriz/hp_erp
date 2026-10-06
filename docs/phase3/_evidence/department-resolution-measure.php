<?php
/**
 * DEPARTMENT: does the id agree with the text, and who wrote the id?
 *
 * READ ONLY. Nothing here writes.
 *
 * Three questions, in the order they change the answer:
 *   1. how much of s_users_skills already carries a department_id
 *   2. WHERE BOTH ARE POPULATED, DO THEY AGREE - two columns describing one fact
 *      and disagreeing is worse than either being empty, and nobody has asked
 *   3. for the rows with text and no id, how many resolve by name
 */
require __DIR__ . '/../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$T = 's_users_skills';

$total   = DB::table($T)->count();
$withId  = DB::table($T)->whereNotNull('department_id')->where('department_id', '!=', 0)->count();
$withTxt = DB::table($T)->whereNotNull('department')->where('department', '!=', '')->count();
$both    = DB::table($T)->whereNotNull('department_id')->where('department_id', '!=', 0)
    ->whereNotNull('department')->where('department', '!=', '')->count();
$neither = DB::table($T)->where(function ($q) { $q->whereNull('department_id')->orWhere('department_id', 0); })
    ->where(function ($q) { $q->whereNull('department')->orWhere('department', ''); })->count();

printf("%s: %d rows\n", $T, $total);
printf("  department_id populated     %6d\n", $withId);
printf("  department text populated   %6d\n", $withTxt);
printf("  BOTH                        %6d\n", $both);
printf("  neither                     %6d\n", $neither);

// ---- 2. DO THEY AGREE? ---------------------------------------------------
$depts = DB::table('hrms_departments')->pluck('department', 'id');
$byId  = [];
foreach ($depts as $id => $name) $byId[(int) $id] = mb_strtolower(trim((string) $name));

$rows = DB::table($T)->whereNotNull('department_id')->where('department_id', '!=', 0)
    ->whereNotNull('department')->where('department', '!=', '')
    ->get(['id', 'department_id', 'department']);

$agree = $disagree = $idUnknown = 0;
$samples = [];
foreach ($rows as $r) {
    $want = $byId[(int) $r->department_id] ?? null;
    if ($want === null) { $idUnknown++; continue; }
    if ($want === mb_strtolower(trim((string) $r->department))) { $agree++; continue; }
    $disagree++;
    if (count($samples) < 5) $samples[] = sprintf('#%s id=%s means "%s" but text says "%s"',
        $r->id, $r->department_id, $want, $r->department);
}

printf("\nWHERE BOTH ARE POPULATED (%d rows):\n", count($rows));
printf("  AGREE                       %6d\n", $agree);
printf("  DISAGREE                    %6d\n", $disagree);
printf("  department_id points at a row that DOES NOT EXIST  %d\n", $idUnknown);
foreach ($samples as $s) echo "    $s\n";

// ---- 3. TEXT WITHOUT AN ID: how much resolves by name? -------------------
$byName = [];
foreach ($depts as $id => $name) $byName[mb_strtolower(trim((string) $name))] = (int) $id;

$textOnly = DB::table($T)
    ->where(function ($q) { $q->whereNull('department_id')->orWhere('department_id', 0); })
    ->whereNotNull('department')->where('department', '!=', '')
    ->pluck('department');

$res = $unres = 0;
$unmatched = [];
foreach ($textOnly as $t) {
    $k = mb_strtolower(trim((string) $t));
    if (isset($byName[$k])) { $res++; continue; }
    $unres++;
    $unmatched[$k] = ($unmatched[$k] ?? 0) + 1;
}
printf("\nTEXT BUT NO ID (%d rows):\n", count($textOnly));
printf("  resolve by exact name       %6d\n", $res);
printf("  DO NOT RESOLVE              %6d  (%d distinct values)\n", $unres, count($unmatched));
arsort($unmatched);
foreach (array_slice($unmatched, 0, 8, true) as $k => $n) printf("    %-46s %d rows\n", $k, $n);
