<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give hrms_leave_workflow_settings something to control. F-124.
 *
 * The Leave Configuration screen has always offered a two-stage approval chain
 * and a 24-hour escalation, written them to hrms_leave_workflow_settings (3 live
 * rows), read them back on reload - and NOTHING ELSE IN THE PRODUCT LOOKED AT
 * THEM. One approval from anyone holding approve_leave decided the request.
 *
 * It cannot be fixed with a column on hrms_emp_leaves. "Approved" is not one
 * fact once a chain exists - a request can hold the reporting manager's
 * approval and still be waiting on the department head, and both of those
 * decisions, with their author and their timestamp, are part of the record. So:
 * one row per REQUIRED APPROVAL, created when the request is submitted from the
 * chain the tenant configured at that moment.
 *
 * Freezing the chain at submit time is deliberate. If HR turns the department
 * head off tomorrow, requests already in flight keep the chain they entered
 * under; changing the rules must not retroactively approve or strand anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hrms_leave_approval_steps', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('leave_id');

            // Denormalised from the parent so every tenant-scoped read and every
            // escalation sweep can filter without a join. Matches how the rest of
            // this schema carries tenancy on children.
            $table->unsignedBigInteger('sub_institute_id');

            // 1, 2, 3 - the order the approvals must happen in. Step 2 stays
            // 'waiting' until step 1 is approved, so nobody can approve out of turn.
            $table->unsignedTinyInteger('step_order');

            // Which role this step belongs to: reporting_manager, department_head, hr.
            // These are the three switches on the configuration screen, stored as the
            // role_key they map to so RoleKey::satisfies() can answer directly.
            $table->string('approver_role', 40);

            // waiting  - an earlier step has not been decided yet
            // pending  - it is this step's turn
            // approved / rejected - decided
            // skipped  - the request was rejected earlier, or cancelled
            $table->string('status', 20)->default('waiting');

            $table->unsignedBigInteger('approver_id')->nullable();
            $table->string('approver_name', 191)->nullable();
            $table->string('comment', 255)->nullable();
            $table->timestamp('decided_at')->nullable();

            // When this step became 'pending'. The escalation sweep measures age
            // from here, not from the request's created_at - a step that waited an
            // hour because the step before it took three days has not breached.
            $table->timestamp('pending_since')->nullable();

            // Set when the escalation sweep hands this step to escalate_to.
            $table->timestamp('escalated_at')->nullable();
            $table->string('escalated_to', 40)->nullable();

            $table->timestamps();

            $table->foreign('leave_id')
                ->references('id')->on('hrms_emp_leaves')
                ->onDelete('cascade');

            // A request has each step exactly once.
            $table->unique(['leave_id', 'step_order'], 'hrms_leave_approval_steps_leave_step_unique');

            // The approver's queue: "which steps are mine to decide, in this tenant".
            $table->index(['sub_institute_id', 'status', 'approver_role'], 'hrms_leave_approval_steps_queue_index');

            // The escalation sweep: "which pending steps have been waiting too long".
            $table->index(['status', 'pending_since'], 'hrms_leave_approval_steps_escalation_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hrms_leave_approval_steps');
    }
};
