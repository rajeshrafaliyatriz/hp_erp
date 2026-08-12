<?php
/**
 * MENU ROW 225 — "Competency Definitions".
 *
 * G-UI-01's lesson, applied: EVERY COLUMN IS DERIVED FROM A SIBLING, NOT GUESSED.
 * Sibling is #156 "Employee Profiles", the same menu, the same level, the same
 * tenant list. The only fields that differ are the three that must: name, link,
 * and sort_order (next free).
 *
 * WHY A ROW IS NEEDED AT ALL: the content map keys on `access_link`. Without a
 * `tblmenumaster_g2g` row nothing renders the screen and nothing links to it —
 * which is exactly the state `CmCompetencyComposer` has been in since it was
 * written.
 *
 * BLAST RADIUS, STATED: this row is visible to the eleven tenants in the
 * sibling's `sub_institute_id` list. It adds a menu entry; it grants no rights
 * and changes no data. The endpoint behind it is already guarded
 * `profile:admin,hr`.
 *
 * REVERSIBLE: prints the exact DELETE for the id it creates. Nothing else in
 * the table is touched, and the script refuses to run twice.
 */
require __DIR__ . '/../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

const LINK    = '/module/competency-management/competency-library/competency-definitions';
const SIBLING = 156;

$existing = DB::table('tblmenumaster_g2g')->where('access_link', LINK)->first();
if ($existing) {
    printf("ALREADY EXISTS: #%d %s - nothing written\n", $existing->id, $existing->menu_name);
    exit(0);
}

$sib = DB::table('tblmenumaster_g2g')->where('id', SIBLING)->first();
if (!$sib) { echo "REFUSING: sibling " . SIBLING . " not found - nothing to derive from\n"; exit(1); }

$maxSort = (int) DB::table('tblmenumaster_g2g')
    ->where('parent_id', $sib->parent_id)->whereNull('deleted_at')->max('sort_order');

echo "DERIVED FROM SIBLING #156 (Employee Profiles):\n";
printf("  parent_id        %s\n", $sib->parent_id);
printf("  level            %s\n", $sib->level);
printf("  page_type        %s\n", $sib->page_type);
printf("  status           %s\n", $sib->status);
printf("  menu_type        %s\n", $sib->menu_type ?? '(null)');
printf("  icon             %s\n", $sib->icon ?? '(null)');
printf("  sub_institute_id %s\n", $sib->sub_institute_id);
printf("  sort_order       %d  (max %d + 1, NOT copied)\n\n", $maxSort + 1, $maxSort);

$before = DB::table('tblmenumaster_g2g')->where('parent_id', $sib->parent_id)->whereNull('deleted_at')->count();

$id = DB::table('tblmenumaster_g2g')->insertGetId([
    'menu_name'        => 'Competency Definitions',
    'parent_id'        => $sib->parent_id,
    'level'            => $sib->level,
    'page_type'        => $sib->page_type,
    'access_link'      => LINK,
    'icon'             => $sib->icon,
    'status'           => $sib->status,
    'sort_order'       => $maxSort + 1,
    'sub_institute_id' => $sib->sub_institute_id,
    'menu_type'        => $sib->menu_type,
    'created_at'       => now(),
    'updated_at'       => now(),
]);

$after = DB::table('tblmenumaster_g2g')->where('parent_id', $sib->parent_id)->whereNull('deleted_at')->count();

printf("WROTE menu row #%d\n", $id);
printf("siblings under parent %s: %d -> %d\n", $sib->parent_id, $before, $after);
printf("\nTO REVERSE:  DELETE FROM tblmenumaster_g2g WHERE id = %d;\n", $id);
printf("CONTENT MAP expects submenuId '225' - if the id above is not 225, UPDATE hooks/content-map-m2.ts\n");
