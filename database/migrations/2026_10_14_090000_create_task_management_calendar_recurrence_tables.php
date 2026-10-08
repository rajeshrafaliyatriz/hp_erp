<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recurrence for tasks and calendar events — eager materialization, matching
 * CRM's own strategy: every occurrence is a real, physical row (a `task` or
 * `task_management_calendar_events` row), not computed virtually from a rule
 * at read time. That keeps every existing query (the calendar feed, My
 * Tasks, the workspace list) working unchanged — an occurrence is simply
 * another row they already know how to show.
 *
 * `task_management_calendar_recurrences` is the SERIES DEFINITION — one row
 * per rule, anchored on the first task/event it was set up on.
 * `task_management_calendar_occurrences` is the MATERIALIZED LIST — one row
 * per physical occurrence (including the anchor itself), so "which rows
 * belong to this series, in order" is a plain indexed lookup rather than a
 * date computation repeated on every read.
 *
 * `task_management_recurrences` (the older, single-table, zero-consumer
 * version — see TaskRecurrenceController's own history) is deliberately left
 * alone here: additive-over-destructive, matching this codebase's established
 * preference (e.g. `task.repeat_days`, kept untouched when the planning
 * columns were added, "because the old screens still read it").
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('task_management_calendar_recurrences')) {
            Schema::create('task_management_calendar_recurrences', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('sub_institute_id');
                $table->string('syear', 50);
                $table->string('entry_type', 10); // TASK | EVENT
                // The first task/event this rule was set up on. Not a foreign
                // key — the entity lives in one of two different tables
                // depending on entry_type, same reason task_management_milestones
                // carries none.
                $table->unsignedBigInteger('entry_id');
                $table->string('frequency', 20); // daily | weekly | monthly
                $table->unsignedSmallInteger('interval_count')->default(1);
                $table->date('until')->nullable();
                // The last date occurrences have been materialized through —
                // what the daily top-up command advances, so it only ever
                // generates forward from here rather than rescanning the
                // whole series.
                $table->date('materialized_through')->nullable();
                $table->unsignedBigInteger('created_by');
                $table->timestamps();

                $table->unique(['entry_type', 'entry_id'], 'tm_cal_rec_entry_unique');
                $table->index(['sub_institute_id', 'syear'], 'tm_cal_rec_tenant_idx');
            });
        }

        if (!Schema::hasTable('task_management_calendar_occurrences')) {
            Schema::create('task_management_calendar_occurrences', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('recurrence_id');
                $table->string('entry_type', 10);
                // The materialized row itself — the anchor's own id for the
                // first occurrence, a freshly-inserted task/event id for
                // every one after it.
                $table->unsignedBigInteger('entry_id');
                $table->date('occurrence_date');
                $table->unsignedInteger('sequence_no');
                // Set when a user has hand-edited THIS occurrence on its own
                // (scope "this occurrence") — a later series-wide rule change
                // must not silently overwrite what they deliberately changed.
                $table->boolean('is_exception')->default(false);
                $table->timestamps();

                $table->unique(['entry_type', 'entry_id'], 'tm_cal_occ_entry_unique');
                $table->index(['recurrence_id', 'sequence_no'], 'tm_cal_occ_rec_seq_idx');
                $table->foreign('recurrence_id', 'tm_cal_occ_rec_fk')
                    ->references('id')->on('task_management_calendar_recurrences')->cascadeOnDelete();
            });
        }

        // Additive, nullable: lets a task/event read reveal which series (if
        // any) it belongs to without joining the occurrences table on every
        // calendar/workspace read.
        if (!Schema::hasColumn('task', 'recurrence_id')) {
            Schema::table('task', function (Blueprint $table) {
                $table->unsignedBigInteger('recurrence_id')->nullable()->after('status_label');
            });
        }
        if (!Schema::hasColumn('task_management_calendar_events', 'recurrence_id')) {
            // Already added nullable in the Phase 1 migration — defensive,
            // in case this runs against a database where that one didn't.
            Schema::table('task_management_calendar_events', function (Blueprint $table) {
                $table->unsignedBigInteger('recurrence_id')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('task', 'recurrence_id')) {
            Schema::table('task', function (Blueprint $table) {
                $table->dropColumn('recurrence_id');
            });
        }
        Schema::dropIfExists('task_management_calendar_occurrences');
        Schema::dropIfExists('task_management_calendar_recurrences');
    }
};
