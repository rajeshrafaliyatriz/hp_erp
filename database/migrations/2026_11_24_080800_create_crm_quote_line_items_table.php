<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quote line items - mirrors legacy's `vtiger_inventoryproductrel`
 * (sequence_no, quantity, listprice, discount_percent, discount_amount,
 * comment, description), polymorphic there to either Products or Services
 * with no discriminator (safe only because both source tables share one
 * global vtiger crmentity id space - see crm_products' own migration
 * comment for why that trick isn't replicated here). Here `product_id` is a
 * single clean FK into the unified `crm_products` table instead.
 *
 * `tax_name_snapshot`/`tax_percent_snapshot` are frozen at save time,
 * deliberately NOT just a live `tax_rate_id` lookup: "recompute server-side
 * on every save" only protects a quote that gets re-saved. Without a frozen
 * snapshot, editing crm_tax_rates later would silently change every
 * untouched historical quote's total the next time it is viewed or PDF'd.
 *
 * No soft-deletes: rows are replaced wholesale on every quote save (the
 * whole line-item set is sent and rewritten together), matching how this
 * codebase already treats child rows of a parent form elsewhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_quote_line_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('quote_id')->index();
            $table->unsignedBigInteger('product_id')->nullable()->index();

            $table->string('description', 500)->nullable();
            $table->decimal('quantity', 15, 2)->default(1);
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('discount_percent', 7, 3)->nullable();
            $table->decimal('discount_amount', 15, 2)->nullable();

            $table->unsignedBigInteger('tax_rate_id')->nullable()->index();
            $table->string('tax_name_snapshot', 100)->nullable();
            $table->decimal('tax_percent_snapshot', 7, 3)->nullable();

            $table->decimal('line_total', 15, 2)->default(0);
            $table->unsignedInteger('sequence_no')->default(0);

            $table->timestamps();

            $table->foreign('quote_id')->references('id')->on('crm_quotes')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('crm_products')->nullOnDelete();
            $table->foreign('tax_rate_id')->references('id')->on('crm_tax_rates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_quote_line_items');
    }
};
