<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Let people open "My Office Hours". Every profile, and the LEAF ONLY.
 *
 * ── NO ROLE FILTER AT ALL, DELIBERATELY ─────────────────────────────────────
 *
 * Every employee has working hours, so the audience is everybody and there is
 * nothing to filter on. That matters because of how the two filters available
 * here both fail:
 *
 *   whereIn('role_key', [...])  misses every profile whose role_key is NULL -
 *                               which on 202.47.117.220, the host the
 *                               application actually uses, is Admin, HR AND
 *                               Employee. This is exactly the under-grant that
 *                               2026_10_07_110200 had to be written to repair,
 *                               and Employee is the worst possible profile to
 *                               repeat it on.
 *
 *   RoleKey::fromProfile()      returns null for any profile that is neither
 *                               keyed nor in LEGACY_NAMES, and
 *                               RoleKey::satisfies(null, ...) is false. So
 *                               routing an "everybody" grant through RoleKey
 *                               would silently EXCLUDE every unmapped profile -
 *                               the opposite failure, from the tool that exists
 *                               to prevent the first one.
 *
 * So: no role predicate. A filter whose correct answer is "everyone" can only
 * be wrong. Authorisation lives where it is enforced - the endpoint resolves
 * the subject from the token and accepts no user id - not in this table.
 *
 * ── THE LEAF ONLY, AND THIS IS THE LOAD-BEARING DECISION ────────────────────
 *
 * `displaySidebarMenu` needs BOTH the leaf and every ancestor, so the other
 * self-service grants in this codebase walk the parent chain and grant it.
 * This one does NOT, and the reason was measured on both hosts before this
 * file was written rather than reasoned about:
 *
 *   Ancestors 93 (Attendance Management) and 5 (HRIT Management) are ALREADY
 *   granted to Employee on both hosts - 93 distinct profiles hold menu 93 on
 *   mysql, 36 on live - because Attendance Tracking lives in this branch. So
 *   the employees this screen is for need no new ancestor row; the branch is
 *   already open to them and an ancestor grant would be a no-op.
 *
 *   And for the profiles that DO lack ancestor 93, granting it is not harmless.
 *   17 profiles on mysql and 7 on live hold `Monthly Attendance Report` (309)
 *   while lacking the ancestor that would make it visible. Granting the
 *   ancestor to them reveals a tenant-wide attendance report to, among others,
 *   ~1,000 active Students in tenant 1000000 and ~962 active Employees in
 *   tenant 1000018.
 *
 * That is the F-209 / 2026_09_30_110300 collateral-exposure trap, and the
 * answer is not to spring it as a side effect of shipping a self-service
 * screen. Those profiles see no attendance menu at all today, so this screen
 * being invisible to them is their existing configuration and not a regression
 * introduced here. The stale 309 grant is a real separate defect and wants its
 * own decision, with its own revoke.
 *
 * Verify the premise on either host:
 *
 *   select count(distinct profile_id) from tblgroupwise_rights_g2g
 *     where menu_id = 93 and can_view = 1;
 *
 *   select distinct profile_id from tblgroupwise_rights_g2g
 *     where menu_id = 309 and can_view = 1
 *     and profile_id not in (select profile_id from tblgroupwise_rights_g2g
 *                             where menu_id = 93 and can_view = 1);
 *
 * ── THE WRITE FLAGS ─────────────────────────────────────────────────────────
 *
 * can_view/can_add/can_edit, because an employee creates a proposal and edits
 * it while it is pending. can_delete stays 0: withdrawing your own pending
 * request is a soft delete of your own row, not the "may delete records"
 * authority this column means elsewhere. Note that the API reads NONE of these
 * - they are read by the sidebar and by screens that choose to - so these
 * flags are a statement of intent and the endpoint is the enforcement.
 *
 * ── TENANT VALUE ────────────────────────────────────────────────────────────
 *
 * `tblgroupwise_rights_g2g.sub_institute_id` is `text` and holds an id, NULL,
 * or a CSV like 1,2,...,11; `tbluserprofilemaster.sub_institute_id` is a
 * bigint and is the correct value to write. Profiles with no tenant are
 * skipped rather than written as 0 - a 0 here is a row no screen can scope and
 * nobody will clean up (2026_09_30_110300 again).
 *
 * Reversal: Docs/hrit-audit/_reversals/REVERSAL-2026-10-09-my-office-hours-menu.sql
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_10_09_120100_grant_my_office_hours_rights.php
 *   php artisan migrate --database=live --path=database/migrations/2026_10_09_120100_grant_my_office_hours_rights.php
 */
return new class extends Migration
{
    private const LINK = '/module/hrit-solutions/attendance-management/my-office-hours';

    public function up(): void
    {
        if (!$this->tableExists('tblgroupwise_rights_g2g') || !$this->tableExists('tbluserprofilemaster')) {
            return;
        }

        $menuRow = DB::table('tblmenumaster_g2g')->where('access_link', self::LINK)->first();

        if (!$menuRow) {
            return;   // the menu migration has not run on this host yet
        }

        $menuId = (int) $menuRow->id;

        $profiles = DB::table('tbluserprofilemaster')
            ->whereNull('deleted_at')
            ->get(['id', 'name', 'sub_institute_id']);

        foreach ($profiles as $profile) {
            $tenant = (int) ($profile->sub_institute_id ?? 0);

            if ($tenant <= 0) {
                continue;
            }

            // Unscoped on tenant deliberately - that is how displaySidebarMenu
            // reads this table (no tenant predicate, keyBy('menu_id')), and
            // sub_institute_id here is text holding three different shapes.
            $exists = DB::table('tblgroupwise_rights_g2g')
                ->where('menu_id', $menuId)
                ->where('profile_id', $profile->id)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('tblgroupwise_rights_g2g')->insert([
                'sub_institute_id' => $tenant,
                'menu_id'          => $menuId,
                'profile_id'       => $profile->id,
                'can_view'         => 1,
                'can_add'          => 1,
                'can_edit'         => 1,
                'can_delete'       => 0,
                'dashboard_right'  => 0,
                'is_mobile'        => 0,
                'created_at'       => now(),
            ]);
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

        // Only this leaf's rows. No ancestor row was created here, so none is
        // removed - the whole point of the leaf-only decision above.
        DB::table('tblgroupwise_rights_g2g')->where('menu_id', (int) $menuRow->id)->delete();
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
