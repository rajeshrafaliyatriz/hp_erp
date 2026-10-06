<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * s_mobility_jobs.job_id: global UNIQUE -> UNIQUE (sub_institute_id, job_id).
 *
 *   php artisan migrate --database=mysql --path=database/migrations/2026_09_03_160000_scope_mobility_job_code_unique_per_tenant.php
 *   php artisan migrate --database=live  --path=database/migrations/2026_09_03_160000_scope_mobility_job_code_unique_per_tenant.php
 *
 * ── WHY ─────────────────────────────────────────────────────────────────────
 *
 * The internal job code is generated PER TENANT and constrained GLOBALLY, and
 * the two have never agreed:
 *
 *   MobilityJobController::store():120   ->where('sub_institute_id', $tenant)
 *   s_mobility_jobs_job_id_unique        UNIQUE (job_id)          <- no tenant
 *
 * So the first tenant to post an internal job in a given year takes
 * `INT-<year>-001` for everybody. Tenant 3 holds INT-2026-001 on both hosts, so
 * every OTHER tenant posting its first internal job in 2026 hits a duplicate
 * key. Not a validation message either - the insert is unguarded, so it
 * surfaces as an uncaught UniqueConstraintViolationException, an HTTP 500 with
 * a stack trace. Found by trying it as tenant 6:
 *
 *   Duplicate entry 'INT-2026-001' for key 's_mobility_jobs_job_id_unique'
 *
 * The whole Internal Job Postings feature is therefore unusable for every
 * tenant except whichever one posted first.
 *
 * ── WHY SCOPE THE CONSTRAINT RATHER THAN CHANGE THE CODE FORMAT ─────────────
 *
 * A per-tenant sequence is the intended behaviour - each institute numbering its
 * own postings from 001 is what the generator is written to do, and it is what
 * the code on screen means to a user. The constraint is the half that is wrong.
 *
 * It also matches how tenant-scoped identity is done everywhere else in this
 * schema, e.g. talent_candidates' UNIQUE (sub_institute_id, email_key).
 *
 * ── SIZE, AGAINST LIVE'S LIMITS ─────────────────────────────────────────────
 *
 *   sub_institute_id  bigint unsigned .......... 8 bytes
 *   job_id            varchar(100) utf8mb4 ..... 400 bytes
 *                                               408 total, cap is 767
 *   index name        smj_tenant_job_id_unique . 24 chars, cap is 64
 *
 * Measured on both hosts: 1 row each, longest existing code 12 characters, so
 * nothing violates the new constraint and the rebuild is instant.
 */
return new class extends Migration
{
    private const TABLE = 's_mobility_jobs';
    private const OLD_INDEX = 's_mobility_jobs_job_id_unique';
    private const NEW_INDEX = 'smj_tenant_job_id_unique';

    public function up(): void
    {
        if (!$this->tableExists(self::TABLE)) {
            return;
        }

        // Add first, drop second: if the add fails there is still a constraint
        // on the column rather than none.
        if (!$this->indexExists(self::NEW_INDEX)) {
            DB::statement(
                'ALTER TABLE `' . self::TABLE . '`
                 ADD UNIQUE `' . self::NEW_INDEX . '` (`sub_institute_id`, `job_id`)'
            );
        }

        if ($this->indexExists(self::OLD_INDEX)) {
            DB::statement('ALTER TABLE `' . self::TABLE . '` DROP INDEX `' . self::OLD_INDEX . '`');
        }
    }

    public function down(): void
    {
        if (!$this->tableExists(self::TABLE)) {
            return;
        }

        /*
         * Reversing can fail, legitimately. Once two tenants each hold
         * INT-2026-001 - which is the whole point of this migration - a global
         * unique on job_id cannot be recreated. The duplicate check runs first
         * so the failure is a clear message rather than a driver error.
         */
        if (!$this->indexExists(self::OLD_INDEX)) {
            $dupes = DB::select(
                'SELECT job_id, COUNT(*) n FROM `' . self::TABLE . '`
                 GROUP BY job_id HAVING n > 1 LIMIT 1'
            );

            if ($dupes) {
                throw new RuntimeException(
                    'Cannot restore a global unique on ' . self::TABLE . '.job_id: '
                    . 'job_id "' . $dupes[0]->job_id . '" is now held by more than one tenant.'
                );
            }

            DB::statement(
                'ALTER TABLE `' . self::TABLE . '` ADD UNIQUE `' . self::OLD_INDEX . '` (`job_id`)'
            );
        }

        if ($this->indexExists(self::NEW_INDEX)) {
            DB::statement('ALTER TABLE `' . self::TABLE . '` DROP INDEX `' . self::NEW_INDEX . '`');
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

    private function indexExists(string $index): bool
    {
        return !empty(DB::select(
            'SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [self::TABLE, $index]
        ));
    }
};
