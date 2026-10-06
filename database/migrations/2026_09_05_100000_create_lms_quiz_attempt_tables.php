<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Where an LMS quiz attempt is recorded.
 *
 * ── WHY NOT REUSE THE ASSESSMENT TABLES ─────────────────────────────────────
 *
 * The employee competency assessment and the candidate hiring assessment
 * already share four tables, discriminated by `subject_type`, and that
 * discriminator is baked into their unique keys. The relevant one is
 *
 *     uq_caa_test_user_subject = (test_id, user_id, subject_type)
 *
 * on `competency_assessment_attempt`. It permits exactly ONE attempt per person
 * per test. That is correct for a competency assessment, and incompatible with
 * an LMS quiz, where `lms_course_settings.max_attempts` exists precisely so a
 * learner can retry. Adding an attempt number to that key would change the
 * meaning of a table two live systems depend on, so the LMS gets its own.
 *
 * ── WHY SCORING LIVES SERVER-SIDE, AND NOTHING HERE MIRRORS THE LEGACY ──────
 *
 * The legacy LMS exam scorer cannot be reused. `online_exam.blade.php` renders
 * each option as `value="{id}##{correct_answer}"` and
 * `onlineExamController::get_calculate_marks()` reads the correctness flag back
 * out of the submitted value — the client is told the answer and then asked to
 * report whether it was right. `lms_quiz_response.is_correct` is therefore
 * written by the server from `answer_master.correct_answer`, and nothing the
 * client sends is ever consulted for it.
 *
 * ── COLUMNS ─────────────────────────────────────────────────────────────────
 *
 * `attempt_no` starts at 1 and is what makes retries first-class; the unique
 * key includes it. `percent` is stored rather than derived so a later change to
 * a question's points cannot retroactively alter a result somebody already
 * received — and so the competency rating it produced stays explicable.
 *
 * `status` is VARCHAR + PHP constants (LmsQuizAttempt::STATUS_*), never ENUM:
 * live is MariaDB 10.1.48 and an ENUM change there is a table rebuild.
 *
 * `awaiting_review` counts answers a model could not mark. A DeepSeek failure
 * must never become a zero against the learner, so those answers stay unscored
 * and the attempt says so.
 *
 * ── LIVE CONSTRAINTS ────────────────────────────────────────────────────────
 *
 * MariaDB 10.1.48: no `json` columns, every index named, identifiers under 64
 * characters, and existence checked against information_schema because
 * Schema::hasTable() throws there. No FKs — nothing else in this schema uses
 * them, and a FK to `sub_std_map` would fight its soft deletes.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_09_05_100000_create_lms_quiz_attempt_tables.php
 *   php artisan migrate --database=live --path=database/migrations/2026_09_05_100000_create_lms_quiz_attempt_tables.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->tableExists('lms_quiz_attempt')) {
            DB::statement(
                'CREATE TABLE `lms_quiz_attempt` (
                    `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    `sub_institute_id` BIGINT(20) UNSIGNED NOT NULL,
                    `course_id` BIGINT(20) UNSIGNED NOT NULL,
                    `paper_id` BIGINT(20) UNSIGNED NOT NULL,
                    `user_id` BIGINT(20) UNSIGNED NOT NULL,
                    `attempt_no` SMALLINT(5) UNSIGNED NOT NULL DEFAULT 1,
                    `status` VARCHAR(20) NOT NULL DEFAULT "in-progress",
                    `score` DECIMAL(8,2) NULL,
                    `max_score` DECIMAL(8,2) NULL,
                    `percent` DECIMAL(5,2) NULL,
                    `passing_score` TINYINT(3) UNSIGNED NULL,
                    `passed` TINYINT(1) NULL,
                    `questions` SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
                    `awaiting_review` SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
                    `started_at` TIMESTAMP NULL,
                    `submitted_at` TIMESTAMP NULL,
                    `created_by` BIGINT(20) UNSIGNED NULL,
                    `updated_by` BIGINT(20) UNSIGNED NULL,
                    `created_at` TIMESTAMP NULL,
                    `updated_at` TIMESTAMP NULL,
                    `deleted_at` TIMESTAMP NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_lqa_tenant_user_paper_no`
                        (`sub_institute_id`, `user_id`, `paper_id`, `attempt_no`),
                    KEY `idx_lqa_course_user` (`course_id`, `user_id`),
                    KEY `idx_lqa_tenant_course` (`sub_institute_id`, `course_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        }

        if (! $this->tableExists('lms_quiz_response')) {
            DB::statement(
                'CREATE TABLE `lms_quiz_response` (
                    `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    `attempt_id` BIGINT(20) UNSIGNED NOT NULL,
                    `question_id` BIGINT(20) UNSIGNED NOT NULL,
                    `answer_id` BIGINT(20) UNSIGNED NULL,
                    `narrative` TEXT NULL,
                    `is_correct` TINYINT(1) NULL,
                    `score` DECIMAL(8,2) NULL,
                    `max_score` DECIMAL(8,2) NULL,
                    `ai_marked` TINYINT(1) NOT NULL DEFAULT 0,
                    `feedback` TEXT NULL,
                    `created_at` TIMESTAMP NULL,
                    `updated_at` TIMESTAMP NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_lqr_attempt_question` (`attempt_id`, `question_id`),
                    KEY `idx_lqr_question` (`question_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        }
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS `lms_quiz_response`');
        DB::statement('DROP TABLE IF EXISTS `lms_quiz_attempt`');
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
