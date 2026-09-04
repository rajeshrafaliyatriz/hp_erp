<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Make sequential lesson unlocking a choice instead of a rule.
 *
 * ── WHY ─────────────────────────────────────────────────────────────────────
 *
 * `LmsLearningController::course()` locked every lesson whose predecessor was
 * unfinished, unconditionally. That is a reasonable rule for compliance
 * training and the wrong default for a catalogue of short courses — and its
 * real effect on live was worse than a wrong default.
 *
 * Measured 2026-09-02: 1,454 enrolments, 163 lessons, and **2 rows** in
 * `lms_content_progress`. One learner, ever, has opened a lesson. So for every
 * other learner `$previousComplete` was false from lesson two onward and the
 * whole course beyond the first lesson was locked. It is one of three
 * independent reasons the reported "employee cannot start a course and learn"
 * is true.
 *
 * ── WHY A COLUMN AND NOT A CONSTANT ─────────────────────────────────────────
 *
 * Some courses genuinely must be taken in order. Deleting the rule outright
 * would remove a capability the product should keep; making it per-course
 * keeps it and stops it applying to everything by accident.
 *
 * DEFAULT 0 — off. Note `lms_course_settings` holds **0 rows** on both
 * databases, so every existing course has no settings row at all and takes the
 * unlocked path through the `?:` in the controller. This column changes
 * nothing until somebody deliberately turns it on.
 *
 * ── LIVE CONSTRAINTS ────────────────────────────────────────────────────────
 *
 * Live is MariaDB 10.1.48. `Schema::hasTable()` throws there, so existence is
 * checked against information_schema directly — the same guard as
 * 2026_08_27_210000_create_task_documents.php. TINYINT(1) rather than a
 * boolean cast, and no index: this column is only ever read for one already
 * known course id.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_09_02_190000_add_sequential_unlock_to_lms_course_settings.php
 *   php artisan migrate --database=live --path=database/migrations/2026_09_02_190000_add_sequential_unlock_to_lms_course_settings.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->tableExists('lms_course_settings')) {
            return;
        }

        if ($this->columnExists('lms_course_settings', 'sequential_unlock')) {
            return;
        }

        DB::statement(
            'ALTER TABLE `lms_course_settings`
                ADD COLUMN `sequential_unlock` TINYINT(1) NOT NULL DEFAULT 0
                AFTER `course_id`'
        );
    }

    public function down(): void
    {
        if ($this->columnExists('lms_course_settings', 'sequential_unlock')) {
            DB::statement('ALTER TABLE `lms_course_settings` DROP COLUMN `sequential_unlock`');
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
