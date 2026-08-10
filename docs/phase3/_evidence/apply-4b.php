<?php
/**
 * 4b — APPLY THE RIGHTS SEED to tblgroupwise_rights_g2g.
 *
 * Scope, deliberately narrow:
 *   - Rows on the 99 profiles that carry a role_key are REPLACED by the seed.
 *   - Rows on any other profile are LEFT ALONE.
 *   - Orphan rows (menu_id with no row in tblmenumaster_g2g) are deleted
 *     everywhere, per R8's pre-deletion checklist.
 *   - tblgroupwise_rights (the Blade table) is NOT touched. Nothing here reads
 *     or writes it.
 *
 * Runs inside a transaction. Backup:
 *   docs/phase3/_changes/X-01-backup-tblgroupwise_rights_g2g-2026-08-10.sql
 *   docs/phase3/_changes/X-01-restore.sql
 */
require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require_once 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

const T = 'tblgroupwise_rights_g2g';

$seed = json_decode(file_get_contents('C:/Users/MILAN/Downloads/hp_erp/docs/phase3/_changes/X-01-seed.json'), true);
if (!$seed || !$seed['rows']) { fwrite(STDERR, "seed missing or empty - refusing\n"); exit(1); }

$roleProfiles = DB::table('tbluserprofilemaster')->whereNotNull('role_key')->pluck('id')->all();
$menuIds      = DB::table('tblmenumaster_g2g')->pluck('id')->all();
$orphanMenus  = array_values(array_diff(DB::table(T)->distinct()->pluck('menu_id')->all(), $menuIds));

$before = [
    'total'   => DB::table(T)->count(),
    'role'    => DB::table(T)->whereIn('profile_id', $roleProfiles)->count(),
    'other'   => DB::table(T)->whereNotIn('profile_id', $roleProfiles)->count(),
    'orphan'  => DB::table(T)->whereIn('menu_id', $orphanMenus)->count(),
];

/* every seed row must name a real menu and a real profile - checked before any write */
$profileSet = array_flip($roleProfiles);
$menuSet    = array_flip($menuIds);
foreach ($seed['rows'] as $r) {
    if (!isset($profileSet[$r['profile_id']])) { fwrite(STDERR, "seed names unknown profile {$r['profile_id']} - refusing\n"); exit(1); }
    if (!isset($menuSet[$r['menu_id']]))       { fwrite(STDERR, "seed names unknown menu {$r['menu_id']} - refusing\n"); exit(1); }
}

DB::transaction(function () use ($seed, $roleProfiles, $orphanMenus) {
    DB::table(T)->whereIn('profile_id', $roleProfiles)->delete();
    if ($orphanMenus) { DB::table(T)->whereIn('menu_id', $orphanMenus)->delete(); }

    $now  = now();
    $rows = [];
    foreach ($seed['rows'] as $r) {
        $rows[] = [
            'menu_id'          => $r['menu_id'],
            'profile_id'       => $r['profile_id'],
            'can_view'         => 1,
            'can_add'          => $r['can_add'] ? 1 : 0,
            'can_edit'         => $r['can_edit'] ? 1 : 0,
            'can_delete'       => $r['can_delete'] ? 1 : 0,
            'dashboard_right'  => 0,
            'is_mobile'        => 0,
            'sub_institute_id' => $r['sub_institute_id'],
            'created_at'       => $now,
        ];
    }
    foreach (array_chunk($rows, 500) as $chunk) { DB::table(T)->insert($chunk); }
});

$after = [
    'total'  => DB::table(T)->count(),
    'role'   => DB::table(T)->whereIn('profile_id', $roleProfiles)->count(),
    'other'  => DB::table(T)->whereNotIn('profile_id', $roleProfiles)->count(),
    'orphan' => DB::table(T)->whereIn('menu_id', $orphanMenus)->count(),
];

echo "=== 4b APPLIED ===\n\n";
printf("%-28s %10s %10s\n", '', 'before', 'after');
foreach ($before as $k => $v) printf("%-28s %10d %10d\n", $k, $v, $after[$k]);

/* the lockout question, asked of the database rather than the seed file */
$adminPid = DB::table('tbluserprofilemaster')->where('role_key', 'administrator')->value('id');
$has = DB::table(T)->where('profile_id', $adminPid)->whereIn('menu_id', [1, 8, 23])
    ->where('can_view', 1)->pluck('menu_id')->all();
sort($has);
printf("\nAdministrator can_view on 1 / 8 / 23 : %s  %s\n",
    implode(',', $has), count($has) === 3 ? 'ALL THREE PRESENT' : '*** LOCKOUT ***');
