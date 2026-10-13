<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase Order line items - same shape and reasoning as
 * crm_quote_line_items. Deliberately not constrained to products whose
 * `crm_products.vendor` free-text column matches the PO's own vendor -
 * that column isn't a real FK (see crm_products' own migration), so it
 * can't safely gate which products are purchasable from which vendor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_purchase_order_line_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('purchase_order_id')->index();
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

            $table->foreign('purchase_order_id')->references('id')->on('crm_purchase_orders')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('crm_products')->nullOnDelete();
            $table->foreign('tax_rate_id')->references('id')->on('crm_tax_rates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_purchase_order_line_items');
    }
};
