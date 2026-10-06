<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Remove rows that point at things which no longer exist.
 *
 * Both sets are inert - they grant nothing and match nothing - and both were
 * cluttering the counts every audit in this engagement reported. A number that
 * is wrong in a document is a number somebody will act on.
 *
 * ── 1. jobrole_competency_map -> a job role that is GONE ────────────────────
 *
 * 23 rows on live, 0 on app, across 7 distinct jobrole_id values. Checked
 * before deleting: those ids are not soft-deleted, they are absent from
 * s_user_jobrole entirely, so there is nothing to recover and nothing to
 * re-point them at. A competency requirement for a role that does not exist can
 * never be evaluated by anything.
 *
 * NO CASCADE IS ADDED, and that is a finding rather than an omission:
 * JobRoleMergeService ALREADY repoints this table correctly on a merge
 * (:279-282), and every application delete path soft-deletes. So current code
 * did not produce these; they came from outside the app. Adding a defensive
 * cascade to a path that is already correct would imply the code was at fault
 * and send the next reader looking in the wrong place.
 *
 * ── 2. tblgroupwise_rights_g2g -> a profile that does not exist ─────────────
 *
 * 760 rows on live across 5 profile ids (31-35), 0 on app. The profiles are
 * absent from tbluserprofilemaster, so nobody can hold them and no sidebar can
 * ever resolve them. They grant nothing today - but they inflated every rights
 * count in AUTHORIZATION-BOUNDARY.md, and they would inflate the next one.
 *
 * 760 is not a rounding error against a table of ~5,200 rows: it is 15% of it.
 *
 * ── NOT REVERSIBLE, AND SAID PLAINLY ────────────────────────────────────────
 *
 * down() cannot restore these. Recreating rights rows for profiles that do not
 * exist would be recreating the defect, and the mappings reference role ids
 * that are gone. If either set turns out to matter, it is a restore from
 * backup, not a rollback - which is why both counts are printed as they go.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_09_30_120100_clear_orphaned_competency_and_rights_rows.php
 *   php artisan migrate --database=live --path=database/migrations/2026_09_30_120100_clear_orphaned_competency_and_rights_rows.php
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->clearOrphanedCompetencyMappings();
        $this->clearGhostRightsRows();
    }

    /** See the docblock: these cannot be put back, and should not be. */
    public function down(): void
    {
    }

    private function clearOrphanedCompetencyMappings(): void
    {
        if (!$this->tableExists('jobrole_competency_map') || !$this->tableExists('s_user_jobrole')) {
            return;
        }

        // Resolved to ids first rather than deleting through a join: MariaDB
        // 10.1 is the older of the two hosts and a DELETE..JOIN there is a
        // different dialect from the one this would be tested against.
        $orphans = DB::table('jobrole_competency_map as m')
            ->leftJoin('s_user_jobrole as r', 'r.id', '=', 'm.jobrole_id')
            ->whereNull('r.id')
            ->pluck('m.id');

        if ($orphans->isEmpty()) {
            echo '  jobrole_competency_map: no orphaned rows' . PHP_EOL;

            return;
        }

        // Chunked because whereIn with a few thousand ids is a query some
        // drivers refuse rather than slow down.
        $deleted = 0;

        foreach ($orphans->chunk(500) as $chunk) {
            $deleted += DB::table('jobrole_competency_map')->whereIn('id', $chunk->all())->delete();
        }

        echo '  jobrole_competency_map: deleted ' . $deleted . ' row(s) pointing at a job role that no longer exists' . PHP_EOL;
    }

    private function clearGhostRightsRows(): void
    {
        if (!$this->tableExists('tblgroupwise_rights_g2g') || !$this->tableExists('tbluserprofilemaster')) {
            return;
        }

        $ghosts = DB::table('tblgroupwise_rights_g2g as g')
            ->leftJoin('tbluserprofilemaster as p', 'p.id', '=', 'g.profile_id')
            ->whereNull('p.id')
            ->pluck('g.id');

        if ($ghosts->isEmpty()) {
            echo '  tblgroupwise_rights_g2g: no ghost rows' . PHP_EOL;

            return;
        }

        $deleted = 0;

        foreach ($ghosts->chunk(500) as $chunk) {
            $deleted += DB::table('tblgroupwise_rights_g2g')->whereIn('id', $chunk->all())->delete();
        }

        echo '  tblgroupwise_rights_g2g: deleted ' . $deleted . ' row(s) for profiles that do not exist' . PHP_EOL;
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
