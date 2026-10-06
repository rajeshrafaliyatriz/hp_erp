<?php
/**
 * G-NAV-02 — create two menu rows under Organization Setup (7).
 *
 * WHY: `/organization/readiness` was built, browser-verified, and had NO MENU
 * ROW — nobody could navigate to it. `PUT /terminology` had none either. A screen
 * with no menu row is invisible in the product AND cannot be expressed in the
 * rights matrix, which is why this blocks matrix-enforced authorization.
 *
 * G-NAV-01 TEMPLATE: backup, guard query, stated blast radius, exact rollback,
 * every value SIBLING-DERIVED. Nothing guessed.
 *
 *   php G-NAV-02-create-readiness-and-terminology-menus.php --dry
 *   php G-NAV-02-create-readiness-and-terminology-menus.php
 *   php G-NAV-02-create-readiness-and-terminology-menus.php --rollback
 */
require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

const PARENT = 7;                       // Organization Setup
const BASE   = '/module/organizational-management/organization-setup/';
$dry      = in_array('--dry', $argv, true);
$rollback = in_array('--rollback', $argv, true);

$NEW = [
    ['name' => 'Readiness Gates', 'slug' => 'readiness-gates'],
    ['name' => 'Terminology',     'slug' => 'terminology'],
];

// ── ROLLBACK ────────────────────────────────────────────────────────────────
if ($rollback) {
    $links = array_map(fn ($n) => BASE . $n['slug'], $NEW);
    $ids = DB::table('tblmenumaster_g2g')->whereIn('access_link', $links)->pluck('id');
    $r = DB::table('tblgroupwise_rights_g2g')->whereIn('menu_id', $ids)->delete();
    $m = DB::table('tblmenumaster_g2g')->whereIn('id', $ids)->delete();
    printf("rolled back: %d menu rows, %d rights rows\n", $m, $r);
    exit;
}

// ── GUARD QUERY — refuse if either already exists ───────────────────────────
foreach ($NEW as $n) {
    $exists = DB::table('tblmenumaster_g2g')->where('access_link', BASE . $n['slug'])->count();
    if ($exists) { exit("REFUSING: {$n['slug']} already exists ($exists row(s)). Nothing written.\n"); }
}

// ── SIBLING-DERIVED VALUES. Every column copied from row 13, not chosen. ────
$sib = DB::table('tblmenumaster_g2g')->where('id', 13)->first();
if (!$sib) { exit("REFUSING: sibling row 13 not found - nothing to derive from.\n"); }

printf("sibling row 13 (Department Management):\n");
printf("  parent_id=%s level=%s page_type=%s menu_type=%s status=%s sub_institute_id=%s\n\n",
    $sib->parent_id, $sib->level, $sib->page_type, $sib->menu_type, $sib->status, $sib->sub_institute_id);

// ── BLAST RADIUS, STATED BEFORE WRITING ─────────────────────────────────────
$tenants = array_filter(array_map('trim', explode(',', (string) $sib->sub_institute_id)));
$maxSort = (int) DB::table('tblmenumaster_g2g')->where('parent_id', PARENT)->max('sort_order');
printf("BLAST RADIUS\n");
printf("  rows created            : %d menu rows\n", count($NEW));
printf("  tenants affected        : %d (%s) - copied from the sibling's CSV, not chosen\n", count($tenants), $sib->sub_institute_id);
printf("  navigation change       : 2 new entries under Organization Setup for those tenants\n");
printf("  rights created here     : NONE. Rights are a separate, reviewable step.\n");
printf("  visible to              : nobody until rights are seeded - a menu with no\n");
printf("                            can_view row renders for no role.\n");
printf("  sort_order              : %d, %d (after the current max %d)\n\n", $maxSort + 1, $maxSort + 2, $maxSort);

if ($dry) { exit("--dry: nothing written.\n"); }

// ── BACKUP ──────────────────────────────────────────────────────────────────
$backup = __DIR__ . '/G-NAV-02-backup-menumaster-parent7.json';
file_put_contents($backup, json_encode(
    DB::table('tblmenumaster_g2g')->where('parent_id', PARENT)->get(), JSON_PRETTY_PRINT));
printf("backup written: %s (%d rows)\n", basename($backup),
    DB::table('tblmenumaster_g2g')->where('parent_id', PARENT)->count());

// ── WRITE ───────────────────────────────────────────────────────────────────
DB::transaction(function () use ($NEW, $sib, $maxSort) {
    $i = 1;
    foreach ($NEW as $n) {
        DB::table('tblmenumaster_g2g')->insert([
            'menu_name'        => $n['name'],
            'parent_id'        => PARENT,
            'level'            => $sib->level,
            'page_type'        => $sib->page_type,
            'access_link'      => BASE . $n['slug'],
            'icon'             => $sib->icon,
            'status'           => $sib->status,
            'sort_order'       => $maxSort + $i,
            'sub_institute_id' => $sib->sub_institute_id,
            'menu_type'        => $sib->menu_type,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
        $i++;
    }
});

foreach ($NEW as $n) {
    $r = DB::table('tblmenumaster_g2g')->where('access_link', BASE . $n['slug'])->first();
    printf("created: id=%-4d %-18s %s\n", $r->id, $r->menu_name, $r->access_link);
}
printf("\nrollback: php %s --rollback\n", basename(__FILE__));
