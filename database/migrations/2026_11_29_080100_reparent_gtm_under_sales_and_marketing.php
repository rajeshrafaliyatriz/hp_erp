<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sales & Marketing unification, Phase 1 step 2: re-parent GTM & Revenue's
 * entire existing menu subtree under the Sales & Marketing root (id 199),
 * renaming the branch "GTM Agents". GTM's 12 pages already have menu rows
 * (seeded incrementally by 2026_10_10_100100_add_gtm_menu_and_rights.php and
 * every feature migration after it) - this migration only moves the ROOT
 * row; its children follow automatically because the sidebar nests by
 * parent_id, and none of their own parent_id/access_link values change.
 *
 * access_link values (`/gtm`, `/gtm/deals`, etc.) are untouched - the Next.js
 * routes do not move (see Docs/sales-marketing-merge/DECISIONS.md for why:
 * these are bespoke React trees, not the generic /module/[moduleId] shell).
 *
 * CRITICAL REMEDIATION (verified against live data in Phase 0, not
 * hypothetical): today, `displaySidebarMenu` only needs can_view=1 on the
 * GTM root (checked once, it was level 1) for a profile to see the whole
 * branch. After re-parenting, the per-level check means a profile also
 * needs can_view=1 on menu 199 now, because 199 becomes GTM's new
 * structural ancestor. Confirmed on dev: 10 Executive profiles hold
 * can_view=1 on the GTM root but ZERO rows on menu 199 - without this
 * remediation, re-parenting would silently remove the entire GTM branch
 * from every Executive's sidebar. This migration unions the grant (adds
 * can_view=1 on 199 for any profile that already had it on the GTM root),
 * never subtracts.
 *
 * The GTM root's own numeric id differs between databases (dev=441,
 * live=438, confirmed in Phase 0) - resolved here by access_link, matching
 * the access-link-not-id discipline already established by GTM's own prior
 * migrations.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_11_29_080100_reparent_gtm_under_sales_and_marketing.php
 *   php artisan migrate --database=live --path=database/migrations/2026_11_29_080100_reparent_gtm_under_sales_and_marketing.php
 */
return new class extends Migration
{
    private const SALES_MARKETING_ID = 199;
    private const GTM_LINK = '/gtm';
    private const OLD_NAME = 'GTM & Revenue';
    private const NEW_NAME = 'GTM Agents';

    public function up(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $gtmRoot = DB::table('tblmenumaster_g2g')
            ->where('access_link', self::GTM_LINK)
            ->where('parent_id', 0)
            ->first(['id', 'menu_name', 'parent_id', 'level', 'sort_order']);

        if ($gtmRoot === null || $gtmRoot->menu_name !== self::OLD_NAME) {
            throw new RuntimeException(
                'No level-1 tblmenumaster_g2g row found at access_link=' . self::GTM_LINK
                . ' named "' . self::OLD_NAME . '" on this database - refusing to reparent something else.'
            );
        }

        $nextSortOrder = (int) DB::table('tblmenumaster_g2g')->where('parent_id', self::SALES_MARKETING_ID)->max('sort_order') + 1;

        DB::table('tblmenumaster_g2g')->where('id', $gtmRoot->id)->update([
            'menu_name' => self::NEW_NAME,
            'parent_id' => self::SALES_MARKETING_ID,
            'level' => 2,
            'sort_order' => $nextSortOrder,
            'updated_at' => now(),
        ]);

        if (! $this->tableExists('tblgroupwise_rights_g2g') || ! $this->tableExists('tbluserprofilemaster')) {
            return;
        }

        $this->unionGrantViewOn199FromGtmRoot((int) $gtmRoot->id);
    }

    public function down(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $gtmRoot = DB::table('tblmenumaster_g2g')
            ->where('access_link', self::GTM_LINK)
            ->where('parent_id', self::SALES_MARKETING_ID)
            ->first(['id']);

        if ($gtmRoot === null) {
            return;
        }

        $nextTopLevelSortOrder = (int) DB::table('tblmenumaster_g2g')->where('level', 1)->max('sort_order') + 1;

        // Restores structural position only. Deliberately does NOT retract the
        // can_view grants on 199 added below - same precedent as
        // 2026_11_20_085000_reactivate_crm_marketing_menu_rights.php's own
        // down(): a grant this migration's up() added should not be silently
        // stripped by its down(), since a human may be relying on it by then.
        DB::table('tblmenumaster_g2g')->where('id', $gtmRoot->id)->update([
            'menu_name' => self::OLD_NAME,
            'parent_id' => 0,
            'level' => 1,
            'sort_order' => $nextTopLevelSortOrder,
            'updated_at' => now(),
        ]);
    }

    private function unionGrantViewOn199FromGtmRoot(int $gtmRootId): void
    {
        $viewers = DB::table('tblgroupwise_rights_g2g')
            ->where('menu_id', $gtmRootId)
            ->where('can_view', 1)
            ->get(['profile_id', 'sub_institute_id']);

        foreach ($viewers as $viewer) {
            $existing = DB::table('tblgroupwise_rights_g2g')
                ->where('menu_id', self::SALES_MARKETING_ID)
                ->where('profile_id', $viewer->profile_id)
                ->first(['id', 'can_view']);

            if ($existing === null) {
                DB::table('tblgroupwise_rights_g2g')->insert([
                    'sub_institute_id' => $viewer->sub_institute_id,
                    'menu_id' => self::SALES_MARKETING_ID,
                    'profile_id' => $viewer->profile_id,
                    'can_view' => 1,
                    'can_add' => 0,
                    'can_edit' => 0,
                    'can_delete' => 0,
                    'dashboard_right' => 0,
                    'is_mobile' => 0,
                    'created_at' => now(),
                ]);

                continue;
            }

            if ((int) $existing->can_view !== 1) {
                DB::table('tblgroupwise_rights_g2g')->where('id', $existing->id)->update(['can_view' => 1]);
            }
        }
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
