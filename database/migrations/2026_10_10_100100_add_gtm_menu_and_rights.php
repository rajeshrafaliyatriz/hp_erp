<?php

use App\Support\RoleKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "GTM & Revenue": a top-level sidebar module with its own pages.
 *
 *   php artisan migrate --path=database/migrations/2026_10_10_100100_add_gtm_menu_and_rights.php
 *   php artisan migrate --database=live --path=database/migrations/2026_10_10_100100_add_gtm_menu_and_rights.php
 *
 * WHY A NEW LEVEL-1 ROW AND NOT A TAB IN AN EXISTING MODULE
 *
 * GTM is neither Central AI & Intelligence (/ai/*) nor a module AI Stack. Putting it in
 * either would mix revenue work with provider/policy configuration, which is the one thing
 * the design must not do. The legacy "CRM" row (id 199, status 0) is left exactly as it
 * is - it is a retired module with its own history, not a home for this one.
 *
 * access_link values are application routes (app/gtm/*), the same precedent as
 * /dashboard (menu 300) and /organization/setup: the sidebar pushes a node's access_link
 * directly. Matching is by access_link, never by id, because dev and live have been seen to
 * disagree on ids for the same row.
 *
 * WHO GETS IT
 *
 * Nobody outside Administrator and Executive. There is no "sales" profile in G2G, and
 * revenue data (deals, customers, contacts) should not appear in front of every employee
 * because a migration said so. Administrator gets view/add/edit/delete; Executive gets
 * view. Anything wider is a decision for the Rights screen, which is where it belongs.
 *
 * Revocation in this system is row absence, so this only ever inserts; down() removes the
 * rows it created. It deliberately does NOT add an ai_modules row: a row with `capabilities`
 * set is what makes a module appear in the per-module AI Stack list, and GTM must not.
 *
 * MariaDB 10.1.48 on live: existence checked through information_schema.
 */
return new class extends Migration
{
    private const ROOT = ['name' => 'GTM & Revenue', 'link' => '/gtm', 'icon' => 'mdi mdi-trending-up'];

    /**
     * Only pages that exist. Each later phase adds its own rows in its own migration, so the
     * sidebar never links to a screen that has not been built.
     *
     * @var array<int, array{0:string,1:string}> [name, access_link]
     */
    private const PAGES = [
        ['Command Center', '/gtm/command-center'],
        ['Prospecting', '/gtm/prospecting'],
    ];

    private const GRANTS = [
        'administrator' => [1, 1, 1, 1],
        'executive' => [1, 0, 0, 0],
    ];

    public function up(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $rootId = $this->ensureMenu(self::ROOT['name'], self::ROOT['link'], 0, 1, self::ROOT['icon']);

        $ids = [$rootId];
        foreach (self::PAGES as $i => [$name, $link]) {
            $ids[] = $this->ensureMenu($name, $link, $rootId, 2, null, $i + 1);
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

        $links = array_merge([self::ROOT['link']], array_column(self::PAGES, 1));
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

        $sortOrder = $sort ?? ((int) DB::table('tblmenumaster_g2g')->where('level', 1)->max('sort_order') + 1);

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
