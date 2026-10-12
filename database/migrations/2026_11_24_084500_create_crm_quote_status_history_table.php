<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Incidental gap-closing: Quotes never got a stage-history table at all,
 * unlike every one of its order-to-cash siblings (crm_sales_order_status_
 * history, crm_purchase_order_status_history, crm_invoice_status_history
 * all exist; Quote was simply missed). Same exact shape, same reasoning -
 * append-only, written inline by CrmQuoteController whenever quote_stage
 * actually changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_quote_status_history', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('quote_id')->index();
            $table->string('from_stage', 100)->nullable();
            $table->string('to_stage', 100)->nullable();
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('quote_id')->references('id')->on('crm_quotes')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_quote_status_history');
    }
};
