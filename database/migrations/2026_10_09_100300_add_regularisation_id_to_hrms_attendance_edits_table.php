<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Let an edit row say which regularisation request produced it.
 *
 * ── WHY ─────────────────────────────────────────────────────────────────────
 *
 * `hrms_attendance_edits` has exactly one writer today -
 * `AttendanceAdminController::correct()` - and it hardcodes `source = 'admin'`.
 * So the `'regularisation'` value this table's own creating migration documents
 * (2026_10_07_100000, lines 80-82: *"'regularisation' for one that came from an
 * approved employee request"*) is produced by nothing, and the Change History
 * screen's empty state tells the user that approved employee requests appear
 * there when they never have.
 *
 * The approval path is about to start writing its own edit row. When it does,
 * the row needs to point back at the request it came from.
 *
 * ── WHY A COLUMN, AND NOT A LOOKUP ──────────────────────────────────────────
 *
 * The alternative was to find the request from `source = 'regularisation'` plus
 * `(user_id, day)`. That is ambiguous: two requests can exist for one day - one
 * rejected and one approved, or one withdrawn and one resubmitted - and
 * `hrms_attendance_regularisations` soft-deletes rather than removing, so the
 * losers are still there. A join that can return two rows for one audit record
 * is not an audit trail.
 *
 * ── Schema::hasColumn CANNOT BE USED HERE ───────────────────────────────────
 *
 * `live` is MariaDB 10.1.48. Laravel's column introspection SELECTs
 * `generation_expression` from `information_schema.COLUMNS`, and 10.1 has no
 * such column - so `Schema::hasColumn()` and `getColumnListing()` THROW there.
 * `Schema::hasTable()` is safe; column checks are not.
 *
 * The raw probe below looks self-contradictory - it queries the very table
 * Laravel's helper fails on - and the reason it works is that it names no
 * column of `information_schema.COLUMNS` that 10.1 lacks.
 *
 * ── NO BACKFILL ─────────────────────────────────────────────────────────────
 *
 * Regularisations approved before this ships have no edit row at all, and this
 * column cannot invent one. Their before-images are in `g2g_event` as
 * `attendance.corrected`, which has carried the regularisation id since Sprint
 * 2. Backfilling would mean parsing JSON payload strings on a 10.1 host with no
 * JSON functions and inventing `created_at` values for rows that were never
 * written. The gap is real, bounded and documented; the screen will say nothing
 * it cannot prove.
 *
 * Reversal: Docs/hrit-audit/_reversals/REVERSAL-2026-10-09-attendance-edits-regularisation-id.sql
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME, BEFORE the controller change ships -
 * otherwise every approval 500s on an unknown column, on the path employees use:
 *   php artisan migrate --path=database/migrations/2026_10_09_100300_add_regularisation_id_to_hrms_attendance_edits_table.php
 *   php artisan migrate --database=live --path=database/migrations/2026_10_09_100300_add_regularisation_id_to_hrms_attendance_edits_table.php
 */
return new class extends Migration
{
    private const TABLE  = 'hrms_attendance_edits';
    private const COLUMN = 'regularisation_id';

    public function up(): void
    {
        if (!$this->tableExists(self::TABLE) || $this->columnExists(self::TABLE, self::COLUMN)) {
            return;   // idempotent: this runs on two hosts
        }

        /*
         * Raw DDL rather than a Blueprint.
         *
         * `Schema::table()` with `$table->unsignedBigInteger(...)->nullable()`
         * would be the house style, but Laravel's `after()` and its change
         * detection both reach for column introspection, and that is what
         * throws on 10.1. One ALTER with no introspection is the portable form.
         */
        DB::statement(
            'ALTER TABLE `' . self::TABLE . '`
                ADD COLUMN `' . self::COLUMN . '` BIGINT UNSIGNED NULL
                AFTER `attendance_id`'
        );

        // The screen asks "what changed this day", not "what did request 412
        // change", so this is a plain index rather than a unique one - and NOT
        // unique on purpose: a single approval corrects exactly one day today,
        // but a future request spanning days would write one edit row per day
        // and a unique index would reject the second.
        DB::statement(
            'ALTER TABLE `' . self::TABLE . '`
                ADD INDEX `hae_regularisation_idx` (`' . self::COLUMN . '`)'
        );
    }

    public function down(): void
    {
        if (!$this->tableExists(self::TABLE) || !$this->columnExists(self::TABLE, self::COLUMN)) {
            return;
        }

        if ($this->indexExists(self::TABLE, 'hae_regularisation_idx')) {
            DB::statement('ALTER TABLE `' . self::TABLE . '` DROP INDEX `hae_regularisation_idx`');
        }

        DB::statement('ALTER TABLE `' . self::TABLE . '` DROP COLUMN `' . self::COLUMN . '`');
    }

    /** information_schema directly - live is MariaDB 10.1, where Schema::hasTable() is safe but this is cheaper to keep alongside the others. */
    private function tableExists(string $table): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table]
        ) !== [];
    }

    /** The replacement for Schema::hasColumn(), which throws on 10.1. See the docblock. */
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
};
