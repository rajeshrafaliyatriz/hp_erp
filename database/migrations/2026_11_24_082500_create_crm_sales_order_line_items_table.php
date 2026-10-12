<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales Order line items - same shape and same reasoning as
 * crm_quote_line_items (frozen tax snapshot, wholesale-replace on save, no
 * soft-deletes). See that migration's docblock for why.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_sales_order_line_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('sales_order_id')->index();
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

            $table->foreign('sales_order_id')->references('id')->on('crm_sales_orders')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('crm_products')->nullOnDelete();
            $table->foreign('tax_rate_id')->references('id')->on('crm_tax_rates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_sales_order_line_items');
    }
};
