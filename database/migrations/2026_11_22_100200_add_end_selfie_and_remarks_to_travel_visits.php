<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Travel Visit: a selfie and a remark when the trip ENDS, beside the ones taken
 * at the start.
 *
 * `remarks` stays what the employee wrote when starting; `end_remarks` is what
 * they wrote when finishing, so the second no longer overwrites the first.
 * Trips that ended before this have both columns NULL.
 *
 * RUN ON BOTH DATABASES, AFTER 2026_11_22_100000_create_hrms_travel_visits_tables.php:
 *   php artisan migrate --path=database/migrations/2026_11_22_100200_add_end_selfie_and_remarks_to_travel_visits.php
 *   php artisan migrate --database=live --path=database/migrations/2026_11_22_100200_add_end_selfie_and_remarks_to_travel_visits.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!$this->tableExists('hrms_travel_visits')) {
            return;
        }

        if (!$this->columnExists('hrms_travel_visits', 'end_selfie_ref')) {
            Schema::table('hrms_travel_visits', function (Blueprint $table) {
                $table->string('end_selfie_ref', 500)->nullable()->after('selfie_ref');
            });
        }

        if (!$this->columnExists('hrms_travel_visits', 'end_remarks')) {
            Schema::table('hrms_travel_visits', function (Blueprint $table) {
                $table->text('end_remarks')->nullable()->after('remarks');
            });
        }
    }

    public function down(): void
    {
        if (!$this->tableExists('hrms_travel_visits')) {
            return;
        }

        foreach (['end_selfie_ref', 'end_remarks'] as $column) {
            if ($this->columnExists('hrms_travel_visits', $column)) {
                Schema::table('hrms_travel_visits', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }

    /** information_schema directly - live is MariaDB 10.1, where Schema::hasTable() throws. */
    private function tableExists(string $table): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table]
        ) !== [];
    }

    private function columnExists(string $table, string $column): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            [$table, $column]
        ) !== [];
    }
};
