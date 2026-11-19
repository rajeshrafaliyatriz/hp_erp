<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Salary payments: money actually paid to an employee against a processed
 * month of salary, so the Employee Ledger can say how much is still owed.
 *
 * ── WHY THIS EXISTS ─────────────────────────────────────────────────────────
 *
 * Payroll records what each employee EARNED for a month
 * (`employee_monthly_salary_data.total_payment` is the net) but nothing
 * records that it was PAID - there is no paid flag, and `received_by` is a
 * free-text name that is null on every live row. "How much salary is still
 * owed" therefore had no answer. A row here is one payment, and a month's
 * balance is its net salary less the payments recorded against it. Payment can
 * be partial, and several payments can land on one month.
 *
 * ── MONTH AND YEAR ARE STORED THE WAY PAYROLL STORES THEM ───────────────────
 *
 * `month` is the three-letter name ("Aug") and `year` the four-digit year, the
 * same strings employee_monthly_salary_data holds, so a payment joins to its
 * month with no date arithmetic to get wrong.
 *
 * ── PAYMENTS ARE REVERSED, NOT EDITED ───────────────────────────────────────
 *
 * A mistaken entry is soft-deleted and re-entered. The ledger is an account, and
 * an account whose past lines can be rewritten is not one.
 *
 * ── NO FOREIGN KEYS ─────────────────────────────────────────────────────────
 *
 * tbluser is shared with the legacy Blade app and live is MariaDB 10.1. The
 * controller checks existence and tenant; every query filters on
 * `sub_institute_id`.
 *
 * Idempotent; uses information_schema directly because Schema::hasTable()
 * throws on MariaDB 10.1.
 *
 * RUN ON EACH DATABASE, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_11_21_100000_create_hrms_salary_payments_table.php
 *   php artisan migrate --database=live --path=database/migrations/2026_11_21_100000_create_hrms_salary_payments_table.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if ($this->tableExists('hrms_salary_payments')) {
            return;
        }

        Schema::create('hrms_salary_payments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('sub_institute_id');

            // tbluser.id
            $table->unsignedBigInteger('employee_id');

            // The processed month this pays: "Aug" + 2026.
            $table->string('month', 20);
            $table->unsignedSmallInteger('year');

            $table->decimal('amount', 14, 2);
            $table->date('paid_on');
            $table->string('payment_reference', 100)->nullable();
            $table->string('note', 255)->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['sub_institute_id', 'employee_id', 'year', 'month'], 'hsalpay_tenant_emp_month_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hrms_salary_payments');
    }

    /** information_schema directly - live is MariaDB 10.1, where Schema::hasTable() throws. */
    private function tableExists(string $table): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table]
        ) !== [];
    }
};
