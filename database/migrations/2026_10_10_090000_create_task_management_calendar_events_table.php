<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Task Calendar's "Event" (meeting/call) concept — deliberately its own
 * table, not a type column on `task`.
 *
 * `task` already carries the status state machine, the approval-chain
 * trigger, dependency resolution, time entries and subtasks — none of which a
 * meeting should ever pass through. An event's own columns (start_at/end_at
 * as real timestamps, location, all_day) would sit permanently empty on every
 * task row, for every task, forever — the same structural-emptiness smell
 * TaskStatusWriter's docblock calls out for delay_category, except here it
 * would never resolve.
 *
 * What unifies tasks, events, milestones and workstream checkpoints into "one
 * calendar" is CalendarFeedService, a read-only per-request composition —
 * same shape as ProjectProgress/WorkstreamRollup. Each source keeps its own
 * write path, permissions and audit trail.
 *
 * No foreign keys, following every other task_management_* table (the
 * legacy `task` table has none either).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('task_management_calendar_events')) {
            return;
        }

        Schema::create('task_management_calendar_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('sub_institute_id');
            $table->string('syear', 50);
            $table->string('title', 191);
            $table->text('description')->nullable();
            $table->string('location', 191)->nullable();
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->boolean('all_day')->default(false);
            // Planned | Held | Cancelled — CRM's eventstatus, without the
            // taskstatus merge: an event is never a task, so it never needs
            // the four-category task vocabulary.
            $table->string('status', 20)->default('Planned');
            // Column exists from Phase 1 for schema stability but is not
            // enforced until Phase 4 (sharing/visibility) — nothing reads it
            // before then.
            $table->string('visibility', 10)->default('PUBLIC');
            $table->unsignedBigInteger('owner_id');
            // What this event is attached to, if anything — hp_erp's own
            // modules, not CRM's full polymorphism into Leads/Quotes/etc,
            // which don't exist here.
            $table->string('linked_type', 40)->nullable();
            $table->unsignedBigInteger('linked_id')->nullable();
            // Set at materialization time once Phase 2 (recurrence) lands;
            // null for every event until then.
            $table->unsignedBigInteger('recurrence_id')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['sub_institute_id', 'syear', 'start_at'], 'tm_cal_events_tenant_start_idx');
            $table->index(['owner_id', 'start_at'], 'tm_cal_events_owner_start_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_management_calendar_events');
    }
};
