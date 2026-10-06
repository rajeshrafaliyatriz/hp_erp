<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Brings s_invisible_library on live up to the schema the code expects.
 *
 * THE BREAK THIS FIXES. The table has 18 columns on dev and 11 on live -
 * live is missing sub_institute_id, deleted_at and the four audit columns.
 * But LibraryController declares the resource as:
 *
 *     'invisible' => [ ..., 'tenant' => 'shared', 'soft' => true ]
 *
 * and baseQuery() therefore emits
 *
 *     WHERE (sub_institute_id IS NULL OR sub_institute_id = ?)
 *       AND deleted_at IS NULL
 *
 * against columns that do not exist there. Every /api/competency/library/invisible
 * request on live answers 500 with MySQL error 1054, and has done since the
 * resource was declared. It is not a tenancy leak - it is a production break
 * sitting next to one.
 *
 * WHY NULLABLE, AND WHY THE 46 EXISTING ROWS STAY NULL. 'tenant' => 'shared'
 * means this library is platform-curated content readable by every tenant,
 * with a NULL owner, and editable by none of them - baseQuery's $ownedOnly
 * branch is what every write path uses. The rows already there are exactly
 * that curated content, so leaving sub_institute_id NULL is not a gap to
 * backfill; it is the correct value.
 *
 * RUN ON BOTH DATABASES. It is a no-op on dev, where the columns exist:
 *   php artisan migrate --path=database/migrations/2026_08_22_090000_align_s_invisible_library_with_dev_schema.php
 *   php artisan migrate --database=live --path=database/migrations/2026_08_22_090000_align_s_invisible_library_with_dev_schema.php
 */
return new class extends Migration
{
    private const TABLE = 's_invisible_library';

    /** column => the DDL fragment that creates it, matching dev exactly. */
    private const COLUMNS = [
        'sub_institute_id' => 'BIGINT UNSIGNED NULL',
        'created_by'       => 'BIGINT UNSIGNED NULL',
        'updated_by'       => 'BIGINT UNSIGNED NULL',
        'deleted_by'       => 'BIGINT UNSIGNED NULL',
        'created_at'       => 'TIMESTAMP NULL',
        'updated_at'       => 'TIMESTAMP NULL',
        'deleted_at'       => 'TIMESTAMP NULL',
    ];

    public function up(): void
    {
        if (!$this->tableExists(self::TABLE)) {
            return;
        }

        foreach (self::COLUMNS as $column => $definition) {
            if (!$this->columnExists(self::TABLE, $column)) {
                DB::statement('ALTER TABLE `' . self::TABLE . '` ADD COLUMN `' . $column . '` ' . $definition);
            }
        }

        // The tenant filter reads this on every list request.
        if (!$this->indexExists(self::TABLE, 'idx_invisible_tenant')) {
            DB::statement('ALTER TABLE `' . self::TABLE . '` ADD INDEX `idx_invisible_tenant` (`sub_institute_id`)');
        }
    }

    public function down(): void
    {
        /*
         * Deliberately not dropped.
         *
         * On dev these columns predate this migration and carry real audit
         * data; on live, rolling back would restore the 500. A rollback that
         * reintroduces a production error is not a safety net.
         */
    }

    /**
     * Not Schema::hasColumn().
     *
     * Laravel 11 introspects columns with a query selecting
     * `generation_expression` from information_schema. Live runs MariaDB 10.1,
     * which has no such column, so that call throws there while working fine
     * on dev - the difference only shows up against production, which is
     * exactly the database this migration exists for.
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
