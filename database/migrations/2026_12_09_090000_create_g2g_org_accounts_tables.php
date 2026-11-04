<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Organisation 360 (slice 3 of the agentic CRM, Docs/agentic-crm/slices/S03-organisation-360.md).
 *
 * An organisation lives in three tables that do not know about each other: the CRM account (crm_organizations: quotes, orders, invoices, tickets and
 * contacts hang from it), the GTM account (gtm_accounts) and the Signals company (g2g_companies). g2g_org_accounts is ONE thin row per real-world
 * organisation that points at whichever of the three exist. It is an index, not a merge: nothing in the three tables moves, nothing points at this table
 * yet except the screens that read it, so it can be ignored or dropped without harm (a two-way door).
 *
 *   lifecycle                discovered (a signal company only) | target (has a GTM account) | engaged (has a CRM organisation) | customer | archived
 *   crm_organization_id etc. loose pointers (no foreign keys: MariaDB 10.1, repo convention); each is UNIQUE per organisation so one source row belongs to
 *                            at most one identity. NULL = this organisation has no row in that source.
 *   *_link_basis             WHY the pointer is there: existing_link (an earlier flow tied them), exact_name, exact_domain, manual (a person confirmed it).
 *                            Never guessed: fuzzy matches are only ever suggestions until a person confirms them.
 *
 * g2g_org_link_rejections remembers a suggestion a person turned down, so it is never offered again.
 *
 * ADDITIVE and idempotent. No JSON, no foreign keys; raw DDL states utf8mb4 (dev's database default is latin1); the first timestamp has an explicit NULL
 * default so MariaDB adds no ON UPDATE clause to it (DB-RULES.md).
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME, after the owner's go:
 *   php artisan migrate --path=database/migrations/2026_12_09_090000_create_g2g_org_accounts_tables.php
 *   php artisan migrate --database=live --path=database/migrations/2026_12_09_090000_create_g2g_org_accounts_tables.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            if (empty(DB::select("SHOW TABLES LIKE 'g2g_org_accounts'"))) {
                DB::statement("CREATE TABLE `g2g_org_accounts` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `sub_institute_id` BIGINT UNSIGNED NOT NULL,
                    `name` VARCHAR(191) NOT NULL,
                    `normalized_name` VARCHAR(191) NOT NULL,
                    `domain` VARCHAR(191) NULL,
                    `lifecycle` VARCHAR(12) NOT NULL DEFAULT 'discovered',
                    `crm_organization_id` BIGINT UNSIGNED NULL,
                    `gtm_account_id` BIGINT UNSIGNED NULL,
                    `g2g_company_id` BIGINT UNSIGNED NULL,
                    `crm_link_basis` VARCHAR(16) NULL,
                    `gtm_link_basis` VARCHAR(16) NULL,
                    `company_link_basis` VARCHAR(16) NULL,
                    `owner_user_id` BIGINT UNSIGNED NULL,
                    `created_by` BIGINT UNSIGNED NULL,
                    `updated_by` BIGINT UNSIGNED NULL,
                    `created_at` TIMESTAMP NULL DEFAULT NULL,
                    `updated_at` TIMESTAMP NULL DEFAULT NULL,
                    `deleted_at` TIMESTAMP NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `g2g_oa_tenant_crm` (`sub_institute_id`, `crm_organization_id`),
                    UNIQUE KEY `g2g_oa_tenant_gtm` (`sub_institute_id`, `gtm_account_id`),
                    UNIQUE KEY `g2g_oa_tenant_company` (`sub_institute_id`, `g2g_company_id`),
                    KEY `g2g_oa_tenant_name` (`sub_institute_id`, `normalized_name`),
                    KEY `g2g_oa_tenant_lifecycle` (`sub_institute_id`, `lifecycle`),
                    KEY `g2g_oa_tenant_domain` (`sub_institute_id`, `domain`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }
            if (empty(DB::select("SHOW TABLES LIKE 'g2g_org_link_rejections'"))) {
                DB::statement("CREATE TABLE `g2g_org_link_rejections` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `sub_institute_id` BIGINT UNSIGNED NOT NULL,
                    `left_type` VARCHAR(16) NOT NULL,
                    `left_id` BIGINT UNSIGNED NOT NULL,
                    `right_type` VARCHAR(16) NOT NULL,
                    `right_id` BIGINT UNSIGNED NOT NULL,
                    `decided_by` BIGINT UNSIGNED NULL,
                    `created_at` TIMESTAMP NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `g2g_olr_pair` (`sub_institute_id`, `left_type`, `left_id`, `right_type`, `right_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            return;
        }

        if (! Schema::hasTable('g2g_org_accounts')) {
            Schema::create('g2g_org_accounts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sub_institute_id');
                $table->string('name', 191);
                $table->string('normalized_name', 191);
                $table->string('domain', 191)->nullable();
                $table->string('lifecycle', 12)->default('discovered');
                $table->unsignedBigInteger('crm_organization_id')->nullable();
                $table->unsignedBigInteger('gtm_account_id')->nullable();
                $table->unsignedBigInteger('g2g_company_id')->nullable();
                $table->string('crm_link_basis', 16)->nullable();
                $table->string('gtm_link_basis', 16)->nullable();
                $table->string('company_link_basis', 16)->nullable();
                $table->unsignedBigInteger('owner_user_id')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->timestamp('deleted_at')->nullable();
                $table->unique(['sub_institute_id', 'crm_organization_id'], 'g2g_oa_tenant_crm');
                $table->unique(['sub_institute_id', 'gtm_account_id'], 'g2g_oa_tenant_gtm');
                $table->unique(['sub_institute_id', 'g2g_company_id'], 'g2g_oa_tenant_company');
                $table->index(['sub_institute_id', 'normalized_name'], 'g2g_oa_tenant_name');
                $table->index(['sub_institute_id', 'lifecycle'], 'g2g_oa_tenant_lifecycle');
                $table->index(['sub_institute_id', 'domain'], 'g2g_oa_tenant_domain');
            });
        }
        if (! Schema::hasTable('g2g_org_link_rejections')) {
            Schema::create('g2g_org_link_rejections', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sub_institute_id');
                $table->string('left_type', 16);
                $table->unsignedBigInteger('left_id');
                $table->string('right_type', 16);
                $table->unsignedBigInteger('right_id');
                $table->unsignedBigInteger('decided_by')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->unique(['sub_institute_id', 'left_type', 'left_id', 'right_type', 'right_id'], 'g2g_olr_pair');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('g2g_org_link_rejections');
        Schema::dropIfExists('g2g_org_accounts');
    }
};
