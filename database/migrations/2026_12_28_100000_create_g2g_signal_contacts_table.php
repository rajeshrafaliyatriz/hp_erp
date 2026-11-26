<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Contacts found on the public web for the organisation behind a signal (the contact-research step of the Signals module).
 *
 * One row per person (or one row for the organisation's own published contact line) per company: every signal of that company shows them.
 * Every value was FOUND, never generated: `*_source_url` is the page that printed it, and `verification_status` is `unverified` for all of them
 * (nothing here checks that an address delivers or a number rings). A missing value is NULL and shown as "Not Available".
 *
 * ADDITIVE and idempotent. No foreign keys and no JSON column: the live host is MariaDB 10.1.48. The raw DDL states utf8mb4 explicitly; the first
 * timestamp column has an explicit NULL default so MariaDB adds no ON UPDATE clause to it. Indexes stay under the 767-byte Compact-row-format limit.
 *
 * RUN ONLY AFTER THE OWNER'S GO, WITH A VERIFIED BACKUP:
 *   php artisan migrate --path=database/migrations/2026_12_28_100000_create_g2g_signal_contacts_table.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            if (empty(DB::select("SHOW TABLES LIKE 'g2g_signal_contacts'"))) {
                DB::statement("CREATE TABLE `g2g_signal_contacts` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `sub_institute_id` BIGINT UNSIGNED NOT NULL,
                    `company_id` BIGINT UNSIGNED NOT NULL,
                    `opportunity_id` BIGINT UNSIGNED NULL,
                    `full_name` VARCHAR(191) NULL,
                    `designation` VARCHAR(191) NULL,
                    `email` VARCHAR(191) NULL,
                    `phone` VARCHAR(40) NULL,
                    `linkedin_url` VARCHAR(500) NULL,
                    `website` VARCHAR(500) NULL,
                    `location` VARCHAR(191) NULL,
                    `source_url` VARCHAR(500) NULL,
                    `email_source_url` VARCHAR(500) NULL,
                    `phone_source_url` VARCHAR(500) NULL,
                    `role_searched` VARCHAR(80) NULL,
                    `verification_status` VARCHAR(20) NOT NULL DEFAULT 'unverified',
                    `researched_at` DATETIME NULL,
                    `created_at` TIMESTAMP NULL DEFAULT NULL,
                    `updated_at` TIMESTAMP NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `g2g_sc_tenant_company` (`sub_institute_id`, `company_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            return;
        }

        if (! Schema::hasTable('g2g_signal_contacts')) {
            Schema::create('g2g_signal_contacts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sub_institute_id');
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('opportunity_id')->nullable();
                $table->string('full_name', 191)->nullable();
                $table->string('designation', 191)->nullable();
                $table->string('email', 191)->nullable();
                $table->string('phone', 40)->nullable();
                $table->string('linkedin_url', 500)->nullable();
                $table->string('website', 500)->nullable();
                $table->string('location', 191)->nullable();
                $table->string('source_url', 500)->nullable();
                $table->string('email_source_url', 500)->nullable();
                $table->string('phone_source_url', 500)->nullable();
                $table->string('role_searched', 80)->nullable();
                $table->string('verification_status', 20)->default('unverified');
                $table->dateTime('researched_at')->nullable();
                $table->timestamps();
                $table->index(['sub_institute_id', 'company_id'], 'g2g_sc_tenant_company');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('g2g_signal_contacts');
    }
};
