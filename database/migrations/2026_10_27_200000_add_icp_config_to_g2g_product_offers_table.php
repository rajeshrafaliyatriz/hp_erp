<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive migration: allows g2g_product_offers to store an individual ICP JSON payload per sub-product (a nullable text column, nothing else changes).
     *
     * On MySQL / MariaDB it uses SHOW TABLES / SHOW COLUMNS and a plain ALTER: Schema::hasColumn() (and Schema::table) read information_schema.columns
     * with `generation_expression`, a column the live MariaDB 10.1 does not have, so they fail there with "Unknown column 'generation_expression'".
     * Other drivers (the sqlite test database) keep using the portable Schema calls. Safe to run twice.
     */
    public function up(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            if (empty(DB::select("SHOW TABLES LIKE 'g2g_product_offers'"))) {
                return;
            }
            if (empty(DB::select("SHOW COLUMNS FROM `g2g_product_offers` LIKE 'icp_config'"))) {
                $after = empty(DB::select("SHOW COLUMNS FROM `g2g_product_offers` LIKE 'notes'")) ? '' : ' AFTER `notes`';
                DB::statement('ALTER TABLE `g2g_product_offers` ADD COLUMN `icp_config` LONGTEXT NULL' . $after);
            }

            return;
        }

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
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            if (! empty(DB::select("SHOW COLUMNS FROM `g2g_product_offers` LIKE 'icp_config'"))) {
                DB::statement('ALTER TABLE `g2g_product_offers` DROP COLUMN `icp_config`');
            }

            return;
        }

        if (Schema::hasTable('g2g_product_offers') && Schema::hasColumn('g2g_product_offers', 'icp_config')) {
            Schema::table('g2g_product_offers', function (Blueprint $table) {
                $table->dropColumn('icp_config');
            });
        }
    }
};
