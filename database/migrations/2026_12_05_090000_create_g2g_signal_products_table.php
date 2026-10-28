<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * PREPARED, NOT RUN. The organisation's own top-level products for Signals (see App\Domain\Signals\Products\CustomProduct).
 *
 * The built-in products (ScholarClone, Enterprise Brain, G2G) stay in config/signals_products.php and are never stored here. A row here is one
 * extra product of ONE organisation (`sub_institute_id` is the tenant, as on every other g2g_ table): its key, label, the `business_fit` value
 * stamped on its opportunities (the existing 20-character column) and the ICP product line its ICP is saved under in g2g_icp_profiles.
 * Additive and idempotent: nothing existing is altered. The code works without this table (there are then no custom products).
 * `portfolio_names` is text holding JSON: the live MariaDB 10.1 has no JSON column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            if (empty(DB::select("SHOW TABLES LIKE 'g2g_signal_products'"))) {
                DB::statement("CREATE TABLE `g2g_signal_products` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `sub_institute_id` INT UNSIGNED NOT NULL,
                    `key` VARCHAR(20) NOT NULL,
                    `label` VARCHAR(50) NOT NULL,
                    `business_fit` VARCHAR(20) NOT NULL,
                    `icp_product_line` VARCHAR(20) NOT NULL,
                    `portfolio_names` TEXT NULL,
                    `status` VARCHAR(20) NOT NULL DEFAULT 'active',
                    `created_by` INT UNSIGNED NULL,
                    `created_at` TIMESTAMP NULL DEFAULT NULL,
                    `updated_at` TIMESTAMP NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `g2g_signal_products_tenant_key` (`sub_institute_id`, `key`),
                    UNIQUE KEY `g2g_signal_products_tenant_fit` (`sub_institute_id`, `business_fit`),
                    UNIQUE KEY `g2g_signal_products_tenant_line` (`sub_institute_id`, `icp_product_line`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
            }

            return;
        }

        if (! Schema::hasTable('g2g_signal_products')) {
            Schema::create('g2g_signal_products', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('sub_institute_id');
                $table->string('key', 20);
                $table->string('label', 50);
                $table->string('business_fit', 20);
                $table->string('icp_product_line', 20);
                $table->text('portfolio_names')->nullable();
                $table->string('status', 20)->default('active');
                $table->unsignedInteger('created_by')->nullable();
                $table->timestamps();
                $table->unique(['sub_institute_id', 'key'], 'g2g_signal_products_tenant_key');
                $table->unique(['sub_institute_id', 'business_fit'], 'g2g_signal_products_tenant_fit');
                $table->unique(['sub_institute_id', 'icp_product_line'], 'g2g_signal_products_tenant_line');
            });
        }
    }

    public function down(): void
    {
    }
};
