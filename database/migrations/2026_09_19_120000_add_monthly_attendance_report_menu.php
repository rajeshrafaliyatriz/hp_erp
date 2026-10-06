<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F-171. A front door for the Monthly Attendance Report.
 *
 * GET /api/employee-attendance-monthly-report is the most complete attendance
 * endpoint in the module and had no caller anywhere: a nine-field summary plus
 * one row per date carrying status, punch times, working hours, lateness, the
 * rostered shift, any leave with its reason, and any holiday name. It resolves
 * each day against the roster and the holiday calendar, so a Sunday reads as
 * 'weekend' rather than as an absence - a distinction none of the day-count
 * reports already shipped can make.
 *
 * THIS ONE IS GRANTED TO EVERY PROFILE, unlike the two payroll reports added
 * alongside it (menus 307 and 308), and the difference is deliberate.
 *
 * Those return every employee's pay, so their rights are restricted to
 * admin/hr. This endpoint enforces HR-OR-SELF in the controller (F-159):
 * admin, hr, executive and auditor may read anybody; everyone else is refused
 * with 403 "You may only view your own attendance" unless the user_id is their
 * own. So a rights row here grants an employee their OWN month and nothing
 * else - which is exactly the self-service the module was missing, and the same
 * reasoning as the My HR migration (2026_09_08_100000).
 *
 * If that controller check is ever removed, this menu must be narrowed to
 * admin/hr in the same change. The two belong together.
 *
 * Reversal: Docs/hrit-audit/_reversals/REVERSAL-2026-09-19-monthly-attendance-menu.sql
 */
return new class extends Migration
{
    /** Verified free on 202.47.117.220 and 128.199.17.97; both max out at 308. */
    private const MENU_ID = 309;

    private const ACCESS_LINK = '/module/hrit-solutions/attendance-management/monthly-attendance-report';

    /** Menu 93, "Attendance Management" - status 1 on both hosts. */
    private const PARENT_ID = 93;

    public function up(): void
    {
        if (DB::table('tblmenumaster_g2g')->where('id', self::MENU_ID)->exists()) {
            return;
        }

        DB::table('tblmenumaster_g2g')->insert([
            'id'          => self::MENU_ID,
            'menu_name'   => 'Monthly Attendance Report',
            'parent_id'   => self::PARENT_ID,
            'level'       => 3,
            'page_type'   => 'page',
            'access_link' => self::ACCESS_LINK,
            'icon'        => '',
            'status'      => 1,
            // After Attendance Tracking and Attendance Reports; the three
            // disabled siblings (162-164) sit below.
            'sort_order'  => 3,
            'sub_institute_id' => null,
            'menu_type'   => '',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $now  = now();
        $rows = [];

        foreach (DB::table('tbluserprofilemaster')->select('id', 'sub_institute_id')->get() as $profile) {
            $rows[] = [
                'menu_id'          => self::MENU_ID,
                'profile_id'       => $profile->id,
                'can_view'         => 1,
                // Read-only report: no writes, no delete path.
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

    public function down(): void
    {
        DB::table('tblgroupwise_rights_g2g')->where('menu_id', self::MENU_ID)->delete();
        DB::table('tblmenumaster_g2g')->where('id', self::MENU_ID)->delete();
    }
};
