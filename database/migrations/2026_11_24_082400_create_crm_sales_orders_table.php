<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales Orders - legacy CRM's `vtiger_salesorder` (confirmed fields:
 * salesorder_no, potentialid -> Opportunity, quoteid -> Quote, contactid,
 * accountid, duedate, carrier, sostatus, the shared money/address/terms
 * block, currency_id+conversion_rate, compound_taxes_info).
 *
 * Same simplifications already applied to crm_quotes, for the same reasons
 * (see that migration's docblock): plain `currency` code instead of a real
 * conversion-rate engine; `crm_tax_rates` + a frozen per-line snapshot
 * (crm_sales_order_line_items) instead of a compound-tax JSON blob;
 * billing/shipping address flattened onto this table instead of legacy's
 * separate `*billads`/`*shipads` side tables. `organization_id` is nullable
 * at the DB level (not a hard NOT NULL) even though legacy's own `accountid`
 * is form-mandatory - matching how `crm_quotes.organization_id` is already
 * nullable here; "required to submit" is an app-level validation rule in
 * the controller, not a schema constraint, consistently across this CRM.
 *
 * `quote_id` and `opportunity_id` are both present and independent (legacy
 * allows a Sales Order to originate from either, or neither).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_sales_orders', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('legacy_id')->nullable()->index();

            $table->string('so_no', 100)->unique();
            $table->string('subject', 191);
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->unsignedBigInteger('contact_id')->nullable()->index();
            $table->unsignedBigInteger('opportunity_id')->nullable()->index();
            $table->unsignedBigInteger('quote_id')->nullable()->index();

            $table->string('so_stage', 100)->nullable()->index();
            $table->date('due_date')->nullable();
            $table->string('carrier', 100)->nullable();
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

        Schema::table('crm_sales_orders', function (Blueprint $table) {
            $table->foreign('organization_id')->references('id')->on('crm_organizations')->nullOnDelete();
            $table->foreign('contact_id')->references('id')->on('crm_contacts')->nullOnDelete();
            $table->foreign('opportunity_id')->references('id')->on('crm_opportunities')->nullOnDelete();
            $table->foreign('quote_id')->references('id')->on('crm_quotes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_sales_orders');
    }
};
