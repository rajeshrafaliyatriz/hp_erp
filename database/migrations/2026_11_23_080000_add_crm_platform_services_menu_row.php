<?php

use App\Support\RoleKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds CRM as a 7th "Platform Services" module-scoped row, matching the
 * existing 6 (organization/hrms/talent/lms/competency/task) exactly:
 * `access_link = "/platform-services/workflow?module=crm"`, nested under
 * CRM's own root (199), `status = 0` (reached via the module's own
 * dashboard tab, never a direct sidebar item, same as the other 6).
 *
 * Without this row, `RequirePlatformRight`'s `{module}` substitution for
 * `module=crm` has nothing to resolve - a CRM-only admin (one with rights
 * on menu 202 "Master Fields" but none of the other 6 modules' own
 * Platform Services rows) would be refused the Fields Configuration API
 * entirely, which defeats the point of reusing menu 202 as its entry
 * point. `DECENTRALIZED_MODULES` (backend `RequirePlatformRight`, frontend
 * `registry.ts`/`access-links.ts`) must also list 'crm' - done alongside
 * this migration, not here (plain code, not a DB row).
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_11_23_080000_add_crm_platform_services_menu_row.php
 *   php artisan migrate --database=live --path=database/migrations/2026_11_23_080000_add_crm_platform_services_menu_row.php
 */
return new class extends Migration
{
    private const CRM_ROOT_ID = 199;
    private const ACCESS_LINK = '/platform-services/workflow?module=crm';

    public function up(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $menuId = $this->createRow();
        $this->topUpAdminRights($menuId);
    }

    public function down(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $menuId = DB::table('tblmenumaster_g2g')
            ->where('parent_id', self::CRM_ROOT_ID)
            ->where('access_link', self::ACCESS_LINK)
            ->value('id');

        if ($menuId === null) {
            return;
        }

        if ($this->tableExists('tblgroupwise_rights_g2g')) {
            DB::table('tblgroupwise_rights_g2g')->where('menu_id', $menuId)->delete();
        }

        DB::table('tblmenumaster_g2g')->where('id', $menuId)->delete();
    }

    private function createRow(): int
    {
        $existing = DB::table('tblmenumaster_g2g')
            ->where('parent_id', self::CRM_ROOT_ID)
            ->where('access_link', self::ACCESS_LINK)
            ->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        $nextSort = (int) DB::table('tblmenumaster_g2g')->where('parent_id', self::CRM_ROOT_ID)->max('sort_order');

        return DB::table('tblmenumaster_g2g')->insertGetId([
            'menu_name' => 'Platform Services',
            'parent_id' => self::CRM_ROOT_ID,
            'level' => 2,
            'page_type' => null,
            'access_link' => self::ACCESS_LINK,
            'icon' => '',
            'status' => 0,
            'sort_order' => $nextSort + 1,
            'sub_institute_id' => null,
            'menu_type' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function topUpAdminRights(int $menuId): void
    {
        if (! $this->tableExists('tblgroupwise_rights_g2g') || ! $this->tableExists('tbluserprofilemaster')) {
            return;
        }

        $profiles = DB::table('tbluserprofilemaster')->get(['id', 'sub_institute_id', 'role_key', 'name']);

        foreach ($profiles as $profile) {
            if (RoleKey::fromProfile($profile) !== 'administrator') {
                continue;
            }

            $existing = DB::table('tblgroupwise_rights_g2g')
                ->where('menu_id', $menuId)
                ->where('profile_id', $profile->id)
                ->first();

            if ($existing !== null) {
                DB::table('tblgroupwise_rights_g2g')
                    ->where('menu_id', $menuId)
                    ->where('profile_id', $profile->id)
                    ->update(['can_view' => 1, 'can_add' => 1, 'can_edit' => 1, 'can_delete' => 1]);

                continue;
            }

            DB::table('tblgroupwise_rights_g2g')->insert([
                'sub_institute_id' => $profile->sub_institute_id,
                'menu_id' => $menuId,
                'profile_id' => $profile->id,
                'can_view' => 1,
                'can_add' => 1,
                'can_edit' => 1,
                'can_delete' => 1,
                'dashboard_right' => 0,
                'is_mobile' => 0,
                'created_at' => now(),
            ]);
        }
    }

    private function tableExists(string $table): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table]
        ) !== [];
    }
};
