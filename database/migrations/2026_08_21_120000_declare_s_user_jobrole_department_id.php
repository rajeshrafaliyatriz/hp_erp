<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Declares a column that already exists in production but in no migration.
 *
 * `s_user_jobrole.department_id` is how a job role belongs to a department. It
 * is on both live and dev, it carries a real foreign key to hrms_departments,
 * 4,783 of 4,886 live rows use it, and the Department Management job roles tab
 * reads it - but **nothing in database/migrations creates it**. Four migrations
 * mention both the table and the column name, which is why a grep looks
 * reassuring; none of them adds it.
 *
 * The consequence is that `migrate:fresh` produces a schema the application
 * cannot run against: the department join in JobroleApiController, the
 * DepartmentJobRoleExportController CSV and the merge service's job-role
 * folding all reference a column that would not be there.
 *
 * This adds it only where it is missing, so on live and dev it is a no-op that
 * simply records the truth in the migration history.
 *
 * RUN ON BOTH DATABASES:
 *   php artisan migrate --path=database/migrations/2026_08_21_120000_declare_s_user_jobrole_department_id.php
 *   php artisan migrate --database=live --path=database/migrations/2026_08_21_120000_declare_s_user_jobrole_department_id.php
 *
 * --path is not optional for live: it is far behind dev, and a bare
 * `migrate --database=live` would run every unrelated pending migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!$this->tableExists('s_user_jobrole')) {
            return;
        }

        if (!$this->columnExists('s_user_jobrole', 'department_id')) {
            Schema::table('s_user_jobrole', function (Blueprint $table) {
                // Nullable, and no foreign key declared here. Live already has
                // one; adding a second definition on a table that has the
                // column would fail, and departments are soft-deleted and
                // merged, which a hard FK turns into failed writes.
                $table->unsignedBigInteger('department_id')->nullable()->index();
            });
        }

        // Backfill from the name column that sat beside it, for any database
        // where the column is new. Scoped to the tenant, because two tenants
        // genuinely share department names on live - matching on name alone
        // would cross the boundary.
        DB::table('s_user_jobrole')
            ->whereNull('department_id')
            ->whereNotNull('department')
            ->where('department', '<>', '')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $departmentId = DB::table('hrms_departments')
                        ->where('department', $row->department)
                        ->where('sub_institute_id', $row->sub_institute_id)
                        ->whereNull('deleted_at')
                        ->value('id');

                    if ($departmentId) {
                        DB::table('s_user_jobrole')
                            ->where('id', $row->id)
                            ->update(['department_id' => $departmentId]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Deliberately not dropped. The column predates this migration on every
        // real database, so dropping it on rollback would destroy the link
        // between 4,783 job roles and their departments - data this migration
        // never created.
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
