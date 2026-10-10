<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A small admin-managed tax-rate master list - this app has no equivalent
 * anywhere today. Replaces the legacy CRM's denormalized
 * `compound_taxes_info` JSON blob with a real, reusable, per-tenant rate
 * list that both Products (a default rate) and Quote line items (an
 * override, frozen as a snapshot at save time - see crm_quote_line_items)
 * can reference.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_tax_rates', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('name', 100);
            $table->decimal('percentage', 7, 3);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_tax_rates');
    }
};
