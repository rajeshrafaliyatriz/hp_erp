<?php

use App\Support\GtmMigrationSupport;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The GTM Forecast screen is gone (26 Nov 2026; Docs/agentic-crm/briefs/REMOVE-PROSPECTING-FORECAST.md), so the two tables only it used are dropped:
 *
 *   gtm_stage_settings       the probability and category an administrator chose per open stage
 *   gtm_forecast_snapshots   the daily GTM forecast record per close month and currency
 *
 * NOT dropped: g2g_forecast_snapshots (the CRM Sales forecast record), gtm_deals.forecast_category (a field on the deal itself) and every other gtm_* table.
 *
 * RUN IT ONLY AFTER THE BACKEND THAT NO LONGER READS THE TABLES IS DEPLOYED: the old scheduler's daily pass writes gtm_forecast_snapshots, so a missing table would fail it.
 * Dev held one snapshot row on 26 Nov 2026; dump the table first if it matters. One file at a time, never an unscoped migrate:
 *
 *   php artisan migrate --path=database/migrations/2026_12_27_090200_drop_gtm_forecast_snapshots_and_stage_settings.php
 *
 * GUARDED AND REPEATABLE: each table is dropped only when it exists (information_schema check, the form that works on both hosts: DB-RULES), so a second run does nothing and a
 * host that never had the tables skips them. RAW DDL on MySQL and MariaDB (10.1 safe: no JSON, no generated columns), the portable Schema call on sqlite (the PHPUnit database).
 *
 * down() recreates BOTH tables EMPTY, with the exact columns and indexes 2026_10_11_100000_gtm_forecasting created. The rows are not brought back.
 */
return new class extends Migration
{
    use GtmMigrationSupport;

    private function isMariaDb(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    public function up(): void
    {
        foreach (['gtm_forecast_snapshots', 'gtm_stage_settings'] as $table) {
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
        if (! $this->has('gtm_stage_settings')) {
            if ($this->isMariaDb()) {
                DB::statement('CREATE TABLE `gtm_stage_settings` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `sub_institute_id` BIGINT UNSIGNED NOT NULL,
                    `stage` VARCHAR(20) NOT NULL,
                    `probability` DECIMAL(5,2) NULL,
                    `category` VARCHAR(12) NOT NULL DEFAULT \'pipeline\',
                    `updated_by` BIGINT UNSIGNED NULL,
                    `created_at` TIMESTAMP NULL,
                    `updated_at` TIMESTAMP NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `gtm_stage_settings_unique` (`sub_institute_id`, `stage`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            } else {
                Schema::create('gtm_stage_settings', function (Blueprint $t) {
                    $t->id();
                    $t->unsignedBigInteger('sub_institute_id');
                    $t->string('stage', 20);
                    $t->decimal('probability', 5, 2)->nullable();
                    $t->string('category', 12)->default('pipeline');
                    $t->unsignedBigInteger('updated_by')->nullable();
                    $t->timestamps();

                    $t->unique(['sub_institute_id', 'stage'], 'gtm_stage_settings_unique');
                });
            }
        }

        if (! $this->has('gtm_forecast_snapshots')) {
            if ($this->isMariaDb()) {
                DB::statement('CREATE TABLE `gtm_forecast_snapshots` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `sub_institute_id` BIGINT UNSIGNED NOT NULL,
                    `snapshot_date` DATE NOT NULL,
                    `period` CHAR(7) NOT NULL,
                    `currency` CHAR(3) NOT NULL,
                    `deal_count` INT UNSIGNED NOT NULL DEFAULT 0,
                    `pipeline_value` DECIMAL(16,2) NOT NULL DEFAULT 0,
                    `best_case_value` DECIMAL(16,2) NOT NULL DEFAULT 0,
                    `commit_value` DECIMAL(16,2) NOT NULL DEFAULT 0,
                    `weighted_value` DECIMAL(16,2) NULL,
                    `created_at` TIMESTAMP NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `gtm_forecast_snapshots_unique` (`sub_institute_id`, `snapshot_date`, `period`, `currency`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            } else {
                Schema::create('gtm_forecast_snapshots', function (Blueprint $t) {
                    $t->id();
                    $t->unsignedBigInteger('sub_institute_id');
                    $t->date('snapshot_date');
                    $t->char('period', 7);
                    $t->char('currency', 3);
                    $t->unsignedInteger('deal_count')->default(0);
                    $t->decimal('pipeline_value', 16, 2)->default(0);
                    $t->decimal('best_case_value', 16, 2)->default(0);
                    $t->decimal('commit_value', 16, 2)->default(0);
                    $t->decimal('weighted_value', 16, 2)->nullable();
                    $t->timestamp('created_at')->nullable();

                    $t->unique(['sub_institute_id', 'snapshot_date', 'period', 'currency'], 'gtm_forecast_snapshots_unique');
                });
            }
        }
    }
};
