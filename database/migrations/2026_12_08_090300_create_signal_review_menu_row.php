<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Signal Review screen (slice 2 of the agentic CRM, Docs/agentic-crm/slices/S02-signals-front-door.md): a real `tblmenumaster_g2g` row
 * beside the original Signals screens, under the existing Signals branch (`/module/crm/signals`, whose children are Company Opportunities,
 * Ingestion Engine, Product Portfolio and Partner Network). The screen itself (g2gv0) is registered at this access_link. Without this row it
 * has no sidebar entry and no rights of its own.
 *
 * THIS MIGRATION GRANTS NOTHING. It only creates the row. The ids of menu rows differ between the default and live connections, so the Signals
 * branch is found by access_link; and who sees the screen is a rollout decision made per organisation with
 *   php artisan signals:grant-review --tenant=<id> [--apply] [--database=live]
 * which gives that organisation's administrator profile(s) the row, the LEAF ONLY, so no organisation id is written into code and no ancestor
 * is ever granted (the "granting an ancestor reveals stale sibling grants" trap). The right to DECIDE on a signal stays the existing
 * `/signals` edit right.
 *
 * Idempotent: it does nothing when the row exists, or when the menu table or the Signals branch is missing (it never creates the branch).
 * `page_type` is `enum('blade','page','link')` and 'page' is what the sibling rows hold.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME, after the owner's go:
 *   php artisan migrate --path=database/migrations/2026_12_08_090300_create_signal_review_menu_row.php
 *   php artisan migrate --database=live --path=database/migrations/2026_12_08_090300_create_signal_review_menu_row.php
 */
return new class extends Migration
{
    private const PARENT_LINK = '/module/crm/signals';

    private const ACCESS_LINK = '/signal-review';

    private const LABEL = 'Signal Review';

    private const ICON = 'mdi mdi-clipboard-check-outline';

    public function up(): void
    {
        if (! Schema::hasTable('tblmenumaster_g2g')) {
            return;
        }

        $parent = DB::table('tblmenumaster_g2g')->where('access_link', self::PARENT_LINK)->orderBy('id')->first(['id', 'level']);
        if ($parent === null) {
            return;
        }

        if (DB::table('tblmenumaster_g2g')->where('access_link', self::ACCESS_LINK)->exists()) {
            return;
        }

        DB::table('tblmenumaster_g2g')->insert([
            'menu_name' => self::LABEL,
            'parent_id' => (int) $parent->id,
            'level' => (int) $parent->level + 1,
            'page_type' => 'page',
            'access_link' => self::ACCESS_LINK,
            'icon' => self::ICON,
            'status' => 1,
            'sort_order' => (int) DB::table('tblmenumaster_g2g')->where('parent_id', $parent->id)->max('sort_order') + 1,
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
