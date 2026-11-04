<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The daily record of the CRM pipeline, per owner (slice 11 of the agentic CRM, Docs/agentic-crm/slices/S11-pipeline-and-forecast.md, task 6).
 *
 * WHY A NEW TABLE (the decision, with its evidence, is in the slice's work log): gtm_forecast_snapshots already keeps a daily row per close month and currency, but
 * (1) it has no owner, and a row per owner cannot be added to it without breaking its unique key and ForecastService::accuracy() (which groups by month and currency
 * and would double count); (2) it is built from gtm_deals, and since the sync with the CRM became bookkeeping (a failed sync no longer fails a save) a deal can
 * lag the opportunity it mirrors, so the CRM pipeline needs a record of its own that does not depend on the sync. The GTM table and its screen are left as they are.
 *
 * One row per organisation, day, scope, owner and currency:
 *   scope             'total' (every owner together; owner_user_id 0) or 'owner' (one person; owner_user_id 0 = opportunities nobody owns)
 *   currency          the opportunity's own currency, never converted; 'XXX' = no (or an unrecognised) currency recorded: those are counted, never summed
 *   open_count        open (not Closed Won / Closed Lost) opportunities, not counting those whose forecast category is Omitted
 *   priced_count      how many of them carry an amount and a currency (the ones the values below add up)
 *   omitted_count     open opportunities whose forecast category is Omitted (left out of everything else)
 *   open_value        the pipeline: everything not omitted. best_case_value = best case + commit; commit_value = commit only (the same cumulative views as the GTM forecast)
 *   weighted_value    amount x the opportunity's OWN probability / 100, over the priced opportunities that have a probability; NULL when none has one (never a made-up 0)
 *   weighted_count    how many opportunities that figure covers
 *
 * ADDITIVE: one new table, nothing existing is touched. No foreign keys, no JSON column (repo convention, MariaDB 10.1). Raw DDL states utf8mb4 (dev's database
 * default is latin1); the unique key is under 100 bytes, well inside the 767-byte limit of the Compact row format on live. created_at has an explicit NULL
 * default so MariaDB adds no ON UPDATE clause to it. Guarded: a second run does nothing. down() drops only what up() made.
 *
 * RUN ON DEV, ONE FILE AT A TIME (never an unscoped migrate):
 *   php artisan migrate --path=database/migrations/2026_12_11_130100_create_g2g_forecast_snapshots_table.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('g2g_forecast_snapshots')) {
            return;
        }

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("CREATE TABLE `g2g_forecast_snapshots` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `sub_institute_id` BIGINT UNSIGNED NOT NULL,
                `snapshot_date` DATE NOT NULL,
                `scope` VARCHAR(6) NOT NULL,
                `owner_user_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `currency` CHAR(3) NOT NULL,
                `open_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `priced_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `omitted_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `open_value` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
                `best_case_value` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
                `commit_value` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
                `weighted_value` DECIMAL(16,2) NULL DEFAULT NULL,
                `weighted_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `created_at` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `g2g_forecast_snapshots_unique` (`sub_institute_id`, `snapshot_date`, `scope`, `owner_user_id`, `currency`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            return;
        }

        Schema::create('g2g_forecast_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sub_institute_id');
            $table->date('snapshot_date');
            $table->string('scope', 6);
            $table->unsignedBigInteger('owner_user_id')->default(0);
            $table->char('currency', 3);
            $table->unsignedInteger('open_count')->default(0);
            $table->unsignedInteger('priced_count')->default(0);
            $table->unsignedInteger('omitted_count')->default(0);
            $table->decimal('open_value', 16, 2)->default(0);
            $table->decimal('best_case_value', 16, 2)->default(0);
            $table->decimal('commit_value', 16, 2)->default(0);
            $table->decimal('weighted_value', 16, 2)->nullable();
            $table->unsignedInteger('weighted_count')->default(0);
            $table->timestamp('created_at')->nullable();

            $table->unique(['sub_institute_id', 'snapshot_date', 'scope', 'owner_user_id', 'currency'], 'g2g_forecast_snapshots_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('g2g_forecast_snapshots');
    }
};
