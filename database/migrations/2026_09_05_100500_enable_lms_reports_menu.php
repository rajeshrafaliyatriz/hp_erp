<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Make the three LMS report screens reachable.
 *
 * ── WHAT WAS WRONG ──────────────────────────────────────────────────────────
 *
 * `tblmenumaster_g2g` carries three LMS report rows — Employee Analysis (151),
 * Quiz Progress (152), Question-wise (153) — under "LMS Report" (125), which is
 * itself under the Reports module (6). All three leaves have `status = 1` and
 * were still invisible, for three independent reasons at once:
 *
 *   1. parent 125 has `status = 0`, and displaySidebarMenu drops a group whose
 *      parent is disabled;
 *   2. no `tblgroupwise_rights_g2g` row grants any profile `can_view` on them;
 *   3. no content map existed for module 6 at all, so even a direct URL
 *      resolved to no component.
 *
 * (3) is fixed in the frontend by hooks/content-map-reports.ts. This migration
 * fixes (1) and (2).
 *
 * ── WHY ONLY THE LMS REPORTS ────────────────────────────────────────────────
 *
 * Module 6 has ten report groups and nine of them are still `status = 0` with
 * no screen. They are deliberately left alone: enabling a menu that opens a
 * blank page is worse than leaving it hidden, and those screens are not part of
 * this work.
 *
 * ── WHO GETS THEM ───────────────────────────────────────────────────────────
 *
 * Admin and HR only. These reports describe other people's learning — how far
 * behind somebody is, how they scored — so they are an administrative surface,
 * and LmsReportController guards on exactly those two profiles. The rights and
 * the controller agree by construction rather than by coincidence.
 *
 * Written per tenant, from the profiles each tenant actually has. Live knows
 * profiles 16/17/18; dev also has 69-73. Hardcoding either list would grant
 * nothing on one database and rows for non-existent profiles on the other.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_09_05_100500_enable_lms_reports_menu.php
 *   php artisan migrate --database=live --path=database/migrations/2026_09_05_100500_enable_lms_reports_menu.php
 */
return new class extends Migration
{
    /** The parent group and its three leaves. */
    private const MENU_IDS = [125, 151, 152, 153];

    /** Reports are about other people, so admin and HR only. */
    private const ROLE_KEYS = ['administrator', 'hr_manager', 'hr_executive'];

    public function up(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        // (1) The group its children hang from.
        DB::table('tblmenumaster_g2g')->where('id', 125)->update(['status' => 1]);

        if (! $this->tableExists('tblgroupwise_rights_g2g')) {
            return;
        }

        /*
         * (2) Rights, per tenant, for the profiles that tenant actually has.
         *
         * Derived from existing rows rather than assumed: a tenant that has
         * never been granted anything is not silently given these.
         */
        $tenants = DB::table('tblgroupwise_rights_g2g')
            ->distinct()
            ->whereNotNull('sub_institute_id')
            ->pluck('sub_institute_id');

        foreach ($tenants as $tenant) {
            /*
             * THE TENANT'S OWN PROFILES.
             *
             * `tbluserprofilemaster` is tenant-scoped - live carries Admin/HR
             * for tenant 1 (ids 1, 2) and for tenant 6 (ids 16, 18). Without
             * this filter every tenant was granted rows for every OTHER
             * tenant's Admin and HR profiles: harmless, since a user's
             * profile_id is their own, but junk that makes the rights table
             * unreadable and multiplies with each tenant.
             */
            $profiles = DB::table('tbluserprofilemaster')
                ->whereIn('role_key', self::ROLE_KEYS)
                ->where('sub_institute_id', $tenant)
                ->whereNull('deleted_at')
                ->pluck('id');

            foreach ($profiles as $profileId) {
                foreach (self::MENU_IDS as $menuId) {
                    $exists = DB::table('tblgroupwise_rights_g2g')
                        ->where('sub_institute_id', $tenant)
                        ->where('menu_id', $menuId)
                        ->where('profile_id', $profileId)
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    DB::table('tblgroupwise_rights_g2g')->insert([
                        'sub_institute_id' => $tenant,
                        'menu_id' => $menuId,
                        'profile_id' => $profileId,
                        'can_view' => 1,
                        // A report is read-only. Granting anything else would
                        // describe a capability these screens do not have.
                        'can_add' => 0,
                        'can_edit' => 0,
                        'can_delete' => 0,
                        'dashboard_right' => 0,
                        'is_mobile' => 0,
                        // This table has created_at and NO updated_at; the
                        // right_* columns are null on every existing row.
                        'created_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        if ($this->tableExists('tblgroupwise_rights_g2g')) {
            DB::table('tblgroupwise_rights_g2g')->whereIn('menu_id', self::MENU_IDS)->delete();
        }

        if ($this->tableExists('tblmenumaster_g2g')) {
            DB::table('tblmenumaster_g2g')->where('id', 125)->update(['status' => 0]);
        }
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
