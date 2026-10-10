<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unified Products + Services catalog.
 *
 * Legacy keeps these as two separate tables (vtiger_products / vtiger_service)
 * whose line-item/tax junctions dereference a shared `productid` column with
 * NO discriminator - that only works there because both tables' primary keys
 * are themselves FKs into one global, shared `vtiger_crmentity.crmid`
 * auto-increment space (every vtiger record of every module draws from the
 * same id sequence). hp_erp's CRM tables are ordinary per-table
 * auto-increment with no shared id space, so replicating legacy's
 * "no discriminator" trick here would be unsafe - a product id and a
 * service id could collide. Unifying into one table with one real PK is the
 * only way to get a clean, single `product_id` FK on quote line items.
 *
 * `item_type` discriminates the row. Service rows simply leave the
 * product-only stock/vendor columns null - mirroring how `vtiger_service`
 * never had those columns at all (confirmed: no qtyinstock/qtyindemand/
 * reorderlevel/vendor_id/manufacturer/serialno anywhere on that table).
 *
 * Two separate menu entries/routes (Products, Services) still present this
 * as two distinct screens, each a pre-filtered view of this one table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_products', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('legacy_id')->nullable()->index();

            $table->enum('item_type', ['product', 'service'])->index();
            $table->string('product_no', 100)->unique();
            $table->string('name', 191);
            $table->string('sku', 100)->nullable();
            $table->string('category', 100)->nullable();
            $table->text('description')->nullable();

            $table->decimal('unit_price', 15, 2)->nullable();
            $table->decimal('cost_price', 15, 2)->nullable();
            $table->string('currency', 10)->nullable();
            $table->unsignedBigInteger('tax_rate_id')->nullable()->index();
            $table->boolean('is_active')->default(true);

            // Product-only - left null for item_type='service'.
            $table->string('vendor', 191)->nullable();
            $table->decimal('qty_in_stock', 15, 2)->nullable();
            $table->unsignedInteger('reorder_level')->nullable();
            $table->decimal('weight', 10, 3)->nullable();

            $table->unsignedBigInteger('assigned_to')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();

            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('crm_products', function (Blueprint $table) {
            $table->foreign('tax_rate_id')->references('id')->on('crm_tax_rates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_products');
    }
};
