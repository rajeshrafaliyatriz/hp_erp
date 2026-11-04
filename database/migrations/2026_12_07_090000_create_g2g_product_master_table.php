<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The Product Master (slice 1 of the agentic CRM, Docs/agentic-crm/slices/S01-product-master.md).
 *
 * One row per product, sub-product or catalogue-only item. It holds identity and loose pointers only: what a sub-product IS stays in
 * g2g_product_offers (the Product Portfolio), its price and SKU in crm_products, its ideal customer in g2g_icp_profiles. Later slices add
 * one nullable `product_master_id` to leads, campaigns, outreach, opportunities and signals so they can all name the same product.
 *
 *   kind            product      a top-level product (Scholar K-12, Enterprise Brain, G2G, ...)
 *                   sub_product  an item inside a product (a Product Portfolio offer)
 *                   item         a catalogue-only item such as a service (a crm_products row with no offer)
 *   sub_institute_id 0 = a row shared by every organisation (the built-in products); never NULL
 *   signal_key      the `business_fit` value Signals stores for the product (scholar, eb, g2g, or a custom key); NULL when none exists
 *   portfolio_name  the exact Product Portfolio `parent_product` string of a product (Scholar K-12, EB, G2G, HE, Bundle)
 *
 * ADDITIVE and idempotent: nothing existing is altered, and it does nothing when the table is already there. No foreign keys and no JSON
 * column: the live host is MariaDB 10.1.48 (Compact row format, not strict), which has no JSON type. The raw DDL states utf8mb4 explicitly
 * because the dev database's own default charset is latin1. The first timestamp column has an explicit NULL default, so MariaDB adds no
 * ON UPDATE clause to it (see DB-RULES.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            if (empty(DB::select("SHOW TABLES LIKE 'g2g_product_master'"))) {
                DB::statement("CREATE TABLE `g2g_product_master` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `sub_institute_id` BIGINT UNSIGNED NOT NULL,
                    `product_key` VARCHAR(50) NOT NULL,
                    `kind` VARCHAR(12) NOT NULL,
                    `parent_id` BIGINT UNSIGNED NULL,
                    `name` VARCHAR(191) NOT NULL,
                    `status` VARCHAR(12) NOT NULL DEFAULT 'active',
                    `origin` VARCHAR(20) NOT NULL DEFAULT 'manual',
                    `signal_key` VARCHAR(20) NULL,
                    `portfolio_name` VARCHAR(50) NULL,
                    `offer_row_id` BIGINT UNSIGNED NULL,
                    `crm_product_id` BIGINT UNSIGNED NULL,
                    `created_by` BIGINT UNSIGNED NULL,
                    `updated_by` BIGINT UNSIGNED NULL,
                    `created_at` TIMESTAMP NULL DEFAULT NULL,
                    `updated_at` TIMESTAMP NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `g2g_pm_tenant_key` (`sub_institute_id`, `product_key`),
                    UNIQUE KEY `g2g_pm_tenant_signal` (`sub_institute_id`, `signal_key`),
                    UNIQUE KEY `g2g_pm_tenant_offer` (`sub_institute_id`, `offer_row_id`),
                    UNIQUE KEY `g2g_pm_tenant_crm` (`sub_institute_id`, `crm_product_id`),
                    KEY `g2g_pm_tenant_parent` (`sub_institute_id`, `parent_id`),
                    KEY `g2g_pm_tenant_kind_status` (`sub_institute_id`, `kind`, `status`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            return;
        }

        if (! Schema::hasTable('g2g_product_master')) {
            Schema::create('g2g_product_master', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sub_institute_id');
                $table->string('product_key', 50);
                $table->string('kind', 12);
                $table->unsignedBigInteger('parent_id')->nullable();
                $table->string('name', 191);
                $table->string('status', 12)->default('active');
                $table->string('origin', 20)->default('manual');
                $table->string('signal_key', 20)->nullable();
                $table->string('portfolio_name', 50)->nullable();
                $table->unsignedBigInteger('offer_row_id')->nullable();
                $table->unsignedBigInteger('crm_product_id')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->unique(['sub_institute_id', 'product_key'], 'g2g_pm_tenant_key');
                $table->unique(['sub_institute_id', 'signal_key'], 'g2g_pm_tenant_signal');
                $table->unique(['sub_institute_id', 'offer_row_id'], 'g2g_pm_tenant_offer');
                $table->unique(['sub_institute_id', 'crm_product_id'], 'g2g_pm_tenant_crm');
                $table->index(['sub_institute_id', 'parent_id'], 'g2g_pm_tenant_parent');
                $table->index(['sub_institute_id', 'kind', 'status'], 'g2g_pm_tenant_kind_status');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('g2g_product_master');
    }
};
