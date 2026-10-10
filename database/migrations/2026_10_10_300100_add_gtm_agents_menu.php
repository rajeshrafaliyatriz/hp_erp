<?php

use App\Support\RoleKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Sidebar page "Agents" under GTM & Revenue (only now that the screen exists).
 *   php artisan migrate --path=database/migrations/2026_10_10_300100_add_gtm_agents_menu.php
 * Same grants as the other GTM pages: Administrator full, Executive view. Insert-only;
 * down() removes the rows it created. MariaDB 10.1: existence via information_schema.
 */
return new class extends Migration
{
    private const LINK = '/gtm/agents';

    public function up(): void
    {
        if (! $this->has('tblmenumaster_g2g')) {
            return;
        }
        $root = DB::table('tblmenumaster_g2g')->where('access_link', '/gtm')->value('id');
        if (! $root) {
            return;
        }
        $id = DB::table('tblmenumaster_g2g')->where('access_link', self::LINK)->value('id')
            ?: DB::table('tblmenumaster_g2g')->insertGetId([
                'menu_name' => 'Agents', 'parent_id' => $root, 'level' => 2, 'access_link' => self::LINK, 'icon' => null, 'page_type' => 'page', 'status' => 1,
                'sort_order' => (int) DB::table('tblmenumaster_g2g')->where('parent_id', $root)->max('sort_order') + 1,
                'sub_institute_id' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);
        if (! $this->has('tblgroupwise_rights_g2g') || ! $this->has('tbluserprofilemaster')) {
            return;
        }
        $grants = ['administrator' => [1, 1, 1, 1], 'executive' => [1, 0, 0, 0]];
        foreach (DB::table('tbluserprofilemaster')->get(['id', 'sub_institute_id', 'role_key', 'name']) as $p) {
            $g = $grants[RoleKey::fromProfile($p)] ?? null;
            if ($g === null || DB::table('tblgroupwise_rights_g2g')->where('menu_id', $id)->where('profile_id', $p->id)->exists()) {
                continue;
            }
            DB::table('tblgroupwise_rights_g2g')->insert([
                'sub_institute_id' => $p->sub_institute_id, 'menu_id' => $id, 'profile_id' => $p->id, 'can_view' => $g[0], 'can_add' => $g[1],
                'can_edit' => $g[2], 'can_delete' => $g[3], 'dashboard_right' => 0, 'is_mobile' => 0, 'created_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (! $this->has('tblmenumaster_g2g')) {
            return;
        }
        $id = DB::table('tblmenumaster_g2g')->where('access_link', self::LINK)->value('id');
        if ($id) {
            if ($this->has('tblgroupwise_rights_g2g')) {
                DB::table('tblgroupwise_rights_g2g')->where('menu_id', $id)->delete();
            }
            DB::table('tblmenumaster_g2g')->where('id', $id)->delete();
        }
    }

    private function has(string $t): bool
    {
        return DB::selectOne('SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$t])->c > 0;
    }
};
