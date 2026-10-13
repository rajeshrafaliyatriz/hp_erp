<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional price book a Quote was built against - per vtiger's own
 * PriceBooks_ProductListPrice_Action, picking a price book changes where a
 * newly-added line item's default price comes from (the book's
 * crm_price_book_items override, falling back to the product's base
 * unit_price when that product has no row in the book). Purely a UI
 * default at add-time, never enforced retroactively - an existing line
 * item's saved unit_price is never rewritten if the quote's price book
 * changes afterward, same "freeze what was saved" discipline
 * tax_name_snapshot/tax_percent_snapshot already use on this same table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_quotes', function (Blueprint $table) {
            $table->unsignedBigInteger('price_book_id')->nullable()->after('opportunity_id');
        });
    }

    public function down(): void
    {
        Schema::table('crm_quotes', function (Blueprint $table) {
            $table->dropColumn('price_book_id');
        });
    }
};
