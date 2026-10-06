<?php
/**
 * V6 - re-derive every headline number by a SECOND, INDEPENDENT method.
 *
 * R17 applied first: which numbers does an existing artefact already answer?
 *   283,126        -> s1-result.json holds per-table row counts (method 1 was a
 *                     schema sweep). Method 2 here: direct COUNT(*) per table.
 *   3.0%           -> method 1 counted DISTINCT users in s_skill_matrix over
 *                     active users. Method 2 here: join from tbluser side.
 *   66.7%          -> method 1 was a populated-count on task.skill_id.
 *                     Method 2 here: recount and cross-check against 2,271.
 *   46 FAIL etc.   -> c23-result-FULL-912.json (records, not a re-run).
 *   route counts   -> Laravel's own router vs the regex parser.
 *
 * Numbers are printed with BOTH derivations so they can be compared, not asserted.
 */
require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require_once 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as R;

echo "=== 283,126 : the four string-joined tables ===\n";
$tables = ['s_user_jobrole_task', 's_user_skill_jobrole', 's_jobrole_skills', 's_jobrole_task'];
$sum = 0;
foreach ($tables as $t) {
    $n = DB::table($t)->count();
    $sum += $n;
    printf("  %-24s %8d\n", $t, $n);
}
printf("  %-24s %8d   <- method 2 (direct COUNT)\n\n", 'TOTAL', $sum);

echo "=== 3.0% : capability coverage ===\n";
// method 2: from the tbluser side, not the matrix side
$active = DB::table('tbluser')->whereNull('deleted_at')->count();
$activeAlt = DB::table('tbluser')->count();
$withMeasure = DB::table('tbluser as u')
    ->join('s_skill_matrix as m', 'm.user_id', '=', 'u.id')
    ->distinct()->count('u.id');
printf("  users (all)            %6d\n", $activeAlt);
printf("  users (not soft-del)   %6d\n", $active);
printf("  users WITH a matrix row%6d\n", $withMeasure);
printf("  coverage vs not-del    %6.1f%%\n", $active ? $withMeasure / $active * 100 : 0);
printf("  coverage vs all        %6.1f%%\n\n", $activeAlt ? $withMeasure / $activeAlt * 100 : 0);

echo "=== 66.7% : task.skill_id ===\n";
$tasks = DB::table('task')->count();
$withSkill = DB::table('task')->whereNotNull('skill_id')->where('skill_id', '<>', '')->count();
$nullSkill = DB::table('task')->whereNull('skill_id')->count();
printf("  task rows              %6d\n", $tasks);
printf("  skill_id populated     %6d  (%.1f%%)\n", $withSkill, $tasks ? $withSkill / $tasks * 100 : 0);
printf("  skill_id NULL          %6d  (sums to %d)\n\n", $nullSkill, $withSkill + $nullSkill);

echo "=== route counts : Laravel router (method 2) vs regex parser (method 1) ===\n";
$all = 0; $get = 0; $write = 0; $byFile = [];
foreach (R::getRoutes() as $rt) {
    $all++;
    $m = $rt->methods();
    if (in_array('GET', $m, true)) $get++;
    if (array_intersect($m, ['POST','PUT','PATCH','DELETE'])) $write++;
}
printf("  registered routes (router) %6d\n", $all);
printf("  with a GET verb            %6d\n", $get);
printf("  with a write verb          %6d\n\n", $write);

echo "=== guard results : from the RECOVERED records, not a re-run ===\n";
$j = json_decode(file_get_contents(
  'C:/Users/MILAN/Downloads/hp_erp/docs/phase3/_evidence/sweeps/c23-result-FULL-912.json'), true);
$tal = [];
foreach ($j as $r) { $tal[$r[2]] = ($tal[$r[2]] ?? 0) + 1; }
printf("  rows %d\n", count($j));
foreach ($tal as $k => $v) printf("  %-14s %4d\n", $k, $v);
$ctrls = [];
foreach ($j as $r) if ($r[2] === 'FAIL') $ctrls[explode('@', $r[0])[0]] = 1;
printf("  FAIL spans %d distinct controllers\n", count($ctrls));
