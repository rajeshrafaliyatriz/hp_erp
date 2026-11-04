<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * One row per Ask conversation (slice 8 of the agentic CRM: the chat, Docs/agentic-crm/slices/S08-ask.md).
 *
 * A conversation belongs to ONE person of ONE organisation: `sub_institute_id` is the organisation, `user_id` the person. Every read of a conversation
 * or of its messages filters by both, and another person's conversation, in the same organisation or in another, answers exactly like one that was
 * never there. Nothing here is shared.
 *
 *   title         the first question, cut (what the conversation list shows)
 *   context_type  the record the person was looking at when they started: lead | contact | organization | opportunity (lowercase singular), or NULL
 *   context_id    that record's id (a loose pointer: no foreign key)
 *   archived_at   set when a conversation is put away; NULL for a live one
 *
 * ADDITIVE and idempotent. No foreign keys (loose pointers). Tenant column BIGINT UNSIGNED NOT NULL. The raw DDL states utf8mb4 explicitly because the
 * dev database's own default charset is latin1. Every timestamp has an explicit NULL default so MariaDB adds no ON UPDATE clause to the first of them
 * (DB-RULES.md). The index key is three short columns, well inside the 767-byte limit of the Compact row format on the live host (MariaDB 10.1).
 *
 * RUN ON DEV, ONE FILE AT A TIME (never an unscoped migrate); live only after the owner's go, after a scratch-table probe (DB-RULES.md):
 *   php artisan migrate --path=database/migrations/2026_12_11_120000_create_g2g_agent_conversations_table.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            if (empty(DB::select("SHOW TABLES LIKE 'g2g_agent_conversations'"))) {
                DB::statement("CREATE TABLE `g2g_agent_conversations` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `sub_institute_id` BIGINT UNSIGNED NOT NULL,
                    `user_id` BIGINT UNSIGNED NOT NULL,
                    `title` VARCHAR(150) NOT NULL DEFAULT '',
                    `context_type` VARCHAR(20) NULL,
                    `context_id` BIGINT UNSIGNED NULL,
                    `created_at` TIMESTAMP NULL DEFAULT NULL,
                    `updated_at` TIMESTAMP NULL DEFAULT NULL,
                    `archived_at` TIMESTAMP NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `g2g_ac_tenant_user_updated` (`sub_institute_id`, `user_id`, `updated_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            return;
        }

        if (! Schema::hasTable('g2g_agent_conversations')) {
            Schema::create('g2g_agent_conversations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sub_institute_id');
                $table->unsignedBigInteger('user_id');
                $table->string('title', 150)->default('');
                $table->string('context_type', 20)->nullable();
                $table->unsignedBigInteger('context_id')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->timestamp('archived_at')->nullable();
                $table->index(['sub_institute_id', 'user_id', 'updated_at'], 'g2g_ac_tenant_user_updated');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('g2g_agent_conversations');
    }
};
