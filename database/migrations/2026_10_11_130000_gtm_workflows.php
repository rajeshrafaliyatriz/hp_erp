<?php

use App\Support\GtmMigrationSupport;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * GTM workflow engine: definitions, runs and per-step history.
 *
 *   php artisan migrate --path=database/migrations/2026_10_11_130000_gtm_workflows.php
 *
 * A workflow is TRIGGER (a gtm.* event) -> CONDITIONS (over the event's real facts) -> ACTIONS. It is
 * driven by the platform's own event store: GtmWorkflowRunner is a reactor registered in
 * EventCatalogue and ReactEvents, so there is no second scheduler or event bus.
 *
 * IDEMPOTENCY: UNIQUE (workflow_id, event_id). An event can start a workflow at most once, however many
 * times it is delivered. Steps are keyed UNIQUE (run_id, step) so a retry resumes after the last
 * success instead of repeating work.
 */
return new class extends Migration
{
    use GtmMigrationSupport;

    public function up(): void
    {
        if (! $this->has('gtm_workflows')) {
            Schema::create('gtm_workflows', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('sub_institute_id');
                $t->string('name', 191);
                $t->text('description')->nullable();
                $t->string('trigger_event', 60);
                $t->longText('conditions')->nullable();                  // JSON list of {field, op, value}
                $t->longText('actions');                                 // JSON list of {type, params}
                $t->boolean('requires_approval')->default(false);
                $t->string('status', 10)->default('draft');              // draft | active | paused | archived
                $t->unsignedSmallInteger('max_runs_per_day')->default(100);
                $t->unsignedBigInteger('created_by')->nullable();
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->timestamps();

                $t->index(['sub_institute_id', 'status', 'trigger_event'], 'gtm_workflows_tenant_status_trigger_idx');
            });
        }

        if (! $this->has('gtm_workflow_runs')) {
            Schema::create('gtm_workflow_runs', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('sub_institute_id');
                $t->unsignedBigInteger('workflow_id');
                $t->unsignedBigInteger('event_id');                      // g2g_event.id that started it
                $t->string('event_type', 60);
                $t->string('entity_type', 40)->nullable();
                $t->unsignedBigInteger('entity_id')->nullable();
                // pending_approval | queued | running | retry_wait | succeeded | failed | rejected | skipped | cancelled
                $t->string('status', 16);
                $t->longText('context')->nullable();                     // JSON: the facts the conditions saw
                $t->text('error')->nullable();
                $t->unsignedTinyInteger('attempts')->default(0);
                $t->timestamp('next_attempt_at')->nullable();
                $t->unsignedBigInteger('decided_by')->nullable();
                $t->timestamp('decided_at')->nullable();
                $t->string('decision_note', 500)->nullable();
                $t->timestamp('started_at')->nullable();
                $t->timestamp('finished_at')->nullable();
                $t->timestamps();

                $t->unique(['workflow_id', 'event_id'], 'gtm_workflow_runs_idempotent');
                $t->index(['sub_institute_id', 'status'], 'gtm_workflow_runs_tenant_status_idx');
                $t->index(['status', 'next_attempt_at'], 'gtm_workflow_runs_due_idx');
            });
        }

        if (! $this->has('gtm_workflow_run_steps')) {
            Schema::create('gtm_workflow_run_steps', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('sub_institute_id');
                $t->unsignedBigInteger('run_id');
                $t->unsignedTinyInteger('step');
                $t->string('action', 40);
                $t->string('status', 12);                                // succeeded | failed | skipped
                $t->longText('result')->nullable();                      // JSON
                $t->text('error')->nullable();
                $t->timestamp('started_at')->nullable();
                $t->timestamp('finished_at')->nullable();

                $t->unique(['run_id', 'step'], 'gtm_workflow_run_steps_unique');
            });
        }

        $this->addMenus([['Workflows', '/gtm/workflows']]);
    }

    public function down(): void
    {
        $this->removeMenus(['/gtm/workflows']);
        Schema::dropIfExists('gtm_workflow_run_steps');
        Schema::dropIfExists('gtm_workflow_runs');
        Schema::dropIfExists('gtm_workflows');
    }
};
