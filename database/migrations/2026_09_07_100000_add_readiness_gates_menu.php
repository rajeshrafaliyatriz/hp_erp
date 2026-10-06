<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give Readiness Gates a way in. F-147.
 *
 * ── THE SCREEN WAS FINISHED AND UNREACHABLE ─────────────────────────────────
 *
 * app/organization/readiness was the first page under app/organization to call
 * Laravel directly, and it is correct: it refuses to render without a real
 * session, it carries the loss and the days remaining into its confirm dialog,
 * and the backend behind it - Recomputer, Enforcer, Acknowledger, Controller,
 * command and schedule - is complete.
 *
 * It had no row in tblmenumaster_g2g. Nothing linked to it. The only way in was
 * to type the URL, which no customer will do.
 *
 * ── WHY 225 WAS ROLLED BACK BEFORE, AND WHY THIS IS SAFE NOW ────────────────
 *
 * routes/api.php still carries `// menuright:225,view RE-ADD WITH THE MENU` on
 * both readiness routes. Menu 225 was created once to prove the guard and then
 * rolled back, because RequireMenuRight denies when no rights row is declared -
 * so the moment the menu existed, /api/readiness/gates returned 403 to
 * everybody including the administrator.
 *
 * That cannot happen here. RequireMenuRight is registered in bootstrap/app.php
 * and attached to ZERO routes - every `menuright:` in routes/ is inside a
 * comment - so no endpoint changes behaviour because a menu row appeared. And
 * the rights rows are written in the same migration, so the row and its grant
 * arrive together rather than a menu existing that everyone is denied.
 *
 * ── WHO GETS IT ─────────────────────────────────────────────────────────────
 *
 * Administrator and HR, matching the routes' own `profile:admin,hr`. Granting
 * view but not add/edit/delete: acknowledging a gate is a POST the controller
 * gates itself, and there is nothing on this screen to create or remove.
 *
 * ── LIVE CONSTRAINTS ────────────────────────────────────────────────────────
 *
 * MariaDB 10.1.48: existence checked through information_schema because
 * Schema::hasTable() throws there. The menu catalogue is GLOBAL -
 * sub_institute_id is NULL on all 191 rows since
 * 2026_08_22_110000_make_g2g_menu_catalogue_global - so the menu row carries no
 * tenant, while every rights row does.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_09_07_100000_add_readiness_gates_menu.php
 *   php artisan migrate --database=live --path=database/migrations/2026_09_07_100000_add_readiness_gates_menu.php
 */
return new class extends Migration
{
    private const ACCESS_LINK = '/module/organizational-management/readiness-gates';
    private const MENU_NAME = 'Readiness Gates';

    /** Organizational Management. */
    private const PARENT_ID = 1;

    public function up(): void
    {
        if (!$this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $existing = DB::table('tblmenumaster_g2g')
            ->where('access_link', self::ACCESS_LINK)
            ->value('id');

        if ($existing) {
            $menuId = (int) $existing;
        } else {
            /*
             * Sorted after Compliance & Discipline, which is the last of the
             * three existing children. sort_order is 2 on all three - they tie
             * and are ordered by id - so 3 puts this last without disturbing
             * them.
             */
            $menuId = (int) DB::table('tblmenumaster_g2g')->insertGetId([
                'menu_name' => self::MENU_NAME,
                'parent_id' => self::PARENT_ID,
                'level' => 2,
                'access_link' => self::ACCESS_LINK,
                'page_type' => 'page',
                'status' => 1,
                'sort_order' => 3,
                // The catalogue is global. A tenant id here would hide the row
                // from every other organisation.
                'sub_institute_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        /*
         * Grant it to the profiles the routes already accept.
         *
         * Only tenants that actually have rights rows are touched: writing a
         * grant for an organisation that has never had a sidebar would be the
         * one screen it can see, which is stranger than seeing none.
         */
        $tenantsWithRights = DB::table('tblgroupwise_rights_g2g')
            ->whereNotNull('sub_institute_id')
            ->distinct()
            ->pluck('sub_institute_id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values();

        $granted = 0;

        foreach ($tenantsWithRights as $tenant) {
            $profiles = DB::table('tbluserprofilemaster')
                ->where('sub_institute_id', $tenant)
                ->where(function ($q) {
                    $q->whereIn('role_key', ['administrator', 'hr_manager'])
                      ->orWhereIn('name', ['Admin', 'HR']);
                })
                ->pluck('id');

            foreach ($profiles as $profileId) {
                $already = DB::table('tblgroupwise_rights_g2g')
                    ->where('profile_id', $profileId)
                    ->where('menu_id', $menuId)
                    ->exists();

                if ($already) {
                    continue;
                }

                DB::table('tblgroupwise_rights_g2g')->insert([
                    'menu_id' => $menuId,
                    'profile_id' => $profileId,
                    'sub_institute_id' => $tenant,
                    'can_view' => 1,
                    // Nothing on this screen is created or deleted; acknowledging
                    // is a POST the controller gates on its own.
                    'can_add' => 0,
                    'can_edit' => 0,
                    'can_delete' => 0,
                    'dashboard_right' => 0,
                    'is_mobile' => 0,
                    'created_at' => now(),
                ]);

                $granted++;
            }
        }

        // The parent must be viewable too, or buildMenuTree drops the branch
        // before it ever considers this child.
        $this->ensureParentVisible($menuId);
    }

    public function down(): void
    {
        $menuId = DB::table('tblmenumaster_g2g')
            ->where('access_link', self::ACCESS_LINK)
            ->value('id');

        if (!$menuId) {
            return;
        }

        DB::table('tblgroupwise_rights_g2g')->where('menu_id', $menuId)->delete();
        DB::table('tblmenumaster_g2g')->where('id', $menuId)->delete();
    }

    /**
     * Anyone granted the child must be able to see Organizational Management,
     * or displaySidebarMenu skips the module before reaching this row.
     */
    private function ensureParentVisible(int $menuId): void
    {
        $holders = DB::table('tblgroupwise_rights_g2g')
            ->where('menu_id', $menuId)
            ->get(['profile_id', 'sub_institute_id']);

        foreach ($holders as $holder) {
            $hasParent = DB::table('tblgroupwise_rights_g2g')
                ->where('profile_id', $holder->profile_id)
                ->where('menu_id', self::PARENT_ID)
                ->where('can_view', 1)
                ->exists();

            if ($hasParent) {
                continue;
            }

            DB::table('tblgroupwise_rights_g2g')->insert([
                'menu_id' => self::PARENT_ID,
                'profile_id' => $holder->profile_id,
                'sub_institute_id' => $holder->sub_institute_id,
                'can_view' => 1,
                'can_add' => 0,
                'can_edit' => 0,
                'can_delete' => 0,
                'dashboard_right' => 0,
                'is_mobile' => 0,
                'created_at' => now(),
            ]);
        }
    }

    /** Schema::hasTable() throws on MariaDB 10.1 - information_schema does not. */
    private function tableExists(string $table): bool
    {
        return DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        )->c > 0;
    }
};
