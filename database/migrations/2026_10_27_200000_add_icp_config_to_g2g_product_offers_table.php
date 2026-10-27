<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Additive migration: allows g2g_product_offers to store an individual ICP JSON payload per sub-product.
     */
    public function up(): void
    {
        if (Schema::hasTable('g2g_product_offers') && ! Schema::hasColumn('g2g_product_offers', 'icp_config')) {
            Schema::table('g2g_product_offers', function (Blueprint $table) {
                $table->longText('icp_config')->nullable()->after('notes');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('g2g_product_offers') && Schema::hasColumn('g2g_product_offers', 'icp_config')) {
            Schema::table('g2g_product_offers', function (Blueprint $table) {
                $table->dropColumn('icp_config');
            });
        }
    }
};

