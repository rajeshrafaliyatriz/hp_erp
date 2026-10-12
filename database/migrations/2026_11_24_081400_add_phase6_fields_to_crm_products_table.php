<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 audit gap: vtiger's Products AND Services both carry
 * `commissionrate` (comp calc) and `qty_per_unit` (unit-of-measure, e.g.
 * "per case of 12") - neither ported when crm_products was first built.
 * `qtyindemand` (backorder/demand quantity) and `glacct` (GL account code)
 * are Products-only in spirit (stock/accounting concepts), same as
 * vendor/qty_in_stock/reorder_level already are - the controller already
 * nulls those three for item_type='service' on every write, and will do
 * the same for qty_in_demand. gl_account is a free-text code, not a FK:
 * hp_erp has no chart-of-accounts/ledger module yet to point at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_products', function (Blueprint $table) {
            $table->decimal('commission_rate', 5, 2)->nullable()->after('cost_price');
            $table->string('usage_unit')->nullable()->after('weight');
            $table->decimal('qty_in_demand', 12, 2)->nullable()->after('qty_in_stock');
            $table->string('gl_account')->nullable()->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('crm_products', function (Blueprint $table) {
            $table->dropColumn(['commission_rate', 'usage_unit', 'qty_in_demand', 'gl_account']);
        });
    }
};
