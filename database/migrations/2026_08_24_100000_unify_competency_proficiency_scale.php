<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * THE BROKEN LINK BETWEEN A FRAMEWORK'S TARGET AND A ROLE'S TARGET.
 *
 * ── WHAT WAS WRONG ──────────────────────────────────────────────────────────
 *
 * The same idea - "what level does this competency need to be at" - was stored
 * in two tables in two INCOMPATIBLE TYPES:
 *
 *     s_competency_framework_items.required_proficiency   varchar(50)  'Level 3'
 *     jobrole_competency_map.required_proficiency         tinyint(3)    3
 *
 * So a framework's target could never be compared with, defaulted into, or
 * reconciled against a role's target. Not "hard to compare" - impossible: one is
 * a sentence, the other is a number. That is the broken link between frameworks
 * and job roles, and it is why the two sets of mappings drift apart with nothing
 * to detect it.
 *
 * Measured before the change - the string side is completely regular, so the
 * conversion is lossless:
 *
 *     'Level 1' 29    'Level 2' 27    'Level 3' 39    'Level 4' 26    'Level 5' 34
 *                                                                     = 155 rows
 *
 * ── THE SCALE IS 1-5, MEASURED RATHER THAN ASSUMED ──────────────────────────
 *
 *     s_proficiency_knowledge / _ability / _attitude / _behaviour   levels 1-5
 *     s_competency_framework_items                                  Level 1-5
 *     competency_kasba_rating                                       values 2-4
 *
 * The one outlier is the 48 generic rows in `s_proficiency_levels`, which run to
 * SIX levels. They are left alone: nothing operational reads level 6, and
 * rewriting reference content is not this migration's business.
 *
 * ── AND THE THING A COMPETENCY COULD NOT DO AT ALL ──────────────────────────
 *
 * `s_proficiency_levels` is keyed by `skill_id`. There is no `competency_id`
 * column anywhere, so a competency could not say what L1 versus L4 LOOKS LIKE -
 * the behavioural descriptor that makes a scale mean something. Without it a
 * target level is a number with no definition behind it, which is why the
 * proficiency scale never felt real.
 *
 * `competency_proficiency_levels` fixes that, and deliberately holds ONLY
 * AUTHORED OVERRIDES. A competency with no rows falls back to the organisation's
 * generic scale at read time. Seeding 227 competencies x 5 levels with 1,135
 * copies of the same boilerplate would make every competency look authored when
 * none of them were - and an override table is the same shape as the framework
 * default / role override rule this scale is being unified for.
 *
 * RUN ON BOTH DATABASES:
 *   php artisan migrate --path=database/migrations/2026_08_24_100000_unify_competency_proficiency_scale.php
 *   php artisan migrate --database=live --path=database/migrations/2026_08_24_100000_unify_competency_proficiency_scale.php
 */
return new class extends Migration
{
    private const ITEMS = 's_competency_framework_items';
    private const LEVELS = 'competency_proficiency_levels';

    public function up(): void
    {
        $this->normaliseFrameworkTargets();
        $this->createCompetencyLevels();
    }

    /**
     * varchar('Level 3') -> tinyint(3), so the two target columns can finally be
     * compared, defaulted and reconciled.
     */
    private function normaliseFrameworkTargets(): void
    {
        if (!$this->tableExists(self::ITEMS) || !$this->columnExists(self::ITEMS, 'required_proficiency')) {
            return;
        }

        // Already a number? Then this has run before. `varchar` is the only
        // shape that needs converting.
        if (!str_contains(strtolower($this->columnType(self::ITEMS, 'required_proficiency')), 'char')) {
            return;
        }

        // 'Level 3' -> '3'. REPLACE rather than REGEXP_REPLACE: live is MariaDB
        // 10.1 and the values are entirely regular, so the simple form is both
        // sufficient and portable.
        DB::statement("
            UPDATE `" . self::ITEMS . "`
               SET required_proficiency = TRIM(REPLACE(REPLACE(required_proficiency, 'Level', ''), 'level', ''))
             WHERE required_proficiency IS NOT NULL
               AND required_proficiency <> ''
        ");

        /*
         * Anything that is not a plain 1-5 becomes NULL rather than being
         * coerced. A value the scale cannot express is missing information, and
         * silently rounding it into a real level would invent a requirement
         * somebody could be measured against.
         */
        DB::statement("
            UPDATE `" . self::ITEMS . "`
               SET required_proficiency = NULL
             WHERE required_proficiency IS NOT NULL
               AND (required_proficiency NOT REGEXP '^[0-9]+$'
                    OR CAST(required_proficiency AS UNSIGNED) < 1
                    OR CAST(required_proficiency AS UNSIGNED) > 5)
        ");

        DB::statement('ALTER TABLE `' . self::ITEMS . '` MODIFY `required_proficiency` TINYINT(3) UNSIGNED NULL');
    }

    /**
     * What L1 versus L4 actually means, per competency.
     *
     * Sparse by design - see the header. No rows for a competency means "use the
     * organisation's generic scale", not "this competency has no scale".
     */
    private function createCompetencyLevels(): void
    {
        if ($this->tableExists(self::LEVELS) || !$this->tableExists('competency')) {
            return;
        }

        DB::statement('
            CREATE TABLE `' . self::LEVELS . '` (
                `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `sub_institute_id` BIGINT UNSIGNED NULL,
                `competency_id`    BIGINT UNSIGNED NOT NULL,
                `level`            TINYINT UNSIGNED NOT NULL,
                `descriptor`       TEXT NULL,
                `indicators`       TEXT NULL,
                `created_by`       BIGINT UNSIGNED NULL,
                `updated_by`       BIGINT UNSIGNED NULL,
                `created_at`       TIMESTAMP NULL,
                `updated_at`       TIMESTAMP NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_cpl_competency_level` (`competency_id`, `level`),
                KEY `idx_cpl_tenant` (`sub_institute_id`),
                CONSTRAINT `cpl_competency_id_foreign`
                    FOREIGN KEY (`competency_id`) REFERENCES `competency` (`id`)
                    ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');

        /*
         * ON DELETE CASCADE, unlike the SET NULL used elsewhere in this work: a
         * level descriptor has no meaning without its competency. It is not a
         * relationship that should survive as an orphan, it is part of the
         * competency's definition.
         *
         * The unique key is what makes a level idempotent to author - one
         * descriptor per (competency, level), so saving twice updates rather
         * than duplicating.
         */
    }

    public function down(): void
    {
        if ($this->tableExists(self::LEVELS)) {
            DB::statement('DROP TABLE `' . self::LEVELS . '`');
        }

        /*
         * The varchar -> tinyint conversion is deliberately NOT reversed.
         *
         * Going back would mean writing 'Level 3' into rows that may since have
         * been edited as numbers, and would restore a column that cannot be
         * compared to the role targets beside it. A rollback that reinstates a
         * broken link is not a safety net.
         */
    }

    /* ----------------------------------------------------------------- *
     * Not Schema::hasTable()/hasColumn(). Laravel 11 introspects with a
     * query selecting `generation_expression`, which live's MariaDB 10.1
     * does not have - so those helpers throw against production while
     * working perfectly on dev.
     * ----------------------------------------------------------------- */

    private function tableExists(string $table): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table]
        ) !== [];
    }

    private function columnExists(string $table, string $column): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            [$table, $column]
        ) !== [];
    }

    private function columnType(string $table, string $column): string
    {
        $rows = DB::select(
            'SELECT COLUMN_TYPE AS t FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            [$table, $column]
        );

        return $rows === [] ? '' : (string) $rows[0]->t;
    }
};
