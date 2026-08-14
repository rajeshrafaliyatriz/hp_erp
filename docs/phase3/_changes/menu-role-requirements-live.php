<?php
/**
 * MENU ROW + RIGHTS FOR ROLE REQUIREMENTS — the link that unblocks the chain.
 *
 * competency -> job role is the ONE link with no way to reach it. The API
 * (POST /competency/role-map, profile:admin,hr) and the screen
 * (role-requirements-panel.tsx) both exist; only the navigation row is missing.
 * Without it a tenant can author 199 competencies and 2,875 job roles and never
 * connect them - which is exactly what tenant 1 shows: 0 rows in
 * jobrole_competency_map.
 *
 * DERIVED FROM A SIBLING, ID TAKEN FROM THE INSERT. Nothing about the row's shape
 * is typed here except its name and link; parent, level, page_type, status, icon
 * and tenant all come from an existing sibling, so a row that works keeps working.
 * sort_order is max+1 rather than copied, because two rows sharing a sort order is
 * how a menu silently reorders itself.
 *
 * RIGHTS ARE COPIED PER PROFILE FROM THE SIBLING'S OWN RIGHTS ROWS, not invented.
 * A profile that cannot see Competency Definitions should not suddenly be able to
 * see Role Requirements - the sibling already encodes who is allowed here.
 *
 * IDEMPOTENT. Re-running writes nothing and says so. REVERSAL IS PRINTED at the
 * end with the real ids, because a change nobody can undo is a change nobody
 * should make.
 */
require __DIR__ . '/../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

const LINK    = '/module/competency-management/competency-library/role-requirements';
const NAME    = 'Role Requirements';
// 227 Competency Definitions - the nearest neighbour in purpose: same parent,
// same audience, and its rights are the ones this screen should inherit.
const SIBLING_LINK = 'competency-definitions';

$existing = DB::table('tblmenumaster_g2g')->where('access_link', LINK)->first();
if ($existing) {
    printf("ALREADY EXISTS: #%d %s - nothing written\n", $existing->id, $existing->menu_name);
    $have = DB::table('tblgroupwise_rights_g2g')->where('menu_id', $existing->id)->count();
    printf("rights rows already present: %d\n", $have);
    exit(0);
}

// RESOLVED BY access_link, NOT BY ID. On dev this sibling is 227; on live it is
// 224. An id is an accident of insert order — the link is the thing itself.
$sib = DB::table('tblmenumaster_g2g')->where('access_link', 'like', '%' . SIBLING_LINK)->first();
if (!$sib) {
    echo "REFUSING: sibling " . SIBLING_LINK . " not found - nothing to derive from\n";
    exit(1);
}

$maxSort = (int) DB::table('tblmenumaster_g2g')
    ->where('parent_id', $sib->parent_id)->whereNull('deleted_at')->max('sort_order');

printf("DERIVED FROM SIBLING #%d (%s):\n", $sib->id, $sib->menu_name);
foreach (['parent_id', 'level', 'page_type', 'status', 'menu_type', 'icon', 'sub_institute_id'] as $f) {
    printf("  %-18s %s\n", $f, $sib->$f ?? '(null)');
}
printf("  %-18s %d  (max %d + 1, NOT copied)\n\n", 'sort_order', $maxSort + 1, $maxSort);

$beforeMenu   = DB::table('tblmenumaster_g2g')->where('parent_id', $sib->parent_id)->whereNull('deleted_at')->count();
$sibRights    = DB::table('tblgroupwise_rights_g2g')->where('menu_id', $sib->id)->get();
$beforeRights = DB::table('tblgroupwise_rights_g2g')->count();

printf("PREDICTED: 1 menu row, %d rights row(s) copied from the sibling's profiles\n", $sibRights->count());
if ($sibRights->isEmpty()) {
    echo "REFUSING: sibling has no rights rows, so there is nothing to inherit and\n";
    echo "the menu would be created invisible to every profile.\n";
    exit(1);
}

$menuId = null;
DB::transaction(function () use (&$menuId, $sib, $maxSort, $sibRights) {
    $menuId = DB::table('tblmenumaster_g2g')->insertGetId([
        'menu_name'        => NAME,
        'parent_id'        => $sib->parent_id,
        'level'            => $sib->level,
        'page_type'        => $sib->page_type,
        'access_link'      => LINK,
        'icon'             => $sib->icon,
        'menu_type'        => $sib->menu_type,
        'status'           => $sib->status,
        'sort_order'       => $maxSort + 1,
        'sub_institute_id' => $sib->sub_institute_id,
        'created_at'       => now(),
        'updated_at'       => now(),
    ]);

    // PER PROFILE, FROM THE SIBLING. The id comes from the insert above, never
    // from a guess at what the next id will be.
    foreach ($sibRights as $r) {
        // COPY THE SIBLING'S OWN COLUMNS, ADD NOTHING. The first version set
        // `updated_at` because rows usually have one - this table does not, and
        // the insert failed. Taking the shape from the row itself cannot be wrong
        // about the shape: whatever columns the sibling has are the columns that
        // exist. Resolve, do not assume.
        $row = (array) $r;
        unset($row['id']);
        $row['menu_id'] = $menuId;
        if (array_key_exists('created_at', $row)) {
            $row['created_at'] = now();
        }
        DB::table('tblgroupwise_rights_g2g')->insert($row);
    }
});

$afterMenu   = DB::table('tblmenumaster_g2g')->where('parent_id', $sib->parent_id)->whereNull('deleted_at')->count();
$afterRights = DB::table('tblgroupwise_rights_g2g')->count();
$mine        = DB::table('tblgroupwise_rights_g2g')->where('menu_id', $menuId)->count();

echo "\nACTUAL:\n";
printf("  menu id            %d\n", $menuId);
printf("  siblings %d -> %d   (+%d)\n", $beforeMenu, $afterMenu, $afterMenu - $beforeMenu);
printf("  rights   %d -> %d   (+%d, %d on this menu)\n", $beforeRights, $afterRights, $afterRights - $beforeRights, $mine);
printf("  DIVERGENCE         %+d\n", $mine - $sibRights->count());

echo "\nTO REVERSE, exactly:\n";
printf("  DELETE FROM tblgroupwise_rights_g2g WHERE menu_id = %d;\n", $menuId);
printf("  DELETE FROM tblmenumaster_g2g WHERE id = %d;\n", $menuId);

echo "\nSTILL REQUIRED - the row alone does not render a screen:\n";
printf("  add { submenuId: '%d', component: CmRoleRequirements } to content-map-m2.ts\n", $menuId);
