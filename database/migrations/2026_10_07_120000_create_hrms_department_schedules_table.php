<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Office hours, per department, per weekday.
 *
 * ── THERE WAS NOWHERE TO PUT THIS ───────────────────────────────────────────
 *
 * Office hours live in exactly one place today: fourteen `time` columns on
 * `tbluser`, per employee per day, plus seven tinyint flags saying which days
 * are worked. There is no office-hours table to extend. Three things look like
 * one and are not:
 *
 *   - `hrms_in_out_times` exists, is EMPTY, and is referenced by nothing.
 *   - `tenant_setting['org.working_days']` is written by a settings screen and
 *     read by no attendance code at all.
 *   - `hrms_weekdays` (tenant, full/half/weekend) describes the same week from
 *     the tenant's side and is not reconciled with the tbluser flags.
 *
 * So "per department" needs a new table, and this is it. It is a TEMPLATE, not
 * a second source of truth: applying it writes the tbluser columns, because
 * those are what the attendance and payroll calculations actually read. Nothing
 * downstream has to learn about this table.
 *
 * ── WHY IT MATTERS MORE THAN A CONVENIENCE ──────────────────────────────────
 *
 * 2,008 of 2,283 active employees - 88% - have no working days set at all; all
 * seven flags are 0. The two screens that read them then disagree about the
 * same person, because each invented its own fallback: Monthly Attendance
 * Report treats a 0 as a weekend, so every day of the month reads "weekend",
 * while Attendance Tracking falls back to "everything except Sunday is worked".
 * Setting a department schedule and applying it is the only way to fix that for
 * 2,008 people without editing 2,008 employees by hand.
 *
 * ── THE WEEKDAY IS A NAME, NOT AN INDEX ─────────────────────────────────────
 *
 * `weekday` holds 'monday'..'sunday' - the exact tbluser column prefix - rather
 * than 0-6. That is deliberate. Two live endpoints select only
 * `tbluser.monday_in_date` and compare EVERY day of the week against it, and
 * the Early Going Report reads Saturday's column for Thursday. Both are index
 * arithmetic gone wrong. A name maps to its column with no arithmetic to get
 * wrong: 'saturday' -> saturday, saturday_in_date, saturday_out_date.
 *
 * ── NULL TIMES ON A WORKING DAY ARE ALLOWED ─────────────────────────────────
 *
 * is_working = 1 with null times means "this day is worked, hours not set" -
 * which is a real state and different from "not worked". The apply writes the
 * flag and leaves the time columns alone in that case, rather than clearing
 * somebody's hours to midnight.
 *
 * Reversal: Docs/hrit-audit/_reversals/REVERSAL-2026-10-07-department-schedules.sql
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_10_07_120000_create_hrms_department_schedules_table.php
 *   php artisan migrate --database=live --path=database/migrations/2026_10_07_120000_create_hrms_department_schedules_table.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hrms_department_schedules')) {
            return;   // idempotent: this runs on two hosts
        }

        Schema::create('hrms_department_schedules', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('sub_institute_id');
            $table->unsignedBigInteger('department_id');

            // 'monday'..'sunday' - the tbluser column prefix, so the apply is a
            // direct mapping. See the docblock for why not an index.
            $table->string('weekday', 9);

            $table->boolean('is_working')->default(true);

            // Nullable on purpose: "worked, hours not set" is a real state and
            // is not the same as "not worked".
            $table->time('in_time')->nullable();
            $table->time('out_time')->nullable();

            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            // One row per department per weekday. The unique index is what makes
            // the writer an upsert rather than a thing that accumulates seven
            // more rows every time somebody presses Save.
            $table->unique(['sub_institute_id', 'department_id', 'weekday'], 'hds_tenant_dept_weekday_uniq');
            $table->index(['sub_institute_id', 'department_id'], 'hds_tenant_dept_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hrms_department_schedules');
    }
};
