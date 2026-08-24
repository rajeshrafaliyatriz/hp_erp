<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The LAST name-keyed hop in the capability chain.
 *
 * ── WHAT WAS WRONG ──────────────────────────────────────────────────────────
 *
 * `s_competency_frameworks` links to a job role through `jobrole` - a TEXT
 * column holding the role's NAME. Its sibling `department_id` is already a
 * proper id, so this column is the odd one out rather than a house style.
 *
 * A name is not a key. Rename the role and the framework quietly stops
 * pointing at it; two roles sharing a name inside one organisation cannot be
 * told apart. That is the same defect already fixed for job role tasks
 * (2026_08_22, `jobrole_id`) and for the department resolver, and this is the
 * last place it survives.
 *
 * ── THE BACKFILL, AND WHY IT REFUSES TO GUESS ───────────────────────────────
 *
 * Measured on BOTH databases before writing this - identical on each:
 *
 *     32 frameworks carry a role name
 *     30 resolve to exactly one role in their own organisation  -> keyed
 *      0 match nothing
 *      2 match MORE THAN ONE role                               -> left NULL
 *
 * The two ambiguous rows are left NULL on purpose. Picking the lower id would
 * produce a link that looks authoritative and is a coin toss; the earlier
 * name-matched provenance backfill is the cautionary case, where 5,470 rows
 * were resolved by guessing and none of them can now be trusted. A NULL is
 * visibly missing, and someone can set it correctly in one click.
 *
 * `jobrole` IS DELIBERATELY NOT DROPPED. It stays as the human label and as the
 * fallback for the two unresolved rows, and dropping a populated column in the
 * same migration that introduces its replacement leaves no way back.
 *
 * RUN ON BOTH DATABASES:
 *   php artisan migrate --path=database/migrations/2026_08_24_090000_add_jobrole_id_to_competency_frameworks.php
 *   php artisan migrate --database=live --path=database/migrations/2026_08_24_090000_add_jobrole_id_to_competency_frameworks.php
 */
return new class extends Migration
{
    private const TABLE = 's_competency_frameworks';
    private const FK    = 'scf_jobrole_id_foreign';

    public function up(): void
    {
        if (!$this->tableExists(self::TABLE) || !$this->tableExists('s_user_jobrole')) {
            return;
        }

        if (!$this->columnExists(self::TABLE, 'jobrole_id')) {
            DB::statement('ALTER TABLE `' . self::TABLE . '` ADD COLUMN `jobrole_id` BIGINT UNSIGNED NULL AFTER `department_id`');
        }

        if (!$this->indexExists(self::TABLE, 'idx_scf_jobrole_id')) {
            DB::statement('ALTER TABLE `' . self::TABLE . '` ADD INDEX `idx_scf_jobrole_id` (`jobrole_id`)');
        }

        /*
         * Keyed only where the name resolves to EXACTLY ONE role in the same
         * organisation. The GROUP BY / HAVING COUNT(*) = 1 is what enforces
         * that - an ambiguous name produces more than one row and is excluded
         * by the HAVING rather than being silently reduced to its first match.
         */
        DB::statement('
            UPDATE `' . self::TABLE . '` f
              JOIN (
                    SELECT j.sub_institute_id,
                           LOWER(TRIM(j.jobrole)) AS name_key,
                           MIN(j.id)              AS jobrole_id
                      FROM s_user_jobrole j
                     WHERE j.deleted_at IS NULL
                     GROUP BY j.sub_institute_id, LOWER(TRIM(j.jobrole))
                    HAVING COUNT(*) = 1
                   ) u
                ON u.sub_institute_id = f.sub_institute_id
               AND u.name_key         = LOWER(TRIM(f.jobrole))
               SET f.jobrole_id = u.jobrole_id
             WHERE f.jobrole_id IS NULL
               AND f.jobrole IS NOT NULL
               AND f.jobrole <> \'\'
               AND f.deleted_at IS NULL
        ');

        /*
         * The constraint enforces EXISTENCE, not tenancy - a framework could
         * still be pointed at another organisation's role id by a bad write, so
         * the tenant check stays in application code where it already lives.
         * A foreign key is the floor, not the whole guard.
         *
         * ON DELETE SET NULL because s_user_jobrole soft-deletes: an ordinary
         * retirement never fires this, and a genuine hard delete should leave
         * the framework unlinked rather than block the delete.
         */
        if (!$this->foreignKeyExists(self::TABLE, self::FK)) {
            DB::statement(
                'ALTER TABLE `' . self::TABLE . '`
                 ADD CONSTRAINT `' . self::FK . '`
                 FOREIGN KEY (`jobrole_id`) REFERENCES `s_user_jobrole` (`id`)
                 ON DELETE SET NULL ON UPDATE CASCADE'
            );
        }
    }

    public function down(): void
    {
        if ($this->foreignKeyExists(self::TABLE, self::FK)) {
            DB::statement('ALTER TABLE `' . self::TABLE . '` DROP FOREIGN KEY `' . self::FK . '`');
        }

        if ($this->columnExists(self::TABLE, 'jobrole_id')) {
            DB::statement('ALTER TABLE `' . self::TABLE . '` DROP COLUMN `jobrole_id`');
        }

        // `jobrole` was never touched, so rolling back loses nothing: every
        // framework still carries the name it had before this ran.
    }

    /**
     * Not Schema::hasColumn()/hasTable().
     *
     * Laravel 11 introspects with a query selecting `generation_expression`,
     * which live's MariaDB 10.1 does not have - so those helpers throw against
     * production while working perfectly on dev.
     */
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

    private function foreignKeyExists(string $table, string $constraint): bool
    {
        return DB::select(
            "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY' LIMIT 1",
            [$table, $constraint]
        ) !== [];
    }

    private function tableExists(string $table): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table]
        ) !== [];
    }
};
