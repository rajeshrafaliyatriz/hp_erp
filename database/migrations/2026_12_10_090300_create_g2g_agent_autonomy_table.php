<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * How much an agent may do without asking, per organisation and action (slice 7, thin, of the agentic CRM, Docs/agentic-crm/slices/S07-agent-gateway-thin.md).
 *
 * The DEFAULTS live in config/agent_actions.php; a row here overrides one for one organisation. Levels: off | suggest | draft | approval | alone.
 * A row with action_key '*' and level 'off' is the kill switch: the agent does nothing for that organisation. A row for organisation 0 (the shared
 * value for "everybody") switches it off everywhere. Unique per (organisation, action), so a setting has one value.
 *
 * ADDITIVE and idempotent. No foreign keys. utf8mb4 stated in the raw DDL.
 *
 * RUN ON DEV, ONE FILE AT A TIME (never an unscoped migrate):
 *   php artisan migrate --path=database/migrations/2026_12_10_090300_create_g2g_agent_autonomy_table.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            if (empty(DB::select("SHOW TABLES LIKE 'g2g_agent_autonomy'"))) {
                DB::statement("CREATE TABLE `g2g_agent_autonomy` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `sub_institute_id` BIGINT UNSIGNED NOT NULL,
                    `action_key` VARCHAR(60) NOT NULL,
                    `level` VARCHAR(12) NOT NULL,
                    `updated_by` BIGINT UNSIGNED NULL,
                    `updated_at` TIMESTAMP NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `g2g_aa_tenant_action` (`sub_institute_id`, `action_key`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            return;
        }

        if (! Schema::hasTable('g2g_agent_autonomy')) {
            Schema::create('g2g_agent_autonomy', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sub_institute_id');
                $table->string('action_key', 60);
                $table->string('level', 12);
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->unique(['sub_institute_id', 'action_key'], 'g2g_aa_tenant_action');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('g2g_agent_autonomy');
    }
};
