<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opportunity <-> Product/Service "interest" tag - deliberately UNPRICED,
 * mirroring legacy's loose `vtiger_seproductsrel` (crmid, productid, setype,
 * quantity - no price, no discount, no tax). This is NOT a line-item table;
 * that's what crm_quote_line_items is for. An Opportunity can show "what
 * they're interested in" without a Quote ever having been created.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_opportunity_products', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('opportunity_id')->index();
            $table->unsignedBigInteger('product_id')->index();
            $table->decimal('quantity', 15, 2)->default(1);
            $table->timestamp('created_at')->nullable();

            $table->foreign('opportunity_id')->references('id')->on('crm_opportunities')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('crm_products')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_opportunity_products');
    }
};
