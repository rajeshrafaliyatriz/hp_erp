<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who changed somebody's attendance, from what, to what, and why.
 *
 * ── WHY A TABLE AND NOT JUST THE EVENT LOG ──────────────────────────────────
 *
 * `g2g_event` already records `attendance.corrected` with a full before-image,
 * and that is the right place for an append-only platform audit. It is not a
 * thing an HR user can open next to the month they are looking at: it is keyed
 * by entity and time, holds every event in the product, and answering "who
 * changed Priya's Tuesday" from it means a query nobody on the HR desk will
 * write.
 *
 * This table answers exactly that question, scoped the way the screen asks it -
 * by employee and day. The event log stays the system of record; this is the
 * reader's copy, and the screen that writes one writes both.
 *
 * ── WHY IT MATTERS MORE THAN A USUAL AUDIT ──────────────────────────────────
 *
 * Attendance feeds pay. `timestamp_diff` is read by PayrollController, and a
 * corrected 2nd-Saturday punch-in changes the late count that is SUBTRACTED
 * from payable days. A correction nobody can account for is a pay change nobody
 * can account for, and the person it lands on is the employee.
 *
 * ── SHAPE ───────────────────────────────────────────────────────────────────
 *
 * The before and after times are stored as plain nullable strings rather than
 * TIME columns, because a null here is meaningful in a way a time cannot carry:
 * it is "there was no punch", which is a different fact from "the punch was at
 * 00:00". The created_row flag separates "these times changed" from "this day
 * did not exist until somebody made it".
 *
 * No foreign keys, matching hrms_attendance_regularisations - an audit row must
 * survive the thing it describes being deleted, or it stops being an audit.
 *
 * Reversal: Docs/hrit-audit/_reversals/REVERSAL-2026-10-07-attendance-edits.sql
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hrms_attendance_edits')) {
            return;   // idempotent: this runs on two hosts
        }

        Schema::create('hrms_attendance_edits', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('sub_institute_id');
            $table->unsignedBigInteger('user_id');          // whose attendance
            $table->date('day');

            // The attendance row this touched. Nullable because the row may be
            // deleted later and this record must outlive it.
            $table->unsignedBigInteger('attendance_id')->nullable();

            $table->string('before_in_time', 20)->nullable();
            $table->string('before_out_time', 20)->nullable();
            $table->string('before_duration', 20)->nullable();

            $table->string('after_in_time', 20)->nullable();
            $table->string('after_out_time', 20)->nullable();
            $table->string('after_duration', 20)->nullable();

            // True when the day had no attendance row at all before this.
            $table->boolean('created_row')->default(false);

            /*
             * Required, like the self-service path requires one. A correction
             * without a reason is the kind of record that satisfies an auditor
             * and helps nobody.
             */
            $table->string('reason', 255);

            // 'admin' for an HR-initiated edit, 'regularisation' for one that
            // came from an approved employee request.
            $table->string('source', 32)->default('admin');

            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            // The screen asks "this employee, this month" and "this tenant,
            // recently"; both are indexed rather than one.
            $table->index(['sub_institute_id', 'user_id', 'day'], 'hae_tenant_user_day_idx');
            $table->index(['sub_institute_id', 'created_at'], 'hae_tenant_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hrms_attendance_edits');
    }
};
