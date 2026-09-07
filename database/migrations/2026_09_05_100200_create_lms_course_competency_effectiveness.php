<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * What learners actually reach on a course, as opposed to what it aims for.
 *
 * ── WHY THIS IS NOT A COLUMN ON course_competency_map ───────────────────────
 *
 * `course_competency_map.proficiency_level` looks like the obvious place to
 * record measured performance, and it is the wrong one for two independent
 * reasons.
 *
 * First, it means something else. It is a TARGET, authored by HR: "this course
 * is meant to take you to level 3". All 17 rows on live sit at 3, which is seed
 * data, not measurement. Overwriting it with what learners score would destroy
 * the declared intent and leave nothing to compare against — the comparison
 * being the entire value of measuring.
 *
 * Second, it would not survive. CourseCompetencyMapController::store() syncs
 * destructively: competencies absent from a save are deleted and the rest
 * rewritten. Any measured value written there is erased by the next HR edit of
 * the mapping, silently, with no error.
 *
 * So the target stays where it is, the measurement lives here, and a course
 * whose takers consistently land below its target becomes visible as weak
 * teaching rather than being averaged into invisibility.
 *
 * ── COLUMNS ─────────────────────────────────────────────────────────────────
 *
 * `attempts` and `mean_percent` are a running aggregate recomputed on each quiz
 * submission, so the read side never has to scan attempts. `derived_level` is
 * mean_percent put through the same RATING_BANDS the competency module uses,
 * so "achieved 3" and "target 3" are on one scale and genuinely comparable.
 *
 * ── LIVE CONSTRAINTS ────────────────────────────────────────────────────────
 *
 * MariaDB 10.1.48: named indexes, identifiers under 64 characters, no `json`,
 * no FKs (nothing in this schema uses them), and existence checked against
 * information_schema because Schema::hasTable() throws there.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_09_05_100200_create_lms_course_competency_effectiveness.php
 *   php artisan migrate --database=live --path=database/migrations/2026_09_05_100200_create_lms_course_competency_effectiveness.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if ($this->tableExists('lms_course_competency_effectiveness')) {
            return;
        }

        DB::statement(
            'CREATE TABLE `lms_course_competency_effectiveness` (
                `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                `sub_institute_id` BIGINT(20) UNSIGNED NOT NULL,
                `course_id` BIGINT(20) UNSIGNED NOT NULL,
                `competency_id` BIGINT(20) UNSIGNED NOT NULL,
                `attempts` INT(10) UNSIGNED NOT NULL DEFAULT 0,
                `learners` INT(10) UNSIGNED NOT NULL DEFAULT 0,
                `mean_percent` DECIMAL(5,2) NULL,
                `derived_level` TINYINT(3) UNSIGNED NULL,
                `target_level` TINYINT(3) UNSIGNED NULL,
                `last_computed_at` TIMESTAMP NULL,
                `created_at` TIMESTAMP NULL,
                `updated_at` TIMESTAMP NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_lcce_tenant_course_comp`
                    (`sub_institute_id`, `course_id`, `competency_id`),
                KEY `idx_lcce_competency` (`competency_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS `lms_course_competency_effectiveness`');
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
