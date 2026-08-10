<?php
/**
 * 4b — THE PER-PROFILE BEFORE/AFTER DIFF, split ADDITIVE vs SUBTRACTIVE.
 *
 * ADDITIVE   action rights (add/edit/delete). Nothing holds them today, so
 *            populating can only GRANT. Low risk.
 * SUBTRACTIVE can_view. Every profile currently sees all 157 menus, so real
 *            rights REMOVE menus from every profile including Administrator.
 *            This is where the risk sits.
 *
 * Reports nothing else. Applies nothing.
 */
require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require_once 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$seed = json_decode(file_get_contents('C:/Users/MILAN/Downloads/hp_erp/docs/phase3/_changes/X-01-seed.json'), true);

/* seed, keyed by profile */
$after = [];
foreach ($seed['rows'] as $r) {
    $after[$r['profile_id']][$r['menu_id']] = $r;
}

/* current state */
$before = [];
foreach (DB::table('tblgroupwise_rights_g2g')->get() as $r) {
    $before[$r->profile_id][$r->menu_id] = $r;
}

$profiles = DB::table('tbluserprofilemaster')->whereNotNull('role_key')->get()->keyBy('id');
$menuNames = DB::table('tblmenumaster_g2g')->pluck('menu_name', 'id');

/* aggregate by role_key - the 11 tenants are identical by construction */
$agg = [];
foreach ($profiles as $pid => $p) {
    $b = $before[$pid] ?? [];
    $a = $after[$pid] ?? [];

    $bView = array_keys(array_filter($b, fn($x) => $x->can_view == 1));
    $aView = array_keys($a);                       // every seed row has can_view=1

    $lost   = array_diff($bView, $aView);
    $gained = array_diff($aView, $bView);

    $addRights = 0;
    foreach ($a as $x) if ($x['can_add'] || $x['can_edit'] || $x['can_delete']) $addRights++;

    $k = $p->role_key;
    $agg[$k]['tenants'] = ($agg[$k]['tenants'] ?? 0) + 1;
    $agg[$k]['before']  = count($bView);
    $agg[$k]['after']   = count($aView);
    $agg[$k]['lost']    = $lost;
    $agg[$k]['gained']  = $gained;
    $agg[$k]['action']  = $addRights;
}

echo "=== SUBTRACTIVE — can_view. THE RISK ===\n\n";
printf("%-20s %8s %8s %8s %8s\n", 'role', 'before', 'after', 'LOST', 'gained');
foreach ($agg as $k => $v)
    printf("%-20s %8d %8d %8d %8d\n", $k, $v['before'], $v['after'], count($v['lost']), count($v['gained']));

echo "\n=== ADDITIVE — action rights (nothing holds any today) ===\n\n";
printf("%-20s %8s %8s\n", 'role', 'before', 'after');
foreach ($agg as $k => $v) printf("%-20s %8d %8d\n", $k, 0, $v['action']);

echo "\n=== WHAT ADMINISTRATOR LOSES (the lockout question) ===\n";
$adminLost = $agg['administrator']['lost'] ?? [];
printf("  %d menus\n", count($adminLost));
foreach (array_slice($adminLost, 0, 40) as $m)
    printf("    %-5s %s\n", $m, $menuNames[$m] ?? '(no menu row - ORPHAN)');

echo "\n=== ORPHAN ROWS TO DELETE WITH THE SEED ===\n";
$menuIds = DB::table('tblmenumaster_g2g')->pluck('id')->all();
$orphIds = array_values(array_diff(DB::table('tblgroupwise_rights_g2g')->distinct()->pluck('menu_id')->all(), $menuIds));
printf("  %d orphan menu_ids, %d rows\n", count($orphIds),
    DB::table('tblgroupwise_rights_g2g')->whereIn('menu_id', $orphIds)->count());
