<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Helpers for the GTM migrations that must behave on THREE databases: modern MySQL (dev),
 * MariaDB 10.1 (live - no Schema::hasTable(), no JSON type, information_schema.columns without
 * generation_expression) and sqlite (PHPUnit). Kept out of database/migrations because the
 * migrator treats every file in that folder as a migration.
 */
trait GtmMigrationSupport
{
    private function has(string $table): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            return Schema::hasTable($table);
        }

        return DB::selectOne('SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table])->c > 0;
    }

    private function hasColumn(string $table, string $column): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            return Schema::hasColumn($table, $column);
        }

        return DB::selectOne('SELECT COUNT(*) AS c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$table, $column])->c > 0;
    }

    /**
     * Add sidebar pages under "GTM & Revenue". Administrator gets view/add/edit/delete, Executive view.
     * Insert-only (revocation is row absence); a no-op where the menu tables or the GTM root do not exist.
     *
     * @param  array<int, array{0: string, 1: string}>  $pages  [name, access_link]
     */
    private function addMenus(array $pages): void
    {
        if (! $this->has('tblmenumaster_g2g')) {
            return;
        }
        $root = DB::table('tblmenumaster_g2g')->where('access_link', '/gtm')->value('id');
        if (! $root) {
            return;
        }
        $ids = [];
        foreach ($pages as [$name, $link]) {
            $ids[] = DB::table('tblmenumaster_g2g')->where('access_link', $link)->value('id')
                ?: DB::table('tblmenumaster_g2g')->insertGetId([
                    'menu_name' => $name, 'parent_id' => $root, 'level' => 2, 'access_link' => $link, 'icon' => null, 'page_type' => 'page', 'status' => 1,
                    'sort_order' => (int) DB::table('tblmenumaster_g2g')->where('parent_id', $root)->max('sort_order') + 1,
                    'sub_institute_id' => null, 'created_at' => now(), 'updated_at' => now(),
                ]);
        }
        if (! $this->has('tblgroupwise_rights_g2g') || ! $this->has('tbluserprofilemaster')) {
            return;
        }
        $grants = ['administrator' => [1, 1, 1, 1], 'executive' => [1, 0, 0, 0]];
        foreach (DB::table('tbluserprofilemaster')->get(['id', 'sub_institute_id', 'role_key', 'name']) as $p) {
            $g = $grants[RoleKey::fromProfile($p)] ?? null;
            if ($g === null) {
                continue;
            }
            foreach ($ids as $menuId) {
                if (DB::table('tblgroupwise_rights_g2g')->where('menu_id', $menuId)->where('profile_id', $p->id)->exists()) {
                    continue;
                }
                DB::table('tblgroupwise_rights_g2g')->insert([
                    'sub_institute_id' => $p->sub_institute_id, 'menu_id' => $menuId, 'profile_id' => $p->id, 'can_view' => $g[0], 'can_add' => $g[1],
                    'can_edit' => $g[2], 'can_delete' => $g[3], 'dashboard_right' => 0, 'is_mobile' => 0, 'created_at' => now(),
                ]);
            }
        }
    }

    /** @param  array<int, string>  $links */
    private function removeMenus(array $links): void
    {
        if (! $this->has('tblmenumaster_g2g')) {
            return;
        }
        $ids = DB::table('tblmenumaster_g2g')->whereIn('access_link', $links)->pluck('id');
        if ($ids->isEmpty()) {
            return;
        }
        if ($this->has('tblgroupwise_rights_g2g')) {
            DB::table('tblgroupwise_rights_g2g')->whereIn('menu_id', $ids)->delete();
        }
        DB::table('tblmenumaster_g2g')->whereIn('id', $ids)->delete();
    }
}
