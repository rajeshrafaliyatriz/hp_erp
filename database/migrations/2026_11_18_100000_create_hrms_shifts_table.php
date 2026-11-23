<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shift Master: the named working shifts an organisation runs ("General
 * 09:00-18:00", "Night 22:00-06:00"), and which one an employee is on.
 *
 * ── A NAMED SHIFT, NOT A REPLACEMENT FOR THE ROSTER ─────────────────────────
 *
 * Office hours per employee still live in the 14 `tbluser` day columns, and
 * attendance/payroll read those. This table does not change that: a shift is
 * a label an employee is on, with the hours it stands for, so HR can say "who
 * is on nights" and the profile can show it. It deliberately does not write
 * the roster columns - doing so from here would take authorship of hours that
 * department templates and employee requests already stamp provenance on.
 *
 * ── `is_overnight` IS STORED, NOT DERIVED AT READ TIME ──────────────────────
 *
 * A shift whose end time is not after its start time runs past midnight. The
 * controller sets the flag on write so every reader (list, profile, reports)
 * agrees without each re-deriving it.
 *
 * ── `tbluser.shift_id` IS NULLABLE AND UNCONSTRAINED ────────────────────────
 *
 * No foreign key: tbluser is the legacy table shared with the Blade app, and
 * the live host is MariaDB 10.1. Existence and tenant are checked in the
 * controller instead, as every other reference on that table is.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_11_18_100000_create_hrms_shifts_table.php
 *   php artisan migrate --database=live --path=database/migrations/2026_11_18_100000_create_hrms_shifts_table.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!$this->tableExists('hrms_shifts')) {
            Schema::create('hrms_shifts', function (Blueprint $table) {
                $table->bigIncrements('id');

                $table->unsignedBigInteger('sub_institute_id');

                $table->string('name', 100);
                $table->string('code', 20)->nullable();

                $table->time('start_time');
                $table->time('end_time');
                $table->boolean('is_overnight')->default(false);

                // Unpaid break inside the shift, in minutes.
                $table->unsignedSmallInteger('break_minutes')->default(0);
                // How late an arrival is still "on time", in minutes.
                $table->unsignedSmallInteger('grace_minutes')->default(0);

                $table->string('color', 20)->nullable();
                $table->text('description')->nullable();
                $table->tinyInteger('status')->default(1);

                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['sub_institute_id', 'status'], 'hshift_tenant_status_idx');
            });
        }

        if ($this->tableExists('tbluser') && !$this->columnExists('tbluser', 'shift_id')) {
            Schema::table('tbluser', function (Blueprint $table) {
                $table->unsignedBigInteger('shift_id')->nullable()->index('tbluser_shift_id_idx');
            });
        }
    }

    public function down(): void
    {
        if ($this->tableExists('tbluser') && $this->columnExists('tbluser', 'shift_id')) {
            Schema::table('tbluser', function (Blueprint $table) {
                $table->dropIndex('tbluser_shift_id_idx');
                $table->dropColumn('shift_id');
            });
        }

        Schema::dropIfExists('hrms_shifts');
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
