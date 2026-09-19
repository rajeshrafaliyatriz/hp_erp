<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use App\Support\RoleKey;

/**
 * F-172 and the Salary Structure Report - the last two of the five payroll
 * reports that were implemented, routed, gated, and reachable by nobody.
 *
 * MENU 310 - Payroll Register (POST /payroll-report)
 *   The month's payslips with the DAY COUNTS reconciled against attendance,
 *   leave and the holiday calendar: lwp_days, leave_days and absent_days are
 *   computed per employee by walking the month. Nothing else in the frontend
 *   joins payroll to attendance, so "why is this person's pay low" had nowhere
 *   to be answered in the same row as the pay. This is the pre-payment check.
 *
 * MENU 311 - Salary Structure Report (POST /salary-structure-report)
 *   Every employee's structure for a year in one grid. This one could not have
 *   been built at all before F-160: the controller read session() with no
 *   type=API branch, so an API caller resolved to tenant null, every
 *   `where sub_institute_id = null` matched nothing, and it returned an empty
 *   list however it was filtered. The endpoint was not merely uncalled - it
 *   could not have fed a screen.
 *
 * BOTH ARE RESTRICTED TO admin/hr, like menus 307 and 308 and unlike 305/309.
 * The rule this module now follows consistently:
 *
 *   every profile   - the screen shows you only YOURSELF, and the server
 *                     enforces that (My HR 305, Monthly Attendance 309)
 *   admin/hr only   - the screen shows EVERYBODY'S pay (307, 308, 310, 311)
 *
 * Roles resolve through RoleKey, never through tbluserprofilemaster.name,
 * because the name is a label a tenant can edit (D-010). canView() reads
 * `($rights->can_view ?? 0) == 1`, so profiles outside the set get NO row -
 * absence is how revocation is expressed here, not a zero.
 *
 * Reversal: Docs/hrit-audit/_reversals/REVERSAL-2026-09-19-register-and-structure-menus.sql
 */
return new class extends Migration
{
    /** Verified free on 202.47.117.220 and 128.199.17.97; both max out at 309. */
    private const MENUS = [
        310 => [
            'name'        => 'Payroll Register',
            'access_link' => '/module/hrit-solutions/payroll-management/payroll-register',
            'sort_order'  => 12,
        ],
        311 => [
            'name'        => 'Salary Structure Report',
            'access_link' => '/module/hrit-solutions/payroll-management/salary-structure-report',
            'sort_order'  => 13,
        ],
    ];

    /** Menu 95, "Payroll Management" - status 1 on both hosts. */
    private const PARENT_ID = 95;

    public function up(): void
    {
        // Resolved once: the same set applies to both menus, and
        // RoleKey::forProfileId caches per process anyway.
        $permitted = DB::table('tbluserprofilemaster')
            ->get(['id', 'sub_institute_id', 'role_key', 'name'])
            ->filter(fn ($profile) => RoleKey::satisfies(RoleKey::fromProfile($profile), ['admin', 'hr']));

        foreach (self::MENUS as $id => $menu) {
            // Idempotent: this runs on two hosts and must not double-insert.
            if (DB::table('tblmenumaster_g2g')->where('id', $id)->exists()) {
                continue;
            }

            DB::table('tblmenumaster_g2g')->insert([
                'id'          => $id,
                'menu_name'   => $menu['name'],
                'parent_id'   => self::PARENT_ID,
                'level'       => 3,
                'page_type'   => 'page',
                'access_link' => $menu['access_link'],
                'icon'        => '',
                'status'      => 1,
                'sort_order'  => $menu['sort_order'],
                'sub_institute_id' => null,
                'menu_type'   => '',
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);

            $now  = now();
            $rows = [];

            foreach ($permitted as $profile) {
                $rows[] = [
                    'menu_id'          => $id,
                    'profile_id'       => $profile->id,
                    'can_view'         => 1,
                    // Read-only reports: no writes, no delete path.
                    'can_add'          => 0,
                    'can_edit'         => 0,
                    'can_delete'       => 0,
                    'dashboard_right'  => 0,
                    'is_mobile'        => 0,
                    'sub_institute_id' => $profile->sub_institute_id,
                    'created_at'       => $now,
                ];
            }

            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('tblgroupwise_rights_g2g')->insert($chunk);
            }
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::MENUS) as $id) {
            // Rights first - they reference the menu.
            DB::table('tblgroupwise_rights_g2g')->where('menu_id', $id)->delete();
            DB::table('tblmenumaster_g2g')->where('id', $id)->delete();
        }
    }
};
