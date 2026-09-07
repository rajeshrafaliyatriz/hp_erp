<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A payroll month you can declare finished. F-129.
 *
 * Sprint 6 stopped a re-save DUPLICATING a month. It did not stop a re-save
 * happening at all - and once salaries are paid, silently rewriting the figures
 * behind them is its own defect. `employee_monthly_salary_data` has no state:
 * a month that has been paid and a month still being edited are the same rows.
 *
 * "Locked" is a fact about a MONTH, not about a payslip, so it does not belong
 * as a column on 122 rows that would then have to agree with each other. One
 * row per (tenant, month, year).
 *
 * A LOCK YOU CANNOT UNDO IS A TRAP, so this records reopening too. A month is
 * reopened WITH A REASON, by a named person, at a recorded time - which makes
 * "why were March's figures changed after we paid them?" a question the data can
 * answer rather than one that needs somebody's memory.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_month_locks', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('sub_institute_id');

            // Stored exactly as employee_monthly_salary_data stores them, so the
            // join is direct and nobody has to remember a conversion: month is
            // the 3-letter label ('Nov'), year is the payroll year, which for
            // Jan-Mar is already the incremented one.
            $table->string('month', 20);
            $table->year('year');

            $table->timestamp('locked_at')->nullable();
            $table->unsignedBigInteger('locked_by')->nullable();

            // Reopening is recorded on the same row rather than deleting it: a
            // month that was locked, reopened and locked again has a history,
            // and deleting the row would erase it.
            $table->timestamp('reopened_at')->nullable();
            $table->unsignedBigInteger('reopened_by')->nullable();
            $table->string('reopen_reason', 255)->nullable();

            $table->timestamps();

            $table->unique(['sub_institute_id', 'month', 'year'], 'payroll_month_locks_period_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_month_locks');
    }
};
