<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The messages of an Ask conversation (slice 8 of the agentic CRM: the chat, Docs/agentic-crm/slices/S08-ask.md). Append only.
 *
 *   role       user (the question as typed) | assistant (the answer as shown)
 *   content    the text, as shown to the person
 *   citations  an assistant message's cited records as JSON TEXT (a list of {type, id, label, url}); NULL otherwise. No JSON column: the live host is
 *              MariaDB 10.1.48, which has no JSON type
 *   status     assistant only: answered | no_data | refused | unavailable; NULL for a question
 *   run_id     the g2g_agent_runs row of the question (agent_key 'ask': tokens, cost, tool calls); a loose pointer
 *
 * A message has no owner column of its own: it belongs to its conversation, which belongs to one person (g2g_agent_conversations), and the code only ever
 * reaches a message through the caller's own conversation. `sub_institute_id` is stored on every row anyway so no query on this table can run without it.
 *
 * ADDITIVE and idempotent. No foreign keys. utf8mb4 stated in the raw DDL. The first timestamp has an explicit NULL default (no ON UPDATE clause).
 *
 * RUN ON DEV, ONE FILE AT A TIME (never an unscoped migrate); live only after the owner's go, after a scratch-table probe (DB-RULES.md):
 *   php artisan migrate --path=database/migrations/2026_12_11_120100_create_g2g_agent_messages_table.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            if (empty(DB::select("SHOW TABLES LIKE 'g2g_agent_messages'"))) {
                DB::statement("CREATE TABLE `g2g_agent_messages` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `sub_institute_id` BIGINT UNSIGNED NOT NULL,
                    `conversation_id` BIGINT UNSIGNED NOT NULL,
                    `role` VARCHAR(10) NOT NULL,
                    `content` LONGTEXT NOT NULL,
                    `citations` LONGTEXT NULL,
                    `status` VARCHAR(12) NULL,
                    `run_id` BIGINT UNSIGNED NULL,
                    `created_at` TIMESTAMP NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `g2g_am_conversation` (`conversation_id`, `id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            return;
        }

        if (! Schema::hasTable('g2g_agent_messages')) {
            Schema::create('g2g_agent_messages', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sub_institute_id');
                $table->unsignedBigInteger('conversation_id');
                $table->string('role', 10);
                $table->longText('content');
                $table->longText('citations')->nullable();
                $table->string('status', 12)->nullable();
                $table->unsignedBigInteger('run_id')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->index(['conversation_id', 'id'], 'g2g_am_conversation');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('g2g_agent_messages');
    }
};
