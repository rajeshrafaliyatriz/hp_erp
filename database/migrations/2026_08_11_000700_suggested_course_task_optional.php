<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * X-13 — a recommendation does not have to come from a task.
 *
 * `suggested_course.task_id` was NOT NULL: the table was built when the only
 * source of a suggestion was a task. X-13 recommends from an EXPIRING
 * CERTIFICATION, which has no task behind it - so the column's shape encoded an
 * assumption about where suggestions come from, and that assumption is no longer
 * true.
 *
 * NULLABLE, NOT DROPPED. Task-derived suggestions still populate it, and 12 rows
 * already do. Widening a column is additive; removing it would discard why those
 * 12 exist.
 */
return new class extends Migration
{
    /**
     * Portable column check.
     *
     * Schema::hasColumn() asks information_schema for `generation_expression`,
     * which exists only on MySQL >= 5.7.6 / MariaDB >= 10.2. One deployment
     * runs MariaDB 10.1.48, where that call dies with
     * "1054 Unknown column 'generation_expression'" before the migration's own
     * logic is ever reached. SHOW COLUMNS has existed in every release.
     */
    private function hasColumnPortable(string $table, string $column): bool
    {
        return DB::table('information_schema.columns')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->exists();
    }

    public function up(): void
    {
        if (!Schema::hasTable('suggested_course') || !$this->hasColumnPortable('suggested_course', 'task_id')) {
            return;
        }

        // Read the real type first (R15) - a guessed type is how a bigint becomes an int.
        $col = collect(DB::select("SHOW COLUMNS FROM `suggested_course` LIKE 'task_id'"))->first();
        if (!$col || $col->Null === 'YES') {
            return;
        }

        DB::statement("ALTER TABLE `suggested_course` MODIFY `task_id` {$col->Type} NULL "
            . "COMMENT 'FK -> task.id. NULL when the suggestion came from something other than a task (X-13: an expiring certification).'");
    }

    /** DELIBERATELY EMPTY - narrowing it again would reject valid rows. */
    public function down(): void
    {
    }
};
