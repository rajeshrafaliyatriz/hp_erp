<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The generic frozen-steps table `ApprovalEngine` writes to, for every workflow
 * point enforced from this round onward — attendance regularisation, offer,
 * offboarding clearance, mobility transfer, competency mapping review, task
 * execution approval.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * THIS IS `hrms_leave_approval_steps`'S OWN SHAPE, GENERALIZED — NOT A NEW DESIGN
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Every column here mirrors a column that table already proves in production,
 * confirmed by reading its three migrations directly rather than assumed. The
 * one real difference is `subject_type` + `subject_id` in place of a hardcoded
 * `leave_id` FK — the same polymorphic shape `s_competency_approvals` already
 * uses in this codebase, so six domains share one table instead of needing six
 * near-identical ones. `hrms_leave_approval_steps` and `LeaveApprovalWorkflow`
 * are UNTOUCHED by this — leave keeps its own table, unchanged, and this is
 * additive only.
 *
 * `decision` here is `approved | rejected | sent_back` — leave's own
 * `approved_lwp` ("leave without pay") variant is leave's own concept and has
 * no equivalent in any of the six domains this table serves; nothing here
 * needs it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('g2g_platform_approval_steps', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('sub_institute_id');

            // Which declared workflow point this step's chain was selected
            // against — e.g. 'hrms.attendance.regularisation'. Denormalised onto
            // every row (not just derivable from workflow_id, which can go
            // dangling — see below) so the escalation sweep can group by point
            // without a join through a chain that may no longer exist.
            $table->string('flow_key', 100);

            // What this step belongs to — e.g. 'attendance_regularisation',
            // 'task_submission'. One table, six (and future) domains.
            $table->string('subject_type', 60);
            $table->unsignedBigInteger('subject_id');

            $table->unsignedTinyInteger('step_order');

            $table->string('approver_role', 40);
            $table->unsignedBigInteger('approver_user_id')->nullable();
            $table->string('approver_user_name', 191)->nullable();
            $table->string('step_name', 120)->nullable();

            // waiting | pending | approved | rejected | sent_back | skipped
            $table->string('status', 20)->default('waiting');
            // approved | rejected | sent_back — what actually happened, verbatim.
            $table->string('decision', 20)->nullable();

            $table->unsignedBigInteger('approver_id')->nullable();
            $table->string('approver_name', 191)->nullable();
            $table->string('comment', 255)->nullable();
            $table->string('auto_decided_reason', 191)->nullable();
            $table->timestamp('decided_at')->nullable();

            $table->timestamp('pending_since')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->string('escalated_to', 40)->nullable();
            $table->timestamp('reminded_at')->nullable();

            $table->unsignedSmallInteger('sla_hours')->nullable();
            $table->string('on_breach', 20)->nullable();
            $table->boolean('require_comment')->default(false);

            // 'platform' always, for every row this table will ever hold — kept
            // for shape-parity with the leave table's own 'source' column (which
            // also carries its legacy 'settings' path) rather than because this
            // table has a second source today.
            $table->string('source', 20)->default('platform');

            // The chain this step was frozen from. Deliberately NOT a foreign
            // key — the chain may be deleted (WorkflowController::destroy() is a
            // hard delete) while a request that came from it is still in
            // flight, and that must not cascade. A dangling id here is
            // expected and means "the chain this came from is gone".
            $table->unsignedBigInteger('workflow_id')->nullable();

            $table->timestamps();

            // One request has each step exactly once.
            $table->unique(
                ['subject_type', 'subject_id', 'step_order'],
                'g2g_platform_approval_steps_subject_step_unique'
            );

            // Lookups for one subject's own steps.
            $table->index(['subject_type', 'subject_id'], 'g2g_platform_approval_steps_subject_index');

            // The approver's queue and the escalation sweep, mirroring
            // hrms_leave_approval_steps's own two indexes.
            $table->index(
                ['sub_institute_id', 'flow_key', 'status'],
                'g2g_platform_approval_steps_queue_index'
            );
            $table->index(
                ['status', 'sla_hours', 'pending_since'],
                'g2g_platform_approval_steps_escalation_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('g2g_platform_approval_steps');
    }
};
