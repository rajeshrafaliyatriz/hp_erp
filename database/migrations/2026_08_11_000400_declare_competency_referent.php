<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * X-20 — WRITE THE DECLARED REFERENT INTO THE SCHEMA ITSELF.
 *
 * G-DATA-11 existed because `competency_id` had no declared referent, so two
 * meanings grew side by side for months and neither was wrong on its face. The
 * migration that fixed the DATA does not stop that happening again; a comment on
 * the column does, because it is the first thing anyone reads.
 *
 *     competency_id -> `competency`.id  (the KASBA BUNDLE), always, everywhere.
 *     NEVER a skill id. A skill is ONE OF FIVE KASBA DIMENSIONS inside a bundle
 *     (Q-A2), not a synonym for the bundle.
 *
 * Comments are cheap and load-bearing here. `SHOW FULL COLUMNS` shows them, every
 * GUI shows them, and they travel with a schema dump - unlike a design document
 * nobody opens while writing a query.
 */
return new class extends Migration
{
    private const COMMENT =
        'FK -> competency.id (a KASBA BUNDLE). NEVER a skill id: a skill is one of '
        . 'five KASBA dimensions inside a bundle (Q-A2, G-DATA-11).';

    private const TABLES = [
        'course_competency_map',
        'jobrole_competency_map',
        'competency_kasba_item',
        's_competency_plan_actions',
        's_competency_development_plans',
        's_competency_certifications',
        'lms_assignments',
        'certification_competency_map',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'competency_id')) {
                continue;
            }

            // Read the column's real type first (R15). Rewriting a column with a
            // guessed type is how a bigint quietly becomes an int.
            $col = collect(DB::select("SHOW COLUMNS FROM `$table` LIKE 'competency_id'"))->first();
            if (!$col) {
                continue;
            }

            $type = $col->Type;
            $null = $col->Null === 'YES' ? 'NULL' : 'NOT NULL';
            $default = $col->Default !== null ? " DEFAULT '" . addslashes($col->Default) . "'" : '';

            DB::statement(
                "ALTER TABLE `$table` MODIFY `competency_id` $type $null$default COMMENT '"
                . addslashes(self::COMMENT) . "'"
            );
        }
    }

    /**
     * DELIBERATELY EMPTY. Removing the declaration would restore the exact
     * condition that produced G-DATA-11.
     */
    public function down(): void
    {
        // no-op by design.
    }
};
