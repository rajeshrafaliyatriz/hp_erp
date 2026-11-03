<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Organisation 360 screen (slice 3 of the agentic CRM, Docs/agentic-crm/slices/S03-organisation-360.md): a real `tblmenumaster_g2g` row under the
 * CRM root (shown as "Sales & Marketing Intelligence"), a level-2 leaf like Product Master (`/module/crm/product-master`). The screen itself (g2gv0)
 * is registered at this access_link. Without this row it has no sidebar entry and no rights of its own.
 *
 * THIS MIGRATION GRANTS NOTHING. It only creates the row. The ids of menu rows differ between the default and live connections, so the CRM
 * root is found by access_link; and which organisation sees the screen is a rollout decision made per organisation with
 *   php artisan org-accounts:grant --tenant=<id> [--apply] [--database=live]
 * which gives that organisation's administrator profile(s) the row, the LEAF ONLY, so no organisation id is written into code and no ancestor
 * is ever granted (the "granting an ancestor reveals stale sibling grants" trap).
 *
 * Idempotent: it does nothing when the row exists, or when the menu tables or the CRM root are missing. `page_type` is `enum('blade','page','link')`
 * and 'page' is what the sibling rows hold.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME, after the owner's go:
 *   php artisan migrate --path=database/migrations/2026_12_09_090200_create_organisation_360_menu_row.php
 *   php artisan migrate --database=live --path=database/migrations/2026_12_09_090200_create_organisation_360_menu_row.php
 */
return new class extends Migration
{
    private const ROOT_LINK = '/module/crm';

    private const ACCESS_LINK = '/module/crm/organisation-360';

    private const LABEL = 'Organisation 360';

    public function up(): void
    {
        if (! Schema::hasTable('tblmenumaster_g2g')) {
            return;
        }

        $rootId = DB::table('tblmenumaster_g2g')->where('access_link', self::ROOT_LINK)->where('parent_id', 0)->value('id');
        if ($rootId === null) {
            return;
        }

        if (DB::table('tblmenumaster_g2g')->where('access_link', self::ACCESS_LINK)->exists()) {
            return;
        }

        DB::table('tblmenumaster_g2g')->insert([
            'menu_name' => self::LABEL,
            'parent_id' => (int) $rootId,
            'level' => 2,
            'page_type' => 'page',
            'access_link' => self::ACCESS_LINK,
            'icon' => null,
            'status' => 1,
            'sort_order' => (int) DB::table('tblmenumaster_g2g')->where('parent_id', $rootId)->max('sort_order') + 1,
            'sub_institute_id' => null,
            'menu_type' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('tblmenumaster_g2g')) {
            return;
        }

        $menuId = DB::table('tblmenumaster_g2g')->where('access_link', self::ACCESS_LINK)->value('id');
        if ($menuId === null) {
            return;
        }

        if (Schema::hasTable('tblgroupwise_rights_g2g')) {
            DB::table('tblgroupwise_rights_g2g')->where('menu_id', $menuId)->delete();
        }
        DB::table('tblmenumaster_g2g')->where('id', $menuId)->delete();
    }
};
