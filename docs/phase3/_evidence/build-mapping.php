<?php
/**
 * 4b-prep (c) — SCREEN → MENU MAPPING, the gating artefact for 4b.
 *
 * Maps the 57 screen names in 03-rbac-matrix.md §3.1–3.7 onto menu ids in
 * tblmenumaster_g2g (the Next.js tree).
 *
 * ─── RULES, applied exactly ──────────────────────────────────────────────────
 *  - HIGH confidence auto-applies. Everything else is declared BY HAND.
 *    A wrong mapping is more dangerous than a missing one because it reads as
 *    configured (C30's lesson, unchanged).
 *  - NOTHING maps to a container / module-root row (parent_id = 0 or has children).
 *  - SHIP-scoped: DEFER and DELETE rows from 01b-scope-triage.md do not need
 *    permissions designed for them.
 *  - UNMAPPED = DENIED. With canView's `?? 0`, a screen that maps to no menu is
 *    removed from every role. That is a permission decision, so it is listed
 *    explicitly rather than left to fall through.
 *
 * Output: docs/phase3/_changes/X-01-screen-menu-map.csv
 */
require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require_once 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

/* ---------- 1. the 57 screens from §3.1–3.7 ---------- */
$md = file_get_contents('C:/Users/MILAN/Downloads/hp_erp/docs/phase3/03-rbac-matrix.md');
preg_match_all('/### (3\.\d) ([^\n]+)\n(.*?)(?=\n### |\n## |\z)/s', $md, $m, PREG_SET_ORDER);

$screens = [];
foreach ($m as [, $num, $title, $body]) {
    foreach (explode("\n", $body) as $line) {
        if (!str_starts_with($line, '| ') || str_starts_with($line, '| Screen') || str_starts_with($line, '|---')) continue;
        $cells = array_map('trim', explode('|', trim($line, '|')));
        $name = trim(preg_replace('/\*\*|\*|\(SHIP\)|\(.*?\)/', '', $cells[0]));
        if ($name === '') continue;
        $screens[] = ['section' => $num, 'module' => trim($title), 'screen' => $name, 'raw' => $cells[0]];
    }
}

/* ---------- 2. the menu tree ---------- */
$menus = DB::table('tblmenumaster_g2g')->select('id', 'menu_name', 'parent_id', 'status')->get();
$childCount = [];
foreach ($menus as $x) { $childCount[$x->parent_id] = ($childCount[$x->parent_id] ?? 0) + 1; }

/* menus that actually carry a rights row */
$withRights = DB::table('tblgroupwise_rights_g2g')->distinct()->pluck('menu_id')->flip();

$norm = fn($s) => preg_replace('/[^a-z0-9]+/', ' ', strtolower(trim($s)));

$rows = [];
$usedMenus = [];

foreach ($screens as $s) {
    $target = $norm($s['screen']);
    $best = null; $bestScore = 0; $bestWhy = '';

    foreach ($menus as $mm) {
        $cand = $norm($mm->menu_name);
        if ($cand === '') continue;

        $isContainer = ($mm->parent_id == 0) || (($childCount[$mm->id] ?? 0) > 0);

        if ($cand === $target)                 { $score = 100; $why = 'exact name match'; }
        elseif (str_contains($cand, $target))  { $score = 80;  $why = 'menu contains screen name'; }
        elseif (str_contains($target, $cand))  { $score = 70;  $why = 'screen name contains menu'; }
        else {
            similar_text($cand, $target, $pct);
            if ($pct < 78) continue;
            $score = (int) $pct; $why = sprintf('fuzzy %.0f%%', $pct);
        }
        // A container can never be the target of a screen permission.
        if ($isContainer) { $score -= 50; $why .= ' [CONTAINER - rejected]'; }

        if ($score > $bestScore) { $bestScore = $score; $best = $mm; $bestWhy = $why; $bestIsContainer = $isContainer; }
    }

    if ($best === null || $bestScore <= 0) {
        $rows[] = [$s['section'], $s['module'], $s['screen'], '', '', 'NONE', 'NO MENU MATCH',
                   'SCREEN-TO-BE-BUILT or UNMAPPED=DENIED - declare by hand'];
        continue;
    }

    $container = $bestIsContainer ?? false;
    $conf = $container ? 'REJECTED-CONTAINER' : ($bestScore >= 100 ? 'HIGH' : ($bestScore >= 80 ? 'MEDIUM' : 'LOW'));
    $auto  = ($conf === 'HIGH') ? 'AUTO' : 'BY HAND';
    $hasRights = isset($withRights[$best->id]) ? 'yes' : 'NO RIGHTS ROW';

    $rows[] = [$s['section'], $s['module'], $s['screen'], $best->id, $best->menu_name,
               $conf, $bestWhy, $auto . ' | rights row: ' . $hasRights];
    if ($conf === 'HIGH') $usedMenus[$best->id] = true;
}

/* ---------- 3. menus with no rights row ---------- */
$absent = [];
foreach ($menus as $mm) {
    if (!isset($withRights[$mm->id])) {
        $isContainer = ($mm->parent_id == 0) || (($childCount[$mm->id] ?? 0) > 0);
        $absent[] = [$mm->id, $mm->menu_name, $isContainer ? 'container' : 'leaf', $mm->status];
    }
}

/* ---------- 4. write the CSV ---------- */
$out = fopen('C:/Users/MILAN/Downloads/hp_erp/docs/phase3/_changes/X-01-screen-menu-map.csv', 'w');
fputcsv($out, ['section', 'module', 'screen_name_3x', 'menu_id', 'menu_name', 'confidence', 'source', 'decision']);
foreach ($rows as $r) fputcsv($out, $r);
fputcsv($out, []);
fputcsv($out, ['--- MENUS WITH NO RIGHTS ROW (unmapped = denied) ---']);
fputcsv($out, ['menu_id', 'menu_name', 'kind', 'status', 'DECISION REQUIRED']);
foreach ($absent as $a) fputcsv($out, [$a[0], $a[1], $a[2], $a[3], '']);
fclose($out);

/* ---------- 5. summary ---------- */
$byConf = [];
foreach ($rows as $r) { $byConf[$r[5]] = ($byConf[$r[5]] ?? 0) + 1; }
printf("screens in §3.1–3.7 : %d\n", count($screens));
printf("menus in tree       : %d\n\n", count($menus));
foreach ($byConf as $k => $v) printf("  %-20s %d\n", $k, $v);
printf("\nHIGH (auto-applied) : %d\n", $byConf['HIGH'] ?? 0);
printf("declared BY HAND    : %d\n", count($rows) - ($byConf['HIGH'] ?? 0));
printf("menus with NO rights row: %d\n", count($absent));
