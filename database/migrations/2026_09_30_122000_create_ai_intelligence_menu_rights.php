<?php

use App\Support\RoleKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Real menu rows for 11 of the 12 "AI & Intelligence" capabilities — the
 * twelfth, Agent Management (`ai.agents`), already rides a real, pre-existing
 * menu row (`/module/agentic-ai/agentic-library`), the same reuse pattern
 * already used for Platform Services' RBAC/Onboarding/Mobile App Rights.
 *
 * Same pattern as `2026_09_30_110000_create_event_bus_and_audit_menu_rights.php`:
 * no per-module concept for any of these (AI & Intelligence has none at
 * all - confirmed by reading `AI_CAPABILITIES`, unlike Platform Services'
 * six-module set), so one fixed row each, seeded administrator-only. No
 * revoke companion needed here the way Platform Services' module rows
 * needed one - these are brand new rows with nothing inherited yet.
 *
 * Links match `App\Http\Controllers\AI\CapabilityController::TABLES`'s keys
 * and the frontend `AI_CAPABILITIES` registry's own `slug` field exactly,
 * confirmed against both before writing this. Knowledge & RAG and Knowledge
 * Graph have no backend routes at all yet (status `in-progress`) - their
 * rows exist so an admin can already decide who will see them once built,
 * matching how Platform Services' own in-progress entries (Workflow, Add
 * Process) were never held back from having a real row either.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_09_30_122000_create_ai_intelligence_menu_rights.php
 *   php artisan migrate --database=live --path=database/migrations/2026_09_30_122000_create_ai_intelligence_menu_rights.php
 */
return new class extends Migration
{
    private const PARENT_ID = 1;

    private const ROWS = [
        ['label' => 'AI Providers', 'link' => '/ai/providers', 'roles' => ['administrator']],
        ['label' => 'Model Management', 'link' => '/ai/models', 'roles' => ['administrator']],
        ['label' => 'Template Management', 'link' => '/ai/prompts', 'roles' => ['administrator']],
        ['label' => 'AI Policies', 'link' => '/ai/policies', 'roles' => ['administrator']],
        ['label' => 'Conversational AI', 'link' => '/ai/conversational-ai', 'roles' => ['administrator']],
        ['label' => 'Knowledge & RAG', 'link' => '/ai/knowledge-rag', 'roles' => ['administrator']],
        ['label' => 'Recommendation Engine', 'link' => '/ai/recommendations', 'roles' => ['administrator']],
        ['label' => 'Knowledge Graph', 'link' => '/ai/knowledge-graph', 'roles' => ['administrator']],
        ['label' => 'AI Evaluation', 'link' => '/ai/evaluation', 'roles' => ['administrator']],
        ['label' => 'Usage & Cost', 'link' => '/ai/usage-cost', 'roles' => ['administrator']],
        ['label' => 'AI Audit', 'link' => '/ai/audit', 'roles' => ['administrator']],
    ];

    public function up(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $hasRights = $this->tableExists('tblgroupwise_rights_g2g') && $this->tableExists('tbluserprofilemaster');
        $profiles = $hasRights
            ? DB::table('tbluserprofilemaster')->get(['id', 'sub_institute_id', 'role_key', 'name'])
            : collect();

        foreach (self::ROWS as $row) {
            $menuId = $this->createMenuRow($row['label'], $row['link']);

            if ($menuId === null || ! $hasRights) {
                continue;
            }

            foreach ($profiles as $profile) {
                if (! in_array(RoleKey::fromProfile($profile), $row['roles'], true)) {
                    continue;
                }

                $exists = DB::table('tblgroupwise_rights_g2g')
                    ->where('menu_id', $menuId)
                    ->where('profile_id', $profile->id)
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('tblgroupwise_rights_g2g')->insert([
                    'sub_institute_id' => $profile->sub_institute_id,
                    'menu_id' => $menuId,
                    'profile_id' => $profile->id,
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

    public function down(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $links = array_column(self::ROWS, 'link');

        $ids = DB::table('tblmenumaster_g2g')
            ->where('parent_id', self::PARENT_ID)
            ->whereIn('access_link', $links)
            ->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        if ($this->tableExists('tblgroupwise_rights_g2g')) {
            DB::table('tblgroupwise_rights_g2g')->whereIn('menu_id', $ids)->delete();
        }

        DB::table('tblmenumaster_g2g')->whereIn('id', $ids)->delete();
    }

    private function createMenuRow(string $label, string $accessLink): ?int
    {
        $existing = DB::table('tblmenumaster_g2g')
            ->where('parent_id', self::PARENT_ID)
            ->where('access_link', $accessLink)
            ->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        $nextSort = (int) DB::table('tblmenumaster_g2g')
            ->where('parent_id', self::PARENT_ID)
            ->max('sort_order');

        return DB::table('tblmenumaster_g2g')->insertGetId([
            'menu_name' => $label,
            'parent_id' => self::PARENT_ID,
            'level' => 2,
            'page_type' => null,
            'access_link' => $accessLink,
            'icon' => '',
            'status' => 0,
            'sort_order' => $nextSort + 1,
            'sub_institute_id' => null,
            'menu_type' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
