<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Make a competency rating say where it came from.
 *
 * ── THE INVARIANT THIS IS PROTECTING ────────────────────────────────────────
 *
 * AssessmentScoringService::approve() is the only code path in this codebase
 * from a test score to a competency rating, and it is deliberately gated on a
 * person: "A test result is EVIDENCE, not a verdict." The LMS quiz is to write
 * ratings without that review, by explicit product decision.
 *
 * The decision is honoured, but not by going around the gate. The quiz still
 * writes a PROPOSAL, and a per-course flag auto-approves it — so the rule is
 * configured off for that course rather than bypassed, and the proposal row
 * survives as the record of what was decided and why. That matters because an
 * AI-marked quiz will occasionally be wrong, and somebody will need to see why
 * a rating moved.
 *
 * ── THE THREE COLUMNS ───────────────────────────────────────────────────────
 *
 * 1. `competency_kasba_rating.source_ref_id`
 *    `source` already distinguishes 'manual' / 'self' / 'assessment' — but
 *    nothing anywhere links a rating back to the attempt that caused it. With
 *    unreviewed writes that gap stops being cosmetic: a wrong score becomes
 *    somebody's record with no way to find the quiz that produced it. This
 *    column makes a rating traceable, and therefore reversible.
 *
 * 2. `competency_assessment_rating_proposal.source`
 *    `attempt_id` on that table is NOT NULL and today always means a
 *    `competency_assessment_attempt`. An LMS proposal carries an
 *    `lms_quiz_attempt` id in the same column, and the two id spaces overlap.
 *    Without a discriminator the column would be ambiguous — and following it
 *    would land on an unrelated row rather than failing. DEFAULT 'assessment'
 *    so every existing row keeps its current meaning.
 *
 * 3. `lms_course_settings.auto_apply_rating`
 *    Per course, because "write the rating directly" is a reasonable policy for
 *    a mandatory compliance course and a bad one for an optional short course.
 *    DEFAULT 0 — off. Nothing changes until a course turns it on.
 *
 * ── LIVE CONSTRAINTS ────────────────────────────────────────────────────────
 *
 * MariaDB 10.1.48: VARCHAR + PHP constant rather than ENUM, no index on
 * `source` (three distinct values over a small table — an index would not be
 * used), and existence checked against information_schema because
 * Schema::hasTable() throws there.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_09_05_100100_add_lms_quiz_rating_provenance.php
 *   php artisan migrate --database=live --path=database/migrations/2026_09_05_100100_add_lms_quiz_rating_provenance.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if ($this->tableExists('competency_kasba_rating')
            && ! $this->columnExists('competency_kasba_rating', 'source_ref_id')) {
            DB::statement(
                'ALTER TABLE `competency_kasba_rating`
                    ADD COLUMN `source_ref_id` BIGINT(20) UNSIGNED NULL AFTER `source`'
            );
        }

        if ($this->tableExists('competency_assessment_rating_proposal')
            && ! $this->columnExists('competency_assessment_rating_proposal', 'source')) {
            DB::statement(
                'ALTER TABLE `competency_assessment_rating_proposal`
                    ADD COLUMN `source` VARCHAR(32) NOT NULL DEFAULT "assessment" AFTER `subject_type`'
            );
        }

        if ($this->tableExists('lms_course_settings')
            && ! $this->columnExists('lms_course_settings', 'auto_apply_rating')) {
            DB::statement(
                'ALTER TABLE `lms_course_settings`
                    ADD COLUMN `auto_apply_rating` TINYINT(1) NOT NULL DEFAULT 0 AFTER `max_attempts`'
            );
        }
    }

    public function down(): void
    {
        if ($this->columnExists('competency_kasba_rating', 'source_ref_id')) {
            DB::statement('ALTER TABLE `competency_kasba_rating` DROP COLUMN `source_ref_id`');
        }
        if ($this->columnExists('competency_assessment_rating_proposal', 'source')) {
            DB::statement('ALTER TABLE `competency_assessment_rating_proposal` DROP COLUMN `source`');
        }
        if ($this->columnExists('lms_course_settings', 'auto_apply_rating')) {
            DB::statement('ALTER TABLE `lms_course_settings` DROP COLUMN `auto_apply_rating`');
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

    private function columnExists(string $table, string $column): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            [$table, $column]
        ) !== [];
    }
};
