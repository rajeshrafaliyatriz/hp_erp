<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * talent_job_postings.jobrole_id held ids from the WRONG TABLE.
 *
 * ── WHAT WENT WRONG ─────────────────────────────────────────────────────────
 *
 * The column exists so a posting can find the competencies its role requires:
 * the assessment builder joins `jobrole_competency_map` on it
 * (AiAssessmentController:138). That table is keyed by `s_user_jobrole.id` -
 * RoleCompetencyMapController validates its own jobrole_id against
 * `s_user_jobrole` before writing (line 122).
 *
 * But both writers resolved the id against `s_jobrole`, the GLOBAL catalogue:
 * talent_jobpostingcontroller::resolveJobroleId(), and the earlier backfill in
 * 2026_09_19_160000. A catalogue id stores cleanly, passes `exists`, and reads
 * plausibly in the row - and matches nothing in the map.
 *
 * Measured on live before this migration:
 *
 *   postings with a jobrole_id                     125
 *   whose id names the same role in s_jobrole      125
 *   whose id has any row in jobrole_competency_map   0
 *
 * Not one posting in the installation could be assessed, and the column looked
 * correctly populated the whole time - the same "none and hidden are
 * indistinguishable" shape as the rest of this class of bug.
 *
 * ── WHY IT LOOKED FINE ──────────────────────────────────────────────────────
 *
 * 266 of tenant 6's 270 roles happen to share a NAME with a catalogue row, so
 * name-matching found something almost every time. The roles it failed on are
 * the ones somebody authored in the Capability Library - precisely the case
 * reported as "I added the role and the posting does not work".
 *
 * ── WHAT THIS DOES ──────────────────────────────────────────────────────────
 *
 * Re-resolves each posting's jobrole_id from its TITLE against that tenant's
 * own s_user_jobrole, and leaves the row alone when no tenant role matches -
 * a NULL that says "unresolved" is better than an id that points elsewhere.
 *
 * Guarded so it is safe to run on a database that does not have these tables,
 * and idempotent: running it twice resolves to the same ids.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['talent_job_postings', 's_user_jobrole'] as $table) {
            if (!Schema::hasTable($table)) {
                return;
            }
        }

        /*
         * SHOW COLUMNS, not Schema::hasColumn().
         *
         * Laravel's column introspection selects `generation_expression` from
         * information_schema, which MariaDB 10.1 - what live runs - does not
         * have. hasColumn() therefore throws 1054 rather than answering, and
         * the guard meant to make this migration safe is what would break it.
         */
        $hasJobroleId = collect(DB::select("SHOW COLUMNS FROM talent_job_postings"))
            ->contains(fn ($column) => $column->Field === 'jobrole_id');

        if (!$hasJobroleId) {
            return;
        }

        $repointed = 0;
        $cleared = 0;
        $unchanged = 0;

        DB::table('talent_job_postings')
            ->select('id', 'sub_institute_id', 'title', 'jobrole_id')
            ->orderBy('id')
            ->chunk(200, function ($postings) use (&$repointed, &$cleared, &$unchanged) {
                foreach ($postings as $posting) {
                    $title = trim((string) $posting->title);

                    if ($title === '') {
                        $unchanged++;
                        continue;
                    }

                    $tenantRoleId = DB::table('s_user_jobrole')
                        ->where('sub_institute_id', $posting->sub_institute_id)
                        ->where('jobrole', $title)
                        ->whereNull('deleted_at')
                        ->value('id');

                    // Already correct - nothing to do, and running twice is safe.
                    if ($tenantRoleId !== null && (int) $tenantRoleId === (int) $posting->jobrole_id) {
                        $unchanged++;
                        continue;
                    }

                    if ($tenantRoleId !== null) {
                        DB::table('talent_job_postings')->where('id', $posting->id)
                            ->update(['jobrole_id' => (int) $tenantRoleId]);
                        $repointed++;
                        continue;
                    }

                    /*
                     * No role of that name in this tenant's library. The id
                     * currently there is a catalogue id that cannot join, so
                     * it is cleared: an honest NULL tells the assessment
                     * screen "this posting has no role mapped", which is true
                     * and fixable. Leaving it would keep claiming a mapping
                     * that resolves to nothing.
                     */
                    if ($posting->jobrole_id !== null) {
                        DB::table('talent_job_postings')->where('id', $posting->id)
                            ->update(['jobrole_id' => null]);
                        $cleared++;
                    } else {
                        $unchanged++;
                    }
                }
            });

        echo "  repointed to the tenant library: $repointed\n";
        echo "  cleared (no tenant role of that name): $cleared\n";
        echo "  already correct or empty title: $unchanged\n";
    }

    /**
     * No down(). The previous values were ids from the wrong table that matched
     * nothing; restoring them would reinstate the bug, and nothing depended on
     * them because nothing could join them.
     */
    public function down(): void
    {
    }
};
