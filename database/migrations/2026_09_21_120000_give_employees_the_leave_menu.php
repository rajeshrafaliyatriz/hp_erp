<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use App\Support\RoleKey;

/**
 * F-209. The employee's HRIT sidebar, corrected in both directions.
 *
 * WHAT AN EMPLOYEE ACTUALLY SAW, fetched from the live sidebar endpoint rather
 * than reasoned from the tables:
 *
 *   GET /user/ajax_sidebar_menu_g2g?type=API&token=<employee>&profile_id=9
 *     HRIT Management
 *       Attendance Management -> Attendance Tracking, Monthly Attendance Report
 *       My HR
 *
 * There is no Leave management. Menu 103 "Leave Requests" is granted to the
 * `employee` profile in exactly ONE tenant out of eleven, and its parent 94 in
 * the same one. So in every other organisation an employee has no navigation
 * path to apply for leave, withdraw one, or see where a request has got to -
 * 209 active employees across tenants 2-11 on this deployment.
 *
 * The screen is not broken and the API is not closed: LeaveRequestApiController
 * scopes a Self-scoped caller to their own rows, and applying forces the
 * subject to the caller. The feature works. It is simply not in the menu, and
 * the only way in is a button inside My HR - which is why this reads as
 * "some features is not working" rather than as an obvious hole.
 *
 * AND THE OPPOSITE ERROR, in the same profiles. Tenant 1's employee profile
 * holds can_view on the whole payroll menu - Payroll Type, Salary Structure,
 * Payroll Deduction, Form 16, Salary Certificate, Monthly Payroll - and on the
 * organisation-wide HR analytics. Every one of those refuses when opened:
 * PayrollPageShell renders "Access Restricted" and `hrit.role:admin,hr` returns
 * 403. No data leaks. What leaks is the user's time: a menu that exists only to
 * turn them away. On 128.199.17.97 BOTH employee profiles are in this state.
 *
 * So the same migration grants what was missing and removes what only refuses.
 *
 * WHAT IS DELIBERATELY LEFT ALONE. Menus 96-99, 115 and 181 (Compliance,
 * Document Management, Organization Handbook, Front Desk, Disciplinary, HRIT
 * Dashboard) are all status=0 and never render. Whether an employee should see
 * an organisation handbook when it is switched on is a product question, not a
 * permissions bug, and it is not mine to answer.
 *
 * Roles resolve through RoleKey, never tbluserprofilemaster.name (D-010).
 * canView() reads `($rights->can_view ?? 0) == 1`, so absence IS revocation -
 * which is why revoking deletes the row rather than zeroing it.
 *
 * Reversal: Docs/hrit-audit/_reversals/REVERSAL-2026-09-21-employee-leave-menu.sql
 *           (generated from Docs/hrit-audit/_evidence/before-nav-employee.json,
 *            not hand-written - the Q8 lesson.)
 */
return new class extends Migration
{
    /**
     * What an employee must be able to reach.
     *
     * 94 is the container; a leaf under a container the profile cannot view
     * never enters the sidebar, so both are required. can_add on 103 because
     * applying for leave is an add, matching menu 100's existing grant.
     */
    private const GRANT = [
        94  => ['can_add' => 0],   // Leave management (container)
        103 => ['can_add' => 1],   // Leave Requests
    ];

    /**
     * HR-only screens. Every one of these refuses an employee server-side; the
     * menu row only promises something the screen will not honour.
     *
     * Includes the status=0 members (107, 162, 163, 164, 166, 167) on purpose:
     * they do not render today, but the grant would come alive the moment a
     * tenant enabled the menu, which is the same latent fault one layer down.
     */
    private const REVOKE = [
        95, 105, 106, 107, 108, 109, 110, 140, 307, 308, 310, 311,  // Payroll Management
        101, 162, 163, 164,                                          // org-wide attendance reports
        102, 104, 165, 166, 167,                                     // leave analytics + configuration
    ];

    /**
     * Profiles that already held a GRANT row before this migration ran, so
     * down() does not delete an access it did not create. Profile ids are a
     * global auto-increment (see F-151), so these identify the same profiles on
     * both hosts; on 202.47.117.220 profile 17 simply has no such row and there
     * is nothing to preserve.
     */
    private const PRE_EXISTING = [3, 17];

    /** @return \Illuminate\Support\Collection */
    private function employeeProfiles()
    {
        return DB::table('tbluserprofilemaster')
            ->get(['id', 'sub_institute_id', 'role_key', 'name'])
            ->filter(fn ($profile) => RoleKey::fromProfile($profile) === 'employee');
    }

    public function up(): void
    {
        $now = now();

        foreach ($this->employeeProfiles() as $profile) {
            foreach (self::GRANT as $menuId => $flags) {
                $existing = DB::table('tblgroupwise_rights_g2g')
                    ->where('profile_id', $profile->id)
                    ->where('menu_id', $menuId)
                    ->first();

                // Idempotent: this runs on two hosts, and two profiles already
                // hold the row with can_view already 1.
                if ($existing) {
                    DB::table('tblgroupwise_rights_g2g')
                        ->where('id', $existing->id)
                        ->update(['can_view' => 1, 'can_add' => $flags['can_add']]);
                    continue;
                }

                DB::table('tblgroupwise_rights_g2g')->insert([
                    'menu_id'          => $menuId,
                    'profile_id'       => $profile->id,
                    'can_view'         => 1,
                    'can_add'          => $flags['can_add'],
                    'can_edit'         => 0,
                    'can_delete'       => 0,
                    'dashboard_right'  => 0,
                    'is_mobile'        => 0,
                    'sub_institute_id' => $profile->sub_institute_id,
                    'created_at'       => $now,
                ]);
            }

            DB::table('tblgroupwise_rights_g2g')
                ->where('profile_id', $profile->id)
                ->whereIn('menu_id', self::REVOKE)
                ->delete();
        }
    }

    public function down(): void
    {
        $now = now();

        foreach ($this->employeeProfiles() as $profile) {
            /*
             * Faithful, and checked before relying on it: every row this
             * migration deletes carries can_view=1 with add, edit and delete
             * all 0 - 17 rows on 202.47.117.220, 22 on 128.199.17.97. So the
             * restore is exact, not approximate.
             */
            foreach (self::REVOKE as $menuId) {
                $held = DB::table('tblgroupwise_rights_g2g')
                    ->where('profile_id', $profile->id)
                    ->where('menu_id', $menuId)
                    ->exists();

                if ($held) {
                    continue;
                }

                DB::table('tblgroupwise_rights_g2g')->insert([
                    'menu_id'          => $menuId,
                    'profile_id'       => $profile->id,
                    'can_view'         => 1,
                    'can_add'          => 0,
                    'can_edit'         => 0,
                    'can_delete'       => 0,
                    'dashboard_right'  => 0,
                    'is_mobile'        => 0,
                    'sub_institute_id' => $profile->sub_institute_id,
                    'created_at'       => $now,
                ]);
            }

            // Leave the two profiles that already had the leave menu holding it.
            if (in_array($profile->id, self::PRE_EXISTING, true)) {
                continue;
            }

            DB::table('tblgroupwise_rights_g2g')
                ->where('profile_id', $profile->id)
                ->whereIn('menu_id', array_keys(self::GRANT))
                ->delete();
        }
    }
};
