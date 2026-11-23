<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Expense Management (HRIT): categories, claims and the lines on a claim.
 *
 *   hrms_expense_categories   what can be claimed ("Travel", "Meals"...) and its limits
 *   hrms_expense_claims       one claim by one employee: a header with a status
 *   hrms_expense_items        the lines on a claim, each against a category
 *
 * ── STATUS FLOW ─────────────────────────────────────────────────────────────
 *
 *   draft -> submitted -> approved -> paid
 *                      \-> rejected -> (edited) -> submitted again
 *   draft | submitted -> cancelled (by the employee)
 *
 * `status` is a short string, not a number, so a row read in a query console
 * says what it means.
 *
 * ── THE TOTAL IS STORED, BUT THE SERVER OWNS IT ─────────────────────────────
 *
 * `total_amount` is the sum of the items, written by the controller on every
 * save so lists and reports can sort and total it without joining. It is never
 * taken from the request.
 *
 * ── `approver_id` IS A SNAPSHOT ─────────────────────────────────────────────
 *
 * The employee's reporting manager AT SUBMIT TIME. If they are re-assigned
 * later, a claim already in somebody's queue stays in that queue rather than
 * vanishing from it.
 *
 * ── NO FOREIGN KEYS ─────────────────────────────────────────────────────────
 *
 * Same as the rest of this schema: `tbluser` is the legacy table shared with
 * the Blade app and live is MariaDB 10.1. Existence and tenant are checked in
 * the controllers. Every table carries `sub_institute_id` and every query
 * filters on it.
 *
 * Idempotent, because it runs on several databases. Uses information_schema
 * directly - live is MariaDB 10.1, where Schema::hasTable() throws.
 *
 * RUN ON EACH DATABASE, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_11_19_100000_create_hrms_expense_tables.php
 *   php artisan migrate --database=live --path=database/migrations/2026_11_19_100000_create_hrms_expense_tables.php
 *
 * Reversal: php artisan migrate:rollback --path=... (drops all three tables
 * and every expense in them).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!$this->tableExists('hrms_expense_categories')) {
            Schema::create('hrms_expense_categories', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('sub_institute_id');

                $table->string('name', 100);
                $table->string('code', 20)->nullable();
                $table->text('description')->nullable();

                // Largest single line allowed in this category. Null = no limit.
                $table->decimal('max_amount', 12, 2)->nullable();
                // When set, a line must carry a bill / receipt reference.
                $table->boolean('requires_receipt')->default(false);

                $table->tinyInteger('status')->default(1);

                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['sub_institute_id', 'status'], 'hexpcat_tenant_status_idx');
            });
        }

        if (!$this->tableExists('hrms_expense_claims')) {
            Schema::create('hrms_expense_claims', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('sub_institute_id');

                // Human-readable, unique per organisation: EXP-2026-0001.
                $table->string('claim_no', 30);
                // tbluser.id of the person claiming.
                $table->unsignedBigInteger('employee_id');

                $table->string('title', 150);
                $table->text('description')->nullable();

                $table->decimal('total_amount', 14, 2)->default(0);
                $table->string('currency', 3)->default('INR');

                // draft | submitted | approved | rejected | paid | cancelled
                $table->string('status', 15)->default('draft');

                $table->timestamp('submitted_at')->nullable();
                // The reporting manager when it was submitted. See the docblock.
                $table->unsignedBigInteger('approver_id')->nullable();

                $table->unsignedBigInteger('decided_by')->nullable();
                $table->timestamp('decided_at')->nullable();
                $table->text('decision_note')->nullable();

                $table->unsignedBigInteger('paid_by')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->string('payment_reference', 100)->nullable();

                $table->timestamps();
                $table->softDeletes();

                $table->unique(['sub_institute_id', 'claim_no'], 'hexpclaim_tenant_no_uniq');
                $table->index(['sub_institute_id', 'employee_id', 'status'], 'hexpclaim_tenant_emp_status_idx');
                $table->index(['sub_institute_id', 'approver_id', 'status'], 'hexpclaim_tenant_appr_status_idx');
            });
        }

        if (!$this->tableExists('hrms_expense_items')) {
            Schema::create('hrms_expense_items', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('sub_institute_id');
                $table->unsignedBigInteger('claim_id');
                $table->unsignedBigInteger('category_id');

                $table->date('expense_date');
                $table->string('description', 255)->nullable();
                $table->decimal('amount', 12, 2);
                // Bill / receipt number or note. A file upload is a later addition.
                $table->string('receipt_ref', 150)->nullable();

                $table->timestamps();

                $table->index(['sub_institute_id', 'claim_id'], 'hexpitem_tenant_claim_idx');
                $table->index('category_id', 'hexpitem_category_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hrms_expense_items');
        Schema::dropIfExists('hrms_expense_claims');
        Schema::dropIfExists('hrms_expense_categories');
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
