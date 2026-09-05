<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * talent_team_members + talent_resume_screenings: give them a tenant column.
 *
 *   php artisan migrate --database=mysql --path=database/migrations/2026_09_03_170000_adopt_talent_team_and_resume_screening_tables.php
 *   php artisan migrate --database=live  --path=database/migrations/2026_09_03_170000_adopt_talent_team_and_resume_screening_tables.php
 *
 * ── WHY ─────────────────────────────────────────────────────────────────────
 *
 * Audit F-59: tenancy on this platform is in-row, and these two tables carry no
 * `sub_institute_id`. The proposal had been to drop them, since both hold 0 rows
 * on both hosts. The decision was to keep them and make them real, so the
 * finding is closed the other way - by adding the column.
 *
 * Neither table has ever had a migration in this repository. They exist only on
 * the two databases. This file is therefore also the act of adopting them into
 * version control, which is why it is written defensively: every change is
 * guarded, because the starting state was never defined by code.
 *
 * ── WHAT `talent_team_members` ALREADY DOES, WHICH IS NOT NOTHING ───────────
 *
 * It looks dead and is not. It is a registered entry in the department
 * merge/delete engine:
 *
 *   Services/Org/DepartmentMergeService.php:89   'talent_team_members' => 'team members'
 *   Console/Commands/DepartmentsDedupe.php:83    'talent_team_members'
 *
 * `impact()` counts it, `merge()` repoints its department_id, `release()` NULLs
 * it on delete, and the delete/merge dialog renders the count. Nobody has seen
 * that line only because the table is empty and the UI filters zeros. Once it
 * holds rows, an existing screen starts reporting them.
 *
 * ── THE CHANGES, AND WHY EACH ───────────────────────────────────────────────
 *
 * sub_institute_id  BIGINT UNSIGNED NOT NULL - the finding itself. NOT NULL is
 *                   only safe because both tables are empty; that is re-checked
 *                   at run time below rather than trusted.
 *
 * deleted_at        TIMESTAMP NULL - every sibling talent_* table has it. Its
 *                   absence is exactly what the audit complained about for
 *                   s_mobility_talent_pool_members, where removeMember() hard
 *                   deletes with no audit trail. Not repeating that here.
 *
 * role              ENUM('HR Manager','Recruiter','Interviewer') -> VARCHAR(30).
 *                   House rule is VARCHAR plus a PHP const, never ENUM, so that
 *                   adding a role later is a code change and not an ALTER TABLE
 *                   rebuild on live. HiringTeamController::ROLES is the
 *                   vocabulary from here on. The three values are preserved.
 *
 * ── SIZE, AGAINST LIVE'S LIMITS ─────────────────────────────────────────────
 *
 *   ttm_tenant_role_idx         (sub_institute_id, role)
 *                               8 + (30 x 4) = 128 bytes, 19-char name
 *   trs_tenant_application_idx  (sub_institute_id, application_id)
 *                               8 + 8       = 16 bytes, 26-char name
 *
 * Cap is 767 bytes under live's ROW_FORMAT=Compact, and 64 characters for an
 * identifier. Both are far inside. The existing FK on
 * talent_resume_screenings.application_id -> talent_job_applications.id is left
 * alone, and its idx_application_id index with it.
 */
return new class extends Migration
{
    private const TEAM = 'talent_team_members';
    private const SCREENING = 'talent_resume_screenings';

    public function up(): void
    {
        /*
         * Check BOTH tables before touching either. NOT NULL with no default
         * cannot be added to a table that already holds rows, and failing half
         * way through would leave the two hosts in different states - the one
         * thing this project's migration rule exists to prevent.
         */
        foreach ([self::TEAM, self::SCREENING] as $table) {
            if (!$this->tableExists($table)) {
                continue;
            }

            $rows = (int) DB::table($table)->count();

            if ($rows > 0 && !$this->columnExists($table, 'sub_institute_id')) {
                throw new RuntimeException(
                    $table . " holds {$rows} row(s) but has no sub_institute_id, so the column "
                    . 'cannot be added NOT NULL without deciding which tenant those rows belong to. '
                    . 'This migration was written when both tables were empty on both hosts. '
                    . 'Resolve the rows first, then re-run.'
                );
            }
        }

        if ($this->tableExists(self::TEAM)) {
            if (!$this->columnExists(self::TEAM, 'sub_institute_id')) {
                DB::statement('ALTER TABLE `' . self::TEAM . '` ADD COLUMN `sub_institute_id` BIGINT UNSIGNED NOT NULL AFTER `id`');
            }

            if (!$this->columnExists(self::TEAM, 'deleted_at')) {
                DB::statement('ALTER TABLE `' . self::TEAM . '` ADD COLUMN `deleted_at` TIMESTAMP NULL DEFAULT NULL AFTER `updated_at`');
            }

            if ($this->isEnum(self::TEAM, 'role')) {
                DB::statement('ALTER TABLE `' . self::TEAM . '` MODIFY `role` VARCHAR(30) NULL DEFAULT "Recruiter"');
            }

            if (!$this->indexExists(self::TEAM, 'ttm_tenant_role_idx')) {
                DB::statement('ALTER TABLE `' . self::TEAM . '` ADD INDEX `ttm_tenant_role_idx` (`sub_institute_id`, `role`)');
            }
        }

        if ($this->tableExists(self::SCREENING)) {
            if (!$this->columnExists(self::SCREENING, 'sub_institute_id')) {
                DB::statement('ALTER TABLE `' . self::SCREENING . '` ADD COLUMN `sub_institute_id` BIGINT UNSIGNED NOT NULL AFTER `id`');
            }

            if (!$this->columnExists(self::SCREENING, 'deleted_at')) {
                DB::statement('ALTER TABLE `' . self::SCREENING . '` ADD COLUMN `deleted_at` TIMESTAMP NULL DEFAULT NULL AFTER `updated_at`');
            }

            if (!$this->indexExists(self::SCREENING, 'trs_tenant_application_idx')) {
                DB::statement('ALTER TABLE `' . self::SCREENING . '` ADD INDEX `trs_tenant_application_idx` (`sub_institute_id`, `application_id`)');
            }
        }
    }

    /**
     * Reversing drops the tenant column, and with it the only thing separating
     * one institute's rows from another's. Kept reversible because the rule says
     * so, but anything seeded after this ran is not recoverable by re-applying.
     */
    public function down(): void
    {
        if ($this->tableExists(self::TEAM)) {
            if ($this->indexExists(self::TEAM, 'ttm_tenant_role_idx')) {
                DB::statement('ALTER TABLE `' . self::TEAM . '` DROP INDEX `ttm_tenant_role_idx`');
            }
            if ($this->columnExists(self::TEAM, 'sub_institute_id')) {
                DB::statement('ALTER TABLE `' . self::TEAM . '` DROP COLUMN `sub_institute_id`');
            }
            if ($this->columnExists(self::TEAM, 'deleted_at')) {
                DB::statement('ALTER TABLE `' . self::TEAM . '` DROP COLUMN `deleted_at`');
            }
            if (!$this->isEnum(self::TEAM, 'role')) {
                DB::statement(
                    'ALTER TABLE `' . self::TEAM . "` MODIFY `role` ENUM('HR Manager','Recruiter','Interviewer') NULL DEFAULT 'Recruiter'"
                );
            }
        }

        if ($this->tableExists(self::SCREENING)) {
            if ($this->indexExists(self::SCREENING, 'trs_tenant_application_idx')) {
                DB::statement('ALTER TABLE `' . self::SCREENING . '` DROP INDEX `trs_tenant_application_idx`');
            }
            if ($this->columnExists(self::SCREENING, 'sub_institute_id')) {
                DB::statement('ALTER TABLE `' . self::SCREENING . '` DROP COLUMN `sub_institute_id`');
            }
            if ($this->columnExists(self::SCREENING, 'deleted_at')) {
                DB::statement('ALTER TABLE `' . self::SCREENING . '` DROP COLUMN `deleted_at`');
            }
        }
    }

    /** Schema::hasTable() throws on the live host; information_schema does not. */
    private function tableExists(string $table): bool
    {
        return !empty(DB::select(
            'SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table]
        ));
    }

    private function columnExists(string $table, string $column): bool
    {
        return !empty(DB::select(
            'SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            [$table, $column]
        ));
    }

    private function indexExists(string $table, string $index): bool
    {
        return !empty(DB::select(
            'SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$table, $index]
        ));
    }

    private function isEnum(string $table, string $column): bool
    {
        return !empty(DB::select(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
               AND DATA_TYPE = 'enum' LIMIT 1",
            [$table, $column]
        ));
    }
};
