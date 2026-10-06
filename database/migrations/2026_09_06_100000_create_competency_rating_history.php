<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A record of how a capability rating got to where it is.
 *
 * ── WHY THIS TABLE HAS TO EXIST ─────────────────────────────────────────────
 *
 * `competency_kasba_rating` carries UNIQUE (sub_institute_id, user_id,
 * kasba_item_id) and its own migration says why:
 *
 *     "One current rating per employee per item. History belongs in the event
 *      store, not in a second row here."
 *
 * That was the right call for the current-value table, and the event store it
 * defers to does exist. But nothing has ever recorded a rating change into it:
 * neither writer imports EventRecorder, so a re-rating simply destroys the old
 * value. On live, Milan's item 706 is dated 2026-09-02 while his other
 * nineteen are 2026-08-25 — he was re-rated, and what he was rated before is
 * gone.
 *
 * The consequence is that the product cannot answer the one question anybody
 * asks about capability development: DID THIS PERSON IMPROVE? A gap that
 * closed leaves no trace of ever having been open. The single "history" screen
 * an employee can reach today is fabricated — EmployeeCompetencyProfileController
 * synthesises "Current Rating" and "Initial Assessment" and stamps BOTH with
 * the current level, so it always draws a flat line and always will.
 *
 * ── WHY A TABLE AND NOT THE EVENT STORE ─────────────────────────────────────
 *
 * g2g_event is append-only, tenant-scoped and well suited to this, and a
 * `rating.changed` event is still worth emitting. But the event store is
 * drained by a scheduler every ten minutes into reactors and projectors; it is
 * not a queryable series. "Show me this employee's capability over the last six
 * months, per competency, with what caused each move" is a read this screen
 * makes on every open, and answering it by replaying an event log is the wrong
 * shape. This is the projection.
 *
 * ── APPEND-ONLY, AND DELIBERATELY DENORMALISED ──────────────────────────────
 *
 * No unique key: several rows per (user, item) is the entire point. `old_rating`
 * is NULL when an item is rated for the first time — distinct from a change to
 * the same value, which is simply never written.
 *
 * `competency_id` and `course_id` are copied in rather than joined for. A
 * rating traced back through source_ref_id -> lms_quiz_attempt -> question_paper
 * -> sub_std_map is four joins across two id spaces that overlap (an attempt_id
 * can mean two different things — see the `source` column on
 * competency_assessment_rating_proposal). Storing the answer at write time,
 * when the writer already holds it, is both cheaper and less likely to be
 * silently wrong later.
 *
 * ── LIVE CONSTRAINTS ────────────────────────────────────────────────────────
 *
 * MariaDB 10.1.48: VARCHAR + PHP constant rather than ENUM, every index named
 * and under 64 characters, no index wider than 767 bytes, and existence checked
 * through information_schema because Schema::hasTable() throws on 10.1.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_09_06_100000_create_competency_rating_history.php
 *   php artisan migrate --database=live --path=database/migrations/2026_09_06_100000_create_competency_rating_history.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!$this->tableExists('competency_rating_history')) {
            DB::statement("
                CREATE TABLE `competency_rating_history` (
                    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `sub_institute_id` BIGINT UNSIGNED NOT NULL,
                    `user_id`          BIGINT UNSIGNED NOT NULL,

                    -- The same dual keying competency_kasba_rating uses: a rating
                    -- is either against a competency's KASBA item, or directly
                    -- against a library item by (kasba_type, item_id).
                    `kasba_item_id`    BIGINT UNSIGNED NULL DEFAULT NULL,
                    `kasba_type`       VARCHAR(20) NULL DEFAULT NULL,
                    `item_id`          BIGINT UNSIGNED NULL DEFAULT NULL,
                    `item_label`       VARCHAR(191) NULL DEFAULT NULL,

                    -- Copied in at write time. See the header.
                    `competency_id`    BIGINT UNSIGNED NULL DEFAULT NULL,
                    `course_id`        BIGINT UNSIGNED NULL DEFAULT NULL,

                    -- NULL old_rating = first measurement, not a drop from zero.
                    `old_rating`       TINYINT UNSIGNED NULL DEFAULT NULL,
                    `new_rating`       TINYINT UNSIGNED NOT NULL,

                    `source`           VARCHAR(32) NOT NULL DEFAULT 'manual',
                    `source_ref_id`    BIGINT UNSIGNED NULL DEFAULT NULL,
                    `assessor_id`      BIGINT UNSIGNED NULL DEFAULT NULL,
                    `note`             TEXT NULL DEFAULT NULL,

                    `changed_at`       DATETIME NOT NULL,
                    `created_at`       TIMESTAMP NULL DEFAULT NULL,

                    PRIMARY KEY (`id`),
                    KEY `idx_crh_tenant_user` (`sub_institute_id`, `user_id`),
                    KEY `idx_crh_item` (`kasba_item_id`),
                    KEY `idx_crh_competency` (`sub_institute_id`, `competency_id`),
                    KEY `idx_crh_course` (`course_id`),
                    KEY `idx_crh_changed` (`sub_institute_id`, `changed_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        /*
         * A quiz question can now name the capability it tests.
         *
         * Without this a course quiz can only ever move a competency as a
         * whole: one percentage applied identically to every KASBA item under
         * it, which is a blunt instrument and not what "the gap fills based on
         * the result" means. With it, a learner who answers the secure-coding
         * questions well and the code-review questions poorly moves those two
         * items differently, which is the whole point of measuring at the KASBA
         * level in the first place.
         *
         * Nullable, because every question authored by hand today has no
         * citation and must keep working exactly as it does.
         */
        if ($this->tableExists('lms_question_master')
            && !$this->columnExists('lms_question_master', 'kasba_item_id')) {
            DB::statement("
                ALTER TABLE `lms_question_master`
                ADD COLUMN `kasba_item_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `topic_id`,
                ADD KEY `idx_lqm_kasba_item` (`kasba_item_id`)
            ");
        }
    }

    public function down(): void
    {
        if ($this->columnExists('lms_question_master', 'kasba_item_id')) {
            DB::statement('ALTER TABLE `lms_question_master` DROP KEY `idx_lqm_kasba_item`');
            DB::statement('ALTER TABLE `lms_question_master` DROP COLUMN `kasba_item_id`');
        }

        if ($this->tableExists('competency_rating_history')) {
            DB::statement('DROP TABLE `competency_rating_history`');
        }
    }

    /** Schema::hasTable() throws on MariaDB 10.1 - information_schema does not. */
    private function tableExists(string $table): bool
    {
        return DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        )->c > 0;
    }

    private function columnExists(string $table, string $column): bool
    {
        return DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$table, $column]
        )->c > 0;
    }
};
