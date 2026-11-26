<?php

use App\Support\GtmMigrationSupport;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The CRM Vendors and Purchase Orders modules are gone (26 Nov 2026; Docs/agentic-crm/briefs/REMOVE-PURCHASE-ORDERS-VENDORS.md), so the five tables only they used are dropped, child tables first:
 *
 *   crm_purchase_order_status_history   one row per change of a purchase order's stage          (2026_11_24_083000)
 *   crm_purchase_order_line_items       the lines of a purchase order                           (2026_11_24_082700)
 *   crm_purchase_orders                 the purchase orders                                     (2026_11_24_082600)
 *   crm_vendor_contacts                 the contacts linked to a vendor                         (2026_11_24_082300)
 *   crm_vendors                         the vendors                                             (2026_11_24_082200)
 *
 * NOT dropped: lms_vendors (the LMS training suppliers, a different feature), crm_products.vendor (the free-text vendor of a product), crm_quotes, crm_sales_orders, crm_invoices and
 * every other crm_* table. The tables held no row on dev or on db3 when they were checked on 26 Nov 2026; dump them first if that has changed.
 *
 * RUN IT ONLY AFTER THE BACKEND THAT NO LONGER READS THE TABLES IS DEPLOYED, and after `php artisan crm:retire-purchase-orders-vendors --apply` has taken the menu rows, rights, picklist values
 * and reports out. One file at a time, never an unscoped migrate:
 *
 *   php artisan migrate --path=database/migrations/2026_12_27_090300_drop_crm_purchase_orders_and_vendors_tables.php
 *
 * GUARDED AND REPEATABLE: each table is dropped only when it exists (information_schema check, the form that works on both hosts: DB-RULES), so a second run does nothing and a host that
 * never had a table skips it. RAW DDL on MySQL and MariaDB (10.1 safe: no JSON, no generated columns), the portable Schema call on sqlite (the PHPUnit database).
 *
 * down() recreates all five tables EMPTY, parents first, each only when it is missing, with the exact columns and indexes (names included) that their create migrations made. The rows are not
 * brought back. The foreign keys of the originals (cascade or set-null on delete towards crm_vendors, crm_purchase_orders, crm_organizations, crm_contacts, crm_quotes, crm_products and
 * crm_tax_rates) are NOT recreated: every pointer column keeps its index, and a rollback that cannot fail on a host that lacks one of those parents is the safer rollback (DB-RULES item 5, loose pointer columns).
 */
return new class extends Migration
{
    use GtmMigrationSupport;

    /** Child tables first: a table is never dropped while another still points at it. */
    private const DROP_ORDER = [
        'crm_purchase_order_status_history',
        'crm_purchase_order_line_items',
        'crm_purchase_orders',
        'crm_vendor_contacts',
        'crm_vendors',
    ];

    private function isMariaDb(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    public function up(): void
    {
        foreach (self::DROP_ORDER as $table) {
            if (! $this->has($table)) {
                continue;
            }
            if ($this->isMariaDb()) {
                DB::statement('DROP TABLE `'.$table.'`');
            } else {
                Schema::drop($table);
            }
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::DROP_ORDER) as $table) {
            if ($this->has($table)) {
                continue;
            }
            $this->isMariaDb() ? DB::statement($this->ddl($table)) : $this->blueprint($table);
        }
    }

    /** The CREATE TABLE of one table, as 2026_11_24_0822xx to 083000 made it (MariaDB 10.1 safe). */
    private function ddl(string $table): string
    {
        $tail = ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        return match ($table) {
            'crm_vendors' => 'CREATE TABLE `crm_vendors` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `legacy_id` BIGINT UNSIGNED NULL,
                `vendor_no` VARCHAR(100) NOT NULL,
                `name` VARCHAR(191) NOT NULL,
                `phone` VARCHAR(50) NULL,
                `email` VARCHAR(191) NULL,
                `website` VARCHAR(191) NULL,
                `gl_account` VARCHAR(100) NULL,
                `category` VARCHAR(50) NULL,
                `street` VARCHAR(250) NULL,
                `city` VARCHAR(50) NULL,
                `state` VARCHAR(50) NULL,
                `country` VARCHAR(50) NULL,
                `postal_code` VARCHAR(30) NULL,
                `po_box` VARCHAR(30) NULL,
                `description` TEXT NULL,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `assigned_to` BIGINT UNSIGNED NULL,
                `created_by` BIGINT UNSIGNED NULL,
                `updated_by` BIGINT UNSIGNED NULL,
                `deleted_by` BIGINT UNSIGNED NULL,
                `sub_institute_id` BIGINT UNSIGNED NOT NULL,
                `created_at` TIMESTAMP NULL,
                `updated_at` TIMESTAMP NULL,
                `deleted_at` TIMESTAMP NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `crm_vendors_vendor_no_unique` (`vendor_no`),
                KEY `crm_vendors_legacy_id_index` (`legacy_id`),
                KEY `crm_vendors_assigned_to_index` (`assigned_to`),
                KEY `crm_vendors_sub_institute_id_index` (`sub_institute_id`)
            '.$tail,

            'crm_vendor_contacts' => 'CREATE TABLE `crm_vendor_contacts` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `vendor_id` BIGINT UNSIGNED NOT NULL,
                `contact_id` BIGINT UNSIGNED NOT NULL,
                `created_at` TIMESTAMP NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `crm_vendor_contacts_vendor_id_contact_id_unique` (`vendor_id`, `contact_id`),
                KEY `crm_vendor_contacts_vendor_id_index` (`vendor_id`),
                KEY `crm_vendor_contacts_contact_id_index` (`contact_id`)
            '.$tail,

            'crm_purchase_orders' => 'CREATE TABLE `crm_purchase_orders` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `legacy_id` BIGINT UNSIGNED NULL,
                `po_no` VARCHAR(100) NOT NULL,
                `subject` VARCHAR(191) NOT NULL,
                `vendor_id` BIGINT UNSIGNED NULL,
                `organization_id` BIGINT UNSIGNED NULL,
                `contact_id` BIGINT UNSIGNED NULL,
                `quote_id` BIGINT UNSIGNED NULL,
                `po_stage` VARCHAR(100) NULL,
                `requisition_no` VARCHAR(100) NULL,
                `tracking_no` VARCHAR(100) NULL,
                `due_date` DATE NULL,
                `currency` VARCHAR(10) NULL,
                `subtotal` DECIMAL(15,2) NOT NULL DEFAULT 0,
                `discount_percent` DECIMAL(7,3) NULL,
                `discount_amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
                `shipping_handling_amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
                `adjustment` DECIMAL(15,2) NOT NULL DEFAULT 0,
                `tax_total` DECIMAL(15,2) NOT NULL DEFAULT 0,
                `total` DECIMAL(15,2) NOT NULL DEFAULT 0,
                `billing_street` VARCHAR(250) NULL,
                `billing_city` VARCHAR(50) NULL,
                `billing_state` VARCHAR(50) NULL,
                `billing_code` VARCHAR(30) NULL,
                `billing_country` VARCHAR(50) NULL,
                `billing_po_box` VARCHAR(30) NULL,
                `shipping_street` VARCHAR(250) NULL,
                `shipping_city` VARCHAR(50) NULL,
                `shipping_state` VARCHAR(50) NULL,
                `shipping_code` VARCHAR(30) NULL,
                `shipping_country` VARCHAR(50) NULL,
                `shipping_po_box` VARCHAR(30) NULL,
                `terms_conditions` TEXT NULL,
                `description` TEXT NULL,
                `assigned_to` BIGINT UNSIGNED NULL,
                `created_by` BIGINT UNSIGNED NULL,
                `updated_by` BIGINT UNSIGNED NULL,
                `deleted_by` BIGINT UNSIGNED NULL,
                `sub_institute_id` BIGINT UNSIGNED NOT NULL,
                `created_at` TIMESTAMP NULL,
                `updated_at` TIMESTAMP NULL,
                `deleted_at` TIMESTAMP NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `crm_purchase_orders_po_no_unique` (`po_no`),
                KEY `crm_purchase_orders_legacy_id_index` (`legacy_id`),
                KEY `crm_purchase_orders_vendor_id_index` (`vendor_id`),
                KEY `crm_purchase_orders_organization_id_index` (`organization_id`),
                KEY `crm_purchase_orders_contact_id_index` (`contact_id`),
                KEY `crm_purchase_orders_quote_id_index` (`quote_id`),
                KEY `crm_purchase_orders_po_stage_index` (`po_stage`),
                KEY `crm_purchase_orders_assigned_to_index` (`assigned_to`),
                KEY `crm_purchase_orders_sub_institute_id_index` (`sub_institute_id`)
            '.$tail,

            'crm_purchase_order_line_items' => 'CREATE TABLE `crm_purchase_order_line_items` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `purchase_order_id` BIGINT UNSIGNED NOT NULL,
                `product_id` BIGINT UNSIGNED NULL,
                `description` VARCHAR(500) NULL,
                `quantity` DECIMAL(15,2) NOT NULL DEFAULT 1,
                `unit_price` DECIMAL(15,2) NOT NULL DEFAULT 0,
                `discount_percent` DECIMAL(7,3) NULL,
                `discount_amount` DECIMAL(15,2) NULL,
                `tax_rate_id` BIGINT UNSIGNED NULL,
                `tax_name_snapshot` VARCHAR(100) NULL,
                `tax_percent_snapshot` DECIMAL(7,3) NULL,
                `line_total` DECIMAL(15,2) NOT NULL DEFAULT 0,
                `sequence_no` INT UNSIGNED NOT NULL DEFAULT 0,
                `created_at` TIMESTAMP NULL,
                `updated_at` TIMESTAMP NULL,
                PRIMARY KEY (`id`),
                KEY `crm_purchase_order_line_items_purchase_order_id_index` (`purchase_order_id`),
                KEY `crm_purchase_order_line_items_product_id_index` (`product_id`),
                KEY `crm_purchase_order_line_items_tax_rate_id_index` (`tax_rate_id`)
            '.$tail,

            'crm_purchase_order_status_history' => 'CREATE TABLE `crm_purchase_order_status_history` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `purchase_order_id` BIGINT UNSIGNED NOT NULL,
                `from_stage` VARCHAR(100) NULL,
                `to_stage` VARCHAR(100) NULL,
                `total` DECIMAL(15,2) NULL,
                `changed_by` BIGINT UNSIGNED NULL,
                `created_at` TIMESTAMP NULL,
                PRIMARY KEY (`id`),
                KEY `crm_purchase_order_status_history_purchase_order_id_index` (`purchase_order_id`)
            '.$tail,
        };
    }

    /** The same five tables for sqlite (the PHPUnit database), the way the create migrations wrote them. */
    private function blueprint(string $table): void
    {
        match ($table) {
            'crm_vendors' => Schema::create('crm_vendors', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('legacy_id')->nullable()->index();
                $t->string('vendor_no', 100)->unique();
                $t->string('name', 191);
                $t->string('phone', 50)->nullable();
                $t->string('email', 191)->nullable();
                $t->string('website', 191)->nullable();
                $t->string('gl_account', 100)->nullable();
                $t->string('category', 50)->nullable();
                $t->string('street', 250)->nullable();
                $t->string('city', 50)->nullable();
                $t->string('state', 50)->nullable();
                $t->string('country', 50)->nullable();
                $t->string('postal_code', 30)->nullable();
                $t->string('po_box', 30)->nullable();
                $t->text('description')->nullable();
                $t->boolean('is_active')->default(true);
                $t->unsignedBigInteger('assigned_to')->nullable()->index();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->unsignedBigInteger('deleted_by')->nullable();
                $t->unsignedBigInteger('sub_institute_id')->index();
                $t->timestamps();
                $t->softDeletes();
            }),

            'crm_vendor_contacts' => Schema::create('crm_vendor_contacts', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('vendor_id')->index();
                $t->unsignedBigInteger('contact_id')->index();
                $t->timestamp('created_at')->nullable();
                $t->unique(['vendor_id', 'contact_id']);
            }),

            'crm_purchase_orders' => Schema::create('crm_purchase_orders', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('legacy_id')->nullable()->index();
                $t->string('po_no', 100)->unique();
                $t->string('subject', 191);
                $t->unsignedBigInteger('vendor_id')->nullable()->index();
                $t->unsignedBigInteger('organization_id')->nullable()->index();
                $t->unsignedBigInteger('contact_id')->nullable()->index();
                $t->unsignedBigInteger('quote_id')->nullable()->index();
                $t->string('po_stage', 100)->nullable()->index();
                $t->string('requisition_no', 100)->nullable();
                $t->string('tracking_no', 100)->nullable();
                $t->date('due_date')->nullable();
                $t->string('currency', 10)->nullable();
                $t->decimal('subtotal', 15, 2)->default(0);
                $t->decimal('discount_percent', 7, 3)->nullable();
                $t->decimal('discount_amount', 15, 2)->default(0);
                $t->decimal('shipping_handling_amount', 15, 2)->default(0);
                $t->decimal('adjustment', 15, 2)->default(0);
                $t->decimal('tax_total', 15, 2)->default(0);
                $t->decimal('total', 15, 2)->default(0);
                $t->string('billing_street', 250)->nullable();
                $t->string('billing_city', 50)->nullable();
                $t->string('billing_state', 50)->nullable();
                $t->string('billing_code', 30)->nullable();
                $t->string('billing_country', 50)->nullable();
                $t->string('billing_po_box', 30)->nullable();
                $t->string('shipping_street', 250)->nullable();
                $t->string('shipping_city', 50)->nullable();
                $t->string('shipping_state', 50)->nullable();
                $t->string('shipping_code', 30)->nullable();
                $t->string('shipping_country', 50)->nullable();
                $t->string('shipping_po_box', 30)->nullable();
                $t->text('terms_conditions')->nullable();
                $t->text('description')->nullable();
                $t->unsignedBigInteger('assigned_to')->nullable()->index();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->unsignedBigInteger('deleted_by')->nullable();
                $t->unsignedBigInteger('sub_institute_id')->index();
                $t->timestamps();
                $t->softDeletes();
            }),

            'crm_purchase_order_line_items' => Schema::create('crm_purchase_order_line_items', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('purchase_order_id')->index();
                $t->unsignedBigInteger('product_id')->nullable()->index();
                $t->string('description', 500)->nullable();
                $t->decimal('quantity', 15, 2)->default(1);
                $t->decimal('unit_price', 15, 2)->default(0);
                $t->decimal('discount_percent', 7, 3)->nullable();
                $t->decimal('discount_amount', 15, 2)->nullable();
                $t->unsignedBigInteger('tax_rate_id')->nullable()->index();
                $t->string('tax_name_snapshot', 100)->nullable();
                $t->decimal('tax_percent_snapshot', 7, 3)->nullable();
                $t->decimal('line_total', 15, 2)->default(0);
                $t->unsignedInteger('sequence_no')->default(0);
                $t->timestamps();
            }),

            'crm_purchase_order_status_history' => Schema::create('crm_purchase_order_status_history', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('purchase_order_id')->index();
                $t->string('from_stage', 100)->nullable();
                $t->string('to_stage', 100)->nullable();
                $t->decimal('total', 15, 2)->nullable();
                $t->unsignedBigInteger('changed_by')->nullable();
                $t->timestamp('created_at')->nullable();
            }),
        };
    }
};
