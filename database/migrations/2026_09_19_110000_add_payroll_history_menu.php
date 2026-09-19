<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use App\Support\RoleKey;

/**
 * F-169. A front door for Employee Payroll History.
 *
 * POST /employee-payroll-history has been implemented, routed and gated behind
 * hrit.role:admin,hr with no caller anywhere in the frontend. It returns one
 * FINANCIAL year (Apr-Mar) of an employee's payslips with the per-component
 * breakdown - the thing an employee needs at loan time and HR needs in a pay
 * dispute. My HR's /payslips gives month, gross and net only, with no
 * components at all, so there was no way to answer "what made up this figure".
 *
 * Rights follow the route's own gate - hrit.role:admin,hr - resolved through
 * RoleKey rather than tbluserprofilemaster.name (D-010). Same reasoning as the
 * Bank-wise migration: this report shows every employee's pay, so it is NOT
 * granted to every profile the way My HR is. Profiles outside the set get no
 * row at all, because canView() reads `($rights->can_view ?? 0) == 1` and
 * absence IS revocation here.
 *
 * NOTE for whoever extends this later: an employee's access to their OWN
 * history is a different feature. It belongs on My HR, gated on the token
 * subject, not on this menu - granting this row more widely would expose
 * everyone's pay, not just the viewer's.
 *
 * Reversal: Docs/hrit-audit/_reversals/REVERSAL-2026-09-19-payroll-history-menu.sql
 */
return new class extends Migration
{
    /** Verified free on 202.47.117.220 and 128.199.17.97; both max out at 307. */
    private const MENU_ID = 308;

    private const ACCESS_LINK = '/module/hrit-solutions/payroll-management/payroll-history';

    /** Menu 95, "Payroll Management" - status 1 on both hosts. */
    private const PARENT_ID = 95;

    public function up(): void
    {
        if (DB::table('tblmenumaster_g2g')->where('id', self::MENU_ID)->exists()) {
            return;
        }

        DB::table('tblmenumaster_g2g')->insert([
            'id'          => self::MENU_ID,
            'menu_name'   => 'Employee Payroll History',
            'parent_id'   => self::PARENT_ID,
            'level'       => 3,
            'page_type'   => 'page',
            'access_link' => self::ACCESS_LINK,
            'icon'        => '',
            'status'      => 1,
            'sort_order'  => 11,        // after Bank-wise Payment Advice (10)
            'sub_institute_id' => null,
            'menu_type'   => '',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $now  = now();
        $rows = [];

        foreach (DB::table('tbluserprofilemaster')->get(['id', 'sub_institute_id', 'role_key', 'name']) as $profile) {
            if (!RoleKey::satisfies(RoleKey::fromProfile($profile), ['admin', 'hr'])) {
                continue;
            }

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
