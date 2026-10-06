<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "My Dashboard" — the employee's own dashboard, as a real menu row.
 *
 * ── WHY A SECOND ROW RATHER THAN REUSING #300 ───────────────────────────────
 *
 * tblmenumaster_g2g #300 "Main Dashboard" -> /dashboard already exists on both
 * databases with a can_view row for all 91 profiles. That route renders a ROLE
 * SWITCH: admins and HR get the organisation dashboard, everyone else gets the
 * employee one. The switch reads `user.role`, which mapProfileNameToRole()
 * derives by SUBSTRING-MATCHING A TENANT-EDITABLE PROFILE NAME — main-dashboard.tsx
 * says in its own comment that this is "unfit as a security boundary".
 *
 * So the employee dashboard was reachable but never addressable: nothing could
 * link to it, no menu carried it, and which one you got was a guess about your
 * profile's name.
 *
 * This row makes it a destination. /dashboard keeps its switch and its
 * behaviour; /dashboard/me is always the employee's own dashboard, for
 * everybody — an administrator has their own tasks and capability too.
 *
 * ── THE ID IS PINNED, AND THAT IS DELIBERATE ────────────────────────────────
 *
 * DashboardLinkResolver records why: "menu id is NOT stable across databases.
 * The same screen is 229 locally and 226 on live; that divergence has caused
 * four incidents." tblgroupwise_rights_g2g joins on menu_id, so two different
 * auto-increment values would mean the rights rows written here point at
 * different screens on the two databases.
 *
 * Both databases were measured at MAX(id) = 301 with 189 rows before this ran,
 * so 302 is free on both and is written explicitly. If it is ever taken, this
 * migration refuses rather than guessing.
 *
 * ── RIGHTS ARE DERIVED, NEVER HARDCODED ─────────────────────────────────────
 *
 * canView() is `($rights->can_view ?? 0) == 1` — A MENU WITH NO RIGHTS ROW IS
 * INVISIBLE. And the two databases do not have the same profiles: dev has 9 for
 * tenant 6 (including reporting_manager, department_head, auditor, recruiter),
 * live has 3. A hardcoded profile list would silently hide this from six roles
 * on dev and be wrong again the next time a tenant adds one.
 *
 * So the grant is copied from whoever can already see #300: if you can reach
 * the dashboard today, you can reach your own.
 *
 * RUN ON BOTH DATABASES:
 *   php artisan migrate --path=database/migrations/2026_08_31_100000_add_employee_dashboard_menu.php
 *   php artisan migrate --database=live --path=database/migrations/2026_08_31_100000_add_employee_dashboard_menu.php
 */
return new class extends Migration
{
    /** The existing dashboard row the grant is copied from. */
    private const SOURCE_MENU_ID = 300;

    /** Pinned so both databases agree. Verified free on both before writing. */
    private const MENU_ID = 302;

    private const ACCESS_LINK = '/dashboard/me';

    public function up(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $existing = DB::table('tblmenumaster_g2g')
            ->where('access_link', self::ACCESS_LINK)
            ->first(['id']);

        if ($existing) {
            // Already seeded. Re-running must not create a duplicate menu.
            $this->grantRights((int) $existing->id);

            return;
        }

        $taken = DB::table('tblmenumaster_g2g')->where('id', self::MENU_ID)->exists();

        if ($taken) {
            // REFUSE RATHER THAN GUESS. Letting auto-increment pick is what
            // produces the id divergence this migration exists to avoid; an
            // operator seeing this message can pick a free id on BOTH databases
            // and set MENU_ID once.
            throw new RuntimeException(
                'tblmenumaster_g2g id ' . self::MENU_ID . ' is already taken on this database. '
                . 'Choose an id free on BOTH databases and update MENU_ID, so the two stay in step.'
            );
        }

        // Shape copied field-for-field from #300, which is the only other
        // top-level dashboard row: parent_id 0, level 1, page_type 'page'.
        // sub_institute_id is '' so scopeVisibleToTenant() shows it to every
        // tenant — the same as #300, and the reason a per-tenant seed is not
        // needed here.
        DB::table('tblmenumaster_g2g')->insert([
            'id'               => self::MENU_ID,
            'menu_name'        => 'My Dashboard',
            'parent_id'        => 0,
            'level'            => 1,
            'page_type'        => 'page',
            'access_link'      => self::ACCESS_LINK,
            'icon'             => 'mdi mdi-account-details-outline',
            'status'           => 1,
            // Immediately after Main Dashboard, which is sort_order 0.
            'sort_order'       => 1,
            'sub_institute_id' => '',
            'menu_type'        => '',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        $this->grantRights(self::MENU_ID);
    }

    public function down(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $row = DB::table('tblmenumaster_g2g')
            ->where('access_link', self::ACCESS_LINK)
            ->first(['id']);

        if (! $row) {
            return;
        }

        if ($this->tableExists('tblgroupwise_rights_g2g')) {
            DB::table('tblgroupwise_rights_g2g')->where('menu_id', $row->id)->delete();
        }

        // Hard delete: this row was created by this migration and holds no
        // tenant-authored content, so there is nothing to preserve.
        DB::table('tblmenumaster_g2g')->where('id', $row->id)->delete();
    }

    /**
     * Copy the view grant from the existing dashboard row.
     *
     * Only can_view and dashboard_right are carried across. add/edit/delete are
     * meaningless on a dashboard and are written as 0 rather than mirrored, so a
     * profile that can edit some other screen does not inherit a phantom
     * "can edit the dashboard" right that some future check might read.
     */
    private function grantRights(int $menuId): void
    {
        if (! $this->tableExists('tblgroupwise_rights_g2g')) {
            return;
        }

        $source = DB::table('tblgroupwise_rights_g2g')
            ->where('menu_id', self::SOURCE_MENU_ID)
            ->where('can_view', 1)
            ->get(['profile_id', 'sub_institute_id', 'is_mobile']);

        if ($source->isEmpty()) {
            return;
        }

        $already = DB::table('tblgroupwise_rights_g2g')
            ->where('menu_id', $menuId)
            ->pluck('profile_id')
            ->all();

        $rows = [];

        foreach ($source as $r) {
            if (in_array($r->profile_id, $already, true)) {
                continue;
            }

            $rows[] = [
                'menu_id'          => $menuId,
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
     * Schema::hasTable() THROWS on live (MariaDB 10.1.48) — it issues a query
     * that server rejects. information_schema is read directly instead, which
     * is what every migration in this codebase does for the same reason.
     */
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
