<?php

use App\Support\RoleKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reports-engine Phase 3 (Docs/reports-engine/PLAN.md): a new, top-level CRM
 * menu leaf for the generic report builder's own entry point
 * (`/module/crm/reports`), sibling to Marketing/Sales/Support directly under
 * CRM root 199 — matching the real vtiger reference's own Reports tab,
 * which sits at the top level, not nested under any other module.
 *
 * Deliberately NOT the same menu row as the pre-existing
 * `/module/crm/support/reports` leaf (`2026_11_25_091000_create_crm_reports_
 * menu_right.php`) — that one is the admin-only AI Stack report screen
 * (CrmReportsView / ModuleDataSourceCatalog) nested under Support, and is
 * untouched by this phase. This is a second, separate, new leaf for the
 * generic end-user report builder (ReportsShell, scope=crm).
 *
 * Same insert/rights shape as `2026_11_24_080000_create_crm_sales_menu_
 * rights.php` (a fresh row under CRM root, matched by access_link only,
 * since auto-increment ids are not guaranteed to match between `mysql` and
 * `live`).
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_11_28_100300_create_crm_top_level_reports_menu.php
 *   php artisan migrate --database=live --path=database/migrations/2026_11_28_100300_create_crm_top_level_reports_menu.php
 */
return new class extends Migration
{
    private const CRM_ID = 199;
    private const ACCESS_LINK = '/module/crm/reports';

    public function up(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $crm = DB::table('tblmenumaster_g2g')->where('id', self::CRM_ID)->first(['id', 'menu_name', 'access_link']);

        if ($crm === null || $crm->menu_name !== 'CRM' || $crm->access_link !== '/module/crm') {
            throw new RuntimeException(
                'tblmenumaster_g2g id ' . self::CRM_ID . ' is not the expected CRM root row on this '
                . 'database - refusing to attach a Reports leaf to something else by mistake.'
            );
        }

        $leafId = $this->createLeaf();

        $this->topUpAdminRights($leafId);
    }

    public function down(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $leafId = DB::table('tblmenumaster_g2g')
            ->where('parent_id', self::CRM_ID)
            ->where('access_link', self::ACCESS_LINK)
            ->value('id');

        if ($leafId === null) {
            return;
        }

        if ($this->tableExists('tblgroupwise_rights_g2g')) {
            DB::table('tblgroupwise_rights_g2g')->where('menu_id', $leafId)->delete();
        }

        DB::table('tblmenumaster_g2g')->where('id', $leafId)->delete();
    }

    private function createLeaf(): int
    {
        $existing = DB::table('tblmenumaster_g2g')
            ->where('parent_id', self::CRM_ID)
            ->where('access_link', self::ACCESS_LINK)
            ->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        $nextSort = (int) DB::table('tblmenumaster_g2g')->where('parent_id', self::CRM_ID)->max('sort_order');

        return DB::table('tblmenumaster_g2g')->insertGetId([
            'menu_name' => 'Reports',
            'parent_id' => self::CRM_ID,
            'level' => 2,
            'page_type' => 'page',
            'access_link' => self::ACCESS_LINK,
            'icon' => 'mdi mdi-chart-box-outline',
            'status' => 1,
            'sort_order' => $nextSort + 1,
            'sub_institute_id' => '',
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
