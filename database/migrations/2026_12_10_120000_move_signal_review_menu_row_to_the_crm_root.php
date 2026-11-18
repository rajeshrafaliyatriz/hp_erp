<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves the Signal Review menu row (slice 2 of the agentic CRM) from under the Market Signals branch to the Sales & Marketing root, as a line of its own.
 *
 * WHY (found 3 Nov 2026 by reading the live Signals navigation, `lib/signals-nav.ts` on origin/main, which is Harshit's design that is on the live site):
 * Market Signals is folded into ONE flat sidebar link with its four tabs (Company Opportunities, Ingestion Engine, Product Portfolio, Partner Network) inside
 * the Signals screen, and `collapseSignalsNav` returns the Signals parent with `children: []`, so every child of that branch that is not one of those
 * four tabs or Signal Inbox is dropped from the sidebar. A Signal Review row left under Market Signals would therefore have no sidebar entry once the live
 * front end is merged: the screen would exist and nobody could reach it. The owner has asked that the Signals screen design (the four tabs) is NOT changed
 * before the end, so the row moves instead; the Signals menu rows, their rights and the Signals design are not touched.
 *
 * WHAT: only `parent_id`, `level`, `sort_order` and `updated_at` of the ONE row whose access_link is `/signal-review`. It creates nothing, grants nothing and
 * changes no other row, so the rights rows (which hang off the menu id, not the parent) are untouched and who sees the screen is unchanged. The row goes to
 * the END of the root's children (largest sort_order + 1): moving it next to Market Signals would mean renumbering other products' rows.
 *
 * The root is found by access_link `/module/crm` with parent_id 0 (menu ids differ between the databases). Idempotent, and a no-op when the menu table, the
 * root or the row is missing. `down()` puts the row back under the Signals branch when that branch exists.
 *
 * RUN ON DEV NOW (no go needed); ON LIVE ONLY AFTER THE OWNER'S GO, one database at a time:
 *   php artisan migrate --path=database/migrations/2026_12_10_120000_move_signal_review_menu_row_to_the_crm_root.php
 *   php artisan migrate --database=live --path=database/migrations/2026_12_10_120000_move_signal_review_menu_row_to_the_crm_root.php
 */
return new class extends Migration
{
    private const ROOT_LINK = '/module/crm';

    private const SIGNALS_LINK = '/module/crm/signals';

    private const ACCESS_LINK = '/signal-review';

    public function up(): void
    {
        if (! Schema::hasTable('tblmenumaster_g2g')) {
            return;
        }

        $root = DB::table('tblmenumaster_g2g')->where('access_link', self::ROOT_LINK)->where('parent_id', 0)->orderBy('id')->first(['id', 'level']);
        $row = DB::table('tblmenumaster_g2g')->where('access_link', self::ACCESS_LINK)->orderBy('id')->first(['id', 'parent_id']);
        if ($root === null || $row === null || (int) $row->parent_id === (int) $root->id) {
            return;
        }

        DB::table('tblmenumaster_g2g')->where('id', $row->id)->update([
            'parent_id' => (int) $root->id,
            'level' => (int) $root->level + 1,
            'sort_order' => (int) DB::table('tblmenumaster_g2g')->where('parent_id', $root->id)->max('sort_order') + 1,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('tblmenumaster_g2g')) {
            return;
        }

        $signals = DB::table('tblmenumaster_g2g')->where('access_link', self::SIGNALS_LINK)->orderBy('id')->first(['id', 'level']);
        $row = DB::table('tblmenumaster_g2g')->where('access_link', self::ACCESS_LINK)->orderBy('id')->first(['id', 'parent_id']);
        if ($signals === null || $row === null || (int) $row->parent_id === (int) $signals->id) {
            return;
        }

        DB::table('tblmenumaster_g2g')->where('id', $row->id)->update([
            'parent_id' => (int) $signals->id,
            'level' => (int) $signals->level + 1,
            'sort_order' => (int) DB::table('tblmenumaster_g2g')->where('parent_id', $signals->id)->max('sort_order') + 1,
            'updated_at' => now(),
        ]);
    }
};
