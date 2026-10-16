<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales & Marketing gap-closure, Wave 1: closing CRM's order-to-cash chain.
 * Before this, `crm_invoices` had `total` but nothing tracking how much of
 * it was actually collected - no payment-status field existed at all.
 *
 * `amount_paid` is a maintained aggregate, same convention as `total` itself
 * (recomputed by CrmInvoiceController, never trusted from the client) -
 * `crm_invoice_payments` is its one source of truth (append-only, same shape
 * as `crm_invoice_status_history`), so a partially-paid invoice can record
 * more than one payment over time without losing the earlier ones.
 * `balance_due` is NOT a stored column - it is `total - amount_paid`,
 * computed in the response mapper only, same reasoning this module already
 * applies to Opportunities' "weighted revenue" (a derived value that must
 * never be allowed to drift from its own inputs).
 *
 * Deliberately no payment-gateway integration here - this is a manual
 * "record what was collected" ledger, not a charge-processing feature; see
 * DECISIONS.md's gap-closure scope tiering for why that is a separate,
 * vendor-dependent decision, not bundled into this wave.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_invoices', function (Blueprint $table) {
            $table->decimal('amount_paid', 15, 2)->default(0)->after('total');
        });

        Schema::create('crm_invoice_payments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('invoice_id')->index();

            $table->decimal('amount', 15, 2);
            $table->string('method', 50)->nullable();
            $table->string('reference', 191)->nullable();
            $table->date('paid_on')->nullable();
            $table->text('note')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('invoice_id')->references('id')->on('crm_invoices')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_invoice_payments');

        Schema::table('crm_invoices', function (Blueprint $table) {
            $table->dropColumn('amount_paid');
        });
    }
};
