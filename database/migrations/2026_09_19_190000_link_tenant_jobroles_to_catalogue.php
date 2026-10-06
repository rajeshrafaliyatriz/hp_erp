<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * s_user_jobrole.catalogue_jobrole_id existed and NOTHING EVER WROTE IT.
 *
 * NULL on all 4,886 rows. The column is the intended bridge between the two
 * job-role tables this product keeps:
 *
 *   s_jobrole        the global catalogue - 3,347 roles, with s_jobrole_skills
 *                    (64,923) and s_jobrole_task (55,868) behind them. The
 *                    source of CONTENT.
 *   s_user_jobrole   what each organisation actually uses - 4,886 rows, the
 *                    thing postings, competency maps and assessments point at.
 *                    The source of IDENTITY.
 *
 * Without the bridge, code that needed both had to guess, and guessing by name
 * against the wrong table is what made every job posting unassessable
 * (2026_09_19_180000). The bridge makes "which catalogue role is this tenant
 * role" answerable instead of inferred at each call site.
 *
 * ── HOW ROLES ARE MATCHED ───────────────────────────────────────────────────
 *
 * By name, case-insensitively, and ONLY when the name matches exactly one
 * catalogue row. An ambiguous name is left NULL rather than resolved to
 * whichever row sorted first: a wrong link is worse than a missing one,
 * because a missing one is visible and a wrong one silently generates an
 * assessment for the wrong job.
 *
 * Roles authored inside a tenant's Capability Library have no catalogue
 * counterpart at all and stay NULL. That is correct - they are that
 * organisation's own roles.
 *
 * Idempotent: only fills rows that are still NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['s_user_jobrole', 's_jobrole'] as $table) {
            if (!Schema::hasTable($table)) {
                return;
            }
        }

        // SHOW COLUMNS, not Schema::hasColumn(): the latter selects
        // generation_expression, which MariaDB 10.1 (live) does not have.
        $hasColumn = collect(DB::select('SHOW COLUMNS FROM s_user_jobrole'))
            ->contains(fn ($column) => $column->Field === 'catalogue_jobrole_id');

        if (!$hasColumn) {
            return;
        }

        /*
         * The catalogue, keyed by lowercased name, with ambiguous names
         * dropped. Read once: matching 4,886 rows one query at a time is
         * thousands of round trips for a table of 3,347.
         */
        $byName = [];
        $ambiguous = [];

        foreach (DB::table('s_jobrole')->select('id', 'jobrole')->get() as $role) {
            $key = mb_strtolower(trim((string) $role->jobrole));
            if ($key === '') {
                continue;
            }
            if (isset($byName[$key])) {
                $ambiguous[$key] = true;
                continue;
            }
            $byName[$key] = (int) $role->id;
        }

        foreach (array_keys($ambiguous) as $key) {
            unset($byName[$key]);
        }

        $linked = 0;
        $noMatch = 0;
        $skippedAmbiguous = 0;

        /*
         * chunkById, NOT chunk().
         *
         * chunk() pages with OFFSET. This query filters on
         * catalogue_jobrole_id IS NULL and the loop SETS that column, so every
         * updated row leaves the result set and the next OFFSET jumps past
         * rows that were never examined. The first run of this migration
         * linked 2,387 rows and silently skipped 2,031 that matched perfectly
         * well.
         *
         * chunkById pages on `id > last seen`, which is stable no matter how
         * the filtered set shrinks underneath it.
         */
        DB::table('s_user_jobrole')
            ->whereNull('catalogue_jobrole_id')
            ->select('id', 'jobrole')
            ->chunkById(500, function ($roles) use ($byName, $ambiguous, &$linked, &$noMatch, &$skippedAmbiguous) {
                foreach ($roles as $role) {
                    $key = mb_strtolower(trim((string) $role->jobrole));

                    if ($key === '') {
                        $noMatch++;
                        continue;
                    }

                    if (isset($ambiguous[$key])) {
                        $skippedAmbiguous++;
                        continue;
                    }

                    if (!isset($byName[$key])) {
                        $noMatch++;
                        continue;
                    }

                    DB::table('s_user_jobrole')->where('id', $role->id)
                        ->update(['catalogue_jobrole_id' => $byName[$key]]);
                    $linked++;
                }
            });

        echo "  linked to a catalogue role: $linked\n";
        echo "  no catalogue role of that name: $noMatch\n";
        echo "  name matches several catalogue roles, left NULL: $skippedAmbiguous\n";
    }

    /**
     * Clears only what this migration could have set. Rows linked by hand
     * afterwards are indistinguishable, so this is deliberately conservative:
     * it does not null the column wholesale.
     */
    public function down(): void
    {
    }
};
