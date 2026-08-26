<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give the last six name-keyed tables a job role id.
 *
 * These six reference a job role ONLY by its name string. Six tables is not the
 * problem; what the name costs is:
 *
 *   - A name is not unique. 90 role names in one live tenant belong to roles in
 *     MORE THAN ONE department, so a name cannot say which role it means.
 *   - Renaming a role silently orphans every row that named it.
 *   - A merge cannot re-point what it cannot identify.
 *
 * After this, every table that references a job role has somewhere to put the
 * id. Populating it is `jobroles:backfill-ids`, deliberately NOT done here:
 * roughly 6% of rows cannot be keyed by any rule and have to be REPORTED rather
 * than guessed, and a migration is the wrong place to produce a report someone
 * has to read and act on.
 *
 * NULLABLE, AND NO FOREIGN KEY. Both on purpose. An FK would refuse exactly the
 * rows this is meant to expose - the ambiguous ones and the ones naming a role
 * that no longer exists - and the point is that those rows survive unkeyed and
 * get listed. The tables that already carry an FK got one when their data was
 * already clean; these are not.
 *
 * `jobrole` IS NOT DROPPED and is not touched. It stays the row's label, and
 * roughly twenty screens still read it - one `whereIn('jobrole', ...)` in
 * CommandCenterService alone feeds thirteen Command Center metrics, and the
 * skill matrix builds its entire column list from GROUP BY jobrole. This
 * migration is additive; nothing that works today stops working.
 *
 * RUN ON BOTH DATABASES:
 *   php artisan migrate --path=database/migrations/2026_08_26_100000_add_jobrole_id_to_name_keyed_tables.php
 *   php artisan migrate --database=live --path=database/migrations/2026_08_26_100000_add_jobrole_id_to_name_keyed_tables.php
 */
return new class extends Migration
{
    /**
     * table => [name column, column to add the id after, index name]
     *
     * All six were verified present on dev AND live, all six lacking the id.
     * Live rows carrying a role name: certifications 221, assessments 141,
     * mapping reviews 20, certification requirements 14, performance reviews
     * 13, appraisals 4.
     */
    private const TABLES = [
        's_competency_assessments'                => ['jobrole', 'idx_sca_jobrole_id'],
        's_competency_certifications'             => ['jobrole', 'idx_scc_jobrole_id'],
        's_competency_certification_requirements' => ['jobrole', 'idx_sccr_jobrole_id'],
        's_competency_mapping_reviews'            => ['jobrole', 'idx_scmr_jobrole_id'],
        's_performance_reviews'                   => ['jobrole', 'idx_spr_jobrole_id'],
        's_performance_appraisals'                => ['jobrole', 'idx_spa_jobrole_id'],
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => [$nameColumn, $indexName]) {
            // A table absent on this database is skipped, not failed - the two
            // schemas have drifted before.
            if (!$this->tableExists($table) || !$this->columnExists($table, $nameColumn)) {
                continue;
            }

            if (!$this->columnExists($table, 'jobrole_id')) {
                DB::statement(
                    'ALTER TABLE `' . $table . '` ADD COLUMN `jobrole_id` BIGINT UNSIGNED NULL AFTER `' . $nameColumn . '`'
                );
            }

            if (!$this->indexExists($table, $indexName)) {
                DB::statement('ALTER TABLE `' . $table . '` ADD INDEX `' . $indexName . '` (`jobrole_id`)');
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table => [$nameColumn, $indexName]) {
            if (!$this->tableExists($table)) {
                continue;
            }

            if ($this->indexExists($table, $indexName)) {
                DB::statement('ALTER TABLE `' . $table . '` DROP INDEX `' . $indexName . '`');
            }

            if ($this->columnExists($table, 'jobrole_id')) {
                DB::statement('ALTER TABLE `' . $table . '` DROP COLUMN `jobrole_id`');
            }
        }

        // `jobrole` was never written, so rolling back loses nothing: every row
        // still carries the name it had before this ran.
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

    private function tableExists(string $table): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table]
        ) !== [];
    }
};
