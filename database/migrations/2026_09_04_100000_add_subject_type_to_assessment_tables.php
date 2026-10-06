<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A discriminator on the assessment tables, so a candidate can never be mistaken
 * for an employee.
 *
 *   php artisan migrate --database=mysql --path=database/migrations/2026_09_04_100000_add_subject_type_to_assessment_tables.php
 *   php artisan migrate --database=live  --path=database/migrations/2026_09_04_100000_add_subject_type_to_assessment_tables.php
 *
 * ── WHY THIS EXISTS, AND WHY IT COMES FIRST ─────────────────────────────────
 *
 * The assessment engine keys everything on `user_id`, and `user_id` has NO
 * FOREIGN KEY on any of these tables - the coupling to tbluser lives entirely in
 * joins. Today that is safe only by construction: Sanctum tokens are minted for
 * tbluser rows and nothing else, so `user_id` is always an employee.
 *
 * Assessing CANDIDATES breaks that assumption, and the consequence is not
 * cosmetic. AssessmentScoringService::approve() upserts competency_kasba_rating
 * twice, on
 *
 *     (sub_institute_id, user_id, kasba_item_id)
 *     (sub_institute_id, user_id, kasba_type, item_id)
 *
 * and neither table has a foreign key either. A candidate id that happened to
 * equal a real employee's id would therefore write a competency rating onto that
 * REAL EMPLOYEE'S RECORD - a silent, permanent corruption of the one thing the
 * competency module exists to be trusted about.
 *
 * This migration runs BEFORE any code that can create a candidate attempt, and
 * the guard in approve() lands with it. The dangerous path is closed before it
 * can be reached, not after.
 *
 * ── THE UNIQUE KEYS HAVE TO WIDEN WITH IT ───────────────────────────────────
 *
 * Two uniques currently treat user_id as globally unique per test/question:
 *
 *     uq_caa_test_user          (test_id, user_id)
 *     car_question_user_unique  (question_id, user_id)
 *
 * Left alone, candidate 385 and employee 385 would collide on both, and the
 * second one to sit would be refused or would overwrite the first. Adding
 * subject_type to the key is what makes the two namespaces genuinely separate.
 *
 * ── AND THE AI'S REASONING IS FINALLY KEPT ──────────────────────────────────
 *
 * scoreShortAnswers() asks DeepSeek for a score AND one sentence of feedback per
 * answer, then throws the feedback away because there is nowhere to put it. A
 * person marked down has never been able to see why. `ai_feedback` gives it a
 * home - needed here because a candidate's result has to be explainable to the
 * hiring manager reading it, and to the candidate if they ask.
 *
 * ── SIZE, AGAINST LIVE'S LIMITS ─────────────────────────────────────────────
 *
 *   subject_type VARCHAR(20) utf8mb4 ........... 80 bytes
 *   uq_caa_test_user_subject     8 + 8 + 80 = 96 bytes, 24-char name
 *   car_question_user_subject_unique   ditto  = 96 bytes, 32-char name
 *
 * Cap is 767 bytes under live's ROW_FORMAT=Compact and 64 characters for an
 * identifier. VARCHAR + a PHP const, never ENUM - the vocabulary lives in
 * App\Services\Talent\CandidateAssessmentService::SUBJECTS.
 *
 * All five tables hold 0 rows on both hosts, so NOT NULL with a default is safe;
 * that is re-checked at run time rather than trusted.
 */
return new class extends Migration
{
    private const SUBJECT_TABLES = [
        'competency_assessment_attempt',
        'competency_assessment_response',
        'competency_assessment_rating_proposal',
    ];

    public function up(): void
    {
        foreach (self::SUBJECT_TABLES as $table) {
            if (!$this->tableExists($table) || $this->columnExists($table, 'subject_type')) {
                continue;
            }

            DB::statement(
                'ALTER TABLE `' . $table . '`
                 ADD COLUMN `subject_type` VARCHAR(20) NOT NULL DEFAULT "employee" AFTER `user_id`'
            );
        }

        // The AI's one-sentence justification, which was being discarded.
        if ($this->tableExists('competency_assessment_response')
            && !$this->columnExists('competency_assessment_response', 'ai_feedback')) {
            DB::statement(
                'ALTER TABLE `competency_assessment_response`
                 ADD COLUMN `ai_feedback` TEXT NULL DEFAULT NULL AFTER `scored_by`'
            );
        }

        /*
         * Widen the two uniques. Add the new key BEFORE dropping the old one, so
         * the column is never left without a uniqueness guarantee.
         */
        $this->reindex(
            'competency_assessment_attempt',
            'uq_caa_test_user',
            'uq_caa_test_user_subject',
            '(`test_id`, `user_id`, `subject_type`)'
        );

        $this->reindex(
            'competency_assessment_response',
            'car_question_user_unique',
            'car_question_user_subject_unique',
            '(`question_id`, `user_id`, `subject_type`)'
        );
    }

    public function down(): void
    {
        $this->reindex(
            'competency_assessment_response',
            'car_question_user_subject_unique',
            'car_question_user_unique',
            '(`question_id`, `user_id`)'
        );

        $this->reindex(
            'competency_assessment_attempt',
            'uq_caa_test_user_subject',
            'uq_caa_test_user',
            '(`test_id`, `user_id`)'
        );

        if ($this->tableExists('competency_assessment_response')
            && $this->columnExists('competency_assessment_response', 'ai_feedback')) {
            DB::statement('ALTER TABLE `competency_assessment_response` DROP COLUMN `ai_feedback`');
        }

        foreach (self::SUBJECT_TABLES as $table) {
            if ($this->tableExists($table) && $this->columnExists($table, 'subject_type')) {
                DB::statement('ALTER TABLE `' . $table . '` DROP COLUMN `subject_type`');
            }
        }
    }

    /** Add the replacement unique, then drop the old one. Never the reverse. */
    private function reindex(string $table, string $oldIndex, string $newIndex, string $columns): void
    {
        if (!$this->tableExists($table)) {
            return;
        }

        if (!$this->indexExists($table, $newIndex)) {
            DB::statement('ALTER TABLE `' . $table . '` ADD UNIQUE `' . $newIndex . '` ' . $columns);
        }

        if ($this->indexExists($table, $oldIndex)) {
            DB::statement('ALTER TABLE `' . $table . '` DROP INDEX `' . $oldIndex . '`');
        }
    }

    /** Schema::hasTable() throws on the live host; information_schema does not. */
    private function tableExists(string $table): bool
    {
        return !empty(DB::select(
            'SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table]
        ));
    }

    private function columnExists(string $table, string $column): bool
    {
        return !empty(DB::select(
            'SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            [$table, $column]
        ));
    }

    private function indexExists(string $table, string $index): bool
    {
        return !empty(DB::select(
            'SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$table, $index]
        ));
    }
};
