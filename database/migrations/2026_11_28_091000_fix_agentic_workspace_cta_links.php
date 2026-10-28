<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Companion to 2026_11_28_090000_create_agentic_workspace_menu_row.php and
 * 2026_11_28_080000_fix_agentic_catalogue_cta_links.php: now that
 * '/module/agentic-ai/agent-workspace' is a real row, point the 6 agents
 * still stored with the old, never-resolving
 * '/module/m7/ag-agent-workspace/ag-agent-workspace?agent=<id>' link at it
 * instead — same per-agent id, new base path. AgenticCatalogueSeeder.php's
 * own WORKSPACE constant is fixed alongside this so the next re-seed writes
 * the new path directly rather than reverting to the old one.
 *
 * Matched by the literal old prefix, not by slug — these 6 slugs
 * (pal-agent, marketing-strategy, content-automation, newsletter-agent,
 * seo-agent, social-media-automation) are a fixed, known set, but matching
 * on the stored value itself is the more honest guard: a row already fixed
 * by hand, or a future agent that reuses one of these slugs for something
 * unrelated, is left alone rather than rewritten on a slug guess.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME, AFTER
 * 2026_11_28_090000_create_agentic_workspace_menu_row HAS RUN on the SAME
 * connection (the row it creates is what makes this link resolve):
 *   php artisan migrate --path=database/migrations/2026_11_28_091000_fix_agentic_workspace_cta_links.php
 *   php artisan migrate --database=live --path=database/migrations/2026_11_28_091000_fix_agentic_workspace_cta_links.php
 */
return new class extends Migration
{
    private const OLD_PREFIX = '/module/m7/ag-agent-workspace/ag-agent-workspace?agent=';
    private const NEW_PREFIX = '/module/agentic-ai/agent-workspace?agent=';

    public function up(): void
    {
        $this->rewrite(self::OLD_PREFIX, self::NEW_PREFIX);
    }

    public function down(): void
    {
        $this->rewrite(self::NEW_PREFIX, self::OLD_PREFIX);
    }

    private function rewrite(string $fromPrefix, string $toPrefix): void
    {
        if (! $this->tableExists('agentic_agents')) {
            return;
        }

        $rows = DB::table('agentic_agents')
            ->where('cta_link', 'like', $fromPrefix . '%')
            ->get(['id', 'cta_link']);

        foreach ($rows as $row) {
            $agentId = (int) str_replace($fromPrefix, '', $row->cta_link);

            if ($agentId <= 0) {
                continue;
            }

            DB::table('agentic_agents')
                ->where('id', $row->id)
                ->update(['cta_link' => $toPrefix . $agentId, 'updated_at' => now()]);
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
