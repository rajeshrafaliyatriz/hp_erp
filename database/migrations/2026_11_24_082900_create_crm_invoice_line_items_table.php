<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invoice line items - same shape and reasoning as crm_quote_line_items.
 * When an Invoice is generated from a Sales Order, its line items are a
 * fresh copy, not a shared reference - editing an Invoice's lines must
 * never retroactively change the originating Sales Order's own totals.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_invoice_line_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('invoice_id')->index();
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

            $table->foreign('invoice_id')->references('id')->on('crm_invoices')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('crm_products')->nullOnDelete();
            $table->foreign('tax_rate_id')->references('id')->on('crm_tax_rates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_invoice_line_items');
    }
};
