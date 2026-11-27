<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Outputs delivered by the organisation's own scheduled AI tasks (Claude, ChatGPT / GPT, DeepSeek, Gemini) to an AI signal connector.
 *
 * One row per delivery, kept EXACTLY as received (`payload`): the signals themselves are imported into the existing g2g_company_opportunities by the
 * existing market importer (same gates, same duplicate rules), so this table holds no signals. It exists because nothing else keeps the original
 * output: scan logs keep counts, rejections keep only the rejected records. It also makes a delivery idempotent (`idempotency_key`: the producer's
 * delivery id, or a hash of the body) and records what each delivery turned into (`results`, `scan_log_id`, the counters).
 *
 * ADDITIVE and idempotent. No foreign keys and no JSON column: the live host is MariaDB 10.1.48 (Compact row format, no JSON type). The raw DDL states
 * utf8mb4 explicitly; the first timestamp column has an explicit NULL default so MariaDB adds no ON UPDATE clause. The unique key is
 * (bigint, bigint, varchar(64)) = under 300 bytes, far below the 767-byte index limit.
 *
 * RUN ONLY AFTER THE OWNER'S GO, WITH A VERIFIED BACKUP:
 *   php artisan migrate --path=database/migrations/2026_12_29_100000_create_g2g_ai_deliveries_table.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            if (empty(DB::select("SHOW TABLES LIKE 'g2g_ai_deliveries'"))) {
                DB::statement("CREATE TABLE `g2g_ai_deliveries` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `sub_institute_id` BIGINT UNSIGNED NOT NULL,
                    `connector_id` BIGINT UNSIGNED NOT NULL,
                    `provider` VARCHAR(20) NOT NULL,
                    `idempotency_key` VARCHAR(64) NOT NULL,
                    `delivery_ref` VARCHAR(191) NULL,
                    `via` VARCHAR(12) NOT NULL DEFAULT 'webhook',
                    `status` VARCHAR(12) NOT NULL DEFAULT 'received',
                    `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                    `records_total` INT UNSIGNED NOT NULL DEFAULT 0,
                    `records_accepted` INT UNSIGNED NOT NULL DEFAULT 0,
                    `records_updated` INT UNSIGNED NOT NULL DEFAULT 0,
                    `records_duplicate` INT UNSIGNED NOT NULL DEFAULT 0,
                    `records_rejected` INT UNSIGNED NOT NULL DEFAULT 0,
                    `scan_log_id` BIGINT UNSIGNED NULL,
                    `payload` LONGTEXT NOT NULL,
                    `results` LONGTEXT NULL,
                    `error_message` VARCHAR(500) NULL,
                    `received_at` DATETIME NOT NULL,
                    `imported_at` DATETIME NULL,
                    `created_at` TIMESTAMP NULL DEFAULT NULL,
                    `updated_at` TIMESTAMP NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `g2g_aid_unique` (`sub_institute_id`, `connector_id`, `idempotency_key`),
                    KEY `g2g_aid_status` (`sub_institute_id`, `connector_id`, `status`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            return;
        }

        if (! Schema::hasTable('g2g_ai_deliveries')) {
            Schema::create('g2g_ai_deliveries', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sub_institute_id');
                $table->unsignedBigInteger('connector_id');
                $table->string('provider', 20);
                $table->string('idempotency_key', 64);
                $table->string('delivery_ref', 191)->nullable();
                $table->string('via', 12)->default('webhook');
                $table->string('status', 12)->default('received');
                $table->unsignedSmallInteger('attempts')->default(0);
                $table->unsignedInteger('records_total')->default(0);
                $table->unsignedInteger('records_accepted')->default(0);
                $table->unsignedInteger('records_updated')->default(0);
                $table->unsignedInteger('records_duplicate')->default(0);
                $table->unsignedInteger('records_rejected')->default(0);
                $table->unsignedBigInteger('scan_log_id')->nullable();
                $table->longText('payload');
                $table->longText('results')->nullable();
                $table->string('error_message', 500)->nullable();
                $table->dateTime('received_at');
                $table->dateTime('imported_at')->nullable();
                $table->timestamps();
                $table->unique(['sub_institute_id', 'connector_id', 'idempotency_key'], 'g2g_aid_unique');
                $table->index(['sub_institute_id', 'connector_id', 'status'], 'g2g_aid_status');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('g2g_ai_deliveries');
    }
};
