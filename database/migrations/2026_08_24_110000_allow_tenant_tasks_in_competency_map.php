<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * LETS AN ORGANISATION MAP THE TASKS IT WROTE ITSELF.
 *
 * ── THE PROBLEM ─────────────────────────────────────────────────────────────
 *
 * `jobrole_task_competency_map` answers "which competencies does this task
 * exercise". Its `jobrole_task_id` references `s_jobrole_task` - the SHARED
 * master catalogue - proved on dev, where 121 of 121 rows match it.
 *
 * But employees are assigned tasks from `s_user_jobrole_task`, the tenant's own
 * list. Crossing from one to the other needs `catalogue_task_id`, and on live
 * that column is populated for **0 of 91,539 rows**. So no live task can reach
 * its competencies, and task readiness could never say anything but "unknown".
 *
 * Worse for the direction this product has taken. Phase 1 made a new
 * organisation start empty and author its OWN job role tasks. Such a task was
 * never copied from the catalogue, so it has no catalogue row to point at - and
 * under the old shape it could not be mapped at all. The feature was
 * structurally unavailable to exactly the customers the work was built for.
 *
 * ── WHY THE CATALOGUE KEYING BOUGHT NOTHING ────────────────────────────────
 *
 * Every row already carries `sub_institute_id` and every read scopes by it, so
 * a mapping is never shared between organisations even though the task it names
 * is. The table was tenant-private data keyed on a shared id for a benefit it
 * never collected.
 *
 * ── WHAT CHANGES ────────────────────────────────────────────────────────────
 *
 *   jobrole_task_id       -> now NULLABLE (a tenant-keyed row leaves it empty)
 *   user_jobrole_task_id  -> NEW, references the tenant's own task
 *
 * EXACTLY ONE IS SET. MariaDB 10.1 has no usable CHECK constraint, so the rule
 * lives in the writer, which refuses a row with both or neither rather than
 * storing a shape every reader would then have to guess at.
 *
 * ── TWO UNIQUE INDEXES, NOT ONE ────────────────────────────────────────────
 *
 * `uq_jtcm` is (sub_institute_id, jobrole_task_id, competency_id). Once
 * `jobrole_task_id` is nullable it stops constraining tenant-keyed rows at all,
 * because MySQL treats NULLs as distinct - two identical tenant mappings would
 * both be accepted. So the tenant population gets its own unique index. Each
 * index governs the population it names, and neither interferes with the other.
 *
 * ── THE NAME BACKFILL IS DELIBERATELY NOT HERE ─────────────────────────────
 *
 * Populating live's `catalogue_task_id` by matching task text is a 91,539-row
 * data change with a real error rate (sampled: 76% one match, 20% ambiguous,
 * 5% none). Burying that inside a schema migration would hide its numbers.
 * It runs as a separate, reported step.
 *
 * RUN ON BOTH DATABASES:
 *   php artisan migrate --path=database/migrations/2026_08_24_110000_allow_tenant_tasks_in_competency_map.php
 *   php artisan migrate --database=live --path=database/migrations/2026_08_24_110000_allow_tenant_tasks_in_competency_map.php
 */
return new class extends Migration
{
    private const TABLE = 'jobrole_task_competency_map';
    private const FK    = 'jtcm_user_task_id_foreign';
    private const UQ    = 'uq_jtcm_user';

    public function up(): void
    {
        if (!$this->tableExists(self::TABLE) || !$this->tableExists('s_user_jobrole_task')) {
            return;
        }

        // A catalogue-keyed row keeps its id; a tenant-keyed one leaves it NULL.
        if (str_contains(strtolower($this->columnNullable(self::TABLE, 'jobrole_task_id')), 'no')) {
            DB::statement('ALTER TABLE `' . self::TABLE . '` MODIFY `jobrole_task_id` BIGINT UNSIGNED NULL');
        }

        if (!$this->columnExists(self::TABLE, 'user_jobrole_task_id')) {
            DB::statement(
                'ALTER TABLE `' . self::TABLE . '`
                 ADD COLUMN `user_jobrole_task_id` BIGINT UNSIGNED NULL AFTER `jobrole_task_id`'
            );
        }

        if (!$this->indexExists(self::TABLE, 'idx_jtcm_user_task')) {
            DB::statement('ALTER TABLE `' . self::TABLE . '` ADD INDEX `idx_jtcm_user_task` (`user_jobrole_task_id`)');
        }

        /*
         * The tenant population's own uniqueness. Same three-part shape as
         * uq_jtcm so the two read alike, and so a second organisation can map
         * its own copy of a task without colliding with the first.
         */
        if (!$this->indexExists(self::TABLE, self::UQ)) {
            DB::statement(
                'ALTER TABLE `' . self::TABLE . '`
                 ADD UNIQUE KEY `' . self::UQ . '` (`sub_institute_id`, `user_jobrole_task_id`, `competency_id`)'
            );
        }

        /*
         * ON DELETE CASCADE, unlike the SET NULL used for role links elsewhere
         * in this work: a mapping row has no meaning once its task is gone. It
         * is part of the task's definition, not a relationship that should
         * survive as an orphan pointing nowhere.
         */
        if (!$this->foreignKeyExists(self::TABLE, self::FK)) {
            DB::statement(
                'ALTER TABLE `' . self::TABLE . '`
                 ADD CONSTRAINT `' . self::FK . '`
                 FOREIGN KEY (`user_jobrole_task_id`) REFERENCES `s_user_jobrole_task` (`id`)
                 ON DELETE CASCADE ON UPDATE CASCADE'
            );
        }
    }

    public function down(): void
    {
        if ($this->foreignKeyExists(self::TABLE, self::FK)) {
            DB::statement('ALTER TABLE `' . self::TABLE . '` DROP FOREIGN KEY `' . self::FK . '`');
        }
        if ($this->indexExists(self::TABLE, self::UQ)) {
            DB::statement('ALTER TABLE `' . self::TABLE . '` DROP INDEX `' . self::UQ . '`');
        }
        if ($this->indexExists(self::TABLE, 'idx_jtcm_user_task')) {
            DB::statement('ALTER TABLE `' . self::TABLE . '` DROP INDEX `idx_jtcm_user_task`');
        }
        if ($this->columnExists(self::TABLE, 'user_jobrole_task_id')) {
            DB::statement('ALTER TABLE `' . self::TABLE . '` DROP COLUMN `user_jobrole_task_id`');
        }

        /*
         * `jobrole_task_id` is deliberately left NULLABLE.
         *
         * Restoring NOT NULL would fail outright if any tenant-keyed row still
         * existed, and would otherwise silently re-impose a constraint on rows
         * that were written without it. A rollback that can fail on real data
         * is not a rollback.
         */
    }

    /* ----------------------------------------------------------------- *
     * Not Schema::* - Laravel 11 introspects with a query selecting
     * `generation_expression`, which live's MariaDB 10.1 lacks, so those
     * helpers throw against production while working on dev.
     * ----------------------------------------------------------------- */

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

    /** 'YES' / 'NO', or '' when the column is absent. */
    private function columnNullable(string $table, string $column): string
    {
        $rows = DB::select(
            'SELECT IS_NULLABLE AS n FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            [$table, $column]
        );

        return $rows === [] ? '' : (string) $rows[0]->n;
    }

    private function indexExists(string $table, string $index): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$table, $index]
        ) !== [];
    }

    private function foreignKeyExists(string $table, string $constraint): bool
    {
        return DB::select(
            "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY' LIMIT 1",
            [$table, $constraint]
        ) !== [];
    }
};
