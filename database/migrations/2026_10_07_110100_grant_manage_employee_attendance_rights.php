<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Who may open the HR attendance desk.
 *
 * ── THE ROLE LIST IS NOT A JUDGEMENT CALL, IT IS A MEASUREMENT ──────────────
 *
 * The route carries `profile:admin,hr`. RequireProfile resolves the caller with
 * RoleKey::forUser() and tests RoleKey::satisfies($key, ['admin','hr']), so the
 * set of role_keys that can USE this screen is already decided. Granting the
 * menu to anybody outside that set produces the worst outcome available: a
 * sidebar item that opens to an Access Restricted panel.
 *
 * So the list below was measured rather than chosen - all ten role_keys through
 * RoleKey::satisfies(..., ['admin','hr']):
 *
 *   administrator      ADMITTED        reporting_manager  -
 *   hr_manager         ADMITTED        department_head    -
 *   hr_executive       ADMITTED        executive          -
 *                                      auditor            -
 *                                      employee           -
 *                                      recruiter          -
 *                                      team_employee      -
 *
 * executive and auditor are the two worth naming, because they DO hold the
 * reporting grants on this parent and can read the whole organisation's
 * attendance. Reading it and changing it are different acts - changing somebody's
 * recorded hours changes their pay - so they are deliberately not here.
 *
 * ── can_edit = 1, UNLIKE THE RECENT PRECEDENTS ──────────────────────────────
 *
 * The last few grant migrations in this module set can_view only, correctly:
 * their screens are read-only. This one writes. can_add/can_edit are read back
 * by LmsGovernanceController::menuTree and rendered by the Role & Permissions
 * screen, so leaving them at 0 would show an editing screen as "view only" to
 * the administrator deciding who gets it. They do not gate the API - the route
 * does - but they are what a human reads when deciding.
 *
 * can_delete stays 0: nothing here deletes an attendance row. The one method
 * that claimed to (HrmsController::destroy) is an empty stub that replies
 * "Deleted Successfully" having done nothing, and is not wired to this screen.
 *
 * ── THE ANCESTOR CHAIN ──────────────────────────────────────────────────────
 *
 * A granted leaf under an ungranted parent is invisible (F-209), so the whole
 * parent chain is granted, stopping at parent_id = 0. No stale-grant collateral
 * here: the ancestors are HRIT Solutions -> Attendance Management, and these
 * three role_keys already hold can_view on both via the reporting grants - this
 * adds no new reachability to any sibling.
 *
 * ── THE CSV TRAP ────────────────────────────────────────────────────────────
 *
 * tblgroupwise_rights_g2g.sub_institute_id is `text` and holds three shapes - an
 * id, NULL, and a CSV like '1,2,...,11'. tbluserprofilemaster.sub_institute_id
 * is bigint, so comparing the CSV against it coerces to 1 and double-grants
 * tenant 1's profiles. That is the defect 2026_09_30_110300 existed to repair;
 * filtered to plain digits here from the start.
 *
 * Reversal: Docs/hrit-audit/_reversals/REVERSAL-2026-10-07-attendance-admin-menu.sql
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_10_07_110100_grant_manage_employee_attendance_rights.php
 *   php artisan migrate --database=live --path=database/migrations/2026_10_07_110100_grant_manage_employee_attendance_rights.php
 */
return new class extends Migration
{
    private const LINK = '/module/hrit-solutions/attendance-management/manage-employee-attendance';

    /** Exactly the set RoleKey::satisfies(..., ['admin','hr']) admits. */
    private const ROLE_KEYS = [
        'administrator',
        'hr_manager',
        'hr_executive',
    ];

    public function up(): void
    {
        if (!$this->tableExists('tblgroupwise_rights_g2g') || !$this->tableExists('tbluserprofilemaster')) {
            return;
        }

        $menuRow = DB::table('tblmenumaster_g2g')->where('access_link', self::LINK)->first();

        if (!$menuRow) {
            return;   // the menu migration has not run on this host yet
        }

        $leafId  = (int) $menuRow->id;
        $menuIds = [$leafId];

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
                    // Only the leaf is a write screen. An ancestor is a
                    // container; granting it add/edit would say something
                    // untrue about "Attendance Management" as a whole.
                    $this->grant($tenant, $menuId, $profileId, $menuId === $leafId);
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

        // The leaf only. The ancestors are left behind on purpose - by the time
        // this rolls back, other leaves under them may depend on those rows,
        // and revoking a shared ancestor would blank somebody else's sidebar.
        DB::table('tblgroupwise_rights_g2g')->where('menu_id', $menuRow->id)->delete();
    }

    private function grant($tenant, int $menuId, $profileId, bool $writable): void
    {
        // Unscoped on tenant deliberately - that is how displaySidebarMenu
        // reads this table (no tenant predicate, keyBy('menu_id')), and
        // sub_institute_id here is text holding three different shapes.
        $exists = DB::table('tblgroupwise_rights_g2g')
            ->where('menu_id', $menuId)
            ->where('profile_id', $profileId)
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('tblgroupwise_rights_g2g')->insert([
            'sub_institute_id' => $tenant,
            'menu_id'          => $menuId,
            'profile_id'       => $profileId,
            'can_view'         => 1,
            'can_add'          => $writable ? 1 : 0,
            'can_edit'         => $writable ? 1 : 0,
            'can_delete'       => 0,
            'dashboard_right'  => 0,
            'is_mobile'        => 0,
            'created_at'       => now(),
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
