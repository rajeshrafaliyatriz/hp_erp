<?php
/**
 * RIGHTS FOR THE PHASE 3 MENUS, RESOLVED BY access_link — NOT BY ID.
 *
 * WHY THIS EXISTS: menu-227-rights.php and its two siblings hardcode
 * `const NEW_MENU = 227`, which is the id those menus got ON DEV. On live the
 * same three screens are 224, 225 and 226, because live's menu table stopped at
 * 223. Running the dev scripts here would write rights for menus that do not
 * exist.
 *
 *     AN ID IS AN ACCIDENT OF INSERT ORDER. access_link IS THE THING ITSELF.
 *
 * That is the same rule this project has paid for repeatedly: resolve, do not
 * match. This script works on any environment because it never assumes a number.
 *
 * RIGHTS ARE COPIED FROM A SIBLING MENU'S OWN ROWS, per profile. A profile that
 * cannot see the sibling does not gain access to the new screen — the sibling
 * already encodes who belongs there.
 *
 * IDEMPOTENT: a menu that already has rights is skipped and says so.
 */
require __DIR__ . '/../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

// The sibling whose rights define who may reach these screens. 156 = Employee
// Profiles, the same one the dev scripts used.
const SIBLING = 156;

$targets = [
    'competency-definitions',
    'course-competencies',
    'task-competencies',
];

echo "connection: " . config('database.default') . "\n\n";

$sibRights = DB::table('tblgroupwise_rights_g2g')->where('menu_id', SIBLING)->get();
if ($sibRights->isEmpty()) {
    echo "REFUSING: sibling menu " . SIBLING . " has no rights rows, so there is\n";
    echo "nothing to inherit and every menu would be created invisible.\n";
    exit(1);
}
printf("sibling %d has %d rights row(s) — that is the template\n\n", SIBLING, $sibRights->count());

$before = DB::table('tblgroupwise_rights_g2g')->count();
$totalWritten = 0;

foreach ($targets as $slug) {
    $menu = DB::table('tblmenumaster_g2g')->where('access_link', 'like', '%' . $slug)->first(['id', 'menu_name']);

    if (!$menu) {
        printf("  %-26s NOT FOUND by access_link — skipped, nothing written\n", $slug);
        continue;
    }

    $have = DB::table('tblgroupwise_rights_g2g')->where('menu_id', $menu->id)->count();
    if ($have > 0) {
        printf("  %-26s menu #%d already has %d rights row(s) — skipped\n", $slug, $menu->id, $have);
        continue;
    }

    $written = 0;
    DB::transaction(function () use ($sibRights, $menu, &$written) {
        foreach ($sibRights as $r) {
            // Copy the sibling's OWN columns and add nothing. Taking the shape
            // from the row itself cannot be wrong about the shape - this table
            // has no updated_at, which an earlier version of this logic assumed
            // and failed on.
            $row = (array) $r;
            unset($row['id']);
            $row['menu_id'] = $menu->id;
            if (array_key_exists('created_at', $row)) {
                $row['created_at'] = now();
            }
            DB::table('tblgroupwise_rights_g2g')->insert($row);
            $written++;
        }
    });

    $totalWritten += $written;
    printf("  %-26s menu #%-4d %-24s +%d rights\n", $slug, $menu->id, $menu->menu_name, $written);
}

$after = DB::table('tblgroupwise_rights_g2g')->count();

echo "\n";
printf("PREDICTED written : %d\n", $totalWritten);
printf("ACTUAL   rights   : %d -> %d  (+%d)\n", $before, $after, $after - $before);
printf("DIVERGENCE        : %+d\n", ($after - $before) - $totalWritten);

echo "\nTO REVERSE, exactly:\n";
foreach ($targets as $slug) {
    $menu = DB::table('tblmenumaster_g2g')->where('access_link', 'like', '%' . $slug)->first(['id']);
    if ($menu) {
        printf("  DELETE FROM tblgroupwise_rights_g2g WHERE menu_id = %d;\n", $menu->id);
    }
}
