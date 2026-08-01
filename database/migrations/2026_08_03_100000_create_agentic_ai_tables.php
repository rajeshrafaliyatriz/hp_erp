<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agentic AI module storage.
 *
 * The module previously had no server side at all: agents, runs and traces were
 * held by an external HuggingFace Space (pariharajit6348-agenticai.hf.space) and
 * the tool calls by a second one (karan-01-agentic-tools.hf.space), with no
 * authentication and no tenant scoping - any browser could read or delete any
 * organisation's agents. Analytics, Multi-Agent and Reflection had no backing at
 * all and rendered fixture data.
 *
 * These tables bring the module in-house and tenant-scope it like the rest of
 * the platform. Executing an agent against a model provider stays a separate
 * concern: a run row records the request, the outcome and the trace, whoever
 * ends up performing the inference.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agentic_agents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sub_institute_id')->index();

            $table->string('name', 191);
            $table->text('description')->nullable();
            $table->string('module', 191)->nullable();
            $table->string('sub_module', 191)->nullable();
            $table->string('role', 191)->nullable();

            $table->string('model', 100)->default('gpt-4');
            // 0.00 - 2.00; decimal not float so 0.7 round-trips exactly.
            $table->decimal('temperature', 3, 2)->default(0.70);
            $table->unsignedInteger('max_tokens')->default(2000);
            $table->longText('system_prompt')->nullable();

            /** Enabled tool ids, e.g. ["knowledge_base","web_search"]. */
            $table->json('tools')->nullable();

            // draft -> deployed -> paused; archived is the soft retirement.
            $table->string('status', 20)->default('draft');

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['sub_institute_id', 'status'], 'agentic_agents_tenant_status_index');
        });

        Schema::create('agentic_agent_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->unsignedBigInteger('agent_id')->index();

            // pending -> running -> success | error | cancelled
            $table->string('status', 20)->default('pending');
            $table->string('trigger', 30)->default('manual');

            $table->longText('input')->nullable();
            $table->longText('output')->nullable();
            $table->text('error_message')->nullable();

            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedInteger('tokens_used')->nullable();
            // Six decimals: per-run cost is routinely fractions of a cent.
            $table->decimal('cost', 12, 6)->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // The run list and the analytics series both read tenant + time.
            $table->index(['sub_institute_id', 'created_at'], 'agentic_runs_tenant_created_index');
            $table->index(['agent_id', 'status'], 'agentic_runs_agent_status_index');
        });

        /** One row per step of a run - what the Run Log's trace view shows. */
        Schema::create('agentic_run_tasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->unsignedBigInteger('run_id')->index();

            $table->unsignedInteger('sequence')->default(1);
            $table->string('description', 500)->nullable();
            // success | error | running
            $table->string('status', 20)->default('running');
            $table->string('tool', 60)->nullable();
            $table->longText('result')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->timestamps();
        });

        /** Every tool form submission, so a tool call is auditable and repeatable. */
        Schema::create('agentic_tool_invocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->unsignedBigInteger('agent_id')->index();
            $table->unsignedBigInteger('run_id')->nullable()->index();

            // knowledge | email | web_search | sql_exec | visualization | file
            $table->string('tool', 40);
            $table->json('payload')->nullable();
            $table->json('response')->nullable();
            $table->string('status', 20)->default('success');
            $table->text('error_message')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['sub_institute_id', 'tool'], 'agentic_tool_tenant_tool_index');
        });

        /* -------------------- Multi-agent coordination -------------------- */

        Schema::create('agentic_workflows', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sub_institute_id')->index();

            $table->string('name', 191);
            $table->text('description')->nullable();
            // sequential runs steps in order; parallel fans them out at once.
            $table->string('mode', 20)->default('sequential');
            $table->string('status', 20)->default('draft');

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('agentic_workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->unsignedBigInteger('workflow_id')->index();
            $table->unsignedBigInteger('agent_id')->index();

            $table->unsignedInteger('sequence')->default(1);
            $table->string('name', 191)->nullable();
            $table->text('instruction')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('agentic_workflow_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->unsignedBigInteger('workflow_id')->index();

            $table->string('status', 20)->default('running');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();
        });

        Schema::create('agentic_workflow_step_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->unsignedBigInteger('workflow_run_id')->index();
            $table->unsignedBigInteger('workflow_step_id')->nullable();
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->unsignedBigInteger('run_id')->nullable();

            $table->unsignedInteger('sequence')->default(1);
            // idle | processing | completed | error - the states the flow chart draws.
            $table->string('status', 20)->default('idle');
            $table->longText('output')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();
        });

        /** Inter-agent message passing, shown on the Multi-Agent screen. */
        Schema::create('agentic_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->unsignedBigInteger('workflow_run_id')->nullable()->index();
            $table->unsignedBigInteger('from_agent_id')->nullable();
            $table->unsignedBigInteger('to_agent_id')->nullable();
            $table->text('message');
            $table->timestamps();
        });

        /* ------------------------- Reflection ----------------------------- */

        /**
         * A reflection pass. Failure patterns are derived from run errors at
         * read time rather than stored - storing them would go stale the moment
         * another run failed. This records when analysis last ran and what it
         * concluded.
         */
        Schema::create('agentic_reflection_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sub_institute_id')->index();

            $table->unsignedInteger('window_days')->default(7);
            $table->unsignedInteger('runs_analysed')->default(0);
            $table->unsignedInteger('failures_found')->default(0);
            $table->unsignedInteger('patterns_found')->default(0);
            $table->unsignedInteger('optimizations_created')->default(0);
            $table->json('summary')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('agentic_optimizations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->unsignedBigInteger('reflection_run_id')->nullable()->index();

            $table->string('title', 191);
            $table->text('description')->nullable();
            // performance | cost | reliability | accuracy
            $table->string('category', 30)->default('performance');
            $table->string('priority', 20)->default('medium');
            $table->string('estimated_impact', 191)->nullable();
            $table->string('implementation_complexity', 20)->default('medium');
            /** Agent ids this suggestion touches. */
            $table->json('affected_agents')->nullable();

            // open -> applied | dismissed
            $table->string('status', 20)->default('open');
            $table->timestamp('applied_at')->nullable();
            $table->unsignedBigInteger('applied_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['sub_institute_id', 'status'], 'agentic_optimizations_tenant_status_index');
        });
    }

    public function down(): void
    {
        foreach ([
            'agentic_optimizations',
            'agentic_reflection_runs',
            'agentic_messages',
            'agentic_workflow_step_runs',
            'agentic_workflow_runs',
            'agentic_workflow_steps',
            'agentic_workflows',
            'agentic_tool_invocations',
            'agentic_run_tasks',
            'agentic_agent_runs',
            'agentic_agents',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
