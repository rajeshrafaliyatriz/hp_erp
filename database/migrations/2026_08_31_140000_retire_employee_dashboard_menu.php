<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Retire "My Dashboard" (row 302) — one dashboard entry, decided by permissions.
 *
 * ── WHY IT IS BEING REMOVED ─────────────────────────────────────────────────
 *
 * 2026_08_31_100000 added it so the employee dashboard would be addressable. It
 * was not needed: /dashboard already role-switches — HR and administrators get
 * the organisation view, everyone else gets their own — and that part works.
 * A second row bought nothing and cost two visible faults:
 *
 *   1. Both entries showed for every role, because the rights were copied from
 *      whoever could see row 300, which is everybody.
 *   2. Clicking it opened the WRONG dashboard. GtgSidebar routes every childless
 *      top-level module through the hardcoded HOME_NAV constant, so row 302
 *      navigated to /dashboard. Row 300 only appears to work because HOME_NAV
 *      happens to point at its destination.
 *
 * The frontend fault is fixed separately. This removes the row that made it
 * visible.
 *
 * ── THE PART THAT IS NOT A SIMPLE REVERSAL ──────────────────────────────────
 *
 * REVOCATION IN THIS SYSTEM IS ROW ABSENCE. There is not one can_view = 0 row in
 * either database; storeGroupwiseRightsG2g deletes a profile's whole rights set
 * and re-inserts only what was ticked. So "revoked" and "never granted" are the
 * same state, and that is exactly why the sidebar's hardcoded fallback could not
 * tell them apart.
 *
 * While diagnosing that, menu 300 was revoked for Admin and Employee on live.
 * Measured before writing this:
 *
 *   dev   menu 300: 91 profiles   menu 302: 91   holding 302 but not 300: 0
 *   live  menu 300: 33 profiles   menu 302: 34   holding 302 but not 300: 2  (16, 17)
 *
 * Delete row 302 without acting on that and profiles 16 and 17 are left with NO
 * dashboard entry at all — the fallback is the only reason they still have one
 * today, and it is being removed in the same change. So this grants menu 300 to
 * exactly those profiles that hold a 302 grant and lack a 300 one. Narrow,
 * derived from live data rather than a hardcoded id list, and a no-op on dev.
 *
 * RUN ON BOTH DATABASES:
 *   php artisan migrate --path=database/migrations/2026_08_31_140000_retire_employee_dashboard_menu.php
 *   php artisan migrate --database=live --path=database/migrations/2026_08_31_140000_retire_employee_dashboard_menu.php
 */
return new class extends Migration
{
    private const ACCESS_LINK = '/dashboard/me';

    private const KEEP_MENU_ID = 300;

    public function up(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $row = DB::table('tblmenumaster_g2g')
            ->where('access_link', self::ACCESS_LINK)
            ->first(['id']);

        if (! $row) {
            return; // Already retired.
        }

        $menuId = (int) $row->id;

        if ($this->tableExists('tblgroupwise_rights_g2g')) {
            // ORDER MATTERS: restore before deleting, so a failure part-way
            // through never leaves a profile with neither dashboard.
            $this->restoreMainDashboardFor($menuId);

            DB::table('tblgroupwise_rights_g2g')->where('menu_id', $menuId)->delete();
        }

        DB::table('tblmenumaster_g2g')->where('id', $menuId)->delete();
    }

    /**
     * Reinstating this row is the reversal. Reinstating the REVOCATION is not.
     *
     * down() deliberately does NOT take menu 300 back off the profiles up() gave
     * it to. A rollback that silently removes somebody's access to their
     * dashboard is a worse outcome than a rollback that leaves one extra grant
     * in place, and the grant is one untick away in Roles & Permissions. Stated
     * here so the asymmetry is a decision rather than an oversight.
     */
    public function down(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        if (DB::table('tblmenumaster_g2g')->where('access_link', self::ACCESS_LINK)->exists()) {
            return;
        }

        DB::table('tblmenumaster_g2g')->insert([
            'id'               => 302,
            'menu_name'        => 'My Dashboard',
            'parent_id'        => 0,
            'level'            => 1,
            'page_type'        => 'page',
            'access_link'      => self::ACCESS_LINK,
            'icon'             => 'mdi mdi-account-details-outline',
            'status'           => 1,
            'sort_order'       => 1,
            'sub_institute_id' => '',
            'menu_type'        => '',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        if (! $this->tableExists('tblgroupwise_rights_g2g')) {
            return;
        }

        $source = DB::table('tblgroupwise_rights_g2g')
            ->where('menu_id', self::KEEP_MENU_ID)
            ->where('can_view', 1)
            ->get(['profile_id', 'sub_institute_id', 'is_mobile']);

        $rows = [];

        foreach ($source as $r) {
            $rows[] = [
                'menu_id'          => 302,
                'profile_id'       => $r->profile_id,
                'can_view'         => 1,
                'can_add'          => 0,
                'can_edit'         => 0,
                'can_delete'       => 0,
                'dashboard_right'  => 1,
                'is_mobile'        => $r->is_mobile ?? 0,
                'sub_institute_id' => $r->sub_institute_id,
                'created_at'       => now(),
            ];
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('tblgroupwise_rights_g2g')->insert($chunk);
        }
    }

    /**
     * Give menu 300 to anyone who would otherwise be left with no dashboard.
     *
     * The set is DERIVED from this database, never hardcoded: the two databases
     * are in different states, and a fixed id list correct for one would be
     * wrong for the other.
     */
    private function restoreMainDashboardFor(int $menuId): void
    {
        $has302 = DB::table('tblgroupwise_rights_g2g')
            ->where('menu_id', $menuId)->where('can_view', 1)
            ->pluck('profile_id');

        if ($has302->isEmpty()) {
            return;
        }

        $has300 = DB::table('tblgroupwise_rights_g2g')
            ->where('menu_id', self::KEEP_MENU_ID)
            ->pluck('profile_id');

        // Compared against EVERY 300 row, not only can_view=1 ones: a profile
        // with a row that has can_view=0 must be updated, not given a duplicate.
        $needs = $has302->diff($has300)->unique()->values();

        if ($needs->isEmpty()) {
            return;
        }

        // The shape is copied from a live 302 grant so tenant scoping matches
        // whatever this database actually uses.
        $template = DB::table('tblgroupwise_rights_g2g')
            ->where('menu_id', $menuId)->where('can_view', 1)
            ->first(['sub_institute_id', 'is_mobile']);

        $rows = [];

        foreach ($needs as $profileId) {
            $rows[] = [
                'menu_id'          => self::KEEP_MENU_ID,
                'profile_id'       => $profileId,
                'can_view'         => 1,
                'can_add'          => 0,
                'can_edit'         => 0,
                'can_delete'       => 0,
                'dashboard_right'  => 1,
                'is_mobile'        => $template->is_mobile ?? 0,
                'sub_institute_id' => $template->sub_institute_id ?? '',
                'created_at'       => now(),
            ];
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('tblgroupwise_rights_g2g')->insert($chunk);
        }
    }

    /** Schema::hasTable() throws on live (MariaDB 10.1.48); read the catalogue directly. */
    private function tableExists(string $table): bool
    {
        $rows = DB::select(
            'SELECT COUNT(*) AS c FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        );

        return (int) ($rows[0]->c ?? 0) > 0;
    }
};
