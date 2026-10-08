<?php

use App\Support\RoleKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repair: the profiles 110100 missed, because it asked the wrong question.
 *
 * ── WHAT WENT WRONG ────────────────────────────────────────────────────────
 *
 * 2026_10_07_110100 selected profiles with
 *
 *     whereIn('role_key', ['administrator', 'hr_manager', 'hr_executive'])
 *
 * That is how the three precedents in this module do it, and it is wrong on one
 * of the two hosts. `tbluserprofilemaster.role_key` is NOT populated
 * everywhere:
 *
 *   128.199.17.97   every Admin/HR profile has a role_key -> 26 grants, correct
 *   202.47.117.220  Admin, HR and Employee have role_key NULL
 *                   -> 10 grants, ALL of them "HR Executive"
 *
 * So on the host the application actually uses, the new menu was granted to a
 * profile almost nobody holds and withheld from the Admin and HR profiles
 * everybody does. 32 profiles across 16 tenants, including Admin and HR in
 * every one of tenants 1-11. The API worked for them - that is gated on
 * RoleKey, which resolves these correctly - so the symptom would have been
 * "the screen exists but I have no menu item for it", with nothing in either
 * migration's output to suggest why.
 *
 * ── WHY THE ORIGINAL IS NOT EDITED ─────────────────────────────────────────
 *
 * 110100 is recorded as run on both hosts. Editing it would leave the two
 * databases agreeing with a file that describes neither. The precedent is
 * 2026_09_30_110300, which exists for exactly this class of mistake.
 *
 * ── THE RIGHT QUESTION ─────────────────────────────────────────────────────
 *
 * RoleKey::fromProfile() is what RequireProfile uses to decide who may open the
 * route: role_key when it is set, LEGACY_NAMES matched exactly on the lowercased
 * name when it is not, null otherwise. Asking RoleKey the same question the gate
 * asks is the only way the menu and the API cannot disagree - which is the
 * invariant 110100's own docblock claims and its code did not keep.
 *
 * Four legacy names resolve into admin/hr: 'admin', 'administrator',
 * 'organization administrator' and 'hr'. Note what is NOT there - profile 38
 * "Deparment Administrator" is deliberately unmapped, because a department
 * administrator is not an institute administrator and it only ever passed an
 * admin gate through substring matching.
 *
 * Reversal: Docs/hrit-audit/_reversals/REVERSAL-2026-10-07-attendance-admin-menu.sql
 *           (same leaf; the DELETE there covers these rows too)
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_10_07_110200_grant_manage_employee_attendance_legacy_profiles.php
 *   php artisan migrate --database=live --path=database/migrations/2026_10_07_110200_grant_manage_employee_attendance_legacy_profiles.php
 */
return new class extends Migration
{
    private const LINK = '/module/hrit-solutions/attendance-management/manage-employee-attendance';

    /** The route vocabulary this screen is gated on: Route::middleware('profile:admin,hr'). */
    private const ALLOWED = ['admin', 'hr'];

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

        /*
         * Every profile, resolved through RoleKey rather than filtered in SQL.
         *
         * This is the whole correction: a `whereIn('role_key', ...)` cannot see
         * a profile whose role_key is null and whose NAME is what identifies
         * it, and that is the state of 32 profiles on 202.47.117.220.
         */
        $profiles = DB::table('tbluserprofilemaster')
            ->whereNull('deleted_at')
            ->get(['id', 'name', 'role_key', 'sub_institute_id'])
            ->filter(static fn ($profile) => RoleKey::satisfies(
                RoleKey::fromProfile($profile),
                self::ALLOWED,
            ));

        foreach ($profiles as $profile) {
            /*
             * The tenant written into the rights row.
             *
             * tblgroupwise_rights_g2g.sub_institute_id is `text` and holds an
             * id, NULL, or a CSV like '1,2,...,11'; the profile's own tenant is
             * a bigint and is the correct value. Profiles with no tenant are
             * skipped rather than written as 0 - a 0 here is a row no screen
             * can scope and nobody will clean up.
             */
            $tenant = (int) ($profile->sub_institute_id ?? 0);

            if ($tenant <= 0) {
                continue;
            }

            foreach ($menuIds as $menuId) {
                // Only the leaf is a write screen; an ancestor is a container,
                // and granting it add/edit would say something untrue about
                // "Attendance Management" as a whole.
                $this->grant($tenant, $menuId, $profile->id, $menuId === $leafId);
            }
        }
    }

    public function down(): void
    {
        /*
         * Nothing. 110100's down() already deletes every rights row for this
         * leaf, and these are rows for the same leaf - a second delete would
         * either be a no-op or, run in the wrong order, remove rows 110100 is
         * accountable for. One owner per deletion.
         */
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
