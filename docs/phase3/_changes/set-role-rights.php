<?php
/**
 * ROLE RIGHTS — HR and Employee, set from the agreed access matrix.
 *
 * WHY THIS WAS NEEDED: profiles 2 (HR) and 3 (Employee) had ZERO rights rows on
 * live. A profile with no rights rows does not get "no access" — the system has
 * nothing to filter by, SO IT SHOWS EVERYTHING. It failed OPEN, which is why an
 * employee could open Organization and every other module.
 *
 *     NO ROWS IS NOT "DENIED". IT IS "UNFILTERED".
 *
 * THE MATRIX, as agreed:
 *
 *   MODULE              ADMIN        HR           EMPLOYEE
 *   Organization        configures   reads        none
 *   Competency          authors      rates        own only (read)
 *   HRIT / HRMS         full         full         self service (read)
 *   LMS                 full         assigns      learns (read)
 *   Task Management     full         full         assigned only (read)
 *   Talent              full         full         none
 *   Reports             full         full         none
 *
 * CRM and Agentic AI are not in the matrix and are left to Admin alone —
 * absent from the matrix means NOT DECIDED, and an undecided module is not
 * granted by default.
 *
 * "READ" means can_view only. WRITE FLAGS ARE NOT GRANTED BY THIS SCRIPT except
 * where the matrix says full — an employee who can open a screen is not
 * thereby allowed to change it.
 *
 * IDEMPOTENT. Existing rows for these profiles are replaced, so re-running
 * converges rather than duplicating. Admin (profile 1) IS NOT TOUCHED.
 */
require __DIR__ . '/../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

const TENANT = 1;
const HR = 2;
const EMPLOYEE = 3;

// module root id => [HR level, EMPLOYEE level]   'full' | 'read' | 'none'
$MATRIX = [
    1   => ['read', 'none'],   // Organizational Management
    2   => ['full', 'read'],   // Competency Management
    5   => ['full', 'read'],   // HRIT Management  (HRMS)
    4   => ['full', 'read'],   // LMS
    204 => ['full', 'read'],   // Task Management
    3   => ['full', 'none'],   // Talent Management
    6   => ['full', 'none'],   // Reports
    199 => ['none', 'none'],   // CRM        — not in the matrix
    186 => ['none', 'none'],   // Agentic AI — not in the matrix
];

/** Every descendant of a module, at any depth. */
function tree(int $root): array
{
    $ids = [$root];
    $frontier = [$root];
    while ($frontier) {
        $next = DB::table('tblmenumaster_g2g')->whereIn('parent_id', $frontier)->pluck('id')->all();
        $next = array_values(array_diff($next, $ids));
        if (!$next) {
            break;
        }
        $ids = array_merge($ids, $next);
        $frontier = $next;
    }
    return $ids;
}

echo "connection: " . config('database.default') . "\n\n";

$before = DB::table('tblgroupwise_rights_g2g')
    ->whereIn('profile_id', [HR, EMPLOYEE])->where('sub_institute_id', TENANT)->count();

$plan = [];
foreach ($MATRIX as $root => [$hrLevel, $empLevel]) {
    $name = DB::table('tblmenumaster_g2g')->where('id', $root)->value('menu_name');
    $ids  = tree((int) $root);
    $plan[$root] = ['name' => $name, 'ids' => $ids, 'hr' => $hrLevel, 'emp' => $empLevel];
    printf("  %-34s %3d menu(s)   HR=%-5s EMPLOYEE=%s\n", substr((string) $name, 0, 34), count($ids), $hrLevel, $empLevel);
}

$rows = [];
foreach ($plan as $p) {
    foreach ([HR => $p['hr'], EMPLOYEE => $p['emp']] as $profile => $level) {
        if ($level === 'none') {
            continue;   // no row at all — the menu simply is not listed for them
        }
        $full = $level === 'full';
        foreach ($p['ids'] as $menuId) {
            $rows[] = [
                'menu_id'          => $menuId,
                'profile_id'       => $profile,
                'can_view'         => 1,
                'can_add'          => $full ? 1 : 0,
                'can_edit'         => $full ? 1 : 0,
                'can_delete'       => $full ? 1 : 0,
                'dashboard_right'  => 0,
                'is_mobile'        => 0,
                'sub_institute_id' => TENANT,
                'created_at'       => now(),
            ];
        }
    }
}

printf("\nPREDICTED rows to write: %d\n", count($rows));

DB::transaction(function () use ($rows) {
    // Replace rather than append, so re-running converges.
    DB::table('tblgroupwise_rights_g2g')
        ->whereIn('profile_id', [HR, EMPLOYEE])->where('sub_institute_id', TENANT)->delete();

    foreach (array_chunk($rows, 200) as $chunk) {
        DB::table('tblgroupwise_rights_g2g')->insert($chunk);
    }
});

$after = DB::table('tblgroupwise_rights_g2g')
    ->whereIn('profile_id', [HR, EMPLOYEE])->where('sub_institute_id', TENANT)->count();

printf("ACTUAL   rows now      : %d  (was %d)\n", $after, $before);
printf("DIVERGENCE             : %+d\n\n", $after - count($rows));

foreach ([HR => 'HR', EMPLOYEE => 'Employee'] as $p => $label) {
    $v = DB::table('tblgroupwise_rights_g2g')->where('profile_id', $p)->where('sub_institute_id', TENANT)->count();
    $w = DB::table('tblgroupwise_rights_g2g')->where('profile_id', $p)->where('sub_institute_id', TENANT)->where('can_edit', 1)->count();
    printf("  %-9s %4d menu(s) visible, %4d editable\n", $label, $v, $w);
}
$adm = DB::table('tblgroupwise_rights_g2g')->where('profile_id', 1)->where('sub_institute_id', TENANT)->count();
printf("  %-9s %4d  (UNTOUCHED)\n", 'Admin', $adm);

echo "\nTO REVERSE:\n";
printf("  DELETE FROM tblgroupwise_rights_g2g WHERE profile_id IN (%d,%d) AND sub_institute_id = %d;\n", HR, EMPLOYEE, TENANT);
echo "  (that returns them to zero rows, which is the state before this ran —\n";
echo "   and remember zero rows means UNFILTERED, not denied.)\n";
