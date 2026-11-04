<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The facts ledger (slice 4 of the agentic CRM, Docs/agentic-crm/slices/S04-contact-facts-and-consent.md): one row per VALUE that is known or suspected
 * about a contact, an organisation or a lead, with the score and band its evidence earned and what a person decided about it. The evidence itself is in
 * g2g_entity_fact_evidence (the next migration).
 *
 *   entity_type   contact | organization | lead (lower case, singular: the convention of the new tables). entity_id is a loose pointer to crm_contacts,
 *                 crm_organizations or crm_leads (no foreign key)
 *   field         email, phone, website, linkedin_url, title, ... (config/facts.php)
 *   value         the value as it is stored and shown: TEXT, whatever source it came from; value_hash is the sha1 of its canonical form
 *   value_kind    personal | role for an email address, NULL otherwise
 *   status        proposed (waiting for a person) | applied (a person accepted it) | dismissed | superseded (replaced by a newer accepted value of a one-value field)
 *   band, score   verified | probable | possible and 0.000 to 1.000, from the evidence (App\Domain\Facts\EvidenceScorer)
 *   method        how it was found (a short key); proposed_by who or what proposed it (user:12, agent:organisation, import)
 *   decided_*     who accepted or dismissed it, when, and the reason a person gave for dismissing it (decision_note)
 *   column_written  1 when accepting it wrote the value into the record's own column (only ever when that column was BLANK); 0 when it stays in the ledger only
 *   supersedes_id the applied fact this one replaced
 *
 * "A DISMISSED VALUE IS NEVER OFFERED AGAIN" is this table's unique key, not a rule somebody has to remember: a value exists ONCE per record and field
 * whatever its status (the same value written another way has the same hash), so a dismissed row keeps the key and a second proposal of it cannot be stored.
 * The tenant is part of every key.
 *
 * ADDITIVE and idempotent. MariaDB 10.1 on the live host: no JSON column, no foreign keys, utf8mb4 stated in the raw DDL, timestamps with an explicit NULL
 * default (no ON UPDATE clause), the widest key is far below the 767-byte-per-column limit of the Compact row format. The branch for other drivers (the
 * sqlite test database) builds the same columns and keys, and a test compares the two.
 *
 * RUN ON DEV, ONE FILE AT A TIME (never an unscoped migrate). Live only after the owner's go (docs/agentic-crm/HANDOVER-4NOV.md):
 *   php artisan migrate --path=database/migrations/2026_12_11_090000_create_g2g_entity_facts_table.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            if (empty(DB::select("SHOW TABLES LIKE 'g2g_entity_facts'"))) {
                DB::statement("CREATE TABLE `g2g_entity_facts` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `sub_institute_id` BIGINT UNSIGNED NOT NULL,
                    `entity_type` VARCHAR(12) NOT NULL,
                    `entity_id` BIGINT UNSIGNED NOT NULL,
                    `field` VARCHAR(30) NOT NULL,
                    `value` VARCHAR(1000) NOT NULL,
                    `value_hash` CHAR(40) NOT NULL,
                    `value_kind` VARCHAR(12) NULL,
                    `status` VARCHAR(12) NOT NULL DEFAULT 'proposed',
                    `band` VARCHAR(10) NOT NULL,
                    `score` DECIMAL(4,3) NOT NULL,
                    `method` VARCHAR(30) NOT NULL,
                    `source_url` VARCHAR(1000) NULL,
                    `proposed_by` VARCHAR(40) NOT NULL,
                    `decided_by` BIGINT UNSIGNED NULL,
                    `decided_at` DATETIME NULL,
                    `decision_note` VARCHAR(500) NULL,
                    `column_written` TINYINT(1) NOT NULL DEFAULT 0,
                    `supersedes_id` BIGINT UNSIGNED NULL,
                    `created_at` TIMESTAMP NULL DEFAULT NULL,
                    `updated_at` TIMESTAMP NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `g2g_ef_value_once` (`sub_institute_id`, `entity_type`, `entity_id`, `field`, `value_hash`),
                    KEY `g2g_ef_queue` (`sub_institute_id`, `status`, `created_at`),
                    KEY `g2g_ef_entity` (`sub_institute_id`, `entity_type`, `entity_id`, `status`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            return;
        }

        if (! Schema::hasTable('g2g_entity_facts')) {
            Schema::create('g2g_entity_facts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sub_institute_id');
                $table->string('entity_type', 12);
                $table->unsignedBigInteger('entity_id');
                $table->string('field', 30);
                $table->string('value', 1000);
                $table->char('value_hash', 40);
                $table->string('value_kind', 12)->nullable();
                $table->string('status', 12)->default('proposed');
                $table->string('band', 10);
                $table->decimal('score', 4, 3);
                $table->string('method', 30);
                $table->string('source_url', 1000)->nullable();
                $table->string('proposed_by', 40);
                $table->unsignedBigInteger('decided_by')->nullable();
                $table->dateTime('decided_at')->nullable();
                $table->string('decision_note', 500)->nullable();
                $table->boolean('column_written')->default(false);
                $table->unsignedBigInteger('supersedes_id')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->unique(['sub_institute_id', 'entity_type', 'entity_id', 'field', 'value_hash'], 'g2g_ef_value_once');
                $table->index(['sub_institute_id', 'status', 'created_at'], 'g2g_ef_queue');
                $table->index(['sub_institute_id', 'entity_type', 'entity_id', 'status'], 'g2g_ef_entity');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('g2g_entity_facts');
    }
};
