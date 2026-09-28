<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Round 2. "What did this chain look like before?" — unanswerable until now.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHY A SNAPSHOT PER SAVE, NOT A DIFF ENGINE
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `WorkflowController::update()` has always overwritten `steps`/`condition`/`status`
 * in place with no trace of what they were. This table exists so a save leaves a
 * mark: one row per update, holding the FULL chain as it was about to become, so
 * "what did this look like last month" has an answer.
 *
 * A structural diff (steps added/removed/reordered, SLA changed) is a real thing to
 * want and is computed from these snapshots ON READ, by comparing two rows — it is
 * not stored, because a stored diff between rows A and B is invalidated the moment
 * either snapshot's shape changes, and a diff engine correct enough to trust is a
 * larger thing than this feature needs. See `WorkflowController::history()`.
 *
 * ── WHY THIS IS NOT A FOREIGN KEY TO g2g_platform_workflows ─────────────────
 *
 * Same reasoning as `hrms_leave_approval_steps.workflow_id` in Round 1: the chain a
 * version belongs to can be deleted (`WorkflowController::destroy()` is a hard
 * delete) while its history is still worth keeping — an administrator asking "what
 * did we used to require here, before somebody deleted the chain" is exactly the
 * question this table should still be able to answer. A cascading foreign key would
 * delete the answer along with the question.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('g2g_platform_workflow_versions')) {
            return;
        }

        Schema::create('g2g_platform_workflow_versions', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Deliberately not a foreign key — see the class note.
            $table->unsignedBigInteger('workflow_id');

            // Denormalised alongside the chain id so a version row can be found and
            // scoped without a join back to a chain that may no longer exist.
            $table->unsignedBigInteger('sub_institute_id');
            $table->string('flow_key', 191);

            // The full row as it became after this save, JSON text for the same
            // reason g2g_platform_workflows.steps is longText and not a native JSON
            // column — see that table's migration note on MariaDB 10.1.
            $table->longText('snapshot');

            $table->string('changed_by', 191)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['workflow_id', 'created_at'], 'g2g_pwv_workflow_time_idx');
            $table->index(['sub_institute_id', 'flow_key'], 'g2g_pwv_tenant_flow_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('g2g_platform_workflow_versions');
    }
};
