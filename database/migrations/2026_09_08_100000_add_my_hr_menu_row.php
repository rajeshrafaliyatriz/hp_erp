<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F-130 CORRECTION. My HR shipped without a front door.
 *
 * Sprint 8 built /api/my-hr/* and the My HR screen to close F-130 - "an employee
 * cannot see their own payslip" - and the finding was recorded as CLOSED.
 * `hooks/content-map-m5.ts` routes the screen and says so in a comment:
 *
 *     "Not in tblmenumaster_g2g yet, so it has no submenuId; it is reachable by
 *      URL and from the leave screens until HR adds the menu row."
 *
 * Both halves of that were untrue in practice. There is no link to My HR from
 * any leave screen - a repo-wide search finds the page, the content map, the
 * visibility rule and the service, and no navigation to it from anywhere. And
 * "by URL" only resolves when the user's FIRST module happens to be HRIT,
 * because gtg-app-shell falls back to modules[0] for a path the menu does not
 * contain, and loadContentRoute then searches only that module's routes.
 *
 * So the only screen in the product where an employee can see their own payslip
 * has been unreachable since it was built.
 *
 * TWO INSERTS ARE NEEDED, NOT ONE. tblmenumasterG2gController::canView() reads
 *
 *     return ($rights->can_view ?? 0) == 1;
 *
 * so a menu with NO rights row is invisible - revocation in this system is row
 * absence, not a zero. Menu 102 (Leave Dashboard) carries 72 rights rows. A menu
 * row on its own would leave My HR exactly as unreachable as it is today, which
 * is the trap this migration exists to avoid.
 *
 * can_view only. My HR is read-only self-service: it shows the caller their own
 * leave and payslips and takes no writes, and MyHrController resolves the
 * subject from the token rather than from any id in the request.
 *
 * Reversal: Docs/hrit-audit/_reversals/REVERSAL-2026-09-08-my-hr-menu.sql
 */
return new class extends Migration
{
    /** Free on 202.47.117.220 and 128.199.17.97; both max out at 304. */
    private const MENU_ID = 305;

    private const ACCESS_LINK = '/module/hrit-solutions/my-hr';

    public function up(): void
    {
        // Idempotent: this runs on two hosts and must not double-insert.
        if (DB::table('tblmenumaster_g2g')->where('id', self::MENU_ID)->exists()) {
            return;
        }

        DB::table('tblmenumaster_g2g')->insert([
            'id'          => self::MENU_ID,
            'menu_name'   => 'My HR',
            'parent_id'   => 5,          // HRIT Management
            'level'       => 2,          // a standalone leaf, like Attendance Management's children
            'page_type'   => 'page',
            'access_link' => self::ACCESS_LINK,
            'icon'        => '',
            'status'      => 1,
            'sort_order'  => 8,          // after Payroll Management (3) and the disabled 4-7
            // NULL means "every organisation" - see tblmenumaster_g2gModel::visibleToTenant.
            // My HR is not tenant-specific: every employee on the platform has one.
            'sub_institute_id' => null,
            'menu_type'   => '',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        /*
         * A rights row for every profile, stamped with that profile's own
         * tenant. Modelled on the existing rows for menu 102 rather than
         * invented: same columns, same shape, can_view = 1 and nothing else.
         */
        $profiles = DB::table('tbluserprofilemaster')
            ->select('id', 'sub_institute_id')
            ->get();

        $now  = now();
        $rows = [];

        foreach ($profiles as $profile) {
            $rows[] = [
                'menu_id'          => self::MENU_ID,
                'profile_id'       => $profile->id,
                'can_view'         => 1,
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
        // Rights first - they reference the menu.
        DB::table('tblgroupwise_rights_g2g')->where('menu_id', self::MENU_ID)->delete();
        DB::table('tblmenumaster_g2g')->where('id', self::MENU_ID)->delete();
    }
};
