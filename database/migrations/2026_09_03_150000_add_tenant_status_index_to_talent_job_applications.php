<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * talent_job_applications: composite index on (sub_institute_id, status).
 *
 *   php artisan migrate --database=mysql --path=database/migrations/2026_09_03_150000_add_tenant_status_index_to_talent_job_applications.php
 *   php artisan migrate --database=live  --path=database/migrations/2026_09_03_150000_add_tenant_status_index_to_talent_job_applications.php
 *
 * ── WHY ─────────────────────────────────────────────────────────────────────
 *
 * Every query against this table is tenant-scoped and NO index contained
 * `sub_institute_id` on either host - measured, not assumed:
 *
 *   idx_job_id           (job_id)
 *   tapp_candidate_idx   (candidate_id)
 *   PRIMARY              (id)
 *
 * So all three of the shapes the application actually issues were full table
 * scans on both hosts, `type=ALL key=NULL`.
 *
 * ── WHY COMPOSITE, AND WHY THIS ORDER ───────────────────────────────────────
 *
 * `sub_institute_id` alone is a poor index on this data. Measured distribution:
 *
 *   tenant 7 ... 150 of 281 rows (53.4%)     tenant 3 ... 107     tenant 6 ... 24
 *
 * Half the table in one value, so a tenant-only index still visits half the
 * rows for the busiest tenant. Adding `status` cuts the worst case from 150 rows
 * (53.4%) to 58 (20.6%), and 14 distinct pairs against 3 distinct tenants.
 *
 * `status` earns second place because the recruitment kanban and the analytics
 * KPIs both count per status inside a tenant - `WHERE sub_institute_id = ? AND
 * status = ?` appears five times in EmployeeDirectoryAnalyticsController alone.
 * Leading with `sub_institute_id` means the tenant-only queries still use the
 * index as a leftmost prefix, so one index serves both shapes.
 *
 * ── SIZE, AGAINST LIVE'S LIMITS ─────────────────────────────────────────────
 *
 *   sub_institute_id  bigint unsigned .............. 8 bytes
 *   status            varchar(30) utf8mb4 .......... 120 bytes + length prefix
 *                                                    ~131 total, cap is 767
 *   index name        tapp_tenant_status_idx ....... 22 chars, cap is 64
 *
 * Named explicitly rather than left to Laravel, whose generated name for this
 * pair would be `talent_job_applications_sub_institute_id_status_index` - 53
 * characters, under the limit here but the reason the house rule exists.
 *
 * ── MEASURED EFFECT ─────────────────────────────────────────────────────────
 *
 * I expected the optimiser to keep table-scanning at this size, since scanning
 * 281 rows is cheap. It does not - EXPLAIN, identical on both hosts:
 *
 *                      before                          after
 *   tenant only        ALL  key=NULL  rows=281   ->    ref    rows=150  Using index
 *   tenant + status    ALL  key=NULL  rows=281   ->    ref    rows=58   Using index condition
 *   tenant + newest    ALL  key=NULL  rows=281   ->    ref    rows=150  (filesort remains)
 *
 * The tenant-only count is now answered from the index alone - `Using index`,
 * so the table is never touched. That is the shape the analytics KPIs use most.
 *
 * ── WHAT THIS DOES NOT FIX, STATED PLAINLY ──────────────────────────────────
 *
 * It does not remove the filesort on the recruitment list, which orders by
 * `applied_date` (talent_jobapplicationcontroller.php:591). That would need a
 * second index, `(sub_institute_id, applied_date)`, and a second index on a
 * 281-row table costs more in write amplification than it returns. Left out
 * deliberately, and worth revisiting when the table is an order of magnitude
 * larger.
 */
return new class extends Migration
{
    private const TABLE = 'talent_job_applications';
    private const INDEX = 'tapp_tenant_status_idx';

    public function up(): void
    {
        if (!$this->tableExists(self::TABLE) || $this->indexExists(self::INDEX)) {
            return;
        }

        DB::statement(
            'ALTER TABLE `' . self::TABLE . '`
             ADD INDEX `' . self::INDEX . '` (`sub_institute_id`, `status`)'
        );
    }

    public function down(): void
    {
        if (!$this->tableExists(self::TABLE) || !$this->indexExists(self::INDEX)) {
            return;
        }

        DB::statement('ALTER TABLE `' . self::TABLE . '` DROP INDEX `' . self::INDEX . '`');
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
