<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Hide "Performance Reviews & Appraisals" from the sidebar.
 *
 * Confirmed directly against the live menu table before writing this — it is
 * the only row anywhere in `tblmenumaster_g2g` with "performance" in its name
 * (id 49, under Talent Management, `access_link`
 * `/module/talent-management/performance-reviews-and-appraisals`).
 *
 * A soft hide (`status = 0`), not a delete: `status` already governs sidebar
 * visibility everywhere else in this table (see the same pattern in
 * `2026_09_29_170000_consolidate_decentralized_platform_services_menu.php`),
 * so this is reversible and leaves the row, its rights, and the screen itself
 * untouched if the item needs to come back.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_09_30_090000_hide_performance_reviews_menu_item.php
 *   php artisan migrate --database=live --path=database/migrations/2026_09_30_090000_hide_performance_reviews_menu_item.php
 */
return new class extends Migration
{
    private const MENU_ID = 49;

    public function up(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        DB::table('tblmenumaster_g2g')
            ->where('id', self::MENU_ID)
            ->where('menu_name', 'Performance Reviews & Appraisals')
            ->update(['status' => 0, 'updated_at' => now()]);
    }

    public function down(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        DB::table('tblmenumaster_g2g')
            ->where('id', self::MENU_ID)
            ->where('menu_name', 'Performance Reviews & Appraisals')
            ->update(['status' => 1, 'updated_at' => now()]);
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
