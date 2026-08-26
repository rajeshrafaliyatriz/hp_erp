<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * What an assessment needs to be a real test rather than a question bank.
 *
 * The three AI assessment tables already hold a test, its questions and one
 * answer per person per question. Three things are missing, and each is the
 * reason a whole feature could not be built:
 *
 *   1. SCOPE. generate() takes a job role and produces questions for EVERY
 *      KASBA item of EVERY competency mapped to it - all or nothing. There is
 *      no way to say "just this competency" or "just this one KASBA item",
 *      which is precisely what was asked for.
 *
 *   2. AN ATTEMPT. `_response` records answers but nothing records the sitting:
 *      who was assigned it, when they started, when they submitted, what they
 *      scored overall. "Submitted" is currently INFERRED as answered == total,
 *      which cannot tell a finished test from one where every question happened
 *      to be answered. Without a start time a timer is impossible, and without
 *      a total the employee can never be shown a result.
 *
 *   3. A PROPOSAL. The controller states, correctly, that submitting a test
 *      must not move anyone's proficiency - "proficiency changes only on
 *      explicit confirmation elsewhere". That elsewhere did not exist, so a
 *      score could never become a rating at all. This adds the queue in
 *      between: a test proposes, a person approves, and only then does
 *      competency_kasba_rating change.
 *
 * NULLABLE AND NO FOREIGN KEYS, matching the tables these extend
 * (2026_08_13_140000 declares every relationship as unsignedBigInteger only).
 *
 * RUN ON BOTH DATABASES:
 *   php artisan migrate --path=database/migrations/2026_08_26_120000_assessment_scope_attempts_and_proposals.php
 *   php artisan migrate --database=live --path=database/migrations/2026_08_26_120000_assessment_scope_attempts_and_proposals.php
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->extendTest();
        $this->createAttempts();
        $this->createProposals();
    }

    /**
     * Scope, timing and openness on the test itself.
     *
     * `scope_type` defaults to 'jobrole' because that is what every existing
     * test is - all three tables are empty on both databases today, but the
     * default keeps the column honest if that ever stops being true.
     */
    private function extendTest(): void
    {
        $table = 'competency_assessment_test';
        if (!$this->tableExists($table)) {
            return;
        }

        $add = [
            // jobrole | competency | kasba_item - what the test is ABOUT.
            'scope_type'         => "VARCHAR(20) NOT NULL DEFAULT 'jobrole' AFTER `jobrole_id`",
            // Set when scope_type = 'competency'.
            'competency_id'      => 'BIGINT UNSIGNED NULL AFTER `scope_type`',
            // Set when scope_type = 'kasba_item' - ONE item, the "individual
            // KASBA" case. Points at competency_kasba_item.id.
            'kasba_item_id'      => 'BIGINT UNSIGNED NULL AFTER `competency_id`',
            // NULL means no limit. Minutes for the WHOLE test, not per question.
            'time_limit_minutes' => 'SMALLINT UNSIGNED NULL AFTER `instructions`',
            // Percent needed to pass. NULL means the test reports a score and
            // makes no pass/fail claim - which is honest when nobody has set a
            // threshold, and better than inventing 50%.
            'pass_percent'       => 'DECIMAL(5,2) NULL AFTER `time_limit_minutes`',
            // 1 = any employee of the tenant may take it without being
            // assigned. 0 = assigned people only.
            'is_open'            => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `pass_percent`',
        ];

        foreach ($add as $column => $definition) {
            if (!$this->columnExists($table, $column)) {
                DB::statement("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
            }
        }

        if (!$this->indexExists($table, 'cat_scope_index')) {
            DB::statement("ALTER TABLE `$table` ADD INDEX `cat_scope_index` (`sub_institute_id`, `scope_type`, `status`)");
        }
    }

    /**
     * One row per person per test: the sitting.
     *
     * Created when the test is assigned, or on first open for an open test.
     * `started_at` is what a countdown is measured from - a timer anchored to
     * anything the browser holds is a timer the browser can reset.
     *
     * total_score / max_score / percent are STORED, not computed on read.
     * Questions can be added to a draft or a test superseded later; a result
     * has to keep saying what it said on the day.
     */
    private function createAttempts(): void
    {
        if ($this->tableExists('competency_assessment_attempt')) {
            return;
        }

        DB::statement("
            CREATE TABLE `competency_assessment_attempt` (
                `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `sub_institute_id` BIGINT UNSIGNED NOT NULL,
                `test_id`          BIGINT UNSIGNED NOT NULL,
                `user_id`          BIGINT UNSIGNED NOT NULL,
                `assigned_by`      BIGINT UNSIGNED NULL,
                `due_date`         DATE NULL,
                `started_at`       TIMESTAMP NULL,
                `submitted_at`     TIMESTAMP NULL,
                `total_score`      DECIMAL(8,2) NULL,
                `max_score`        DECIMAL(8,2) NULL,
                `percent`          DECIMAL(5,2) NULL,
                `awaiting_review`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `status`           VARCHAR(20) NOT NULL DEFAULT 'assigned',
                `created_at`       TIMESTAMP NULL,
                `updated_at`       TIMESTAMP NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_caa_test_user` (`test_id`, `user_id`),
                KEY `caa_user_index` (`sub_institute_id`, `user_id`, `status`),
                KEY `caa_test_index` (`test_id`, `status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    /**
     * What a result PROPOSES, before anyone's record changes.
     *
     * A test result is evidence, not a verdict. This holds the rating the
     * evidence suggests until a person accepts it; only then is
     * competency_kasba_rating written, with source='assessment' - a value the
     * rating table has reserved since it was created and which nothing has ever
     * written.
     *
     * Keyed the same two ways competency_kasba_rating is: kasba_item_id for a
     * competency-linked item, or (kasba_type, item_id) for a direct one, so an
     * approval can write whichever the rating table expects.
     */
    private function createProposals(): void
    {
        if ($this->tableExists('competency_assessment_rating_proposal')) {
            return;
        }

        DB::statement("
            CREATE TABLE `competency_assessment_rating_proposal` (
                `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `sub_institute_id` BIGINT UNSIGNED NOT NULL,
                `attempt_id`       BIGINT UNSIGNED NOT NULL,
                `test_id`          BIGINT UNSIGNED NOT NULL,
                `user_id`          BIGINT UNSIGNED NOT NULL,
                `kasba_item_id`    BIGINT UNSIGNED NULL,
                `kasba_type`       VARCHAR(20) NULL,
                `item_id`          BIGINT UNSIGNED NULL,
                `item_label`       VARCHAR(191) NULL,
                `competency_id`    BIGINT UNSIGNED NULL,
                `questions`        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `scored_percent`   DECIMAL(5,2) NULL,
                `proposed_rating`  TINYINT UNSIGNED NULL,
                `current_rating`   TINYINT UNSIGNED NULL,
                `status`           VARCHAR(20) NOT NULL DEFAULT 'pending',
                `decided_by`       BIGINT UNSIGNED NULL,
                `decided_at`       TIMESTAMP NULL,
                `note`             TEXT NULL,
                `created_at`       TIMESTAMP NULL,
                `updated_at`       TIMESTAMP NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_carp_attempt_item` (`attempt_id`, `kasba_item_id`, `kasba_type`, `item_id`),
                KEY `carp_pending_index` (`sub_institute_id`, `status`),
                KEY `carp_user_index` (`sub_institute_id`, `user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    public function down(): void
    {
        foreach (['competency_assessment_rating_proposal', 'competency_assessment_attempt'] as $table) {
            if ($this->tableExists($table)) {
                DB::statement("DROP TABLE `$table`");
            }
        }

        $table = 'competency_assessment_test';
        if (!$this->tableExists($table)) {
            return;
        }

        if ($this->indexExists($table, 'cat_scope_index')) {
            DB::statement("ALTER TABLE `$table` DROP INDEX `cat_scope_index`");
        }

        foreach (['is_open', 'pass_percent', 'time_limit_minutes', 'kasba_item_id', 'competency_id', 'scope_type'] as $column) {
            if ($this->columnExists($table, $column)) {
                DB::statement("ALTER TABLE `$table` DROP COLUMN `$column`");
            }
        }
    }

    /**
     * Not Schema::hasColumn()/hasTable().
     *
     * Laravel 11 introspects with a query selecting `generation_expression`,
     * which live's MariaDB 10.1 does not have - so those helpers throw against
     * production while working perfectly on dev.
     */
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
