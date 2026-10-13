<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 audit gap: vtiger's PriceBooks module - a named price list
 * (`bookname`, currency, active flag) a Quote can select instead of using
 * every line item's base `crm_products.unit_price`. Per-product override
 * prices live in `crm_price_book_items` (its own migration), never here.
 *
 * Soft-deletes (Recycle Bin parity with every other Sales entity) and
 * bulk delete/assign, matching the established pattern - no
 * duplicate-detection/merge pair, same reasoning as Opportunities/Quotes/
 * Campaigns (two price books can legitimately share a name, e.g.
 * "Wholesale" re-created yearly is not an accidental double-entry).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_price_books', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('price_book_no')->unique();
            $table->string('name');
            $table->string('currency')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();

            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->unsignedBigInteger('sub_institute_id')->index();

            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_price_books');
    }
};
