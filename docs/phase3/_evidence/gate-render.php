<?php
/**
 * 4b GATES — run against the REGENERATED seed, through the real render path.
 *
 * Counting rows is what missed the container defect. This replays
 * displaySidebarMenu's own logic: a module is skipped unless canView() passes,
 * and canView denies on absence (`?? 0`).
 */
require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require_once 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$seed  = json_decode(file_get_contents('C:/Users/MILAN/Downloads/hp_erp/docs/phase3/_changes/X-01-seed.json'), true);
$menus = DB::table('tblmenumaster_g2g')->where('status', 1)->get();
$byParent = [];
foreach ($menus as $m) $byParent[$m->parent_id][] = $m;
$names = DB::table('tblmenumaster_g2g')->pluck('menu_name', 'id');

/* one representative profile per role */
$profiles = DB::table('tbluserprofilemaster')->whereNotNull('role_key')->get()->groupBy('role_key');

/* seed keyed profile => menu => row */
$byProfile = [];
foreach ($seed['rows'] as $r) $byProfile[$r['profile_id']][$r['menu_id']] = $r;

$canView = fn($rights, $id) => ($rights[$id]['can_view'] ?? 0) == 1;

/** replays displaySidebarMenu: modules filtered first, then children */
function render(array $rights, array $byParent, callable $canView): array {
    $visible = [];
    foreach ($byParent[0] ?? [] as $module) {
        if (!$canView($rights, $module->id)) continue;
        $kids = [];
        foreach ($byParent[$module->id] ?? [] as $sec) {
            if (!$canView($rights, $sec->id)) continue;
            $leaves = [];
            foreach ($byParent[$sec->id] ?? [] as $leaf)
                if ($canView($rights, $leaf->id)) $leaves[] = $leaf->id;
            // a section with children but none visible is dropped, as the controller does
            if (isset($byParent[$sec->id]) && !$leaves) continue;
            $kids[] = $sec->id;
            $visible = array_merge($visible, $leaves);
        }
        if (isset($byParent[$module->id]) && !$kids) continue;
        $visible = array_merge($visible, $kids, [$module->id]);
    }
    return array_values(array_unique($visible));
}

echo "=== GATE A — EVERY ROLE'S SIDEBAR RENDERS AND IS NON-EMPTY ===\n\n";
printf("%-20s %10s %10s  %s\n", 'role', 'modules', 'total', 'verdict');
$employeeVisible = [];
foreach ($profiles as $roleKey => $ps) {
    $pid = $ps[0]->id;
    $rights = $byProfile[$pid] ?? [];
    $vis = render($rights, $byParent, $canView);
    $mods = count(array_filter($vis, fn($id) => !array_key_exists($id, [])
        && (DB::table('tblmenumaster_g2g')->where('id', $id)->value('parent_id') == 0)));
    printf("%-20s %10d %10d  %s\n", $roleKey, $mods, count($vis),
        count($vis) === 0 ? '*** EMPTY - BLOCKER ***' : 'renders');
    if ($roleKey === 'employee') $employeeVisible = $vis;
}

echo "\n=== GATE B — ADMINISTRATOR REACHES ROLE & PERMISSIONS THROUGH ITS ANCESTORS ===\n";
$adminPid = $profiles['administrator'][0]->id;
$adminRights = $byProfile[$adminPid] ?? [];
$adminVis = render($adminRights, $byParent, $canView);
$parentOf = DB::table('tblmenumaster_g2g')->pluck('parent_id', 'id')->all();
$chain = [23]; $c = 23;
while (isset($parentOf[$c]) && $parentOf[$c] != 0) { $c = $parentOf[$c]; $chain[] = $c; }
foreach (array_reverse($chain) as $id)
    printf("  %-5s %-34s visible to admin: %s\n", $id, $names[$id] ?? '?',
        in_array($id, $adminVis) ? 'YES' : '*** NO - UNREACHABLE ***');

echo "\n=== GATE C — EMPLOYEE'S VISIBLE LEAVES, BY NAME ===\n";
$leafIds = array_filter($employeeVisible, fn($id) => !isset($byParent[$id]));
sort($leafIds);
printf("  %d leaf screens (plus containers to reach them)\n\n", count($leafIds));
foreach ($leafIds as $id) printf("    %-5s %s\n", $id, $names[$id] ?? '?');
