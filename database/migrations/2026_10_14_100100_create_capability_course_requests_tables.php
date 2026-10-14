<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The HR-facing queue for "a gap exists and no course is mapped to close it".
 *
 * ── WHY TWO TABLES, GRAINED PER COMPETENCY ──────────────────────────────────
 *
 * "No course exists for Regulatory Reporting" is ONE piece of work no matter
 * how many employees currently have that gap - the HR action (create or map
 * one course) resolves every one of them at once. Grained per employee instead
 * would mean the same work item appearing once per person, inviting HR to
 * create the same course several times and giving nothing to de-duplicate on.
 *
 * `capability_course_requests` is the parent, UNIQUE on (tenant, competency) -
 * the strongest anti-spam guarantee available, enforced by the index rather
 * than application code, and cheap to add while the table is empty.
 * `capability_course_request_subjects` is the child: who currently has the gap.
 * A re-raise of an already-open request updates `last_seen_at`/`times_seen`
 * and upserts a subject row; it is never a second parent row.
 *
 * ── WHY NOT `suggested_course` OR `s_competency_approvals` ──────────────────
 *
 * `suggested_course` is employee-facing ("you should take this") and keyed on
 * an existing `course_id` - the opposite direction from "no course exists".
 * `s_competency_approvals` is shaped for "a thing exists, decide on it"
 * (subject_type/subject_id point at a row); here the subject is a MISSING
 * thing, so there is no row to point at. Column-naming convention is borrowed
 * from both; neither table is reused.
 *
 * ── A DISMISSAL IS NOT A DELETE ──────────────────────────────────────────────
 *
 * `status='dismissed'` + `dismissed_at`/`dismissed_reason`. The reactor only
 * re-opens a dismissed request once `dismissed_at` is older than the tenant's
 * `reflag_cooldown_days` setting - a competency is one row forever, and that
 * row is its own history.
 *
 * ── LIVE CONSTRAINTS ────────────────────────────────────────────────────────
 *
 * MariaDB 10.1.48: raw CREATE TABLE, VARCHAR not ENUM, existence checked
 * through information_schema (Schema::hasTable() throws on 10.1), every index
 * named and under 64 characters, no index wider than 767 bytes.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_10_14_100100_create_capability_course_requests_tables.php
 *   php artisan migrate --database=live --path=database/migrations/2026_10_14_100100_create_capability_course_requests_tables.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!$this->tableExists('capability_course_requests')) {
            DB::statement("
                CREATE TABLE `capability_course_requests` (
                    `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `sub_institute_id`     BIGINT UNSIGNED NOT NULL,
                    `competency_id`        BIGINT UNSIGNED NOT NULL,
                    `competency_name`      VARCHAR(191) NULL DEFAULT NULL,
                    `jobrole_id`           BIGINT UNSIGNED NULL DEFAULT NULL,
                    `required_proficiency` TINYINT UNSIGNED NULL DEFAULT NULL,
                    `is_mandatory`         TINYINT(1) NOT NULL DEFAULT 1,

                    -- How many rows currently sit in the subjects table for
                    -- this request. Denormalised so the inbox list can sort
                    -- and filter by it without a join-and-count on every row.
                    `affected_count`       INT UNSIGNED NOT NULL DEFAULT 0,

                    -- open | course_created | dismissed | superseded
                    `status`               VARCHAR(20) NOT NULL DEFAULT 'open',

                    -- The event that most recently raised or re-opened this
                    -- request - not a FK to g2g_event (cross-table FKs are not
                    -- used elsewhere in this chain either), just a pointer for
                    -- tracing.
                    `origin_event_id`      BIGINT UNSIGNED NULL DEFAULT NULL,

                    -- Set when CourseCompetencyMapController::store() resolves
                    -- this request - the course HR mapped or created.
                    `created_course_id`    BIGINT UNSIGNED NULL DEFAULT NULL,
                    `reviewer_id`          BIGINT UNSIGNED NULL DEFAULT NULL,
                    `reviewed_at`          DATETIME NULL DEFAULT NULL,
                    `resolution_note`      TEXT NULL DEFAULT NULL,

                    `dismissed_reason`     VARCHAR(191) NULL DEFAULT NULL,
                    `dismissed_at`         DATETIME NULL DEFAULT NULL,

                    `first_raised_at`      DATETIME NOT NULL,
                    `last_seen_at`         DATETIME NOT NULL,
                    `times_seen`           INT UNSIGNED NOT NULL DEFAULT 1,

                    `created_at`           TIMESTAMP NULL DEFAULT NULL,
                    `updated_at`           TIMESTAMP NULL DEFAULT NULL,
                    `deleted_at`           TIMESTAMP NULL DEFAULT NULL,

                    PRIMARY KEY (`id`),
                    -- THE anti-spam guarantee: one open-or-closed request per
                    -- competency per tenant, ever. A re-raise is an UPDATE.
                    UNIQUE KEY `uq_ccr_tenant_competency` (`sub_institute_id`, `competency_id`),
                    KEY `idx_ccr_status` (`sub_institute_id`, `status`),
                    KEY `idx_ccr_jobrole` (`jobrole_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        if (!$this->tableExists('capability_course_request_subjects')) {
            DB::statement("
                CREATE TABLE `capability_course_request_subjects` (
                    `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `request_id`           BIGINT UNSIGNED NOT NULL,
                    `sub_institute_id`     BIGINT UNSIGNED NOT NULL,
                    `user_id`              BIGINT UNSIGNED NOT NULL,
                    `required_proficiency` TINYINT UNSIGNED NULL DEFAULT NULL,
                    `measured_level`       DECIMAL(4,2) NULL DEFAULT NULL,
                    `gap`                  DECIMAL(4,2) NULL DEFAULT NULL,
                    `added_at`             DATETIME NOT NULL,
                    `resolved_at`          DATETIME NULL DEFAULT NULL,
                    `created_at`           TIMESTAMP NULL DEFAULT NULL,
                    `updated_at`           TIMESTAMP NULL DEFAULT NULL,

                    PRIMARY KEY (`id`),
                    -- One standing row per person per request. A resolved
                    -- subject is DELETED (see CapabilityGapResponder's shrink
                    -- rule), not soft-deleted - still affected is the only
                    -- question the inbox asks of this table, and a steadily
                    -- growing history of who used to be affected is not one it
                    -- needs to answer.
                    UNIQUE KEY `uq_ccrs_request_user` (`request_id`, `user_id`),
                    KEY `idx_ccrs_tenant_user` (`sub_institute_id`, `user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    }

    public function down(): void
    {
        if ($this->tableExists('capability_course_request_subjects')) {
            DB::statement('DROP TABLE `capability_course_request_subjects`');
        }
        if ($this->tableExists('capability_course_requests')) {
            DB::statement('DROP TABLE `capability_course_requests`');
        }
    }

    private function tableExists(string $table): bool
    {
        return DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        )->c > 0;
    }
};
