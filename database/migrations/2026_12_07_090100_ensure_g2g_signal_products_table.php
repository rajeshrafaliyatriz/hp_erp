<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Makes sure the organisation's own-product table exists, in the shape every other g2g_ table has (defect D12, Docs/agentic-crm).
 *
 * 2026_12_05_090000_create_g2g_signal_products_table was written as "PREPARED, NOT RUN" with an INT UNSIGNED tenant column and utf8, but the
 * 2026-11-02 baseline found it HAD run on live (empty, in that old shape) and had NOT run on dev. A migration that has run on a host is never
 * edited, so this is a separate file: it creates the table with BIGINT UNSIGNED ids and utf8mb4 only where the table is missing (dev, a fresh
 * install). Where it already exists (live) it changes nothing: the live table has 0 rows and works as it is, and converting production schema
 * for a cosmetic difference is not worth the risk. If the live table is ever worth converting, that is its own file with its own owner "go".
 *
 * The original file then does nothing on dev too, because the table is already there. Same columns, same unique keys and index names.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            if (empty(DB::select("SHOW TABLES LIKE 'g2g_signal_products'"))) {
                DB::statement("CREATE TABLE `g2g_signal_products` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `sub_institute_id` BIGINT UNSIGNED NOT NULL,
                    `key` VARCHAR(20) NOT NULL,
                    `label` VARCHAR(50) NOT NULL,
                    `business_fit` VARCHAR(20) NOT NULL,
                    `icp_product_line` VARCHAR(20) NOT NULL,
                    `portfolio_names` TEXT NULL,
                    `status` VARCHAR(20) NOT NULL DEFAULT 'active',
                    `created_by` BIGINT UNSIGNED NULL,
                    `created_at` TIMESTAMP NULL DEFAULT NULL,
                    `updated_at` TIMESTAMP NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `g2g_signal_products_tenant_key` (`sub_institute_id`, `key`),
                    UNIQUE KEY `g2g_signal_products_tenant_fit` (`sub_institute_id`, `business_fit`),
                    UNIQUE KEY `g2g_signal_products_tenant_line` (`sub_institute_id`, `icp_product_line`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            return;
        }

        if (! Schema::hasTable('g2g_signal_products')) {
            Schema::create('g2g_signal_products', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sub_institute_id');
                $table->string('key', 20);
                $table->string('label', 50);
                $table->string('business_fit', 20);
                $table->string('icp_product_line', 20);
                $table->text('portfolio_names')->nullable();
                $table->string('status', 20)->default('active');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->unique(['sub_institute_id', 'key'], 'g2g_signal_products_tenant_key');
                $table->unique(['sub_institute_id', 'business_fit'], 'g2g_signal_products_tenant_fit');
                $table->unique(['sub_institute_id', 'icp_product_line'], 'g2g_signal_products_tenant_line');
            });
        }
    }

    public function down(): void
    {
        // Not reversed: the table may have been created by the original migration, and dropping it could remove an organisation's own products.
    }
};
