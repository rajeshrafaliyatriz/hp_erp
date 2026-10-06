<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Action items / milestones inside a development plan.
 *
 * s_competency_development_plans holds only the plan header (title, owner,
 * dates, a hand-entered progress integer). The Development & Career Path
 * Workspace's plan detail panel needs the plan's actual contents: the "Actions"
 * tab and the "Next Milestone" card, and it needs plan progress to be derived
 * from completed actions rather than typed in.
 *
 * No milestone/action/goal table exists in the schema (checked %plan%,
 * %milestone%, %goal%, %action% - only s_competency_activity_log, which is an
 * append-only audit feed, not a work list). Hence this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('s_competency_plan_actions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->unsignedBigInteger('plan_id')->index();
            $table->string('title', 191);
            $table->text('description')->nullable();
            // milestone | training | mentoring | project | reading | other
            $table->string('action_type', 50)->default('milestone')->index();
            // pending | in_progress | completed | blocked
            $table->string('status', 30)->default('pending')->index();
            // Optional competency this action closes the gap on (s_users_skills.id)
            $table->unsignedBigInteger('competency_id')->nullable()->index();
            $table->unsignedBigInteger('owner_id')->nullable();   // tbluser.id
            $table->date('due_date')->nullable()->index();
            $table->dateTime('completed_at')->nullable();
            $table->unsignedInteger('sequence')->default(0);
            $table->unsignedBigInteger('created_by')->index()->nullable();
            $table->unsignedBigInteger('updated_by')->index()->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('s_competency_plan_actions');
    }
};
