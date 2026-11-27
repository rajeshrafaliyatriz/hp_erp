<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The "Sales Kit" menu row (27 Nov 2026; Docs/agentic-crm/briefs/SALES-KIT-CONTRACT.md), so who may see and who may manage the Sales Kit tab of a Product is decided in Role and
 * Permissions like every other screen. The kit's API routes are guarded `platformright:/portfolio/sales-kit,<view|add|edit|delete>`.
 *
 * A sibling of the Product Portfolio row (`/portfolio`): same parent, same level, the next sort position. Like the Outreach rows of the same screen it is folded out of the sidebar in the
 * front end (lib/signals-nav.ts) and MUST stay `status 1`: a status 0 row loses its rights on the next Role and Permissions save (memory g2g-menu-hide-wipes-rights).
 *
 * THIS GRANTS NOTHING. With no rights row the tab is invisible to everybody and every kit route answers 403. The rights are written by `php artisan crm:grant-sales-kit-rights`
 * (dry run by default), after the audience has been counted, never by a schema migration (DB-RULES item 8).
 *
 * Idempotent on the LINK, not the id, and the id is not pinned: numeric menu ids differ between hosts, `access_link` is the stable key (MenuRight::idForAccessLink). A host that has no
 * `/portfolio` row (no Market Signals menu) gets no row, because there is nowhere to put it.
 *
 *   php artisan migrate --path=database/migrations/2026_12_30_090100_add_sales_kit_menu_row.php
 */
return new class extends Migration
{
    private const LINK = '/portfolio/sales-kit';

    private const SIBLING = '/portfolio';

    public function up(): void
    {
        if (! Schema::hasTable('tblmenumaster_g2g')) {
            return;
        }

        if (DB::table('tblmenumaster_g2g')->where('access_link', self::LINK)->exists()) {
            return;
        }

        $sibling = DB::table('tblmenumaster_g2g')->where('access_link', self::SIBLING)->orderBy('id')->first();
        if (! $sibling) {
            return;
        }

        $row = [
            'menu_name' => 'Sales Kit',
            // Inherited from the Product Portfolio row rather than hardcoded, so it lands in the right place on a host where that branch has been rearranged.
            'parent_id' => $sibling->parent_id,
            'level' => $sibling->level,
            'access_link' => self::LINK,
            'icon' => 'FolderOpen',
            'status' => 1,
            'sort_order' => ((int) $sibling->sort_order) + 1,
            'sub_institute_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('tblmenumaster_g2g', 'page_type')) {
            $row['page_type'] = $sibling->page_type ?? 'page';
        }

        DB::table('tblmenumaster_g2g')->insert($row);
    }

    /** Removes the row and any right granted on it (rights on a row that no longer exists grant nothing, and must not be picked up by a row that later reuses its id). */
    public function down(): void
    {
        if (! Schema::hasTable('tblmenumaster_g2g')) {
            return;
        }

        $ids = DB::table('tblmenumaster_g2g')->where('access_link', self::LINK)->pluck('id')->all();
        if ($ids !== [] && Schema::hasTable('tblgroupwise_rights_g2g')) {
            DB::table('tblgroupwise_rights_g2g')->whereIn('menu_id', $ids)->delete();
        }
        DB::table('tblmenumaster_g2g')->where('access_link', self::LINK)->delete();

        try {
            Cache::forget('menu_id_for_link:' . self::LINK);
        } catch (\Throwable) {
            // a cache that cannot be reached only means the old id is remembered until it expires
        }
    }
};
