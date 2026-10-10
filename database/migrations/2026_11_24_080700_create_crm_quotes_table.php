<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quotes - the legacy CRM's "Quotes" module. Unlike Opportunities, this is a
 * real multi-line-item document (see crm_quote_line_items): confirmed by
 * reading `Quotes.php`'s own `$tab_name` array, which lists
 * `vtiger_inventoryproductrel` as one of its own persisted tables, joined on
 * `id = quoteid`.
 *
 * Every money total column here is SERVER-RECOMPUTED on every save, never
 * trusted from the client - the live "total updates as you type" UX on the
 * frontend is a client-side preview only.
 *
 * Dropped from legacy: `inventorymanager` (a second "owner" concept no other
 * hp_erp CRM entity has - folded into `assigned_to`). No real multi-currency
 * conversion-rate engine - `currency` is a plain code column, matching how
 * nothing else in this app's CRM has real multi-currency infrastructure
 * (even legacy itself is inconsistent here: Potentials' own `currency` is a
 * bare varchar with no conversion rate either). `compound_taxes_info`'s
 * denormalized JSON blob is replaced by crm_tax_rates + a frozen per-line
 * snapshot (see crm_quote_line_items) - a real, queryable, admin-manageable
 * replacement rather than an opaque blob.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_quotes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('legacy_id')->nullable()->index();

            $table->string('quote_no', 100)->unique();
            $table->string('subject', 191);
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->unsignedBigInteger('contact_id')->nullable()->index();
            $table->unsignedBigInteger('opportunity_id')->nullable()->index();

            $table->string('quote_stage', 100)->nullable()->index();
            $table->date('valid_till')->nullable();
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

        Schema::table('crm_quotes', function (Blueprint $table) {
            $table->foreign('organization_id')->references('id')->on('crm_organizations')->nullOnDelete();
            $table->foreign('contact_id')->references('id')->on('crm_contacts')->nullOnDelete();
            $table->foreign('opportunity_id')->references('id')->on('crm_opportunities')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_quotes');
    }
};
