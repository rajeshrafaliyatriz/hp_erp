<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-price-book override price for a product/service - vtiger's
 * `vtiger_pricebookproductrel` (listprice), confirmed from PriceBooks'
 * own model code. Distinct from `crm_products.unit_price` (the base
 * price) - a product with no row here simply has no override in that
 * book, and a Quote line item falls back to the base price.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_price_book_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('price_book_id')->index();
            $table->unsignedBigInteger('product_id')->index();
            $table->decimal('list_price', 12, 2);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['price_book_id', 'product_id'], 'crm_price_book_item_pair_unique');
            $table->foreign('price_book_id')->references('id')->on('crm_price_books')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('crm_products')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_price_book_items');
    }
};
