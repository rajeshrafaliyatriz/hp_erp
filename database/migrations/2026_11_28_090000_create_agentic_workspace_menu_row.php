<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A real tblmenumaster_g2g row for the Agent Workspace screen
 * (components/domain/agentic/ag-agent-workspace.tsx) — the one Agentic AI
 * screen that never had one. Its 7 siblings (Agent Dashboard, Create Agent,
 * Run Log, Analytics, Multi-Agent, Reflection, Agentic Library) all got a
 * real row when the module was built; this one was always reached through a
 * placeholder path, '/module/m7/ag-agent-workspace/...', that was never a
 * real access_link anywhere — confirmed directly against this table and
 * against hooks/use-content-map.ts's CONTENT_MAP_LOADERS, not assumed. Every
 * catalogue agent whose "Open X" button pointed there landed on the generic
 * "not available yet" screen; the frontend's own temporary workaround
 * (opening the agent's detail Sheet instead) is superseded by this + the
 * matching g2gv0 commit, which makes the button open the real screen.
 *
 * Same shape as its 7 siblings exactly (page_type 'page', icon
 * 'mdi mdi-file-chart', status 1, sub_institute_id null) — status 1, not 0:
 * "Agentic AI" the top-level parent is hidden from the sidebar by a
 * FRONTEND filter (SIDEBAR_HIDDEN_ACCESS_LINKS in
 * use-sidebar-navigation.ts), not a DB status flag, so its real children —
 * this one included — stay status 1 like the rest, reachable by direct
 * link/resolveAccessLink() without ever appearing in the rendered sidebar.
 *
 * Rights: granted to every (profile_id, sub_institute_id) pair that already
 * has can_view=1 on the sibling Agentic Library row
 * ('/module/agentic-ai/agentic-library') on THIS SAME connection — whoever
 * can already reach the Library can already reach every agent's card, so
 * granting the same set here changes no one's actual access, it just lets
 * the screen their card's button already promised finally open. Resolved by
 * access_link, not a hardcoded id — ids are not guaranteed to match between
 * the default and live connections.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_11_28_090000_create_agentic_workspace_menu_row.php
 *   php artisan migrate --database=live --path=database/migrations/2026_11_28_090000_create_agentic_workspace_menu_row.php
 */
return new class extends Migration
{
    private const PARENT_ID = 186;
    private const ACCESS_LINK = '/module/agentic-ai/agent-workspace';
    private const SIBLING_ACCESS_LINK = '/module/agentic-ai/agentic-library';
    private const LABEL = 'Agent Workspace';

    public function up(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $menuId = $this->createMenuRow();

        if ($menuId === null || ! $this->tableExists('tblgroupwise_rights_g2g')) {
            return;
        }

        $siblingId = DB::table('tblmenumaster_g2g')
            ->where('parent_id', self::PARENT_ID)
            ->where('access_link', self::SIBLING_ACCESS_LINK)
            ->value('id');

        if ($siblingId === null) {
            return;
        }

        $siblingGrants = DB::table('tblgroupwise_rights_g2g')
            ->where('menu_id', $siblingId)
            ->where('can_view', 1)
            ->get();

        foreach ($siblingGrants as $grant) {
            $exists = DB::table('tblgroupwise_rights_g2g')
                ->where('menu_id', $menuId)
                ->where('profile_id', $grant->profile_id)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('tblgroupwise_rights_g2g')->insert([
                'sub_institute_id' => $grant->sub_institute_id,
                'menu_id' => $menuId,
                'profile_id' => $grant->profile_id,
                'can_view' => 1,
                'can_add' => 0,
                'can_edit' => 0,
                'can_delete' => 0,
                'dashboard_right' => 0,
                'is_mobile' => $grant->is_mobile,
                'created_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $menuId = DB::table('tblmenumaster_g2g')
            ->where('parent_id', self::PARENT_ID)
            ->where('access_link', self::ACCESS_LINK)
            ->value('id');

        if ($menuId === null) {
            return;
        }

        if ($this->tableExists('tblgroupwise_rights_g2g')) {
            DB::table('tblgroupwise_rights_g2g')->where('menu_id', $menuId)->delete();
        }

        DB::table('tblmenumaster_g2g')->where('id', $menuId)->delete();
    }

    private function createMenuRow(): ?int
    {
        $existing = DB::table('tblmenumaster_g2g')
            ->where('parent_id', self::PARENT_ID)
            ->where('access_link', self::ACCESS_LINK)
            ->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        $nextSort = (int) DB::table('tblmenumaster_g2g')
            ->where('parent_id', self::PARENT_ID)
            ->max('sort_order');

        return DB::table('tblmenumaster_g2g')->insertGetId([
            'menu_name' => self::LABEL,
            'parent_id' => self::PARENT_ID,
            'level' => 2,
            'page_type' => 'page',
            'access_link' => self::ACCESS_LINK,
            'icon' => 'mdi mdi-file-chart',
            'status' => 1,
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
