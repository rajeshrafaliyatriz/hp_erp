<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Index the column every LMS tenant query filters on.
 *
 * ── WHY ─────────────────────────────────────────────────────────────────────
 *
 * `lms_course_enroll` is the largest LMS table (1,496 rows on live) and its
 * only indexes are PRIMARY, `course_id` and `user_id`. `lms_assignments` has
 * five indexes and none on the tenant either. Yet every read of both begins
 * by narrowing to one organisation, so each of those reads scans rows
 * belonging to organisations it will then discard.
 *
 * It is small today and grows with every enrolment. 1,496 rows is nothing;
 * the same query shape at 100,000 is a table scan per page view.
 *
 * ── SHAPE ───────────────────────────────────────────────────────────────────
 *
 * Composite, tenant first, because that is the order the predicates are
 * written in: tenant, then the person, then the course. A lone
 * `sub_institute_id` index would be nearly useless — one tenant holds 1,333
 * of the 1,496 rows, so its selectivity is poor on its own and excellent in
 * combination.
 *
 * Every index is NAMED. Laravel's generator has produced names over the
 * 64-character limit in this project before.
 *
 * Live is MariaDB 10.1.48; existence is checked through information_schema
 * because Schema::hasTable() throws there.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_09_02_200000_add_tenant_indexes_to_lms_tables.php
 *   php artisan migrate --database=live --path=database/migrations/2026_09_02_200000_add_tenant_indexes_to_lms_tables.php
 */
return new class extends Migration
{
    /** table => [index name, column list] */
    private const INDEXES = [
        'lms_course_enroll' => ['lms_course_enroll_tenant_idx', '`sub_institute_id`, `user_id`, `course_id`'],
        'lms_assignments'   => ['lms_assignments_tenant_idx', '`sub_institute_id`, `user_id`, `course_id`'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => [$name, $columns]) {
            if (! $this->tableExists($table) || $this->indexExists($table, $name)) {
                continue;
            }

            DB::statement("ALTER TABLE `{$table}` ADD INDEX `{$name}` ({$columns})");
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => [$name, $columns]) {
            if ($this->tableExists($table) && $this->indexExists($table, $name)) {
                DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$name}`");
            }
        }
    }

    private function tableExists(string $table): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table]
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
};
