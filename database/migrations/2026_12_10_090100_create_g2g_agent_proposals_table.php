<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * What an agent wants done, and what became of it (slice 7, thin, of the agentic CRM, Docs/agentic-crm/slices/S07-agent-gateway-thin.md).
 *
 * ONE ROW PER ACTION, whether a person decides it or the autonomy setting lets the agent do it alone: an action applied alone is stored here too,
 * with status 'applied' and applied_via 'policy', so "what did the agent do" is one query.
 *
 *   action        add_note | add_task | confirm_link   (the whitelist in config/agent_actions.php; there is no other tool)
 *   entity_*      the record the action is applied to (the CRM organisation for a note or a task; NULL for confirm_link)
 *   payload       the normalised arguments, as JSON text (the live host has no JSON type): plain text only
 *   payload_hash  sha1 of the action's identity (its normalised arguments)
 *   evidence      the records the proposal cites, as JSON text; every one of them was in the context the model was sent
 *   status        pending | applied | dismissed | failed
 *   applied_via   policy (alone) | user (accepted by a person)
 *   decided_*     who accepted or dismissed it and when; decision_note is the optional reason given when dismissing
 *
 * NEVER OFFERED TWICE: the unique key (tenant, subject, action, payload_hash) is the whole mechanism. A proposal a person dismissed, one that is
 * waiting, one that was applied and one that failed all hold their key, so the same proposal cannot be created again. The tenant is part of the key.
 *
 * ADDITIVE and idempotent. No foreign keys. utf8mb4 stated in the raw DDL. Timestamps have an explicit NULL default (no ON UPDATE clause on the first).
 *
 * RUN ON DEV, ONE FILE AT A TIME (never an unscoped migrate):
 *   php artisan migrate --path=database/migrations/2026_12_10_090100_create_g2g_agent_proposals_table.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            if (empty(DB::select("SHOW TABLES LIKE 'g2g_agent_proposals'"))) {
                DB::statement("CREATE TABLE `g2g_agent_proposals` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `sub_institute_id` BIGINT UNSIGNED NOT NULL,
                    `run_id` BIGINT UNSIGNED NOT NULL,
                    `subject_type` VARCHAR(20) NOT NULL,
                    `subject_id` BIGINT UNSIGNED NOT NULL,
                    `action` VARCHAR(40) NOT NULL,
                    `entity_type` VARCHAR(20) NULL,
                    `entity_id` BIGINT UNSIGNED NULL,
                    `payload` LONGTEXT NOT NULL,
                    `payload_hash` CHAR(40) NOT NULL,
                    `evidence` LONGTEXT NULL,
                    `reason` VARCHAR(500) NULL,
                    `status` VARCHAR(12) NOT NULL DEFAULT 'pending',
                    `applied_via` VARCHAR(12) NULL,
                    `decided_by` BIGINT UNSIGNED NULL,
                    `decided_at` DATETIME NULL,
                    `decision_note` VARCHAR(500) NULL,
                    `applied_at` DATETIME NULL,
                    `result_ref` VARCHAR(80) NULL,
                    `error` VARCHAR(500) NULL,
                    `created_at` TIMESTAMP NULL DEFAULT NULL,
                    `updated_at` TIMESTAMP NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `g2g_ap_never_reoffer` (`sub_institute_id`, `subject_type`, `subject_id`, `action`, `payload_hash`),
                    KEY `g2g_ap_tenant_subject_status` (`sub_institute_id`, `subject_type`, `subject_id`, `status`),
                    KEY `g2g_ap_tenant_run` (`sub_institute_id`, `run_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            return;
        }

        if (! Schema::hasTable('g2g_agent_proposals')) {
            Schema::create('g2g_agent_proposals', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sub_institute_id');
                $table->unsignedBigInteger('run_id');
                $table->string('subject_type', 20);
                $table->unsignedBigInteger('subject_id');
                $table->string('action', 40);
                $table->string('entity_type', 20)->nullable();
                $table->unsignedBigInteger('entity_id')->nullable();
                $table->longText('payload');
                $table->char('payload_hash', 40);
                $table->longText('evidence')->nullable();
                $table->string('reason', 500)->nullable();
                $table->string('status', 12)->default('pending');
                $table->string('applied_via', 12)->nullable();
                $table->unsignedBigInteger('decided_by')->nullable();
                $table->dateTime('decided_at')->nullable();
                $table->string('decision_note', 500)->nullable();
                $table->dateTime('applied_at')->nullable();
                $table->string('result_ref', 80)->nullable();
                $table->string('error', 500)->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->unique(['sub_institute_id', 'subject_type', 'subject_id', 'action', 'payload_hash'], 'g2g_ap_never_reoffer');
                $table->index(['sub_institute_id', 'subject_type', 'subject_id', 'status'], 'g2g_ap_tenant_subject_status');
                $table->index(['sub_institute_id', 'run_id'], 'g2g_ap_tenant_run');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('g2g_agent_proposals');
    }
};
