<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 audit gap: vtiger's `vtiger_seproductsrel` lets a Product be a
 * "kit" of other products/services - confirmed from Products' own
 * `Record.php` (getSubProducts()/isBundle()/updateSubProductQuantity()):
 * one level only (no recursion, no cycle guard in legacy either), and the
 * bundle's own `unit_price` is NEVER auto-written from its components -
 * `getSubProductsCostsAndTotalCostInUserCurrency()` is a read-only display
 * helper, not a price source. This table mirrors that exactly:
 * parent_product_id/component_product_id (legacy's reversed
 * productid/crmid, un-reversed here), quantity, no price column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_product_bundle_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('parent_product_id')->index();
            $table->unsignedBigInteger('component_product_id')->index();
            $table->decimal('quantity', 12, 2)->default(1);
            $table->timestamp('created_at')->nullable();

            $table->unique(['parent_product_id', 'component_product_id'], 'crm_bundle_pair_unique');
            $table->foreign('parent_product_id')->references('id')->on('crm_products')->cascadeOnDelete();
            $table->foreign('component_product_id')->references('id')->on('crm_products')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_product_bundle_items');
    }
};
