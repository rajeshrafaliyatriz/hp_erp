<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Three changes to the Sales & Marketing menu tree, all by explicit user
 * request (2026-10-19):
 *
 *   php artisan migrate --path=database/migrations/2026_10_19_163000_reorder_sales_marketing_menu_and_detach_support.php
 *   php artisan migrate --database=live --path=database/migrations/2026_10_19_163000_reorder_sales_marketing_menu_and_detach_support.php
 *
 * 1. Rename the root (id varies per connection - matched by access_link
 *    '/module/crm', never hardcoded) from "Sales & Marketing" to
 *    "Sales & Marketing Intelligence".
 *
 * 2. Detach Support into its own top-level module. It was nested under
 *    Sales & Marketing by the original Phase 1 unification; the user now
 *    wants it as a sibling, not a child - Support is a distinct function
 *    from Sales & Marketing, not a sub-process of it. This is a pure
 *    `parent_id`/`level` move: `tblmenumasterG2gController::displaySidebarMenu`
 *    (the actual endpoint the g2gv0 sidebar calls) builds the tree purely
 *    from `parent_id` via `groupBy('parent_id')` and never reads the stored
 *    `level` column for that - confirmed by reading the controller, not
 *    assumed - but `level` is kept internally consistent anyway (Support's
 *    own row and every descendant shifts up by exactly one), since other
 *    screens (Role & Permissions tree, AI Stack registry) may still read it
 *    for display. Existing `tblgroupwise_rights_g2g` grants are untouched -
 *    they're keyed by menu_id, not by position in the tree, and removing one
 *    ancestor gate (the old requirement to also hold can_view on the
 *    Sales & Marketing root) can only widen access for someone who somehow
 *    already held Support's own grants without the parent's, never narrow
 *    anyone's. No remediation grant is needed, unlike Phase 1's GTM
 *    re-parenting (which moved the opposite direction, adding a gate).
 *    Support's new top-level sort_order is appended after the last existing
 *    top-level module rather than inserted between others, so no unrelated
 *    module's position changes.
 *
 * 3. Reorder Sales & Marketing Intelligence's remaining children into
 *    business-process order (Marketing -> Sales -> GTM Agents -> Signals)
 *    followed by utility/admin items (Reports, Master Fields, Platform
 *    Services) last - replacing the previous order, which interleaved
 *    Master Fields and Platform Services between Marketing and Sales.
 *
 * MariaDB 10.1.48 on live: existence checked through information_schema,
 * same convention as every other menu migration in this effort.
 */
return new class extends Migration
{
    private const ROOT_LINK = '/module/crm';
    private const NEW_ROOT_NAME = 'Sales & Marketing Intelligence';
    private const OLD_ROOT_NAME = 'Sales & Marketing';

    private const SUPPORT_LINK = '/module/crm/support';

    /** New sort_order for Sales & Marketing Intelligence's remaining children, by access_link. */
    private const CHILD_ORDER = [
        '/module/crm/marketing' => 1,
        '/module/crm/sales' => 2,
        '/gtm' => 3,
        '/module/crm/signals' => 4,
        '/module/crm/reports' => 5,
        '/module/crm/master-fields' => 6,
        '/platform-services/workflow?module=crm' => 7,
    ];

    /** The exact prior values, so down() is a true inverse, not a guess. */
    private const CHILD_ORDER_PREVIOUS = [
        '/module/crm/marketing' => 1,
        '/platform-services/workflow?module=crm' => 2,
        '/module/crm/sales' => 3,
        '/module/crm/support' => 4,
        '/module/crm/reports' => 5,
        '/gtm' => 6,
        '/module/crm/signals' => 7,
        '/module/crm/master-fields' => 1,
    ];

    public function up(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $root = DB::table('tblmenumaster_g2g')->where('access_link', self::ROOT_LINK)->first(['id']);
        if (! $root) {
            return;
        }

        DB::table('tblmenumaster_g2g')->where('id', $root->id)->update([
            'menu_name' => self::NEW_ROOT_NAME,
            'updated_at' => now(),
        ]);

        foreach (self::CHILD_ORDER as $link => $sort) {
            DB::table('tblmenumaster_g2g')->where('access_link', $link)->where('parent_id', $root->id)
                ->update(['sort_order' => $sort, 'updated_at' => now()]);
        }

        $support = DB::table('tblmenumaster_g2g')->where('access_link', self::SUPPORT_LINK)->first(['id', 'level']);
        if (! $support) {
            return;
        }

        $newTopSort = (int) DB::table('tblmenumaster_g2g')->where('parent_id', 0)->max('sort_order') + 1;

        DB::table('tblmenumaster_g2g')->where('id', $support->id)->update([
            'parent_id' => 0,
            'level' => 1,
            'sort_order' => $newTopSort,
            'updated_at' => now(),
        ]);

        $this->shiftDescendantLevels($support->id, -1);
    }

    public function down(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $root = DB::table('tblmenumaster_g2g')->where('access_link', self::ROOT_LINK)->first(['id']);
        if (! $root) {
            return;
        }

        DB::table('tblmenumaster_g2g')->where('id', $root->id)->update([
            'menu_name' => self::OLD_ROOT_NAME,
            'updated_at' => now(),
        ]);

        $support = DB::table('tblmenumaster_g2g')->where('access_link', self::SUPPORT_LINK)->first(['id']);
        if ($support) {
            DB::table('tblmenumaster_g2g')->where('id', $support->id)->update([
                'parent_id' => $root->id,
                'level' => 2,
                'updated_at' => now(),
            ]);

            $this->shiftDescendantLevels($support->id, 1);
        }

        foreach (self::CHILD_ORDER_PREVIOUS as $link => $sort) {
            DB::table('tblmenumaster_g2g')->where('access_link', $link)->where('parent_id', $root->id)
                ->update(['sort_order' => $sort, 'updated_at' => now()]);
        }
    }

    /** Shifts every descendant of $rootId's stored `level` by $delta, any depth. Purely for display consistency - displaySidebarMenu itself never reads `level`. */
    private function shiftDescendantLevels(int $rootId, int $delta): void
    {
        $children = DB::table('tblmenumaster_g2g')->where('parent_id', $rootId)->get(['id', 'level']);

        foreach ($children as $child) {
            DB::table('tblmenumaster_g2g')->where('id', $child->id)->update([
                'level' => $child->level + $delta,
                'updated_at' => now(),
            ]);

            $this->shiftDescendantLevels($child->id, $delta);
        }
    }

    /** MariaDB 10.1 (live) cannot run Schema::hasTable(); sqlite (PHPUnit) has no information_schema. */
    private function tableExists(string $t): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            return Schema::hasTable($t);
        }

        return DB::selectOne('SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$t])->c > 0;
    }
};
