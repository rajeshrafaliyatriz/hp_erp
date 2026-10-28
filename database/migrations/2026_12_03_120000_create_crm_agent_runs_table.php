<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 CRM Automation: bounded agent execution audit log.
 * Each AI agent run (prospecting, outreach, deal_coach, workflow) is recorded
 * here with its inputs, outputs, status, cost, and approval chain.
 *
 * RUN ON BOTH DATABASES:
 *   php artisan migrate --path=database/migrations/2026_12_03_120000_create_crm_agent_runs_table.php
 *   php artisan migrate --database=live --path=database/migrations/2026_12_03_120000_create_crm_agent_runs_table.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if ($this->tableExists('crm_agent_runs')) {
            return;
        }

        Schema::create('crm_agent_runs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('agent_type', 50); // prospecting|outreach|deal_coach|workflow
            $table->string('agent_name', 191);
            $table->string('trigger_type', 50)->default('manual'); // manual|scheduled|signal|event
            $table->json('trigger_context')->nullable();
            $table->json('input_data')->nullable();
            $table->json('output_data')->nullable();
            $table->string('status', 20)->default('running'); // running|completed|failed|cancelled|awaiting_approval
            $table->text('error_message')->nullable();
            $table->unsignedInteger('tokens_used')->nullable();
            $table->decimal('cost_usd', 10, 6)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('sub_institute_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->index('sub_institute_id');
            $table->index('agent_type');
            $table->index('status');
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_agent_runs');
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
