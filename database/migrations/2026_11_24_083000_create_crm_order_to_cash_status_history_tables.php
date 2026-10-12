<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Status-change audit trail for Sales Orders / Purchase Orders / Invoices -
 * mirrors legacy's `vtiger_sostatushistory`/`vtiger_postatushistory`/
 * `vtiger_invoicestatushistory` (each: a FK, a denormalized name snapshot,
 * a total snapshot, the status value, when). Append-only, no soft-deletes -
 * same as crm_opportunity_stage_history, which this pattern is copied from.
 * One row is written whenever that document's own stage column changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_sales_order_status_history', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('sales_order_id')->index();
            $table->string('from_stage', 100)->nullable();
            $table->string('to_stage', 100)->nullable();
            $table->decimal('total', 15, 2)->nullable();
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('sales_order_id')->references('id')->on('crm_sales_orders')->cascadeOnDelete();
        });

        Schema::create('crm_purchase_order_status_history', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('purchase_order_id')->index();
            $table->string('from_stage', 100)->nullable();
            $table->string('to_stage', 100)->nullable();
            $table->decimal('total', 15, 2)->nullable();
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('purchase_order_id')->references('id')->on('crm_purchase_orders')->cascadeOnDelete();
        });

        Schema::create('crm_invoice_status_history', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('invoice_id')->index();
            $table->string('from_stage', 100)->nullable();
            $table->string('to_stage', 100)->nullable();
            $table->decimal('total', 15, 2)->nullable();
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('invoice_id')->references('id')->on('crm_invoices')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_invoice_status_history');
        Schema::dropIfExists('crm_purchase_order_status_history');
        Schema::dropIfExists('crm_sales_order_status_history');
    }
};
