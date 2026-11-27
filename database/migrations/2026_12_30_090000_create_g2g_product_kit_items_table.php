<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The Sales Kit of a Product (27 Nov 2026; Docs/agentic-crm/briefs/SALES-KIT-CONTRACT.md): the demo presentations, documents, videos and images a sales person opens before a call.
 *
 * One row per item. A FILE item points at a document_library row (`document_id`, the file itself lives in the Document Library and also gets a `document_library_links` row of type
 * PRODUCT); a LINK item has a `url` instead (an https address or an app-relative path such as the interactive deck). Exactly one of the two is set; the source ("file" or "link") is derived
 * from which one, so there is no column for it.
 *
 *   sub_institute_id   the organisation that owns the item; every read and write is scoped by it. A product that is shared by every organisation (a master row of organisation 0) takes
 *                      per-organisation kit rows, so one organisation's deck never shows in another
 *   product_master_id  g2g_product_master.id of a row of kind `product` (a sub-product is refused in the API)
 *   kind               presentation | document | video | image
 *   deleted_at         soft delete: a removed item leaves the kit, the library document stays
 *
 * ADDITIVE and idempotent: nothing existing is altered, and it does nothing when the table is already there. No foreign keys and no JSON column: the live host is MariaDB 10.1.48 (Compact row
 * format, not strict). The raw DDL states utf8mb4 explicitly because the dev database's own default charset is latin1, and every timestamp has an explicit NULL default, so MariaDB adds no
 * ON UPDATE clause to the first one (DB-RULES.md). Every indexed column is numeric or a timestamp, so the 767-byte index limit of the Compact row format does not apply.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            if (empty(DB::select("SHOW TABLES LIKE 'g2g_product_kit_items'"))) {
                DB::statement("CREATE TABLE `g2g_product_kit_items` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `sub_institute_id` BIGINT UNSIGNED NOT NULL,
                    `product_master_id` BIGINT UNSIGNED NOT NULL,
                    `kind` VARCHAR(12) NOT NULL,
                    `title` VARCHAR(191) NOT NULL,
                    `description` VARCHAR(500) NULL,
                    `document_id` BIGINT UNSIGNED NULL,
                    `url` VARCHAR(500) NULL,
                    `sort_order` INT NOT NULL DEFAULT 0,
                    `created_by` BIGINT UNSIGNED NULL,
                    `updated_by` BIGINT UNSIGNED NULL,
                    `created_at` TIMESTAMP NULL DEFAULT NULL,
                    `updated_at` TIMESTAMP NULL DEFAULT NULL,
                    `deleted_at` TIMESTAMP NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `g2g_pki_tenant_product` (`sub_institute_id`, `product_master_id`, `deleted_at`, `sort_order`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            return;
        }

        if (! Schema::hasTable('g2g_product_kit_items')) {
            Schema::create('g2g_product_kit_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sub_institute_id');
                $table->unsignedBigInteger('product_master_id');
                $table->string('kind', 12);
                $table->string('title', 191);
                $table->string('description', 500)->nullable();
                $table->unsignedBigInteger('document_id')->nullable();
                $table->string('url', 500)->nullable();
                $table->integer('sort_order')->default(0);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->timestamp('deleted_at')->nullable();
                $table->index(['sub_institute_id', 'product_master_id', 'deleted_at', 'sort_order'], 'g2g_pki_tenant_product');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('g2g_product_kit_items');
    }
};
