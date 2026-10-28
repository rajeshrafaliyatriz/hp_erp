<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 CRM Automation: workflow automation rules.
 * Defines trigger → conditions → actions for deterministic CRM automation
 * (e.g. auto-assign leads, send notifications on stage change).
 *
 * RUN ON BOTH DATABASES:
 *   php artisan migrate --path=database/migrations/2026_12_03_120100_create_crm_automation_rules_table.php
 *   php artisan migrate --database=live --path=database/migrations/2026_12_03_120100_create_crm_automation_rules_table.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if ($this->tableExists('crm_automation_rules')) {
            return;
        }

        Schema::create('crm_automation_rules', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 191);
            $table->text('description')->nullable();
            $table->string('trigger_module', 30); // lead|contact|opportunity|signal|campaign
            $table->string('trigger_event', 50);  // created|updated|status_changed|score_changed|signal_received
            $table->json('trigger_conditions')->nullable(); // [{field, operator, value}]
            $table->json('actions');               // [{action_type, params}]
            $table->tinyInteger('is_active')->default(1);
            $table->unsignedInteger('run_count')->default(0);
            $table->timestamp('last_run_at')->nullable();
            $table->unsignedBigInteger('sub_institute_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->index('sub_institute_id');
            $table->index('trigger_module');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_automation_rules');
    }

    private function tableExists(string $table): bool
    {
        if (\Illuminate\Support\Facades\DB::getDriverName() === 'sqlite') {
            return Schema::hasTable($table);
        }

        return \Illuminate\Support\Facades\DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        )->c > 0;
    }
};
