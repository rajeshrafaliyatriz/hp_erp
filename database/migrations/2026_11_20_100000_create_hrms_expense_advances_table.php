<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Employee Advances, and the two columns that tie a claim to one.
 *
 * An advance is money HR pays an employee BEFORE they spend it ("₹20,000 for
 * the Delhi trip"). The employee later claims the real expenses and the claim
 * is settled against the advance, so only the difference is paid out.
 *
 *   requested -> approved -> paid -> claimed (balance used up)
 *             \-> rejected
 *   requested -> cancelled (by the employee)
 *
 * ── THE BALANCE IS DERIVED, NEVER TYPED ─────────────────────────────────────
 *
 * unclaimed = advance_amount - claimed_amount, for an advance that has been
 * paid. `claimed_amount` is only ever raised by the server, at the moment a
 * claim that uses the advance is marked PAID - not when it is submitted or
 * approved, because until then the money has not actually moved and the claim
 * can still be rejected. A claim records what it wants to use in
 * `hrms_expense_claims.advance_adjusted`, and that is re-checked against the
 * live balance when it is paid.
 *
 * ── `approver_id` IS A SNAPSHOT ─────────────────────────────────────────────
 *
 * The reporting manager when it was requested, as on claims.
 *
 * ── NO FOREIGN KEYS ─────────────────────────────────────────────────────────
 *
 * tbluser is shared with the legacy Blade app and live is MariaDB 10.1. The
 * controllers check existence and tenant. Every query filters on
 * `sub_institute_id`.
 *
 * Run AFTER 2026_11_19_100000_create_hrms_expense_tables.php (it alters the
 * claims table that one creates). Idempotent; uses information_schema directly
 * because Schema::hasTable() throws on MariaDB 10.1.
 *
 * RUN ON EACH DATABASE, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_11_20_100000_create_hrms_expense_advances_table.php
 *   php artisan migrate --database=live --path=database/migrations/2026_11_20_100000_create_hrms_expense_advances_table.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!$this->tableExists('hrms_expense_advances')) {
            Schema::create('hrms_expense_advances', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('sub_institute_id');

                // ADV-2026-0001, unique per organisation.
                $table->string('advance_no', 30);
                // tbluser.id of the person asking.
                $table->unsignedBigInteger('employee_id');

                $table->string('purpose', 255);
                $table->date('needed_by')->nullable();
                $table->text('note')->nullable();

                $table->decimal('advance_amount', 14, 2);
                // Raised by the server when a claim using this advance is paid.
                $table->decimal('claimed_amount', 14, 2)->default(0);
                $table->string('currency', 3)->default('INR');

                // requested | approved | rejected | paid | claimed | cancelled
                $table->string('status', 15)->default('requested');

                $table->timestamp('requested_at')->nullable();
                $table->unsignedBigInteger('approver_id')->nullable();

                $table->unsignedBigInteger('decided_by')->nullable();
                $table->timestamp('decided_at')->nullable();
                $table->text('decision_note')->nullable();

                $table->unsignedBigInteger('paid_by')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->string('payment_reference', 100)->nullable();

                $table->timestamps();
                $table->softDeletes();

                $table->unique(['sub_institute_id', 'advance_no'], 'hexpadv_tenant_no_uniq');
                $table->index(['sub_institute_id', 'employee_id', 'status'], 'hexpadv_tenant_emp_status_idx');
                $table->index(['sub_institute_id', 'approver_id', 'status'], 'hexpadv_tenant_appr_status_idx');
            });
        }

        if ($this->tableExists('hrms_expense_claims')) {
            if (!$this->columnExists('hrms_expense_claims', 'advance_id')) {
                Schema::table('hrms_expense_claims', function (Blueprint $table) {
                    // The advance this claim is settled against. Null = none.
                    $table->unsignedBigInteger('advance_id')->nullable()->index('hexpclaim_advance_idx');
                });
            }

            if (!$this->columnExists('hrms_expense_claims', 'advance_adjusted')) {
                Schema::table('hrms_expense_claims', function (Blueprint $table) {
                    // How much of that advance this claim uses. Paid out = total - this.
                    $table->decimal('advance_adjusted', 14, 2)->default(0);
                });
            }
        }
    }

    public function down(): void
    {
        if ($this->tableExists('hrms_expense_claims')) {
            if ($this->columnExists('hrms_expense_claims', 'advance_id')) {
                Schema::table('hrms_expense_claims', function (Blueprint $table) {
                    $table->dropIndex('hexpclaim_advance_idx');
                    $table->dropColumn('advance_id');
                });
            }

            if ($this->columnExists('hrms_expense_claims', 'advance_adjusted')) {
                Schema::table('hrms_expense_claims', function (Blueprint $table) {
                    $table->dropColumn('advance_adjusted');
                });
            }
        }

        Schema::dropIfExists('hrms_expense_advances');
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
