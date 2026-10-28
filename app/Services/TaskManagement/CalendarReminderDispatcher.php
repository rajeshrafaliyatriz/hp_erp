<?php

namespace App\Services\TaskManagement;

use App\Http\Controllers\Api\TaskManagement\NotificationController;
use Illuminate\Support\Facades\DB;

/**
 * FIRES DUE REMINDERS. Invoked only from the scheduled command, never from
 * an HTTP controller - a reminder firing is a clock crossing a threshold,
 * not a response to a request.
 *
 * Writes into the EXISTING `task_management_notifications` inbox
 * (NotificationController::notify) rather than the event-sourced
 * EventRecorder/NotificationDispatcher system: that system's contract is "a
 * state changed, notify a resolvable recipient" - a reminder firing is not a
 * state change, it is a clock crossing a threshold on data that has not
 * changed, and bending an event-sourced system to also handle wall-clock
 * polling is the same category of misuse EventCatalogue's own
 * NOT_SHIPPED/NOT_NOTIFIED entries exist to catch.
 *
 * Idempotent by construction: a delivery only matches the `status='pending'`
 * scan once, and this flips it to 'fired' in the same pass it reads it, so a
 * retry of the whole command cannot double-send what a previous run already
 * fired.
 */
class CalendarReminderDispatcher
{
    public function dispatch(): int
    {
        $due = DB::table('task_management_calendar_reminder_deliveries as d')
            ->join('task_management_calendar_reminders as r', 'r.id', '=', 'd.reminder_id')
            ->where('d.status', 'pending')
            ->where('d.fire_at', '<=', now())
            ->get(['d.id as delivery_id', 'r.entry_type', 'r.entry_id', 'r.user_id', 'r.sub_institute_id']);

        $fired = 0;
        foreach ($due as $row) {
            // Flip FIRST, so a slow notify() call racing a second dispatcher
            // run (withoutOverlapping guards the schedule, not a manual
            // artisan run on a second box) cannot double-fire this row.
            $claimed = DB::table('task_management_calendar_reminder_deliveries')
                ->where('id', $row->delivery_id)->where('status', 'pending')
                ->update(['status' => 'fired', 'fired_at' => now()]);
            if (!$claimed) {
                continue;
            }

            [$title, $body, $taskId] = $this->describe($row->entry_type, $row->entry_id);
            if ($title === null) {
                continue; // the entry was deleted between materialization and firing
            }

            NotificationController::notify(
                (int) $row->sub_institute_id, (int) $row->user_id, 'calendar.reminder', $title, $body, $taskId
            );
            $notificationId = DB::table('task_management_notifications')
                ->where('sub_institute_id', $row->sub_institute_id)->where('user_id', $row->user_id)
                ->where('type', 'calendar.reminder')->orderByDesc('id')->value('id');
            DB::table('task_management_calendar_reminder_deliveries')
                ->where('id', $row->delivery_id)->update(['notification_id' => $notificationId]);

            $fired++;
        }

        return $fired;
    }

    /** @return array{0:?string, 1:?string, 2:?int} [title, body, task_id] */
    private function describe(string $entryType, int $entryId): array
    {
        if ($entryType === 'TASK') {
            $task = DB::table('task')->where('id', $entryId)->whereNull('deleted_at')->first(['task_title', 'task_date']);
            return $task
                ? ["Reminder: {$task->task_title}", "Due {$task->task_date}", $entryId]
                : [null, null, null];
        }

        $event = DB::table('task_management_calendar_events')->where('id', $entryId)->whereNull('deleted_at')
            ->first(['title', 'start_at']);

        return $event
            ? ["Reminder: {$event->title}", "Starts {$event->start_at}", null]
            : [null, null, null];
    }
}
