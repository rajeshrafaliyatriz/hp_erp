<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Let people actually open "My Department Documents" - including every
 * ancestor, same requirement `displaySidebarMenu` already has for every menu
 * item (a granted leaf under an ungranted parent is invisible - F-209).
 *
 * ── THREE LEVELS, NOT TWO ────────────────────────────────────────────────────
 *
 * My Certifications' own grant migration (2026_09_30_110100) only had one
 * ancestor to grant (Talent Management is 2 levels deep: module -> leaf).
 * Organizational Management is 3 levels deep - module -> Organization Setup
 * -> Department Management's branch - so this walks the WHOLE parent chain
 * (stopping at `parent_id = 0`, this table's own root marker) rather than
 * granting one hardcoded ancestor id.
 *
 * ── CHECKED FOR THE STALE-GRANT TRAP BEFORE WRITING THIS ────────────────────
 *
 * My Certifications' own grant needed a THIRD, separate migration
 * (2026_09_30_110300) to repair collateral damage: granting its ancestor
 * made an already-stale leaf-level grant (the admin Certification Center,
 * previously and unintentionally reachable by `employee` profiles) visible
 * too. Organization Setup has real sensitive siblings in the same branch
 * this grant walks through - "Group wise right management" (menu 15) and
 * "Individual right management" (menu 16) among them - so this was checked
 * directly, not assumed: zero existing `tblgroupwise_rights_g2g` rows with
 * `can_view=1` for any of these nine role_keys on the module, Organization
 * Setup, or any of its direct children, on this database, before this
 * migration was written. No companion revoke migration is needed here.
 *
 * ── WHO GETS IT ─────────────────────────────────────────────────────────────
 *
 * Everybody - every employee belongs to some department (or none, which the
 * controller/screen handle as an empty state, not an error). Read-only:
 * can_view 1 only. The screen itself enforces real write authorization
 * (MyDepartmentDocumentsController derives the department server-side;
 * uploads/folder-writes go through the existing self-service document
 * routes, which were already open to any token holder).
 *
 * ── TENANT ITERATION, DONE THE WAY 110100 HAD TO BE FIXED TO DO IT ──────────
 *
 * `tblgroupwise_rights_g2g.sub_institute_id` is `text` and holds three
 * shapes - an id, NULL, and a CSV string like '1,2,...,11'.
 * `tbluserprofilemaster.sub_institute_id` is `bigint`, so comparing the CSV
 * string against it coerces to 1 and double-grants tenant 1's profiles -
 * exactly the defect 2026_09_30_110300 repaired. Filtered to plain digits
 * from the start here, not fixed after the fact.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_10_08_100200_grant_my_department_documents_rights.php
 *   php artisan migrate --database=live --path=database/migrations/2026_10_08_100200_grant_my_department_documents_rights.php
 */
return new class extends Migration
{
    private const LINK = '/module/organizational-management/organization-setup/my-department-documents';

    private const ROLE_KEYS = [
        'employee',
        'administrator',
        'hr_manager',
        'hr_executive',
        'reporting_manager',
        'department_head',
        'executive',
        'auditor',
        'recruiter',
    ];

    public function up(): void
    {
        if (!$this->tableExists('tblgroupwise_rights_g2g') || !$this->tableExists('tbluserprofilemaster')) {
            return;
        }

        $menuRow = DB::table('tblmenumaster_g2g')->where('access_link', self::LINK)->first();

        if (!$menuRow) {
            return;
        }

        $menuIds = [(int) $menuRow->id];
        $parentId = $menuRow->parent_id;

        while ($parentId !== null && (int) $parentId !== 0) {
            $menuIds[] = (int) $parentId;
            $parentId = DB::table('tblmenumaster_g2g')->where('id', $parentId)->value('parent_id');
        }

        $tenants = DB::table('tblgroupwise_rights_g2g')
            ->distinct()
            ->whereNotNull('sub_institute_id')
            ->pluck('sub_institute_id')
            ->filter(static fn ($t) => ctype_digit(trim((string) $t)))
            ->values();

        foreach ($tenants as $tenant) {
            $profiles = DB::table('tbluserprofilemaster')
                ->whereIn('role_key', self::ROLE_KEYS)
                ->where('sub_institute_id', $tenant)
                ->whereNull('deleted_at')
                ->pluck('id');

            foreach ($profiles as $profileId) {
                foreach ($menuIds as $menuId) {
                    $this->grant($tenant, $menuId, $profileId);
                }
            }
        }
    }

    public function down(): void
    {
        if (!$this->tableExists('tblgroupwise_rights_g2g')) {
            return;
        }

        $menuRow = DB::table('tblmenumaster_g2g')->where('access_link', self::LINK)->first();

        if (!$menuRow) {
            return;
        }

        // Only the leaf - the ancestor rows are deliberately left behind,
        // same reasoning as the precedent: other leaves may depend on them
        // by the time this rolls back.
        DB::table('tblgroupwise_rights_g2g')->where('menu_id', $menuRow->id)->delete();
    }

    private function grant($tenant, int $menuId, $profileId): void
    {
        // Unscoped on tenant, deliberately - matches how displaySidebarMenu
        // actually reads this table (no tenant predicate, keyBy('menu_id')).
        $exists = DB::table('tblgroupwise_rights_g2g')
            ->where('menu_id', $menuId)
            ->where('profile_id', $profileId)
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('tblgroupwise_rights_g2g')->insert([
            'sub_institute_id' => $tenant,
            'menu_id' => $menuId,
            'profile_id' => $profileId,
            'can_view' => 1,
            'can_add' => 0,
            'can_edit' => 0,
            'can_delete' => 0,
            'dashboard_right' => 0,
            'is_mobile' => 0,
            'created_at' => now(),
        ]);
    }

    /** information_schema directly - live is MariaDB 10.1, where Schema::hasTable() throws. */
    private function tableExists(string $table): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table]
        ) !== [];
    }
};
