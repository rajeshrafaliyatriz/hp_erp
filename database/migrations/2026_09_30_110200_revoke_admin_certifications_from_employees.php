<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Take the ADMIN certification screen away from the employee profile.
 *
 * ── WHAT THIS REMOVES ───────────────────────────────────────────────────────
 *
 * Menu 158, `/module/talent-management/certifications` - the Certification &
 * Compliance Center. Not the employee's own page (401), which the companion
 * migration grants; this is the HR screen beside it.
 *
 * ── WHY ─────────────────────────────────────────────────────────────────────
 *
 * That screen's backend, CertificationController, is 1,663 lines containing no
 * ownership check of any kind. Verified: no route under it carries `profile:`
 * or `menuright:`, the file contains no competencySubject(), and
 * findCertification() (:1230-1237) matches on id + sub_institute_id only. So
 * anyone who can open it can create, edit, verify, revoke, delete and
 * bulk-operate on ANY colleague's credentials, and export 5,000 rows. See
 * Docs/talent-audit/AUTHORIZATION-BOUNDARY.md, finding S5.
 *
 * The stated requirement for employees is that they "see and download their own,
 * nothing else". Menu 401 does exactly that over an endpoint with no subject
 * parameter. This row is the part that goes.
 *
 * ── WHY IT IS SAFE TO DO NOW, AND WHERE IT IS A REAL CHANGE ─────────────────
 *
 * Measured per database, tenant 6, profile "Employee":
 *
 *   app   menu 3 = NO ROW, menu 158 = can_view 1
 *         -> the parent was never granted, so this screen has never been
 *            reachable. Removing the right takes away nothing anyone has.
 *            It matters because 2026_09_30_110100 grants menu 3, which would
 *            otherwise make this stale right effective for the first time.
 *
 *   live  menu 3 = 1, menu 158 = can_view 1
 *         -> the screen IS reachable today. This is a genuine removal, and it
 *            is the one the requirement asks for.
 *
 * ── SCOPE ───────────────────────────────────────────────────────────────────
 *
 * `employee` only, matched on role_key. HR, admin, executives, auditors,
 * managers and department heads keep it - the screen is theirs, and narrowing
 * who may act on WHOSE record is an authorization change in the controller, not
 * a menu change. This migration deliberately does not attempt that.
 *
 * Reversible: down() restores a can_view-only row. It cannot restore
 * can_add/can_edit flags, which is intentional - if the screen is ever handed
 * back to employees it should not silently come back with write rights.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_09_30_110200_revoke_admin_certifications_from_employees.php
 *   php artisan migrate --database=live --path=database/migrations/2026_09_30_110200_revoke_admin_certifications_from_employees.php
 */
return new class extends Migration
{
    /** The admin Certification & Compliance Center. */
    private const MENU_ID = 158;

    public function up(): void
    {
        if (!$this->tableExists('tblgroupwise_rights_g2g') || !$this->tableExists('tbluserprofilemaster')) {
            return;
        }

        foreach ($this->employeeProfiles() as $row) {
            /*
             * UNSCOPED ON TENANT, AND THAT IS THE WHOLE POINT.
             *
             * This delete was originally scoped by sub_institute_id and it
             * silently failed. The column is `text NULL` and holds an id, NULL
             * (4,379 rows on live) or a CSV string - while displaySidebarMenu
             * reads rights with `where('profile_id', $id)` and NO tenant
             * predicate. So a NULL-tenant row grants the menu but a scoped
             * delete cannot see it: measured, profile 3 "Employee" on live kept
             * menu 158 with sub_institute_id = NULL after the scoped version
             * ran, and the screen stayed reachable.
             *
             * A write path that filters on a column the read path ignores does
             * not revoke anything. profile_id + menu_id is the key the reader
             * uses, so it is the key this must use; a profile belongs to
             * exactly one tenant, which is what makes it complete.
             */
            DB::table('tblgroupwise_rights_g2g')
                ->where('menu_id', self::MENU_ID)
                ->where('profile_id', $row->id)
                ->delete();
        }
    }

    public function down(): void
    {
        if (!$this->tableExists('tblgroupwise_rights_g2g') || !$this->tableExists('tbluserprofilemaster')) {
            return;
        }

        foreach ($this->employeeProfiles() as $row) {
            // Unscoped for the same reason as up().
            $exists = DB::table('tblgroupwise_rights_g2g')
                ->where('menu_id', self::MENU_ID)
                ->where('profile_id', $row->id)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('tblgroupwise_rights_g2g')->insert([
                'sub_institute_id' => $row->sub_institute_id,
                'menu_id'          => self::MENU_ID,
                'profile_id'       => $row->id,
                'can_view'         => 1,
                // Never restored, even if they were set before. See the docblock.
                'can_add'          => 0,
                'can_edit'         => 0,
                'can_delete'       => 0,
                'dashboard_right'  => 0,
                'is_mobile'        => 0,
                'created_at'       => now(),
            ]);
        }
    }

    /**
     * Every tenant's own employee profile.
     *
     * By role_key, never by id: the ids differ between the two databases -
     * measured, "Reporting Manager" is 69 on app and 46 on live - so an id list
     * written against one host silently hits the wrong profile on the other.
     */
    private function employeeProfiles()
    {
        return DB::table('tbluserprofilemaster')
            ->where('role_key', 'employee')
            ->whereNull('deleted_at')
            ->get(['id', 'sub_institute_id']);
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
