<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The evidence behind a fact (slice 4 of the agentic CRM, Docs/agentic-crm/slices/S04-contact-facts-and-consent.md): one row per independent source the fact
 * rests on. A person reading a suggestion sees these, in plain English, before accepting it; an agent that proposes a fact must supply at least one.
 *
 *   fact_id       loose pointer to g2g_entity_facts.id (no foreign key)
 *   kind          the kind of source (profile.email-match, crm.thread-reply, web.cited-claim, ...); its WEIGHT is stored with the row as it was when the fact
 *                 was scored, so a later re-calibration of config/facts.php never rewrites what a person was shown
 *   source_url    where it was seen (text; never fetched by this domain)
 *   quote         what was seen, written for a person (a signature line, the text of a page)
 *   retrieved_at  when it was seen
 *
 * The weights themselves live in config/facts.php, not in the database, so they are reviewed and tested as code.
 *
 * ADDITIVE and idempotent, MariaDB 10.1 safe (no JSON, no foreign key, utf8mb4 stated). Branch for other drivers for the sqlite tests, compared with the raw DDL.
 *
 * RUN ON DEV, ONE FILE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_12_11_090100_create_g2g_entity_fact_evidence_table.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            if (empty(DB::select("SHOW TABLES LIKE 'g2g_entity_fact_evidence'"))) {
                DB::statement("CREATE TABLE `g2g_entity_fact_evidence` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `sub_institute_id` BIGINT UNSIGNED NOT NULL,
                    `fact_id` BIGINT UNSIGNED NOT NULL,
                    `kind` VARCHAR(40) NOT NULL,
                    `weight` DECIMAL(3,2) NOT NULL,
                    `source_url` VARCHAR(1000) NULL,
                    `quote` VARCHAR(1000) NULL,
                    `retrieved_at` DATETIME NULL,
                    `created_at` TIMESTAMP NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `g2g_efe_fact` (`sub_institute_id`, `fact_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            return;
        }

        if (! Schema::hasTable('g2g_entity_fact_evidence')) {
            Schema::create('g2g_entity_fact_evidence', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sub_institute_id');
                $table->unsignedBigInteger('fact_id');
                $table->string('kind', 40);
                $table->decimal('weight', 3, 2);
                $table->string('source_url', 1000)->nullable();
                $table->string('quote', 1000)->nullable();
                $table->dateTime('retrieved_at')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->index(['sub_institute_id', 'fact_id'], 'g2g_efe_fact');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('g2g_entity_fact_evidence');
    }
};
