<?php
/**
 * RIGHTS ROWS FOR MENU 227 ("Competency Definitions").
 *
 * APPROVED BY TRIZ, SCOPED: admin/HR profiles only, derived per-profile from
 * sibling 156 (Employee Profiles) - the same menu family, the same screen kind.
 *
 * WHY IT IS NEEDED: `displaySidebarMenu` builds `rightsByMenuId` from
 * `tblgroupwise_rights_g2g` keyed on `menu_id`. A menu row with no rights row is
 * invisible however correct everything else is - the component, the host, the
 * content map and the menu row were all done and the screen still could not be
 * opened. MOUNTED IS NOT REACHABLE.
 *
 * DRY RUN BY DEFAULT. Pass `--apply` to write. The count is printed either way,
 * before anything happens, and the reversal is printed after.
 *
 * NOT COPIED BLINDLY: only profiles whose role_key is administrator / hr_manager
 * / hr_executive AND which already hold a rights row for 156. A profile with no
 * access to Employee Profiles does not silently gain access to a new screen.
 */
require __DIR__ . '/../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

const NEW_MENU = 229;
const SIBLING  = 156;
$apply = in_array('--apply', $argv, true);

$adminProfiles = DB::table('tbluserprofilemaster')
    ->whereIn('role_key', ['administrator', 'hr_manager', 'hr_executive'])
    ->pluck('id')->all();

$source = DB::table('tblgroupwise_rights_g2g')
    ->where('menu_id', SIBLING)
    ->whereIn('profile_id', $adminProfiles)
    ->get();

$already = DB::table('tblgroupwise_rights_g2g')->where('menu_id', NEW_MENU)
    ->pluck('profile_id')->all();

$todo = $source->reject(fn ($r) => in_array($r->profile_id, $already, true))->values();

printf("admin/HR profiles                       : %d\n", count($adminProfiles));
printf("of those, holding rights on sibling %d  : %d\n", SIBLING, $source->count());
printf("already have rights on menu %d          : %d\n", NEW_MENU, count($already));
printf("ROWS THIS WOULD WRITE                   : %d\n\n", $todo->count());

if ($todo->isEmpty()) { echo "nothing to do\n"; exit(0); }

$cols = array_keys((array) $source->first());
printf("columns copied from sibling rows: %s\n", implode(', ', $cols));
printf("mode: %s\n\n", $apply ? 'APPLY' : 'DRY RUN (pass --apply to write)');

if (!$apply) {
    foreach ($todo->take(4) as $r) {
        printf("  would write profile_id=%-6s menu_id=%d  (from its own row on %d)\n",
            $r->profile_id, NEW_MENU, SIBLING);
    }
    if ($todo->count() > 4) printf("  ... and %d more\n", $todo->count() - 4);
    exit(0);
}

$before = DB::table('tblgroupwise_rights_g2g')->where('menu_id', NEW_MENU)->count();
$written = 0;
DB::transaction(function () use ($todo, &$written) {
    foreach ($todo as $r) {
        $row = (array) $r;
        unset($row['id']);
        $row['menu_id']    = NEW_MENU;
        $row['created_at'] = now();
        // NO `updated_at` ON THIS TABLE. The dry run printed the sibling's real
        // column list and it is absent; setting it would have failed the apply
        // with "Unknown column". Caught by READING the dry run rather than
        // running it - "a dry run whose output nobody reads is not a safeguard",
        // and this is the run where reading it paid.
        DB::table('tblgroupwise_rights_g2g')->insert($row);
        $written++;
    }
});
$after = DB::table('tblgroupwise_rights_g2g')->where('menu_id', NEW_MENU)->count();

printf("WROTE %d rows.  menu %d rights: %d -> %d\n", $written, NEW_MENU, $before, $after);
printf("\nTO REVERSE:  DELETE FROM tblgroupwise_rights_g2g WHERE menu_id = %d;\n", NEW_MENU);
