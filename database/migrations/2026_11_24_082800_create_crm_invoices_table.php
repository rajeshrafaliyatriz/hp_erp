<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invoices - legacy CRM's `vtiger_invoice` (confirmed fields: invoice_no
 * (unique), salesorderid -> Sales Order, invoicedate, duedate,
 * invoicestatus (confirmed NOT mandatory, unlike sostatus/postatus), plus
 * the same shared accountid/contactid/money/address/terms block Sales
 * Order inherits). Deliberately no `quote_id` column here - confirmed in
 * the legacy schema itself, an Invoice only ever chains off a Sales Order
 * (or stands alone), never directly off a Quote.
 *
 * Same simplifications as the other 2 order documents (plain currency
 * code, crm_tax_rates + frozen snapshot, flattened address columns).
 * Legacy's recurring-invoice automation (`enable_recurring` +
 * `vtiger_invoice_recurring_info` + a cron) is deliberately not built -
 * this app has no generic trigger-action/cron-scheduled-job engine for
 * CRM records to hang that on (same reasoning the original Sales-module
 * plan already gave for not building legacy's workflow-engine SMS
 * trigger), and it was not asked for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_invoices', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('legacy_id')->nullable()->index();

            $table->string('invoice_no', 100)->unique();
            $table->string('subject', 191);
            $table->unsignedBigInteger('sales_order_id')->nullable()->index();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->unsignedBigInteger('contact_id')->nullable()->index();

            $table->string('invoice_stage', 100)->nullable()->index();
            $table->date('invoice_date')->nullable();
            $table->date('due_date')->nullable();
            $table->string('currency', 10)->nullable();

            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount_percent', 7, 3)->nullable();
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('shipping_handling_amount', 15, 2)->default(0);
            $table->decimal('adjustment', 15, 2)->default(0);
            $table->decimal('tax_total', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);

            $table->string('billing_street', 250)->nullable();
            $table->string('billing_city', 50)->nullable();
            $table->string('billing_state', 50)->nullable();
            $table->string('billing_code', 30)->nullable();
            $table->string('billing_country', 50)->nullable();
            $table->string('billing_po_box', 30)->nullable();

            $table->string('shipping_street', 250)->nullable();
            $table->string('shipping_city', 50)->nullable();
            $table->string('shipping_state', 50)->nullable();
            $table->string('shipping_code', 30)->nullable();
            $table->string('shipping_country', 50)->nullable();
            $table->string('shipping_po_box', 30)->nullable();

            $table->text('terms_conditions')->nullable();
            $table->text('description')->nullable();

            $table->unsignedBigInteger('assigned_to')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();

            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('crm_invoices', function (Blueprint $table) {
            $table->foreign('sales_order_id')->references('id')->on('crm_sales_orders')->nullOnDelete();
            $table->foreign('organization_id')->references('id')->on('crm_organizations')->nullOnDelete();
            $table->foreign('contact_id')->references('id')->on('crm_contacts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_invoices');
    }
};
