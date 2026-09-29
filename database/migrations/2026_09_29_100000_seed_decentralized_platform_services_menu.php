<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Round 3 — decentralized Platform Services tabs, inside each module's OWN navigation.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHY THIS EXISTS
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Every Platform Services console (Workflow, Scheduler, Add Process, Fields
 * Configuration, Integration) has, until now, been reachable from exactly one
 * place: the centralized `/platform-services/*` hub, which is a static list in
 * `packages/platform-services-core` and has no relationship to `tblmenumaster_g2g`
 * — the table that actually drives the sidebar somebody sees when they open, say,
 * HRIT Management. LMS K12's equivalent screens are reachable BOTH centrally and
 * from inside each business module's own navigation (a "Workflow" tab under Fees,
 * for instance) — checked directly against K12's source, not assumed. This adds
 * the same second path for G2G, without touching the central hub at all.
 *
 * ── IT IS NOT A SECOND SCREEN ────────────────────────────────────────────────
 *
 * Each new row's `access_link` points at the SAME route the central hub already
 * serves, with `?module=<key>` appended — `WorkflowController::index()` already
 * reads that parameter server-side (Round 2), and `SchedulerController` /
 * `IntegrationController` / `FieldConfigController` gained the same support
 * alongside this migration. So this migration adds navigation, not a feature.
 *
 * ── SYMMETRIC ON PURPOSE, UNLIKE EVERY EARLIER ROUND ─────────────────────────
 *
 * Every other allowlist/registry in this project (workflow points, scheduled
 * tasks, custom-field tables) is deliberately asymmetric — a module gets an
 * entry only where something real backs it. This migration is the one
 * exception: all six modules with a real `tblmenumaster_g2g` branch get a tab
 * for all five consoles, even where that module has nothing configured yet,
 * matching K12's own fully-symmetric per-module tab bar — a considered choice,
 * not an oversight. Each console's own page renders an honest "nothing
 * configured for this module yet" state rather than pretending otherwise.
 *
 * `events` (the platform's own event-store module) has NO top-level row in
 * `tblmenumaster_g2g` — confirmed directly against the live table, not assumed
 * — so it has nowhere to attach a tab and stays reachable only from the central
 * hub. That is a structural fact about the sidebar, not a gap this migration
 * could close.
 *
 * ── RIGHTS ARE INHERITED FROM THE PARENT MODULE, NOT GRANTED FRESH ──────────
 *
 * `tblmenumasterG2gController::canView()` requires an explicit `can_view=1` row
 * in `tblgroupwise_rights_g2g` per `(menu_id, profile_id)` — there is no
 * admin/platform-owner bypass. A fresh menu row with no rights row is invisible
 * to everyone, admin included. So for each new child, this copies `can_view=1`
 * to every `(sub_institute_id, profile_id)` pair that ALREADY has it on that
 * child's parent module id — whoever can already see "HRIT Management" sees its
 * five new tabs too, nothing more. `can_add`/`can_edit`/`can_delete` are left 0:
 * this table does not gate what the Platform Services API permits (that is
 * `profile:admin` middleware, per `config/platform_services.php`'s own note) —
 * it only gates whether the tab appears, so a view-only grant is the complete
 * and correct one, the same conservative shape
 * `2026_09_05_100600_grant_my_assessment_rights.php` already established.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_09_29_100000_seed_decentralized_platform_services_menu.php
 *   php artisan migrate --database=live --path=database/migrations/2026_09_29_100000_seed_decentralized_platform_services_menu.php
 */
return new class extends Migration
{
    /** Registry module key => tblmenumaster_g2g parent id. Confirmed directly
        against the live table's level-1 rows, not assumed. */
    private const MODULE_PARENTS = [
        'organization' => 1,
        'hrms' => 5,
        'talent' => 3,
        'lms' => 4,
        'competency' => 2,
        'task' => 204,
    ];

    /** slug => label, in the same order the central hub's QUICK_NAV lists them. */
    private const CONSOLES = [
        'workflow' => 'Workflow',
        'scheduler' => 'Scheduler',
        'add-process' => 'Add Process',
        'fields-configuration' => 'Fields Configuration',
        'integration' => 'Integration',
    ];

    public function up(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $hasRights = $this->tableExists('tblgroupwise_rights_g2g');

        foreach (self::MODULE_PARENTS as $moduleKey => $parentId) {
            $nextSort = (int) DB::table('tblmenumaster_g2g')
                ->where('parent_id', $parentId)
                ->max('sort_order');

            foreach (self::CONSOLES as $slug => $label) {
                $accessLink = "/platform-services/{$slug}?module={$moduleKey}";

                // Idempotent: re-running this migration (e.g. after a rollback
                // during testing) must not duplicate rows.
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
                    // `page_type` is an enum('blade','page','link'), nullable but not
                    // blank-able — an empty string trips MariaDB's strict-mode data
                    // truncation error. Null is the existing convention for a row this
                    // enum does not describe (several already carry it).
                    'page_type' => null,
                    'access_link' => $accessLink,
                    'icon' => '',
                    'status' => 1,
                    'sort_order' => $nextSort,
                    // NULL = global, per tblmenumaster_g2gModel's own note — the same
                    // convention every other cross-tenant menu row here already uses.
                    'sub_institute_id' => null,
                    'menu_type' => '',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if (! $hasRights) {
                    continue;
                }

                $parentRights = DB::table('tblgroupwise_rights_g2g')
                    ->where('menu_id', $parentId)
                    ->where('can_view', 1)
                    ->get(['sub_institute_id', 'profile_id']);

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

    public function down(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $menuIds = DB::table('tblmenumaster_g2g')
            ->whereIn('parent_id', array_values(self::MODULE_PARENTS))
            ->where('access_link', 'like', '/platform-services/%?module=%')
            ->pluck('id');

        if ($menuIds->isEmpty()) {
            return;
        }

        if ($this->tableExists('tblgroupwise_rights_g2g')) {
            DB::table('tblgroupwise_rights_g2g')->whereIn('menu_id', $menuIds)->delete();
        }

        DB::table('tblmenumaster_g2g')->whereIn('id', $menuIds)->delete();
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
