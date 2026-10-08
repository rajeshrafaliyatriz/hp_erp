<?php

namespace App\Services\TaskManagement;

use Illuminate\Support\Facades\DB;

/**
 * THE ONE PLACE `fire_at` AND A REMINDER DELIVERY'S STATE MACHINE ARE OWNED.
 *
 * A reminder (the configured "how many minutes before") and its delivery
 * (the computed "when does that actually fall, and has it fired") are
 * deliberately separate rows - see the migration's own docblock. This class
 * is what keeps them honest: creating/editing a reminder recomputes its
 * delivery's `fire_at` here, and nothing else writes that column.
 *
 * States: pending -> fired (by the dispatcher) -> seen (explicit PATCH) or
 * snoozed -> pending again at a new fire_at. No "fetch marks fired" - see
 * the migration docblock for why that CRM behaviour is rejected here.
 */
class CalendarReminderService
{
    /**
     * @return array{ok:bool, reason:?string, reminder_id:?int}
     */
    public function upsert(string $entryType, int $entryId, int $userId, int $minutesBefore, int $subInstituteId, string $syear): array
    {
        $entryDateTime = $this->entryDateTime($entryType, $entryId, $subInstituteId, $syear);
        if (!$entryDateTime) {
            return ['ok' => false, 'reason' => 'Task or event not found.', 'reminder_id' => null];
        }

        DB::table('task_management_calendar_reminders')->updateOrInsert(
            ['entry_type' => $entryType, 'entry_id' => $entryId, 'user_id' => $userId],
            [
                'sub_institute_id' => $subInstituteId, 'syear' => $syear,
                'minutes_before' => $minutesBefore, 'is_active' => true, 'updated_at' => now(), 'created_at' => now(),
            ]
        );
        $reminderId = DB::table('task_management_calendar_reminders')
            ->where(['entry_type' => $entryType, 'entry_id' => $entryId, 'user_id' => $userId])->value('id');

        $this->recomputeDelivery((int) $reminderId, $entryDateTime, $minutesBefore);

        return ['ok' => true, 'reason' => null, 'reminder_id' => (int) $reminderId];
    }

    public function delete(string $entryType, int $entryId, int $userId): bool
    {
        return (bool) DB::table('task_management_calendar_reminders')
            ->where(['entry_type' => $entryType, 'entry_id' => $entryId, 'user_id' => $userId])->delete();
        // `task_management_calendar_reminder_deliveries` cascades on delete.
    }

    /**
     * Recompute every active reminder's delivery for one entry, because its
     * date/time just changed. Called from whatever write path actually
     * moves a task's due date or an event's start time - a reminder whose
     * entry got rescheduled must fire against the NEW time, not the one it
     * was created against.
     */
    public function recomputeForEntry(string $entryType, int $entryId, string $newDateTime): void
    {
        $reminders = DB::table('task_management_calendar_reminders')
            ->where(['entry_type' => $entryType, 'entry_id' => $entryId, 'is_active' => true])->get();

        foreach ($reminders as $reminder) {
            $this->recomputeDelivery((int) $reminder->id, $newDateTime, (int) $reminder->minutes_before);
        }
    }

    private function recomputeDelivery(int $reminderId, string $entryDateTime, int $minutesBefore): void
    {
        $fireAt = date('Y-m-d H:i:s', strtotime($entryDateTime) - $minutesBefore * 60);

        // One pending delivery per reminder at a time - recomputing replaces
        // whichever hasn't fired yet rather than accumulating stale rows
        // every time a task's due date is nudged.
        $pending = DB::table('task_management_calendar_reminder_deliveries')
            ->where('reminder_id', $reminderId)->where('status', 'pending')->first();

        if ($pending) {
            DB::table('task_management_calendar_reminder_deliveries')->where('id', $pending->id)->update(['fire_at' => $fireAt]);
        } else {
            DB::table('task_management_calendar_reminder_deliveries')->insert([
                'reminder_id' => $reminderId, 'fire_at' => $fireAt, 'status' => 'pending', 'created_at' => now(),
            ]);
        }
    }

    /** Every delivery due now or earlier, for one user - what the frontend polls. */
    public function due(int $userId, int $subInstituteId): \Illuminate\Support\Collection
    {
        return DB::table('task_management_calendar_reminder_deliveries as d')
            ->join('task_management_calendar_reminders as r', 'r.id', '=', 'd.reminder_id')
            ->where('r.user_id', $userId)
            ->where('r.sub_institute_id', $subInstituteId)
            ->where('d.status', 'pending')
            ->where('d.fire_at', '<=', now())
            ->orderBy('d.fire_at')
            ->get(['d.id', 'r.entry_type', 'r.entry_id', 'd.fire_at', 'd.snoozed_until']);
    }

    /** @return array{ok:bool, reason:?string} */
    public function markSeen(int $deliveryId, int $userId, int $subInstituteId): array
    {
        $delivery = $this->ownedDelivery($deliveryId, $userId, $subInstituteId);
        if (!$delivery) {
            return ['ok' => false, 'reason' => 'Reminder not found.'];
        }

        DB::table('task_management_calendar_reminder_deliveries')->where('id', $deliveryId)
            ->update(['status' => 'seen', 'seen_at' => now()]);

        if ($delivery->notification_id) {
            DB::table('task_management_notifications')->where('id', $delivery->notification_id)
                ->whereNull('read_at')->update(['read_at' => now()]);
        }

        return ['ok' => true, 'reason' => null];
    }

    /** @return array{ok:bool, reason:?string} */
    public function snooze(int $deliveryId, int $userId, int $subInstituteId, int $minutes): array
    {
        $delivery = $this->ownedDelivery($deliveryId, $userId, $subInstituteId);
        if (!$delivery) {
            return ['ok' => false, 'reason' => 'Reminder not found.'];
        }

        $fireAt = now()->addMinutes($minutes);
        DB::table('task_management_calendar_reminder_deliveries')->where('id', $deliveryId)->update([
            'status' => 'pending', 'snoozed_until' => $fireAt, 'fire_at' => $fireAt, 'fired_at' => null,
        ]);

        return ['ok' => true, 'reason' => null];
    }

    private function ownedDelivery(int $deliveryId, int $userId, int $subInstituteId): ?object
    {
        return DB::table('task_management_calendar_reminder_deliveries as d')
            ->join('task_management_calendar_reminders as r', 'r.id', '=', 'd.reminder_id')
            ->where('d.id', $deliveryId)->where('r.user_id', $userId)->where('r.sub_institute_id', $subInstituteId)
            ->first(['d.id', 'd.notification_id']);
    }

    private function entryDateTime(string $entryType, int $entryId, int $subInstituteId, string $syear): ?string
    {
        if ($entryType === 'TASK') {
            $task = DB::table('task')->where('id', $entryId)->where('sub_institute_id', $subInstituteId)
                ->where('SYEAR', $syear)->whereNull('deleted_at')->first(['task_date']);

            // A task has no time component - treat its due date as due at
            // end of day, so "remind me 60 minutes before" means 11pm the
            // day before, not midnight of the due date itself.
            return $task?->task_date ? $task->task_date . ' 23:59:59' : null;
        }

        $event = DB::table('task_management_calendar_events')->where('id', $entryId)
            ->where('sub_institute_id', $subInstituteId)->where('syear', $syear)->whereNull('deleted_at')->first(['start_at']);

        return $event?->start_at;
    }
}
