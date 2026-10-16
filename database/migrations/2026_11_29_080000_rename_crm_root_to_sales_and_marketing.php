<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sales & Marketing unification, Phase 1 step 1: rename the CRM root menu row
 * to "Sales & Marketing" so GTM Agents and Signals (reparented/added by the
 * next two migrations in this batch) read as siblings under one module
 * instead of three separate top-level concepts.
 *
 * access_link stays `/module/crm` - no menuright/platformright lookup
 * anywhere keys off the root's own link, only leaf links (confirmed by
 * reading RequirePlatformRight/RequireMenuRight), so this is a pure display
 * rename with zero route/permission impact.
 *
 * Same guard as 2026_11_20_085000_reactivate_crm_marketing_menu_rights.php:
 * refuse to touch anything if id 199 isn't the row we expect, on either
 * connection (confirmed identical on both `mysql` dev (202.47.117.220) and
 * `live` (128.199.17.97) during Phase 0 groundwork - see
 * Docs/sales-marketing-merge/DECISIONS.md).
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_11_29_080000_rename_crm_root_to_sales_and_marketing.php
 *   php artisan migrate --database=live --path=database/migrations/2026_11_29_080000_rename_crm_root_to_sales_and_marketing.php
 */
return new class extends Migration
{
    private const ROOT_ID = 199;
    private const OLD_NAME = 'CRM';
    private const NEW_NAME = 'Sales & Marketing';
    private const EXPECTED_LINK = '/module/crm';

    public function up(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $root = DB::table('tblmenumaster_g2g')->where('id', self::ROOT_ID)->first(['id', 'menu_name', 'access_link']);

        if ($root === null || $root->menu_name !== self::OLD_NAME || $root->access_link !== self::EXPECTED_LINK) {
            throw new RuntimeException(
                'tblmenumaster_g2g id ' . self::ROOT_ID . ' is not the expected CRM root row on this '
                . 'database - refusing to rename something else by mistake.'
            );
        }

        DB::table('tblmenumaster_g2g')->where('id', self::ROOT_ID)->update([
            'menu_name' => self::NEW_NAME,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        DB::table('tblmenumaster_g2g')
            ->where('id', self::ROOT_ID)
            ->where('menu_name', self::NEW_NAME)
            ->update([
                'menu_name' => self::OLD_NAME,
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
