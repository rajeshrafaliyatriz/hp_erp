<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lets a KASBA rating name a library item directly, not only via a competency.
 *
 * WHY. competency_kasba_rating keys on competency_kasba_item.id, so a rating
 * could only be recorded for an item that had already been linked to a
 * competency. On live that link exists for one dimension and not the others:
 *
 *     skill      221 rows, 199 with a usable item_id
 *     knowledge   18 rows,   0
 *     ability      9 rows,   0
 *     attitude     8 rows,   0
 *     behaviour   10 rows,   1
 *
 * The 66 unlinked rows carry free text in item_label - "Infection control
 * protocols", "Hand hygiene compliance" - and none of those labels matches a
 * row in the dimension's library table in ANY tenant, so they cannot be
 * repaired by a backfill; they were authored as prose and never pointed at
 * anything. The consequence was that four of the Competency Rating tab's five
 * categories could not save at all.
 *
 * WHAT. A rating may now be keyed either way:
 *
 *   - kasba_item_id            the existing competency-linked rating
 *   - (kasba_type, item_id)    a direct rating of the library item itself
 *
 * Both are kept. When an item IS linked to a competency the write path records
 * both, so roll-ups through competency_kasba_item keep working exactly as
 * before. The 242 existing rows are untouched.
 *
 * kasba_item_id therefore has to become nullable. It has no foreign key on
 * either database, so nothing else depends on its nullability.
 *
 * RUN ON BOTH DATABASES:
 *   php artisan migrate --path=database/migrations/2026_08_21_140000_allow_direct_kasba_item_ratings.php
 *   php artisan migrate --database=live --path=database/migrations/2026_08_21_140000_allow_direct_kasba_item_ratings.php
 *
 * --path is not optional for live: it is far behind dev, and a bare
 * `migrate --database=live` would run every unrelated pending migration.
 */
return new class extends Migration
{
    private const TABLE = 'competency_kasba_rating';

    /** The direct-rating uniqueness, alongside the existing uq_ckr. */
    private const DIRECT_INDEX = 'uq_ckr_direct';

    public function up(): void
    {
        if (!$this->tableExists(self::TABLE)) {
            return;
        }

        /*
         * Raw ALTER rather than Blueprint ->change().
         *
         * Doctrine/Laravel's column introspection is the same code path that
         * reads generation_expression, which MariaDB 10.1 does not have, so a
         * ->change() on live throws where it succeeds on dev. Naming the
         * resulting type explicitly is also safer than asking Laravel to infer
         * it from a schema it cannot fully read.
         */
        if (!$this->columnExists(self::TABLE, 'kasba_type')) {
            DB::statement('ALTER TABLE `' . self::TABLE . '` ADD COLUMN `kasba_type` VARCHAR(20) NULL AFTER `kasba_item_id`');
        }

        if (!$this->columnExists(self::TABLE, 'item_id')) {
            DB::statement('ALTER TABLE `' . self::TABLE . '` ADD COLUMN `item_id` BIGINT UNSIGNED NULL AFTER `kasba_type`');
        }

        if ($this->columnIsNotNull(self::TABLE, 'kasba_item_id')) {
            DB::statement('ALTER TABLE `' . self::TABLE . '` MODIFY COLUMN `kasba_item_id` BIGINT UNSIGNED NULL');
        }

        /*
         * UNIQUE, not just an index: it is what makes the direct write path
         * idempotent, so re-rating the same item updates instead of stacking a
         * second opinion. NULLs compare as distinct in MySQL and MariaDB, so
         * this does not constrain the competency-linked rows (whose kasba_type
         * and item_id are both NULL) - uq_ckr still covers those.
         */
        if (!$this->indexExists(self::TABLE, self::DIRECT_INDEX)) {
            DB::statement(
                'ALTER TABLE `' . self::TABLE . '`
                 ADD UNIQUE INDEX `' . self::DIRECT_INDEX . '`
                 (`sub_institute_id`, `user_id`, `kasba_type`, `item_id`)'
            );
        }
    }

    public function down(): void
    {
        if (!$this->tableExists(self::TABLE)) {
            return;
        }

        if ($this->indexExists(self::TABLE, self::DIRECT_INDEX)) {
            DB::statement('ALTER TABLE `' . self::TABLE . '` DROP INDEX `' . self::DIRECT_INDEX . '`');
        }

        /*
         * The two columns are dropped, but kasba_item_id is deliberately NOT
         * put back to NOT NULL. By the time anyone rolls this back there may be
         * direct ratings whose kasba_item_id is null; restoring the constraint
         * would fail against them, and forcing it through would mean deleting
         * real assessments to satisfy a schema change.
         */
        foreach (['item_id', 'kasba_type'] as $column) {
            if ($this->columnExists(self::TABLE, $column)) {
                DB::statement('ALTER TABLE `' . self::TABLE . '` DROP COLUMN `' . $column . '`');
            }
        }
    }

    /**
     * Not Schema::hasColumn().
     *
     * Laravel 11 introspects columns with a query selecting
     * `generation_expression` from information_schema. Live runs MariaDB 10.1,
     * which has no such column, so that call throws there while working fine on
     * dev - the difference only shows up against production.
     */
    private function columnExists(string $table, string $column): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
              LIMIT 1',
            [$table, $column]
        ) !== [];
    }

    private function columnIsNotNull(string $table, string $column): bool
    {
        return DB::select(
            "SELECT 1 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
                AND IS_NULLABLE = 'NO'
              LIMIT 1",
            [$table, $column]
        ) !== [];
    }

    private function indexExists(string $table, string $index): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
              LIMIT 1',
            [$table, $index]
        ) !== [];
    }

    private function tableExists(string $table): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
              LIMIT 1',
            [$table]
        ) !== [];
    }
};
