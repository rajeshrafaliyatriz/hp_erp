<?php

use App\Support\RoleKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sales & Marketing unification, Phase 1 step 3 (final step of Phase 1):
 * Signals has zero tblmenumaster_g2g rows (confirmed empty on dev in Phase
 * 0) - it was deliberately removed from sidebar injection, per the comment
 * in g2gv0/hooks/use-sidebar-navigation.ts:172-174 ("Signals, Ingestion
 * Engine, Product Portfolio and Partner Network used to be injected here as
 * standalone sidebar links. They are intentionally no longer added"). This
 * migration gives it real rows again, this time correctly parented under
 * the Sales & Marketing root (199) instead of as a standalone top-level
 * item, so the frontend's own special-case injection logic can eventually
 * be retired (Phase 8) once these generic rows cover the same paths.
 *
 * access_link values (`/signals`, `/ingestion`, `/portfolio`, `/partners`)
 * are existing, untouched Next.js routes - only their presence in the menu
 * tree is new.
 *
 * Grants mirror Signals' OWN existing access tiers today (not GTM's
 * admin/executive split, which would be wrong here), reverse-engineered
 * precisely from routes/api.php's current profile: gates so nobody's
 * access changes when Phase 2 later flips enforcement onto this table:
 *   - administrator           : full (view/add/edit/delete)
 *   - hr_manager, hr_executive: view/add/edit (the 'hr' tier - generate,
 *     research run, ingestion, product-profile writes), no delete
 *   - reporting_manager       : view/edit only (the 'manager' tier - can
 *     review/dismiss/follow-up, but is NOT in the 'hr' tier so cannot
 *     generate/run/ingest)
 *   - everyone else           : no grant (no access today, none added)
 *
 * The parent "Signals" row's own access_link is cosmetic only (same as
 * CRM's existing "Sales"/"Support" parent rows) - a menu node with children
 * is never navigated to directly, only toggled open
 * (SidebarMenuBranch.handleClick in gtg-sidebar.tsx).
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_11_29_080200_add_signals_menu_branch.php
 *   php artisan migrate --database=live --path=database/migrations/2026_11_29_080200_add_signals_menu_branch.php
 */
return new class extends Migration
{
    private const SALES_MARKETING_ID = 199;
    private const PARENT = ['name' => 'Signals', 'link' => '/module/crm/signals', 'icon' => 'mdi mdi-radar'];

    /** @var array<int, array{0:string,1:string,2:string}> [name, access_link, icon] */
    private const PAGES = [
        ['Company Opportunities', '/signals', 'mdi mdi-lightbulb-on-outline'],
        ['Ingestion Engine', '/ingestion', 'mdi mdi-upload-network-outline'],
        ['Product Portfolio', '/portfolio', 'mdi mdi-view-grid-outline'],
        ['Partner Network', '/partners', 'mdi mdi-handshake-outline'],
    ];

    /** role_key => [can_view, can_add, can_edit, can_delete] */
    private const GRANTS = [
        'administrator' => [1, 1, 1, 1],
        'hr_manager' => [1, 1, 1, 0],
        'hr_executive' => [1, 1, 1, 0],
        'reporting_manager' => [1, 0, 1, 0],
    ];

    public function up(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $parentId = $this->ensureMenu(self::PARENT['name'], self::PARENT['link'], self::SALES_MARKETING_ID, 2, self::PARENT['icon']);

        $ids = [$parentId];
        foreach (self::PAGES as $i => [$name, $link, $icon]) {
            $ids[] = $this->ensureMenu($name, $link, $parentId, 3, $icon, $i + 1);
        }

        if (! $this->tableExists('tblgroupwise_rights_g2g') || ! $this->tableExists('tbluserprofilemaster')) {
            return;
        }

        $profiles = DB::table('tbluserprofilemaster')->get(['id', 'sub_institute_id', 'role_key', 'name']);

        foreach ($profiles as $profile) {
            $grant = self::GRANTS[RoleKey::fromProfile($profile)] ?? null;
            if ($grant === null) {
                continue;
            }

            foreach ($ids as $menuId) {
                $exists = DB::table('tblgroupwise_rights_g2g')
                    ->where('menu_id', $menuId)
                    ->where('profile_id', $profile->id)
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('tblgroupwise_rights_g2g')->insert([
                    'sub_institute_id' => $profile->sub_institute_id,
                    'menu_id' => $menuId,
                    'profile_id' => $profile->id,
                    'can_view' => $grant[0],
                    'can_add' => $grant[1],
                    'can_edit' => $grant[2],
                    'can_delete' => $grant[3],
                    'dashboard_right' => 0,
                    'is_mobile' => 0,
                    'created_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $links = array_merge([self::PARENT['link']], array_column(self::PAGES, 1));
        $ids = DB::table('tblmenumaster_g2g')->whereIn('access_link', $links)->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        if ($this->tableExists('tblgroupwise_rights_g2g')) {
            DB::table('tblgroupwise_rights_g2g')->whereIn('menu_id', $ids)->delete();
        }

        DB::table('tblmenumaster_g2g')->whereIn('id', $ids)->delete();
    }

    private function ensureMenu(string $name, string $link, int $parentId, int $level, ?string $icon, ?int $sort = null): int
    {
        $existing = DB::table('tblmenumaster_g2g')->where('access_link', $link)->value('id');
        if ($existing) {
            return (int) $existing;
        }

        $sortOrder = $sort ?? ((int) DB::table('tblmenumaster_g2g')->where('parent_id', $parentId)->max('sort_order') + 1);

        return (int) DB::table('tblmenumaster_g2g')->insertGetId([
            'menu_name' => $name,
            'parent_id' => $parentId,
            'level' => $level,
            'access_link' => $link,
            'icon' => $icon,
            'page_type' => 'page',
            'status' => 1,
            'sort_order' => $sortOrder,
            'sub_institute_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** MariaDB 10.1 (live) cannot run Schema::hasTable(); sqlite (PHPUnit) has no information_schema. */
    private function tableExists(string $table): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            return \Illuminate\Support\Facades\Schema::hasTable($table);
        }

        return DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        )->c > 0;
    }
};
