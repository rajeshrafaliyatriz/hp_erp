<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Let a review cycle use an assessment as its evidence.
 *
 * ── TWO INSTRUMENTS THAT NEVER MET ──────────────────────────────────────────
 *
 * There are two ways this product measures capability, and until now they were
 * unaware of each other:
 *
 *   A REVIEW CYCLE (s_competency_assessment_cycles + s_competency_assessments)
 *   asks a manager to rate their team against a framework over a period. It
 *   produces one percentage per person. There is no question anywhere in it -
 *   somebody's judgement is the whole input.
 *
 *   AN ASSESSMENT (competency_assessment_test/_question/_response) asks the
 *   PERSON to answer questions, and scores what they actually did.
 *
 * The AI migration said the two were "DELIBERATELY NOT WIRED TO CYCLES", and at
 * the time that was right - the test tables were new and unproven. The cost of
 * leaving it is that a cycle asking "how capable is this person?" cannot use the
 * one artefact in the system that measured it, and a test result stays an island
 * nobody's review ever sees.
 *
 * Two nullable columns are enough to join them:
 *
 *   cycles.test_id            the assessment this cycle uses as evidence
 *   assessments.attempt_id    the sitting that produced this person's score
 *
 * ── NULLABLE, AND THAT IS THE POINT ─────────────────────────────────────────
 *
 * A cycle with no test_id is exactly what every existing cycle is: manager
 * rating only. All 4 live cycles and 140 participant rows keep working untouched
 * and unchanged. The link is opt-in per cycle, not a new requirement.
 *
 * RUN ON BOTH DATABASES:
 *   php artisan migrate --path=database/migrations/2026_08_26_160000_link_review_cycles_to_assessments.php
 *   php artisan migrate --database=live --path=database/migrations/2026_08_26_160000_link_review_cycles_to_assessments.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if ($this->tableExists('s_competency_assessment_cycles')
            && !$this->columnExists('s_competency_assessment_cycles', 'test_id')) {
            DB::statement(
                'ALTER TABLE `s_competency_assessment_cycles`
                 ADD COLUMN `test_id` BIGINT UNSIGNED NULL AFTER `framework_id`'
            );
            DB::statement(
                'ALTER TABLE `s_competency_assessment_cycles` ADD INDEX `scac_test_id_index` (`test_id`)'
            );
        }

        if ($this->tableExists('s_competency_assessments')
            && !$this->columnExists('s_competency_assessments', 'attempt_id')) {
            DB::statement(
                'ALTER TABLE `s_competency_assessments`
                 ADD COLUMN `attempt_id` BIGINT UNSIGNED NULL AFTER `cycle_id`'
            );
            DB::statement(
                'ALTER TABLE `s_competency_assessments` ADD INDEX `sca_attempt_id_index` (`attempt_id`)'
            );
        }

        // No foreign keys, matching every other table in this feature
        // (2026_08_13_140000 declares its relationships as plain
        // unsignedBigInteger). An FK would also refuse a cycle whose test was
        // later soft-deleted, and a review that already happened should keep
        // pointing at the thing it used.
    }

    public function down(): void
    {
        if ($this->columnExists('s_competency_assessments', 'attempt_id')) {
            if ($this->indexExists('s_competency_assessments', 'sca_attempt_id_index')) {
                DB::statement('ALTER TABLE `s_competency_assessments` DROP INDEX `sca_attempt_id_index`');
            }
            DB::statement('ALTER TABLE `s_competency_assessments` DROP COLUMN `attempt_id`');
        }

        if ($this->columnExists('s_competency_assessment_cycles', 'test_id')) {
            if ($this->indexExists('s_competency_assessment_cycles', 'scac_test_id_index')) {
                DB::statement('ALTER TABLE `s_competency_assessment_cycles` DROP INDEX `scac_test_id_index`');
            }
            DB::statement('ALTER TABLE `s_competency_assessment_cycles` DROP COLUMN `test_id`');
        }

        // Scores written from a test stay. They were real results.
    }

    /** information_schema directly - live is MariaDB 10.1, where Schema::hasColumn() throws. */
    private function columnExists(string $table, string $column): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            [$table, $column]
        ) !== [];
    }

    private function indexExists(string $table, string $index): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$table, $index]
        ) !== [];
    }

    private function tableExists(string $table): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table]
        ) !== [];
    }
};
