<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reminders for tasks and calendar events.
 *
 * Two tables, deliberately — the same split CRM uses and for the same
 * reason: `task_management_calendar_reminders` is the CONFIGURED value (how
 * many minutes before the entry's date/time this user wants to be told);
 * `task_management_calendar_reminder_deliveries` is a separately computed
 * ROW PER FIRING, because `fire_at` has to be recomputed whenever the
 * entry's own date/time changes and a reschedule must not silently carry a
 * stale delivery time forward.
 *
 * `fire_at` is stored, not derived at query time, so the firing command's
 * hot path is a cheap `WHERE fire_at <= now() AND status = 'pending'`
 * rather than a join-and-subtract against `task`/`task_management_calendar_
 * events` on every tick.
 *
 * EXPLICIT DEVIATION FROM CRM: CRM's popup reminder is marked fired as a
 * side effect of merely fetching it — a GET with a write side effect, which
 * breaks under polling/prefetching and contradicts the house convention
 * this very module already established (NotificationController::index is
 * read-only; marking read is a separate, explicit PATCH). This table's
 * status only ever changes through an explicit write - see
 * CalendarReminderService.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('task_management_calendar_reminders')) {
            Schema::create('task_management_calendar_reminders', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('sub_institute_id');
                $table->string('syear', 50);
                $table->string('entry_type', 10); // TASK | EVENT
                $table->unsignedBigInteger('entry_id');
                // Whose reminder this is - usually the assignee/owner, but one
                // entry can carry several reminders for several people (an
                // event's attendees, in Phase 5), so this is its own column
                // rather than always being "the entry's owner".
                $table->unsignedBigInteger('user_id');
                $table->unsignedInteger('minutes_before');
                $table->string('channel', 16)->default('inapp');
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['entry_type', 'entry_id', 'user_id'], 'tm_cal_rem_unique');
                $table->index(['sub_institute_id', 'syear'], 'tm_cal_rem_tenant_idx');
            });
        }

        if (!Schema::hasTable('task_management_calendar_reminder_deliveries')) {
            Schema::create('task_management_calendar_reminder_deliveries', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('reminder_id');
                $table->dateTime('fire_at');
                $table->string('status', 10)->default('pending'); // pending | fired | seen | snoozed
                $table->dateTime('fired_at')->nullable();
                $table->dateTime('seen_at')->nullable();
                $table->dateTime('snoozed_until')->nullable();
                // The notification row this delivery produced, once fired -
                // lets "seen" also mark the inbox entry read, without a
                // second lookup to find which one it was.
                $table->unsignedBigInteger('notification_id')->nullable();
                $table->timestamp('created_at');

                $table->index(['status', 'fire_at'], 'tm_cal_remdel_due_idx');
                $table->foreign('reminder_id', 'tm_cal_remdel_rem_fk')
                    ->references('id')->on('task_management_calendar_reminders')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('task_management_calendar_reminder_deliveries');
        Schema::dropIfExists('task_management_calendar_reminders');
    }
};
