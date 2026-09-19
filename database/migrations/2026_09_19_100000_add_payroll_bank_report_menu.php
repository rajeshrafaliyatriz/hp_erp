<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use App\Support\RoleKey;

/**
 * F-166 / F-167. A front door for the Bank-wise Payment Advice, and a menu
 * label that stops lying.
 *
 * F-166. POST /payroll-bank-wise-report has been implemented, routed, gated
 * behind hrit.role:admin,hr and returning clean JSON since it was written. It
 * had no caller anywhere in the frontend - a repo-wide search for the path,
 * `bankWise` and `bank_wise` returns nothing outside the controller. It is the
 * one payroll report that produces something finance cannot get any other way:
 * for a month, every employee actually paid (`whereNotNull('total_payment')`)
 * with bank_name, account_no and ifsc_code. That has been re-keyed by hand.
 *
 * F-167. Menu 140 is named "Monthly Payroll Report" and mounts the payroll
 * data-ENTRY grid - with a Generate Payroll button and a per-row Delete on it.
 * Somebody clicking a menu item named "Report" could delete a filed payslip.
 * Renamed to what it is. The access_link is NOT changed: it is the key the
 * content map matches on, and renaming it would unmount a working screen.
 *
 * RIGHTS ARE NOT GRANTED TO EVERY PROFILE, and that is the difference from the
 * My HR migration (2026_09_08_100000). My HR shows a person their own payslip,
 * so every profile gets can_view. This advice shows EVERY employee's salary and
 * bank account. Rights therefore follow the same rule the route already
 * enforces - hrit.role:admin,hr - resolved through RoleKey rather than through
 * tbluserprofilemaster.name, because the name is a label a tenant can edit
 * (D-010) and authorisation must never key on it.
 *
 * canView() reads `($rights->can_view ?? 0) == 1`, so a menu with no rights row
 * is invisible: absence IS revocation in this system. Every other profile is
 * therefore correctly left without a row, rather than given can_view = 0.
 *
 * Reversal: Docs/hrit-audit/_reversals/REVERSAL-2026-09-19-bank-report-menu.sql
 */
return new class extends Migration
{
    /** Verified free on 202.47.117.220 and 128.199.17.97; both max out at 306. */
    private const MENU_ID = 307;

    private const ACCESS_LINK = '/module/hrit-solutions/payroll-management/payroll-bank-report';

    /** Menu 95, "Payroll Management" - status 1 on both hosts. */
    private const PARENT_ID = 95;

    private const MONTHLY_PAYROLL_MENU_ID = 140;

    public function up(): void
    {
        // F-167. Rename only. Guarded so a re-run, or a host where somebody has
        // already renamed it by hand, is left alone.
        DB::table('tblmenumaster_g2g')
            ->where('id', self::MONTHLY_PAYROLL_MENU_ID)
            ->where('menu_name', 'Monthly Payroll Report')
            ->update(['menu_name' => 'Monthly Payroll', 'updated_at' => now()]);

        // Idempotent: this runs on two hosts and must not double-insert.
        if (DB::table('tblmenumaster_g2g')->where('id', self::MENU_ID)->exists()) {
            return;
        }

        DB::table('tblmenumaster_g2g')->insert([
            'id'          => self::MENU_ID,
            'menu_name'   => 'Bank-wise Payment Advice',
            'parent_id'   => self::PARENT_ID,
            'level'       => 3,          // same depth as its siblings 105-110, 140
            'page_type'   => 'page',
            'access_link' => self::ACCESS_LINK,
            'icon'        => '',
            'status'      => 1,
            'sort_order'  => 10,         // after Monthly Payroll (9)
            // NULL means "every organisation" - see tblmenumaster_g2gModel::visibleToTenant.
            // Payroll is not tenant-specific; who may SEE it is decided by the
            // rights rows below.
            'sub_institute_id' => null,
            'menu_type'   => '',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $profiles = DB::table('tbluserprofilemaster')
            ->select('id', 'sub_institute_id', 'role_key', 'name')
            ->get();

        $now  = now();
        $rows = [];

        foreach ($profiles as $profile) {
            // fromProfile() falls back to LEGACY_NAMES for the 13 profiles that
            // predate role_key, and returns null for anything it cannot resolve.
            // Null grants nothing, which is the safe direction here.
            if (!RoleKey::satisfies(RoleKey::fromProfile($profile), ['admin', 'hr'])) {
                continue;
            }

            $rows[] = [
                'menu_id'          => self::MENU_ID,
                'profile_id'       => $profile->id,
                'can_view'         => 1,
                // Read-only report. It takes no writes and has no delete path.
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

        DB::table('tblmenumaster_g2g')
            ->where('id', self::MONTHLY_PAYROLL_MENU_ID)
            ->where('menu_name', 'Monthly Payroll')
            ->update(['menu_name' => 'Monthly Payroll Report', 'updated_at' => now()]);
    }
};
