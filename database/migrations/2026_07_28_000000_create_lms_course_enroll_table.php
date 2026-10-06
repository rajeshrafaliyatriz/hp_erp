<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The CREATE for `lms_course_enroll`, which never had one.
 *
 * ── WHY THIS FILE EXISTS ────────────────────────────────────────────────────
 *
 * `lms_course_enroll` is the largest LMS table — 1,496 rows on live — and the
 * repository contains no migration that creates it. It exists in both
 * databases and nowhere in the history. Searching `database/migrations` for
 * the name returns exactly two files, and both only ALTER it:
 *
 *   2026_07_29_110000_add_pending_to_lms_course_enroll_status.php
 *   2026_07_28_110000_create_lms_content_progress_table.php (a comment)
 *
 * So `php artisan migrate` on a clean database FAILS at that ALTER — it tries
 * to modify a table nothing ever made. Anybody standing up a fresh
 * environment hits it, and the failure looks like "the LMS database is not
 * built", which is close to how this work was first reported.
 *
 * ── WHY IT IS BACKDATED ─────────────────────────────────────────────────────
 *
 * Dated 2026_07_28_000000, before the ALTER that depends on it, so a fresh
 * database creates the table and then alters it in the right order. The date
 * is honest: the table demonstrably predates that ALTER.
 *
 * ── WHY IT CHANGES NOTHING ON DEV OR LIVE ───────────────────────────────────
 *
 * Guarded on existence, so on both existing databases it is a no-op that only
 * records the table in the migration ledger. The live table keeps its ENUM
 * status column and its nullable tenant; this definition is what a NEW
 * database gets, and it is deliberately stricter:
 *
 *   - `sub_institute_id BIGINT UNSIGNED NOT NULL`. On live it is `int(11)`
 *     NULL, which is exactly why the learner's course list cannot safely
 *     filter on it and has to scope through `sub_std_map` instead. A new
 *     database should not inherit that.
 *   - `status VARCHAR(20)` + a PHP const rather than ENUM, per the house rule:
 *     adding a value to an ENUM later means an ALTER TABLE rebuild.
 *
 * NO UNIQUE KEY on (user_id, course_id), deliberately. Live holds 3 duplicate
 * pairs, written by a store() with no dedupe, so the constraint would fail
 * there the moment anybody tried to add it — and code that assumes uniqueness
 * would be wrong about the data it actually has. `EnrolmentWriter` takes the
 * latest non-deleted row for this reason.
 *
 * Live is MariaDB 10.1.48, where Schema::hasTable() throws — hence the
 * information_schema check, copied from 2026_08_27_210000_create_task_documents.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_07_28_000000_create_lms_course_enroll_table.php
 *   php artisan migrate --database=live --path=database/migrations/2026_07_28_000000_create_lms_course_enroll_table.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if ($this->tableExists('lms_course_enroll')) {
            return;
        }

        DB::statement("
            CREATE TABLE `lms_course_enroll` (
                `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `sub_institute_id` BIGINT UNSIGNED NOT NULL,
                `user_id`          BIGINT UNSIGNED NOT NULL,
                `course_id`        BIGINT UNSIGNED NOT NULL,
                `status`           VARCHAR(20) NOT NULL DEFAULT 'enrolled',
                `start_date`       DATE NOT NULL,
                `end_date`         DATE NULL,
                `created_at`       TIMESTAMP NULL,
                `updated_at`       TIMESTAMP NULL,
                `deleted_at`       TIMESTAMP NULL,
                PRIMARY KEY (`id`),
                KEY `lms_course_enroll_lookup` (`sub_institute_id`, `user_id`, `course_id`),
                KEY `lms_course_enroll_course_idx` (`course_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    public function down(): void
    {
        // Deliberately not dropped. This migration did not create the table on
        // any existing database, and dropping 1,496 rows of live enrolments to
        // reverse a no-op would be catastrophic.
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
