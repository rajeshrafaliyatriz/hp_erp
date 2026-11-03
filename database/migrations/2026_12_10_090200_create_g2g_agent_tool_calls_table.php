<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The audit of what the gateway did during a run (slice 7, thin, of the agentic CRM, Docs/agentic-crm/slices/S07-agent-gateway-thin.md): every
 * deterministic read it made to build the context and every action the model asked for, with the verdict. Append only.
 *
 *   seq          the order within the run
 *   tool         read_context | add_note | add_task | confirm_link, or the unknown name a model asked for (kept, so a refusal is visible)
 *   args         the arguments as JSON text, cut to a length cap; args_hash is the sha1 of the whole of them
 *   outcome      read | applied | proposed | denied | error
 *   deny_reason  why it was refused (unknown_tool, unknown_evidence_ref, target_outside_subject, bad_due_date, duplicate, policy_off, ...)
 *   proposal_id  the proposal row the call created, when it created one
 *   result_ref   what an applied action wrote, as table:id
 *
 * ADDITIVE and idempotent. No foreign keys. utf8mb4 stated in the raw DDL. created_at is the table's only timestamp and has an explicit NULL default.
 *
 * RUN ON DEV, ONE FILE AT A TIME (never an unscoped migrate):
 *   php artisan migrate --path=database/migrations/2026_12_10_090200_create_g2g_agent_tool_calls_table.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            if (empty(DB::select("SHOW TABLES LIKE 'g2g_agent_tool_calls'"))) {
                DB::statement("CREATE TABLE `g2g_agent_tool_calls` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `sub_institute_id` BIGINT UNSIGNED NOT NULL,
                    `run_id` BIGINT UNSIGNED NOT NULL,
                    `seq` SMALLINT UNSIGNED NOT NULL,
                    `tool` VARCHAR(60) NOT NULL,
                    `args_hash` CHAR(40) NOT NULL,
                    `args` LONGTEXT NULL,
                    `outcome` VARCHAR(12) NOT NULL,
                    `deny_reason` VARCHAR(60) NULL,
                    `proposal_id` BIGINT UNSIGNED NULL,
                    `result_ref` VARCHAR(80) NULL,
                    `latency_ms` INT UNSIGNED NULL,
                    `created_at` TIMESTAMP NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `g2g_atc_tenant_run_seq` (`sub_institute_id`, `run_id`, `seq`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            return;
        }

        if (! Schema::hasTable('g2g_agent_tool_calls')) {
            Schema::create('g2g_agent_tool_calls', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sub_institute_id');
                $table->unsignedBigInteger('run_id');
                $table->unsignedSmallInteger('seq');
                $table->string('tool', 60);
                $table->char('args_hash', 40);
                $table->longText('args')->nullable();
                $table->string('outcome', 12);
                $table->string('deny_reason', 60)->nullable();
                $table->unsignedBigInteger('proposal_id')->nullable();
                $table->string('result_ref', 80)->nullable();
                $table->unsignedInteger('latency_ms')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->index(['sub_institute_id', 'run_id', 'seq'], 'g2g_atc_tenant_run_seq');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('g2g_agent_tool_calls');
    }
};
