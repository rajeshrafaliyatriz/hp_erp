<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give a training session a way to say which course it belongs to.
 *
 * ── subject_id IS NOT THE COURSE, AND NEVER WAS ─────────────────────────────
 *
 * `lms_virtual_classroom.subject_id` looks like the course link, and both the
 * session controller and the audit treated it as one. It is not. The column
 * carries a foreign key:
 *
 *     lms_virtual_classroom_subject_id_foreign -> subject(id)
 *
 * `subject` is a five-row legacy master list, every row belonging to tenant 1
 * ("Data Analytics and Computational Modelling", "Physiotherapist", ...). A
 * course in this LMS is a `sub_std_map` row — there are hundreds — and course
 * 174's own `sub_std_map.subject_id` is NULL, so even going through the subject
 * table would not find it.
 *
 * The constraint is what actually proved it: writing a course id into that
 * column raises errno 1452 and the write is refused outright. So the link was
 * never merely unpopulated, it was unpopulatable.
 *
 * Hence a new column. `course_id` references sub_std_map by convention and NOT
 * by foreign key: nothing else in this schema constrains to sub_std_map, and a
 * FK would fight its soft deletes — a course is soft-deleted, the row stays,
 * and a FK to it says nothing useful while blocking legitimate cleanup.
 *
 * ── WHAT IT UNLOCKS ─────────────────────────────────────────────────────────
 *
 * With this, attending a session counts toward the course it belongs to. Which
 * sessions count for whom is deliberately narrow — only those a learner is
 * actually registered on — for reasons set out at
 * LmsLearningController::sessionCounts().
 *
 * ── LIVE CONSTRAINTS ────────────────────────────────────────────────────────
 *
 * MariaDB 10.1.48: named index, no FK, existence checked against
 * information_schema because Schema::hasTable() throws there. Indexed because
 * every course-progress query filters on it.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_09_05_100300_add_course_id_to_lms_virtual_classroom.php
 *   php artisan migrate --database=live --path=database/migrations/2026_09_05_100300_add_course_id_to_lms_virtual_classroom.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->tableExists('lms_virtual_classroom')) {
            return;
        }

        if ($this->columnExists('lms_virtual_classroom', 'course_id')) {
            return;
        }

        DB::statement(
            'ALTER TABLE `lms_virtual_classroom`
                ADD COLUMN `course_id` BIGINT(20) UNSIGNED NULL AFTER `subject_id`,
                ADD INDEX `idx_lvc_course` (`course_id`)'
        );
    }

    public function down(): void
    {
        if ($this->columnExists('lms_virtual_classroom', 'course_id')) {
            DB::statement('ALTER TABLE `lms_virtual_classroom` DROP INDEX `idx_lvc_course`');
            DB::statement('ALTER TABLE `lms_virtual_classroom` DROP COLUMN `course_id`');
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
