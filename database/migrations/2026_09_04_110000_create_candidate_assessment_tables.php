<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Candidate assessment: the job-role link, the blueprint, and the sitting.
 *
 *   php artisan migrate --database=mysql --path=database/migrations/2026_09_04_110000_create_candidate_assessment_tables.php
 *   php artisan migrate --database=live  --path=database/migrations/2026_09_04_110000_create_candidate_assessment_tables.php
 *
 * ── WHY ─────────────────────────────────────────────────────────────────────
 *
 * The recruitment pipeline has an Assessment stage that writes a status string
 * nothing reads, so candidates are hired untested. The assessment ENGINE already
 * exists and is good - it generates with DeepSeek, auto-scores MCQ, has DeepSeek
 * mark written answers, and raises rating proposals rather than changing anyone's
 * proficiency silently. What is missing is everything that connects it to
 * recruitment.
 *
 * ── 1. talent_job_postings.jobrole_id ───────────────────────────────────────
 *
 * A posting carries a department id and a free-text title, and nothing else. So
 * recruitment cannot reach the job-role catalogue - 3,347 roles across 44
 * sectors, with 64,923 skills and 55,868 tasks mapped to them. That catalogue is
 * what lets the model write questions for a NURSE rather than generic ones, and
 * it is why an admin never has to guess what suits healthcare or finance. The
 * column is nullable: existing postings stay valid and unlinked.
 *
 * ── 2. talent_assessment_blueprints ─────────────────────────────────────────
 *
 * What HR configures once per role: which kinds of test, how many questions, how
 * long, THE TOTAL MARKS, and THE QUALIFICATION MARK. The qualification mark is
 * the whole point - it is the bar a human sets in advance, and the thing that
 * later decides whether a candidate is shortlisted. Setting it up front, before
 * anyone has sat anything, is what keeps that decision defensible.
 *
 * test_types is a comma-separated VARCHAR with the vocabulary in a PHP const, not
 * an ENUM, so adding "situational judgement" later is a code change and not an
 * ALTER TABLE on a live database.
 *
 * ── 3. talent_candidate_assessments ─────────────────────────────────────────
 *
 * One row per invited candidate: which test, which attempt, the magic-link token,
 * and the outcome. The token pattern is copied from OfferLinkService, which the
 * offer flow already proved: Str::random(64), STORE ONLY THE SHA-256, an expiry
 * that is both written and checked, single use marked rather than deleted, and a
 * uniform 410 so a caller cannot probe which tokens exist.
 *
 * One difference from an offer, and it matters: an offer is answered once, but an
 * assessment is opened, saved, resumed and only then submitted. So the token is
 * burned on FINAL SUBMISSION, not on first resolve.
 *
 * ── 4. competency_assessment_question.kasba_item_id -> NULLABLE ─────────────
 *
 * This one is a deliberate reversal of my own earlier plan, and the reasoning is
 * worth keeping.
 *
 * The column was NOT NULL on purpose - "no item, no question" is the only
 * STRUCTURAL guard in the feature, and it is what stops an LLM inventing a test
 * about competencies nobody authored. An aptitude or a coding question, however,
 * cites no competency item at all, so the guard also forbids the very thing
 * recruitment needs.
 *
 * Relaxing it alone would have been the dangerous choice, because the damage is
 * silent: finalise() totals EVERY question into the percent, while
 * buildProposals() skips any question without an item. The attempt would report
 * 70% and the per-item numbers would not add up to it, with nothing to say why.
 *
 * So the column is relaxed AND finalise() is split in the same change: it now
 * reports `attributed_*` (questions that cite a capability) separately from the
 * overall figures, and buildProposals()/feedReviewCycles() consume the attributed
 * ones. The two can no longer drift, because they are computed apart on purpose.
 *
 * The index on the column (caq_item_index) is a plain BTREE - MySQL indexes
 * NULLs, so it survives untouched. There is no FK and no unique on it.
 *
 * ── SIZE, AGAINST LIVE'S LIMITS ─────────────────────────────────────────────
 *
 *   tab_tenant_jobrole_unique   8 + 8         =  16 bytes, 25-char name
 *   tca_token_unique            char(64)      = 256 bytes, 16-char name
 *   tca_tenant_application_idx  8 + 8         =  16 bytes, 26-char name
 *   tjp_jobrole_idx             8             =   8 bytes, 15-char name
 *
 * Cap is 767 bytes under live's ROW_FORMAT=Compact, 64 characters for a name.
 * No `json` columns - MariaDB 10.1 does not have the type.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. The link that makes the job-role catalogue reachable from recruitment.
        if ($this->tableExists('talent_job_postings') && !$this->columnExists('talent_job_postings', 'jobrole_id')) {
            DB::statement(
                'ALTER TABLE `talent_job_postings`
                 ADD COLUMN `jobrole_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `department_id`,
                 ADD INDEX `tjp_jobrole_idx` (`jobrole_id`)'
            );
        }

        // 2. What HR configures per role, including the bar.
        if (!$this->tableExists('talent_assessment_blueprints')) {
            DB::statement("
                CREATE TABLE `talent_assessment_blueprints` (
                    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `sub_institute_id`    BIGINT UNSIGNED NOT NULL,
                    `department_id`       BIGINT UNSIGNED NULL DEFAULT NULL,
                    `jobrole_id`          BIGINT UNSIGNED NOT NULL,
                    `title`               VARCHAR(191) NULL DEFAULT NULL,
                    -- Comma-separated; vocabulary in AssessmentBlueprintController::TEST_TYPES.
                    `test_types`          VARCHAR(191) NOT NULL DEFAULT 'aptitude',
                    `question_count`      SMALLINT UNSIGNED NOT NULL DEFAULT 10,
                    `total_marks`         SMALLINT UNSIGNED NOT NULL DEFAULT 100,
                    -- The bar a human sets BEFORE anyone sits it. Decides shortlisting.
                    `qualification_marks` SMALLINT UNSIGNED NOT NULL DEFAULT 40,
                    `time_limit_minutes`  SMALLINT UNSIGNED NULL DEFAULT 30,
                    `is_active`           TINYINT(1) NOT NULL DEFAULT 1,
                    `created_by`          BIGINT UNSIGNED NULL DEFAULT NULL,
                    `updated_by`          BIGINT UNSIGNED NULL DEFAULT NULL,
                    `created_at`          TIMESTAMP NULL DEFAULT NULL,
                    `updated_at`          TIMESTAMP NULL DEFAULT NULL,
                    `deleted_at`          TIMESTAMP NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `tab_tenant_jobrole_unique` (`sub_institute_id`, `jobrole_id`),
                    KEY `tab_tenant_active_idx` (`sub_institute_id`, `is_active`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        // 3. One invited candidate's sitting.
        if (!$this->tableExists('talent_candidate_assessments')) {
            DB::statement("
                CREATE TABLE `talent_candidate_assessments` (
                    `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `sub_institute_id`   BIGINT UNSIGNED NOT NULL,
                    `application_id`     BIGINT UNSIGNED NOT NULL,
                    `candidate_id`       BIGINT UNSIGNED NULL DEFAULT NULL,
                    `blueprint_id`       BIGINT UNSIGNED NULL DEFAULT NULL,
                    `test_id`            BIGINT UNSIGNED NULL DEFAULT NULL,
                    `attempt_id`         BIGINT UNSIGNED NULL DEFAULT NULL,
                    -- sha256 hex of the link token. The token itself is never stored.
                    `token_hash`         CHAR(64) NULL DEFAULT NULL,
                    `token_expires_at`   DATETIME NULL DEFAULT NULL,
                    -- Burned on FINAL SUBMIT, not on first open: a sitting is resumed.
                    `token_used_at`      DATETIME NULL DEFAULT NULL,
                    `status`             VARCHAR(20) NOT NULL DEFAULT 'invited',
                    `score`              DECIMAL(8,2) NULL DEFAULT NULL,
                    `max_score`          DECIMAL(8,2) NULL DEFAULT NULL,
                    `percent`            DECIMAL(5,2) NULL DEFAULT NULL,
                    `qualified`          TINYINT(1) NULL DEFAULT NULL,
                    `invited_at`         DATETIME NULL DEFAULT NULL,
                    `submitted_at`       DATETIME NULL DEFAULT NULL,
                    `graded_at`          DATETIME NULL DEFAULT NULL,
                    `created_by`         BIGINT UNSIGNED NULL DEFAULT NULL,
                    `updated_by`         BIGINT UNSIGNED NULL DEFAULT NULL,
                    `created_at`         TIMESTAMP NULL DEFAULT NULL,
                    `updated_at`         TIMESTAMP NULL DEFAULT NULL,
                    `deleted_at`         TIMESTAMP NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `tca_token_unique` (`token_hash`),
                    KEY `tca_tenant_application_idx` (`sub_institute_id`, `application_id`),
                    KEY `tca_attempt_idx` (`attempt_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        // 4. Let a question exist without citing a capability. See the docblock.
        if ($this->tableExists('competency_assessment_question')
            && $this->isNotNullable('competency_assessment_question', 'kasba_item_id')) {
            DB::statement(
                'ALTER TABLE `competency_assessment_question`
                 MODIFY `kasba_item_id` BIGINT UNSIGNED NULL DEFAULT NULL'
            );
        }
    }

    public function down(): void
    {
        /*
         * Restoring NOT NULL fails if any un-cited question exists - correctly,
         * because there would be no honest value to put in those rows. The check
         * makes that a clear message instead of a driver error.
         */
        if ($this->tableExists('competency_assessment_question')
            && !$this->isNotNullable('competency_assessment_question', 'kasba_item_id')) {
            $orphans = DB::table('competency_assessment_question')->whereNull('kasba_item_id')->count();

            if ($orphans > 0) {
                throw new RuntimeException(
                    "Cannot restore NOT NULL on competency_assessment_question.kasba_item_id: "
                    . "{$orphans} question(s) cite no capability item. Remove them first."
                );
            }

            DB::statement(
                'ALTER TABLE `competency_assessment_question` MODIFY `kasba_item_id` BIGINT UNSIGNED NOT NULL'
            );
        }

        foreach (['talent_candidate_assessments', 'talent_assessment_blueprints'] as $table) {
            if ($this->tableExists($table)) {
                DB::statement('DROP TABLE `' . $table . '`');
            }
        }

        if ($this->tableExists('talent_job_postings') && $this->columnExists('talent_job_postings', 'jobrole_id')) {
            DB::statement('ALTER TABLE `talent_job_postings` DROP INDEX `tjp_jobrole_idx`, DROP COLUMN `jobrole_id`');
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

    private function isNotNullable(string $table, string $column): bool
    {
        return !empty(DB::select(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
               AND IS_NULLABLE = 'NO' LIMIT 1",
            [$table, $column]
        ));
    }
};
