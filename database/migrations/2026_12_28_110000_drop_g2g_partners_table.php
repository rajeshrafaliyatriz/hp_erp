<?php

use App\Support\GtmMigrationSupport;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The Partner Network is gone (26 Nov 2026; Docs/agentic-crm/briefs/MARKET-SIGNALS-PARTNER-REMOVAL.md), so the one table only it used is dropped:
 *
 *   g2g_partners   the partner registry (delivery capability, coverage, capacity, authorizations)   (2026_09_30_220000_create_g2g_portfolio_and_partners_tables)
 *
 * No later migration altered it. NOT dropped: g2g_opportunity_matches.partner_id (a nullable column of a table the matching engine still writes; 0 non-empty rows on dev and db3 on 26 Nov
 * 2026), g2g_product_offers.partner_sellable and g2g_company_opportunities.partner_route_note (a product readiness flag and imported signal text: not the registry), and every lms_* table
 * of the LMS partner feature. The table held no row on dev or on db3 when it was checked on 26 Nov 2026; dump it first if that has changed
 * (`php artisan signals:backup-tables --tables=g2g_partners`).
 *
 * RUN IT ONLY AFTER THE BACKEND THAT NO LONGER READS THE TABLE IS DEPLOYED. The old MatchingEngine reads g2g_partners during EVERY signal import (MarketImporter and the match endpoints), so
 * dropping the table under it makes those imports fail on a missing table until the new backend is live. The menu command `php artisan signals:market-signals-menu --apply` does not depend on
 * this and can run earlier. One file at a time, never an unscoped migrate:
 *
 *   php artisan migrate --path=database/migrations/2026_12_28_110000_drop_g2g_partners_table.php
 *
 * GUARDED AND REPEATABLE: the table is dropped only when it exists (information_schema check, the form that works on both hosts: DB-RULES), so a second run does nothing and a host that never
 * had the table skips it. RAW DDL on MySQL and MariaDB (10.1 safe: no JSON, no generated columns), the portable Schema call on sqlite (the PHPUnit database).
 *
 * down() recreates the table EMPTY, only when it is missing, with the exact columns and indexes (names included) that its create migration made. The rows are not brought back.
 */
return new class extends Migration
{
    use GtmMigrationSupport;

    private const TABLE = 'g2g_partners';

    private function isMariaDb(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    public function up(): void
    {
        if (! $this->has(self::TABLE)) {
            return;
        }
        if ($this->isMariaDb()) {
            DB::statement('DROP TABLE `'.self::TABLE.'`');
        } else {
            Schema::drop(self::TABLE);
        }
    }

    public function down(): void
    {
        if ($this->has(self::TABLE)) {
            return;
        }
        $this->isMariaDb() ? DB::statement($this->ddl()) : $this->blueprint();
    }

    /** The CREATE TABLE of g2g_partners, as 2026_09_30_220000 made it (MariaDB 10.1 safe). */
    private function ddl(): string
    {
        return 'CREATE TABLE `g2g_partners` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `sub_institute_id` BIGINT UNSIGNED NULL,
                `partner_id` VARCHAR(50) NOT NULL,
                `partner_name` VARCHAR(191) NOT NULL,
                `partner_type` VARCHAR(100) NOT NULL,
                `hq_state` VARCHAR(100) NULL,
                `states_covered` LONGTEXT NULL,
                `segments_covered` LONGTEXT NULL,
                `needs_addressed` LONGTEXT NULL,
                `procurement_routes` LONGTEXT NULL,
                `offers_authorized` LONGTEXT NULL,
                `empanelments` LONGTEXT NULL,
                `certifications` LONGTEXT NULL,
                `key_buyer_relationships` TEXT NULL,
                `delivery_capability` VARCHAR(100) NULL,
                `max_concurrent_deals` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
                `active_deals_now` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `capacity_available` INT NOT NULL DEFAULT 1,
                `sales_contact_name` VARCHAR(191) NULL,
                `contact_email` VARCHAR(191) NULL,
                `contact_phone` VARCHAR(100) NULL,
                `preferred_channel` VARCHAR(50) NULL,
                `commercial_model` VARCHAR(100) NULL,
                `referral_margin_pct` DECIMAL(5,2) NULL,
                `deal_registration_agreed` TINYINT(1) NOT NULL DEFAULT 0,
                `conflicts` TEXT NULL,
                `leads_received` INT UNSIGNED NOT NULL DEFAULT 0,
                `acknowledged_within_sla` INT UNSIGNED NOT NULL DEFAULT 0,
                `deals_won` INT UNSIGNED NOT NULL DEFAULT 0,
                `partner_status` VARCHAR(50) NOT NULL DEFAULT \'Prospect\',
                `conversion_rate` DECIMAL(5,4) NULL,
                `avg_days_to_first_meeting` SMALLINT UNSIGNED NULL,
                `notes` TEXT NULL,
                `is_example` TINYINT(1) NOT NULL DEFAULT 0,
                `created_by` BIGINT UNSIGNED NULL,
                `updated_by` BIGINT UNSIGNED NULL,
                `created_at` TIMESTAMP NULL,
                `updated_at` TIMESTAMP NULL,
                PRIMARY KEY (`id`),
                KEY `g2g_partners_tenant_partner_id` (`sub_institute_id`, `partner_id`),
                KEY `g2g_partners_tenant_status` (`sub_institute_id`, `partner_status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    }

    /** The same table for sqlite (the PHPUnit database), the way the create migration wrote it. */
    private function blueprint(): void
    {
        Schema::create(self::TABLE, function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->string('partner_id', 50);
            $t->string('partner_name', 191);
            $t->string('partner_type', 100);
            $t->string('hq_state', 100)->nullable();
            $t->longText('states_covered')->nullable();
            $t->longText('segments_covered')->nullable();
            $t->longText('needs_addressed')->nullable();
            $t->longText('procurement_routes')->nullable();
            $t->longText('offers_authorized')->nullable();
            $t->longText('empanelments')->nullable();
            $t->longText('certifications')->nullable();
            $t->text('key_buyer_relationships')->nullable();
            $t->string('delivery_capability', 100)->nullable();
            $t->unsignedSmallInteger('max_concurrent_deals')->default(1);
            $t->unsignedSmallInteger('active_deals_now')->default(0);
            $t->integer('capacity_available')->default(1);
            $t->string('sales_contact_name', 191)->nullable();
            $t->string('contact_email', 191)->nullable();
            $t->string('contact_phone', 100)->nullable();
            $t->string('preferred_channel', 50)->nullable();
            $t->string('commercial_model', 100)->nullable();
            $t->decimal('referral_margin_pct', 5, 2)->nullable();
            $t->boolean('deal_registration_agreed')->default(false);
            $t->text('conflicts')->nullable();
            $t->unsignedInteger('leads_received')->default(0);
            $t->unsignedInteger('acknowledged_within_sla')->default(0);
            $t->unsignedInteger('deals_won')->default(0);
            $t->string('partner_status', 50)->default('Prospect');
            $t->decimal('conversion_rate', 5, 4)->nullable();
            $t->unsignedSmallInteger('avg_days_to_first_meeting')->nullable();
            $t->text('notes')->nullable();
            $t->boolean('is_example')->default(false);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();

            $t->index(['sub_institute_id', 'partner_id'], 'g2g_partners_tenant_partner_id');
            $t->index(['sub_institute_id', 'partner_status'], 'g2g_partners_tenant_status');
        });
    }
};
