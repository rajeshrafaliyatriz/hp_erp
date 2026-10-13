<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill for the stale-module-code bug AgenticCatalogueSeeder.php's own
 * `agents()` array is fixed alongside this migration: 7 of the platform
 * catalogue's agents had a `cta_link` built from a legacy module shorthand
 * ('/module/m6/tm-tasks/tm-tasks', '/module/m2/...', '/module/m1/...',
 * '/module/m3/...', '/module/m4/...') that this app's content-map has never
 * recognised — confirmed directly against `hooks/use-content-map.ts`'s
 * `CONTENT_MAP_LOADERS`, which keys on the real tblmenumaster_g2g id, not a
 * legacy code. Every one of these "Open X" buttons in the Agentic Library
 * landed on the generic "not available yet" screen.
 *
 * The seeder is idempotent and re-run periodically ("Catalogue copy is owned
 * by this seeder and refreshed on each run" - its own docblock), so fixing
 * only the already-seeded rows here, without also fixing agents() itself,
 * would have been undone by the next re-seed. Both are fixed together.
 *
 * Matched by `slug`, not `id` — ids are NOT guaranteed to match between the
 * default and live connections (confirmed directly: pal-agent happens to be
 * 19 on both, but that is coincidence, not a guarantee this migration should
 * rely on).
 *
 * The 6 agents whose cta_link is the 'workspace' placeholder
 * (pal-agent/marketing-strategy/content-automation/newsletter-agent/
 * seo-agent/social-media-automation) are NOT touched here — their resolved
 * link ('/module/m7/ag-agent-workspace/...') has the identical stale-code
 * problem, but there is no real menu row for the workspace screen to point
 * at instead. That half of the bug is fixed on the frontend
 * (ag-agent-library.tsx's followCta()) by opening the agent's own detail
 * Sheet instead of navigating to a dead route, not by correcting this link.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_11_28_080000_fix_agentic_catalogue_cta_links.php
 *   php artisan migrate --database=live --path=database/migrations/2026_11_28_080000_fix_agentic_catalogue_cta_links.php
 */
return new class extends Migration
{
    private const FIXES = [
        'task-management'    => ['/module/m6/tm-tasks/tm-tasks', '/module/task-management/my-tasks'],
        'skill-generator'    => ['/module/m2/cm-competency-library/cm-competency-library', '/module/capability-intelligence/competency-library'],
        'job-role-generator' => ['/module/m2/cm-libraries-taxonomy/cm-libraries-taxonomy', '/module/capability-intelligence/capability-library'],
        'sanity-check'       => ['/module/m2/cm-employee-profiles/cm-employee-profiles', '/module/talent-management/employee-profiles'],
        'course-generator'   => ['/module/m4/lms-administration/create-course', '/module/lms/administration/course-builder'],
        'smart-recruitment'  => ['/module/m3/recruitment/recruitment', '/module/talent-management/recruitment'],
        'website-analyzer'   => ['/module/m1/org-setup/org-profile', '/module/organizational-management/organization-setup/organization-profile'],
    ];

    public function up(): void
    {
        $this->apply(fn ($old, $new) => $new, fromIndex: 0, toIndex: 1);
    }

    public function down(): void
    {
        $this->apply(fn ($old, $new) => $old, fromIndex: 1, toIndex: 0);
    }

    private function apply(callable $pick, int $fromIndex, int $toIndex): void
    {
        if (! $this->tableExists('agentic_agents')) {
            return;
        }

        foreach (self::FIXES as $slug => $pair) {
            DB::table('agentic_agents')
                ->where('slug', $slug)
                ->where('cta_link', $pair[$fromIndex])
                ->update(['cta_link' => $pair[$toIndex], 'updated_at' => now()]);
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
