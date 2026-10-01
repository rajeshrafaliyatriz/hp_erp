<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Hide the per-module "Platform Services" sidebar row.
 *
 * `2026_09_29_170000_consolidate_decentralized_platform_services_menu.php`
 * collapsed each module's five Platform Services rows down to one
 * ("Platform Services", landing on Workflow). That single row is now
 * redundant: the navbar's own Platform Services strip
 * (`components/shell/platform-services-subheader.tsx`) reaches the same
 * five module-scoped consoles from every screen, without a sidebar entry.
 * Requested directly, after the navbar strip landed.
 *
 * A soft hide (`status = 0`), matching the same reversible pattern as the
 * migration above and `2026_09_30_090000_hide_performance_reviews_menu_item`
 * — the row, and every console it links to, is untouched.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_09_30_100000_hide_platform_services_sidebar_row.php
 *   php artisan migrate --database=live --path=database/migrations/2026_09_30_100000_hide_platform_services_sidebar_row.php
 */
return new class extends Migration
{
    private const MODULE_PARENTS = [
        'organization' => 1,
        'hrms' => 5,
        'talent' => 3,
        'lms' => 4,
        'competency' => 2,
        'task' => 204,
    ];

    public function up(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        foreach (self::MODULE_PARENTS as $moduleKey => $parentId) {
            DB::table('tblmenumaster_g2g')
                ->where('parent_id', $parentId)
                ->where('menu_name', 'Platform Services')
                ->where('access_link', "/platform-services/workflow?module={$moduleKey}")
                ->update(['status' => 0, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        foreach (self::MODULE_PARENTS as $moduleKey => $parentId) {
            DB::table('tblmenumaster_g2g')
                ->where('parent_id', $parentId)
                ->where('menu_name', 'Platform Services')
                ->where('access_link', "/platform-services/workflow?module={$moduleKey}")
                ->update(['status' => 1, 'updated_at' => now()]);
        }
    }

    /** information_schema directly - live is MariaDB 10.1, where Schema::hasTable() throws. */
    private function tableExists(string $table): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table]
        ) !== [];
    }
};
