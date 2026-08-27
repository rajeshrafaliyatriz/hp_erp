<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HOW a job role task is executed — the ESO classification layer.
 *
 * ── WHAT IS MISSING TODAY ───────────────────────────────────────────────────
 *
 * A job role task is a sentence and nothing else. `s_user_jobrole_task` carries
 * task, jobrole, critical_work_function, task_category, task_type, track and
 * sector - not one column describing how the work is actually done, by whom, at
 * what risk, or whether a machine could do it.
 *
 * This table answers that, one row per tenant task.
 *
 * ── WHY NOT REUSE `task_type` ───────────────────────────────────────────────
 *
 * It looks like the obvious home and it is not. Measured on live: `task_type`
 * is 'Medium' on ALL 55,961 catalogue rows and 91,537 of 91,539 tenant rows -
 * a uniform default carrying zero information - and the UI already renders it
 * as a PRIORITY (Low/Medium/High/Critical). Overloading it would destroy a
 * field two other modules read as priority.
 *
 * ── WHY THIS REPLACES `task_automation_classification` ──────────────────────
 *
 * That table already exists on both databases with almost exactly these columns
 * (rule_score, llm_classification, automation_confidence_score,
 * final_classification, recommended_automation_flow, reasoning,
 * human_intervention_points). It is a previous attempt at this same idea and it
 * is dead: 0 rows on both databases, referenced by no controller, model, route
 * or component in either repository.
 *
 * It is also at the WRONG GRAIN - keyed to `task.id`, the task-management WORK
 * ITEM, so a classification would have belonged to one person's ticket rather
 * than to the duty itself. Its tenant column is misspelt `sub_institude_id`.
 *
 * It is dropped here rather than left, because a third parallel structure for
 * one idea is how this becomes unmaintainable. `down()` recreates it exactly.
 *
 * ── PER TENANT NOW, PROMOTABLE LATER ────────────────────────────────────────
 *
 * Keyed on the TENANT task, because that is what a customer owns and edits.
 * `catalogue_task_id` is carried alongside - populated on 98% of live rows -
 * so a classification can later be promoted to the shared catalogue and
 * inherited by every tenant WITHOUT a migration or a re-run. Every tenant draws
 * 100% of its tasks from that catalogue (measured: tenant 1 40,297 of 40,297),
 * so that promotion is worth keeping cheap.
 *
 * RUN ON BOTH DATABASES:
 *   php artisan migrate --path=database/migrations/2026_08_27_100000_create_jobrole_task_execution.php
 *   php artisan migrate --database=live --path=database/migrations/2026_08_27_100000_create_jobrole_task_execution.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!$this->tableExists('jobrole_task_execution')) {
            DB::statement("
                CREATE TABLE `jobrole_task_execution` (
                    `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `sub_institute_id`     BIGINT UNSIGNED NOT NULL,

                    -- The tenant's own task row. What a customer owns and edits.
                    `user_jobrole_task_id` BIGINT UNSIGNED NOT NULL,
                    -- The shared catalogue row it came from, where known. Carried
                    -- so this can be promoted to catalogue-level IP later.
                    `catalogue_task_id`    BIGINT UNSIGNED NULL,

                    -- SIX MODES. `system_automated` is deliberately NOT an AI mode:
                    -- deterministic workflow is not machine learning, and saying it
                    -- is loses credibility with anyone technical.
                    -- human_only | human_ai_assist | ai_human_review
                    -- ai_supervised | ai_autonomous | system_automated
                    `execution_mode_current` VARCHAR(20) NULL,
                    `execution_mode_target`  VARCHAR(20) NULL,

                    -- FOUR DIMENSIONS, not twelve. Twelve cannot be scored
                    -- consistently at thousands-of-tasks scale.
                    `digital_input`      TINYINT UNSIGNED NULL,  -- inputs already digital/structured
                    `rule_clarity`       TINYINT UNSIGNED NULL,  -- can correctness be specified
                    `judgment_required`  TINYINT UNSIGNED NULL,  -- contextual/ethical judgement (inverse)
                    `error_consequence`  TINYINT UNSIGNED NULL,  -- cost of getting it wrong (inverse)

                    `ai_executability_score` TINYINT UNSIGNED NULL,
                    -- Low | Medium | High | Regulated. Acts as a CEILING on the
                    -- target mode - enforced in the service, not left to a model.
                    `risk_class`             VARCHAR(12) NULL,
                    `automation_rationale`   TEXT NULL,

                    `human_effort_current_min` INT UNSIGNED NULL,
                    `human_effort_target_min`  INT UNSIGNED NULL,

                    -- Unclassified | AI-proposed | Human-reviewed | Approved.
                    -- An AI proposal must never be presented as fact, so the
                    -- default is the honest one.
                    `classification_status` VARCHAR(20) NOT NULL DEFAULT 'AI-proposed',
                    `model`                 VARCHAR(100) NULL,
                    `classified_at`         TIMESTAMP NULL,
                    `classified_by`         BIGINT UNSIGNED NULL,
                    `reviewed_by`           BIGINT UNSIGNED NULL,
                    `reviewed_at`           TIMESTAMP NULL,
                    `review_note`           TEXT NULL,

                    `created_at` TIMESTAMP NULL,
                    `updated_at` TIMESTAMP NULL,

                    PRIMARY KEY (`id`),
                    -- One classification per task per tenant. Re-running the pass
                    -- updates rather than duplicating.
                    UNIQUE KEY `uq_jte_task` (`sub_institute_id`, `user_jobrole_task_id`),
                    KEY `jte_status_index` (`sub_institute_id`, `classification_status`),
                    KEY `jte_mode_index` (`sub_institute_id`, `execution_mode_target`),
                    KEY `jte_catalogue_index` (`catalogue_task_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }

        /*
         * The abandoned predecessor. Empty on both databases and referenced by
         * nothing - verified across both repositories before dropping.
         */
        if ($this->tableExists('task_automation_classification')
            && (int) DB::table('task_automation_classification')->count() === 0) {
            DB::statement('DROP TABLE `task_automation_classification`');
        }
    }

    public function down(): void
    {
        if ($this->tableExists('jobrole_task_execution')) {
            DB::statement('DROP TABLE `jobrole_task_execution`');
        }

        // Recreated exactly as it was, typo'd tenant column and all, so a
        // rollback restores the schema it found.
        if (!$this->tableExists('task_automation_classification')) {
            DB::statement("
                CREATE TABLE `task_automation_classification` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `task_id` BIGINT UNSIGNED NULL,
                    `clean_title` TEXT NULL,
                    `rule_score` DECIMAL(10,2) NULL,
                    `rule_classification` VARCHAR(255) NULL,
                    `llm_classification` VARCHAR(255) NULL,
                    `automation_confidence_score` DECIMAL(10,2) NULL,
                    `final_score` DECIMAL(10,2) NULL,
                    `final_classification` VARCHAR(255) NULL,
                    `recommended_automation_flow` TEXT NULL,
                    `override_reason` TEXT NULL,
                    `reasoning` TEXT NULL,
                    `human_intervention_points` TEXT NULL,
                    `decision_source` VARCHAR(255) NULL,
                    `timestamp` TIMESTAMP NULL,
                    `sub_institude_id` BIGINT UNSIGNED NULL,
                    `created_at` TIMESTAMP NULL,
                    `updated_at` TIMESTAMP NULL,
                    `deleted_at` TIMESTAMP NULL,
                    PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
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
};
