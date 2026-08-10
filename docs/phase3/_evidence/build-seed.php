<?php
/**
 * 4b — GENERATE THE RIGHTS SEED. Does NOT apply it.
 *
 * Sources, all already approved:
 *   03-rbac-matrix.md §3.1–3.7  the permission marks (V C E D A X)
 *   Q-D1                         Recruiter, module-level, expanded to screens
 *   X-01-screen-menu-map.csv     42 HIGH auto-mapped
 *   Triz's declarations 2026-08-10  the 15 by-hand rows
 *
 * Marks: V=view  C=create  E=edit  D=delete  A=approve  X=export
 *        "–" or empty = NO ROW (and absence denies, per canView's `?? 0`)
 *
 * Writes docs/phase3/_changes/X-01-seed.json for review. Applies nothing.
 */
require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require_once 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

/* §3.x column order -> role_key. Recruiter is NOT a column; handled separately. */
const COLS = ['employee', 'reporting_manager', 'department_head', 'hr_executive',
              'hr_manager', 'administrator', 'executive', 'auditor'];

/* Triz's declarations, 2026-08-10. Screen name (normalised) => menu ids. */
const DECLARED = [
    'permission'                          => [219],   // the misspelled row G-NAV-01 fixed
    'performance reviews'                 => [49],
    'dashboard'                           => [210],   // NOT 46 Talent Dashboard - wrong module
    'payroll'                             => [105, 106, 108, 109, 110, 140],  // the payroll AREA, live children; 107 disabled, excluded
    'status priority management'          => [217, 218],
    'leave dashboard requests'            => [102, 103],
    'leave configuration allocation'      => [165, 166],
    // Module names, not screens. The container rule applied one level up:
    // grant the LIVE CHILDREN, never the container.
    'consolidated reports'                => [122],   // only live child of 6; 9 siblings status=0
    'agentic ai'                          => [188, 189, 190, 191, 192, 193, 194],  // 7 live; 187 Pal status=0 and removed per Q-A5
];

/* Screens with no menu - DENIED BECAUSE NOT BUILT, not denied by decision. */
const NOT_BUILT = ['group wise rights', 'individual rights', 'task approvals',
                   'competency gap report', 'development plan report', 'certification expiry report'];

$norm = fn($s) => trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9]+/', ' ', strtolower($s))));

/* ---------- parse §3.1–3.7 ---------- */
$md = file_get_contents('C:/Users/MILAN/Downloads/hp_erp/docs/phase3/03-rbac-matrix.md');
preg_match_all('/### (3\.\d) ([^\n]+)\n(.*?)(?=\n### |\n## |\z)/s', $md, $mm, PREG_SET_ORDER);

$menus = DB::table('tblmenumaster_g2g')->get()->keyBy('id');
$childCount = [];
foreach ($menus as $x) { $childCount[$x->parent_id] = ($childCount[$x->parent_id] ?? 0) + 1; }

$grants = [];      // role_key => menu_id => ['V'=>1,'C'=>1,...]
$unmapped = [];
$mapped = [];

foreach ($mm as [, $sec, $title, $body]) {
    foreach (explode("\n", $body) as $line) {
        if (!str_starts_with($line, '| ') || str_starts_with($line, '| Screen') || str_starts_with($line, '|---')) continue;
        $cells = array_map('trim', explode('|', trim($line, '|')));
        $screen = trim(preg_replace('/\*\*|\*|\(SHIP\)|\(.*?\)/', '', $cells[0]));
        if ($screen === '') continue;
        $key = $norm($screen);

        /* resolve to menu ids */
        $ids = [];
        if (isset(DECLARED[$key]))            { $ids = DECLARED[$key]; }
        elseif (in_array($key, NOT_BUILT))    { $unmapped[] = [$sec, $screen, 'DENIED BECAUSE NOT BUILT']; continue; }
        else {
            foreach ($menus as $mn) {
                if ($norm($mn->menu_name) !== $key) continue;
                if ($mn->parent_id == 0 || ($childCount[$mn->id] ?? 0) > 0) continue;  // never a container
                $ids[] = $mn->id;
            }
        }
        if (!$ids) { $unmapped[] = [$sec, $screen, 'NO MATCH - not seeded']; continue; }
        $mapped[] = [$sec, $screen, implode(',', $ids)];

        /* the eight §3.x columns */
        foreach (COLS as $i => $role) {
            $mark = $cells[$i + 1] ?? '';
            $mark = strtoupper(preg_replace('/[^A-Za-z]/', '', $mark));
            if ($mark === '' || !str_contains($mark, 'V')) continue;   // no V => no row => denied
            foreach ($ids as $id) {
                $grants[$role][$id]['V'] = 1;
                foreach (['C', 'E', 'D'] as $f) if (str_contains($mark, $f)) $grants[$role][$id][$f] = 1;
            }
        }

        /* Recruiter, from Q-D1, module-level expanded to screens */
        $recruiter = null;
        if (str_contains($title, 'Talent') && str_contains($key, 'recruit'))       $recruiter = 'VCED';
        elseif (str_contains($title, 'Talent') && str_contains($key, 'onboarding')) $recruiter = 'V';
        elseif (str_contains($key, 'employee directory'))                           $recruiter = 'V';
        elseif (str_contains($key, 'framework role mapping'))                       $recruiter = 'V';
        if ($recruiter) foreach ($ids as $id) {
            $grants['recruiter'][$id]['V'] = 1;
            foreach (['C', 'E', 'D'] as $f) if (str_contains($recruiter, $f)) $grants['recruiter'][$id][$f] = 1;
        }
    }
}

/* ---------- DERIVE CONTAINER can_view — every run, never authored ----------
 *
 * displaySidebarMenu filters a module with canView() BEFORE descending into its
 * children, so a denied container hides everything beneath it. Granting a
 * container is therefore required for the tree to render - but it must be
 * DERIVED, so that revoking the last leaf automatically hides the folder again.
 * A hardcoded container grant produces visible empty folders.
 *
 * The tree is THREE levels deep (module -> section -> screen), so the FULL
 * ancestor chain is walked, not just the immediate parent.
 *
 * Action rights are never derived: nobody holds edit on a folder.
 */
$parentOf = $menus->pluck('parent_id', 'id')->all();
foreach ($grants as $role => $held) {
    foreach (array_keys($held) as $leafId) {
        $cursor = $leafId; $hops = 0;
        while (isset($parentOf[$cursor]) && $parentOf[$cursor] != 0 && $hops++ < 10) {
            $cursor = $parentOf[$cursor];
            if (!isset($grants[$role][$cursor]['V'])) {
                $grants[$role][$cursor]['V'] = 1;          // view only - DERIVED
                $grants[$role][$cursor]['derived'] = 1;
            }
        }
    }
}

/* ---------- expand across tenants ---------- */
$profiles = DB::table('tbluserprofilemaster')->whereNotNull('role_key')->get();
$rows = [];
foreach ($profiles as $p) {
    foreach ($grants[$p->role_key] ?? [] as $menuId => $f) {
        $rows[] = ['profile_id' => $p->id, 'role_key' => $p->role_key, 'menu_id' => $menuId,
                   'sub_institute_id' => $p->sub_institute_id,
                   'can_view' => 1, 'can_add' => $f['C'] ?? 0, 'can_edit' => $f['E'] ?? 0, 'can_delete' => $f['D'] ?? 0];
    }
}

file_put_contents('C:/Users/MILAN/Downloads/hp_erp/docs/phase3/_changes/X-01-seed.json',
    json_encode(['generated' => date('c'), 'rows' => $rows, 'mapped' => $mapped, 'unmapped' => $unmapped],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

printf("screens mapped   : %d\n", count($mapped));
printf("screens unmapped : %d  (denied - listed in the seed file)\n", count($unmapped));
printf("seed rows        : %d across %d profiles\n\n", count($rows), $profiles->count());
echo "menus granted per role (one tenant's worth):\n";
foreach (COLS as $r) printf("  %-20s %d\n", $r, count($grants[$r] ?? []));
printf("  %-20s %d\n", 'recruiter', count($grants['recruiter'] ?? []));
