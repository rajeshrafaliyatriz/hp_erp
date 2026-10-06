<?php
/**
 * G-UI-01 — GIVE THE CAPABILITY SCREEN A ROUTE.
 *
 * DERIVED, NOT GUESSED. The mounted siblings were read first:
 *
 *   parent_id = 2 (Competency Management), level = 2, page_type = 'page',
 *   access_link = /module/competency-management/competency-library/<slug>,
 *   sub_institute_id = '1,2,...,11'  (a CSV of tenants, not a single id)
 *
 * A guessed accessLink produces a screen that renders for nobody, which is the
 * bug being fixed.
 *
 * WHAT THIS WRITES: one menu row, and one rights row per profile that already
 * holds rights on a comparable screen. VIEW ONLY - nobody adds, edits or deletes
 * on a gap view; it is a read of a computation. The server already scopes the
 * SUBJECT (an employee sees their own, elevated roles may pass user_id and get
 * 403 otherwise, verified by smoke).
 *
 * Every row created is appended to the seed register. Nothing is deleted.
 */
require __DIR__ . '/../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

const SLUG = '/module/competency-management/competency-library/my-capability';
const TEMPLATE_MENU = 156;   // Employee Profiles - the closest comparable screen

echo "\n================ G-UI-01 — ROUTING THE CAPABILITY SCREEN ================\n\n";

$existing = DB::table('tblmenumaster_g2g')->where('access_link', SLUG)->first();
if ($existing) {
    echo "ALREADY ROUTED: menu id {$existing->id}. Nothing written.\n";
    exit(0);
}

// ── derive every field from a sibling rather than inventing one ─────────────
$sib = DB::table('tblmenumaster_g2g')->where('id', TEMPLATE_MENU)->first();
if (!$sib) { echo "REFUSING: template menu " . TEMPLATE_MENU . " not found.\n"; exit(1); }

$maxSort = (int) DB::table('tblmenumaster_g2g')->where('parent_id', $sib->parent_id)->max('sort_order');

printf("derived from menu %d (%s):\n", $sib->id, $sib->menu_name);
printf("   parent_id        = %s\n", $sib->parent_id);
printf("   level            = %s\n", $sib->level);
printf("   page_type        = %s\n", $sib->page_type);
printf("   sub_institute_id = %s\n", $sib->sub_institute_id);
printf("   sort_order       = %d (max sibling %d + 1)\n\n", $maxSort + 1, $maxSort);

$menuId = DB::table('tblmenumaster_g2g')->insertGetId([
    'menu_name'        => 'My Capability',
    'parent_id'        => $sib->parent_id,
    'level'            => $sib->level,
    'page_type'        => $sib->page_type,
    'access_link'      => SLUG,
    'icon'             => $sib->icon,
    'status'           => 1,
    'sort_order'       => $maxSort + 1,
    'sub_institute_id' => $sib->sub_institute_id,
    'menu_type'        => $sib->menu_type,
    'created_at'       => now(),
    'updated_at'       => now(),
]);
printf("menu row created: id=%d  %s\n\n", $menuId, SLUG);

// ── rights: whoever can see Employee Profiles can see their own capability ──
$rows = DB::table('tblgroupwise_rights_g2g')->where('menu_id', TEMPLATE_MENU)->get();
$made = [];
foreach ($rows as $r) {
    $id = DB::table('tblgroupwise_rights_g2g')->insertGetId([
        'menu_id'          => $menuId,
        'profile_id'       => $r->profile_id,
        // VIEW ONLY. A gap is a computation, not a record anyone edits here.
        'can_view'         => 1,
        'can_add'          => 0,
        'can_edit'         => 0,
        'can_delete'       => 0,
        'dashboard_right'  => 0,
        'is_mobile'        => $r->is_mobile,
        'sub_institute_id' => $r->sub_institute_id,
        'right_view'       => 'allow',
        'right_add'        => 'deny',
        'right_edit'       => 'deny',
        'right_delete'     => 'deny',
        'right_dashboard'  => 'deny',
        'created_at'       => now(),
    ]);
    $made[] = $id;
}
printf("rights rows created: %d (view-only, copied from menu %d's grantees)\n", count($made), TEMPLATE_MENU);

$roles = DB::table('tblgroupwise_rights_g2g as g')
    ->join('tbluserprofilemaster as p', 'p.id', '=', 'g.profile_id')
    ->where('g.menu_id', $menuId)->whereNotNull('p.role_key')
    ->distinct()->pluck('p.role_key')->all();
printf("roles that can now open it: %d - %s\n\n", count($roles), implode(', ', $roles));

// ── register ────────────────────────────────────────────────────────────────
$reg = __DIR__ . '/../_changes/SEED-REGISTER-2026-08-11.md';
if (is_file($reg)) {
    file_put_contents($reg, "\n## G-UI-01 (added " . date('Y-m-d') . ")\n\n"
        . "| Table | Rows | IDs |\n|---|---:|---|\n"
        . "| `tblmenumaster_g2g` | 1 | $menuId |\n"
        . "| `tblgroupwise_rights_g2g` | " . count($made) . " | " . implode(', ', $made) . " |\n"
        . "\n**View-only rights.** Removing them removes the route; the component stays.\n", FILE_APPEND);
    echo "registered in SEED-REGISTER-2026-08-11.md\n";
}

echo "\nNEXT: the frontend must map this accessLink to CmMyCapability.\n";
