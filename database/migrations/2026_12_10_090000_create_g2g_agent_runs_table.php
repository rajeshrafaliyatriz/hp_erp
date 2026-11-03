<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * One row per run of an agent (slice 7, thin, of the agentic CRM: the Organisation agent, Docs/agentic-crm/slices/S07-agent-gateway-thin.md).
 *
 *   agent_key      which agent ('organisation')
 *   subject_type / subject_id   what the run was about: 'org_account' and the Organisation 360 identity id (g2g_org_accounts.id)
 *   trigger_source who started it: manual (a button), cli (the artisan command)
 *   status         running | completed | unavailable (no usable, priced model) | failed
 *   user_id        the person the agent acted FOR; every write it made carries this id as created_by
 *   cost / cost_currency   what the run's model call cost, in the currency it was priced in (rupees by default). NULL when it is not known
 *                  (a call that used tokens has no price): never 0 for "unknown"
 *   brief / brief_evidence   the short "why now" text, and the records it cites, as JSON text (the live host is MariaDB 10.1.48: no JSON type)
 *   correlation_id ties the run's events and tool calls together
 *
 * ADDITIVE and idempotent. No foreign keys (loose pointers). Tenant column BIGINT UNSIGNED NOT NULL. The raw DDL states utf8mb4 explicitly because the
 * dev database's own default charset is latin1. Timestamps have an explicit NULL default so MariaDB adds no ON UPDATE clause to the first of them.
 *
 * RUN ON DEV, ONE FILE AT A TIME (never an unscoped migrate); live only after the owner's go, after a scratch-table probe (DB-RULES.md):
 *   php artisan migrate --path=database/migrations/2026_12_10_090000_create_g2g_agent_runs_table.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            if (empty(DB::select("SHOW TABLES LIKE 'g2g_agent_runs'"))) {
                DB::statement("CREATE TABLE `g2g_agent_runs` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `sub_institute_id` BIGINT UNSIGNED NOT NULL,
                    `user_id` BIGINT UNSIGNED NULL,
                    `agent_key` VARCHAR(40) NOT NULL,
                    `subject_type` VARCHAR(20) NOT NULL,
                    `subject_id` BIGINT UNSIGNED NOT NULL,
                    `trigger_source` VARCHAR(20) NOT NULL DEFAULT 'manual',
                    `status` VARCHAR(16) NOT NULL,
                    `started_at` DATETIME NULL,
                    `finished_at` DATETIME NULL,
                    `provider` VARCHAR(30) NULL,
                    `model` VARCHAR(80) NULL,
                    `input_tokens` INT UNSIGNED NULL,
                    `output_tokens` INT UNSIGNED NULL,
                    `cost` DECIMAL(12,4) NULL,
                    `cost_currency` CHAR(3) NULL,
                    `tool_calls` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                    `error_code` VARCHAR(40) NULL,
                    `error_message` VARCHAR(500) NULL,
                    `correlation_id` CHAR(36) NULL,
                    `brief` LONGTEXT NULL,
                    `brief_evidence` LONGTEXT NULL,
                    `created_at` TIMESTAMP NULL DEFAULT NULL,
                    `updated_at` TIMESTAMP NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `g2g_ar_tenant_subject` (`sub_institute_id`, `subject_type`, `subject_id`, `started_at`),
                    KEY `g2g_ar_tenant_started` (`sub_institute_id`, `started_at`),
                    KEY `g2g_ar_tenant_status` (`sub_institute_id`, `status`, `started_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            return;
        }

        if (! Schema::hasTable('g2g_agent_runs')) {
            Schema::create('g2g_agent_runs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sub_institute_id');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('agent_key', 40);
                $table->string('subject_type', 20);
                $table->unsignedBigInteger('subject_id');
                $table->string('trigger_source', 20)->default('manual');
                $table->string('status', 16);
                $table->dateTime('started_at')->nullable();
                $table->dateTime('finished_at')->nullable();
                $table->string('provider', 30)->nullable();
                $table->string('model', 80)->nullable();
                $table->unsignedInteger('input_tokens')->nullable();
                $table->unsignedInteger('output_tokens')->nullable();
                $table->decimal('cost', 12, 4)->nullable();
                $table->char('cost_currency', 3)->nullable();
                $table->unsignedSmallInteger('tool_calls')->default(0);
                $table->string('error_code', 40)->nullable();
                $table->string('error_message', 500)->nullable();
                $table->char('correlation_id', 36)->nullable();
                $table->longText('brief')->nullable();
                $table->longText('brief_evidence')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->index(['sub_institute_id', 'subject_type', 'subject_id', 'started_at'], 'g2g_ar_tenant_subject');
                $table->index(['sub_institute_id', 'started_at'], 'g2g_ar_tenant_started');
                $table->index(['sub_institute_id', 'status', 'started_at'], 'g2g_ar_tenant_status');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('g2g_agent_runs');
    }
};
