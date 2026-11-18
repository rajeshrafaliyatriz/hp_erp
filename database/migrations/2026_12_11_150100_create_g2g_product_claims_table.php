<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The sentences an organisation has APPROVED about a product (slice 10 of the agentic CRM, docs/agentic-crm/slices/S10-outreach-gate.md).
 *
 * A draft may state a numeric, percentage or superlative claim ("cuts paperwork by 60%", "the only ...") only if that sentence, normalised, is in an approved claim of
 * the product the draft is about. No row here means no such claim passes: the safe default. The outreach gate reads this table; the claims API writes it.
 *
 *   product_master_id   the product or sub-product (g2g_product_master.id; a loose pointer, no foreign key). It may be a SHARED product (tenant 0): the claim row is
 *                       still the organisation's own, because an organisation approves what IT is willing to say.
 *   claim               the sentence as a person wrote it, at most 500 characters.
 *   claim_hash          sha1 of the NORMALISED sentence (lower case, punctuation and spacing folded): the same claim written twice is one row.
 *   source_url          where the claim comes from, optional.
 *   status              proposed | approved | retired. Only approved claims let a draft pass.
 *   approved_by/at      who approved it and when; NULL until then.
 *   created_by          who proposed it.
 *
 * UNIQUE (sub_institute_id, product_master_id, claim_hash): every unique index includes the tenant. NEW TABLE, ADDITIVE, no foreign keys, tenant column
 * BIGINT UNSIGNED NOT NULL, nullable timestamps with explicit values, explicit utf8mb4 on MariaDB, no JSON column (live is MariaDB 10.1).
 *
 * Guarded: a second run does nothing. down() drops the table up() created.
 *
 * RUN ON DEV, ONE FILE AT A TIME (never an unscoped migrate):
 *   php artisan migrate --path=database/migrations/2026_12_11_150100_create_g2g_product_claims_table.php
 */
return new class extends Migration
{
    private const TABLE = 'g2g_product_claims';

    private function isMariaDb(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        if ($this->isMariaDb()) {
            DB::statement('CREATE TABLE `g2g_product_claims` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `sub_institute_id` BIGINT UNSIGNED NOT NULL,
                `product_master_id` BIGINT UNSIGNED NOT NULL,
                `claim` VARCHAR(500) NOT NULL,
                `claim_hash` CHAR(40) NOT NULL,
                `source_url` VARCHAR(1000) NULL,
                `status` VARCHAR(12) NOT NULL DEFAULT \'proposed\',
                `approved_by` BIGINT UNSIGNED NULL,
                `approved_at` TIMESTAMP NULL DEFAULT NULL,
                `created_by` BIGINT UNSIGNED NULL,
                `created_at` TIMESTAMP NULL DEFAULT NULL,
                `updated_at` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `g2g_claims_tenant_product_hash` (`sub_institute_id`, `product_master_id`, `claim_hash`),
                KEY `g2g_claims_tenant_product_status` (`sub_institute_id`, `product_master_id`, `status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

            return;
        }

        Schema::create(self::TABLE, function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id');
            $t->unsignedBigInteger('product_master_id');
            $t->string('claim', 500);
            $t->char('claim_hash', 40);
            $t->string('source_url', 1000)->nullable();
            $t->string('status', 12)->default('proposed');
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
            $t->unique(['sub_institute_id', 'product_master_id', 'claim_hash'], 'g2g_claims_tenant_product_hash');
            $t->index(['sub_institute_id', 'product_master_id', 'status'], 'g2g_claims_tenant_product_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
