<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Round 5 — one sidebar entry per module instead of five.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHY THIS EXISTS
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `2026_09_29_100000_seed_decentralized_platform_services_menu.php` gave every
 * module five separate sidebar rows — Workflow, Scheduler, Add Process, Fields
 * Configuration, Integration — one per decentralizable console. That was the
 * right first step (parity with K12's per-module tabs), but it turned out
 * redundant on its own terms: every one of those five pages already renders a
 * tab strip across the top (`ServiceShell`'s `QUICK_NAV`, unchanged by this
 * migration) that switches between all five without leaving the page. Five
 * sidebar rows that all land one click apart from each other, inside a single
 * screen that already lets you move between them, is navigation clutter the
 * product owner asked to remove directly.
 *
 * ── WHAT CHANGES, CONCRETELY ─────────────────────────────────────────────────
 *
 * Per module: the existing 'Workflow' row (`/platform-services/workflow?module=X`)
 * is kept and renamed to 'Platform Services' — landing on Workflow by request,
 * with the existing tab strip reaching the other four. The other four rows
 * (Scheduler, Add Process, Fields Configuration, Integration) and their
 * `tblgroupwise_rights_g2g` rows are removed.
 *
 * Reusing the Workflow row's id, rather than deleting all five and inserting
 * fresh, matters for one reason: if any tenant had customized that specific
 * row's rights since it was seeded, this preserves it. Checked directly
 * before writing this — as of this migration, all five siblings carry
 * identical rights (93 identical can_view=1 rows each, every module) — so
 * there is nothing to lose either way, but keeping the id is still the
 * correct choice on principle.
 *
 * `access_link` is left exactly as it was on the Workflow row
 * (`/platform-services/workflow?module=X`) — no frontend page needed for
 * this change at all, since the destination page is the same one that
 * already existed and already has the tab strip to the other four consoles.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_09_29_170000_consolidate_decentralized_platform_services_menu.php
 *   php artisan migrate --database=live --path=database/migrations/2026_09_29_170000_consolidate_decentralized_platform_services_menu.php
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

    /** slug => label, same order/content as the original seed migration's CONSOLES. */
    private const CONSOLES = [
        'workflow' => 'Workflow',
        'scheduler' => 'Scheduler',
        'add-process' => 'Add Process',
        'fields-configuration' => 'Fields Configuration',
        'integration' => 'Integration',
    ];

    private const KEPT_LABEL = 'Platform Services';

    public function up(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $hasRights = $this->tableExists('tblgroupwise_rights_g2g');

        foreach (self::MODULE_PARENTS as $moduleKey => $parentId) {
            $keepLink = "/platform-services/workflow?module={$moduleKey}";

            $keepId = DB::table('tblmenumaster_g2g')
                ->where('parent_id', $parentId)
                ->where('access_link', $keepLink)
                ->value('id');

            if ($keepId === null) {
                // Nothing to consolidate for this module — the decentralized
                // seed never ran here (or already ran through this migration).
                continue;
            }

            DB::table('tblmenumaster_g2g')
                ->where('id', $keepId)
                ->update(['menu_name' => self::KEPT_LABEL, 'updated_at' => now()]);

            $dropIds = DB::table('tblmenumaster_g2g')
                ->where('parent_id', $parentId)
                ->where('id', '!=', $keepId)
                ->where('access_link', 'like', '/platform-services/%?module=' . $moduleKey)
                ->pluck('id');

            if ($dropIds->isEmpty()) {
                continue;
            }

            if ($hasRights) {
                DB::table('tblgroupwise_rights_g2g')->whereIn('menu_id', $dropIds)->delete();
            }

            DB::table('tblmenumaster_g2g')->whereIn('id', $dropIds)->delete();
        }
    }

    public function down(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $hasRights = $this->tableExists('tblgroupwise_rights_g2g');

        foreach (self::MODULE_PARENTS as $moduleKey => $parentId) {
            $keepLink = "/platform-services/workflow?module={$moduleKey}";

            $keepRow = DB::table('tblmenumaster_g2g')
                ->where('parent_id', $parentId)
                ->where('access_link', $keepLink)
                ->first(['id', 'menu_name']);

            if ($keepRow === null || $keepRow->menu_name !== self::KEPT_LABEL) {
                // Not in the consolidated state this migration produces —
                // nothing for this module's rollback to undo.
                continue;
            }

            DB::table('tblmenumaster_g2g')
                ->where('id', $keepRow->id)
                ->update(['menu_name' => self::CONSOLES['workflow'], 'updated_at' => now()]);

            $parentRights = $hasRights
                ? DB::table('tblgroupwise_rights_g2g')
                    ->where('menu_id', $parentId)
                    ->where('can_view', 1)
                    ->get(['sub_institute_id', 'profile_id'])
                : collect();

            $nextSort = (int) DB::table('tblmenumaster_g2g')
                ->where('parent_id', $parentId)
                ->max('sort_order');

            foreach (self::CONSOLES as $slug => $label) {
                if ($slug === 'workflow') {
                    continue;
                }

                $accessLink = "/platform-services/{$slug}?module={$moduleKey}";

                $existingId = DB::table('tblmenumaster_g2g')
                    ->where('parent_id', $parentId)
                    ->where('access_link', $accessLink)
                    ->value('id');

                if ($existingId !== null) {
                    continue;
                }

                $nextSort++;

                $menuId = DB::table('tblmenumaster_g2g')->insertGetId([
                    'menu_name' => $label,
                    'parent_id' => $parentId,
                    'level' => 2,
                    'page_type' => null,
                    'access_link' => $accessLink,
                    'icon' => '',
                    'status' => 1,
                    'sort_order' => $nextSort,
                    'sub_institute_id' => null,
                    'menu_type' => '',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                foreach ($parentRights as $right) {
                    DB::table('tblgroupwise_rights_g2g')->insert([
                        'sub_institute_id' => $right->sub_institute_id,
                        'menu_id' => $menuId,
                        'profile_id' => $right->profile_id,
                        'can_view' => 1,
                        'can_add' => 0,
                        'can_edit' => 0,
                        'can_delete' => 0,
                        'dashboard_right' => 0,
                        'is_mobile' => 0,
                        'created_at' => now(),
                    ]);
                }
            }
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
