<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase Orders - legacy CRM's `vtiger_purchaseorder` (confirmed fields:
 * vendorid -> Vendors (mandatory there), requisition_no, purchaseorder_no,
 * tracking_no, quoteid -> Quote, postatus, the same shared money/address/
 * terms block as SalesOrder/Invoice). Legacy also gives PurchaseOrder a
 * dedicated "copy address from Company/Vendor/Account/Contact" UI
 * (`PurchaseOrder/actions/CompanyDetails.php`) - the billing/shipping
 * columns below exist so the frontend can offer that same convenience by
 * copying from crm_vendors' or crm_organizations' own address fields.
 *
 * Same simplifications as crm_quotes/crm_sales_orders (plain currency code,
 * crm_tax_rates + frozen snapshot, flattened address columns) - see those
 * migrations' docblocks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_purchase_orders', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('legacy_id')->nullable()->index();

            $table->string('po_no', 100)->unique();
            $table->string('subject', 191);
            $table->unsignedBigInteger('vendor_id')->nullable()->index();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->unsignedBigInteger('contact_id')->nullable()->index();
            $table->unsignedBigInteger('quote_id')->nullable()->index();

            $table->string('po_stage', 100)->nullable()->index();
            $table->string('requisition_no', 100)->nullable();
            $table->string('tracking_no', 100)->nullable();
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

        Schema::table('crm_purchase_orders', function (Blueprint $table) {
            $table->foreign('vendor_id')->references('id')->on('crm_vendors')->nullOnDelete();
            $table->foreign('organization_id')->references('id')->on('crm_organizations')->nullOnDelete();
            $table->foreign('contact_id')->references('id')->on('crm_contacts')->nullOnDelete();
            $table->foreign('quote_id')->references('id')->on('crm_quotes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_purchase_orders');
    }
};
