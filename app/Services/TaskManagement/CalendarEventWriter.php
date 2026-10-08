<?php

namespace App\Services\TaskManagement;

use Illuminate\Support\Facades\DB;

/**
 * THE ONE WRITE PATH FOR `task_management_calendar_events`.
 *
 * Owns one invariant: an event's start/end stay internally consistent
 * (end_at >= start_at, unless all_day) on every write, create or update
 * alike, so no caller can save a half-valid event by forgetting the check.
 *
 * Reminder recomputation (Phase 3) hangs off this class too — the write
 * path that changes an event's timing is the one place that can know a
 * reminder's fire_at needs to move.
 */
class CalendarEventWriter
{
    public function __construct(private readonly CalendarReminderService $reminders)
    {
    }

    /**
     * @param  array{title:string, description:?string, location:?string,
     *                start_at:string, end_at:string, all_day:bool,
     *                status?:string, visibility?:string, owner_id:int,
     *                linked_type?:?string, linked_id?:?int}  $attributes
     * @return array{ok:bool, reason:?string, id:?int}
     */
    public function create(array $attributes, int $subInstituteId, string $syear, int $actorId): array
    {
        $validity = $this->checkValidity($attributes);
        if (!$validity['ok']) {
            return $validity;
        }

        $id = DB::table('task_management_calendar_events')->insertGetId([
            'sub_institute_id' => $subInstituteId,
            'syear' => $syear,
            'title' => $attributes['title'],
            'description' => $attributes['description'] ?? null,
            'location' => $attributes['location'] ?? null,
            'start_at' => $attributes['start_at'],
            'end_at' => $attributes['end_at'],
            'all_day' => $attributes['all_day'] ?? false,
            'status' => $attributes['status'] ?? 'Planned',
            'visibility' => $attributes['visibility'] ?? 'PUBLIC',
            'owner_id' => $attributes['owner_id'],
            'linked_type' => $attributes['linked_type'] ?? null,
            'linked_id' => $attributes['linked_id'] ?? null,
            'created_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['ok' => true, 'reason' => null, 'id' => $id];
    }

    /**
     * @param  array{title?:string, description?:?string, location?:?string,
     *                start_at?:string, end_at?:string, all_day?:bool,
     *                status?:string, visibility?:string}  $attributes
     * @return array{ok:bool, reason:?string}
     */
    public function update(int $id, array $attributes, int $subInstituteId, string $syear, int $actorId): array
    {
        $event = DB::table('task_management_calendar_events')
            ->where('id', $id)
            ->where('sub_institute_id', $subInstituteId)
            ->where('syear', $syear)
            ->whereNull('deleted_at')
            ->first();

        if (!$event) {
            return ['ok' => false, 'reason' => 'Event not found.'];
        }

        $merged = [
            'start_at' => $attributes['start_at'] ?? $event->start_at,
            'end_at' => $attributes['end_at'] ?? $event->end_at,
            'all_day' => array_key_exists('all_day', $attributes) ? $attributes['all_day'] : (bool) $event->all_day,
        ];
        $validity = $this->checkValidity($merged);
        if (!$validity['ok']) {
            return $validity;
        }

        $update = array_intersect_key($attributes, array_flip([
            'title', 'description', 'location', 'start_at', 'end_at', 'all_day', 'status', 'visibility',
            'linked_type', 'linked_id',
        ]));
        $update['updated_by'] = $actorId;
        $update['updated_at'] = now();

        DB::table('task_management_calendar_events')->where('id', $id)->update($update);

        if (isset($attributes['start_at'])) {
            $this->reminders->recomputeForEntry('EVENT', $id, $attributes['start_at']);
        }

        return ['ok' => true, 'reason' => null];
    }

    public function reschedule(int $id, string $startAt, string $endAt, int $subInstituteId, string $syear, int $actorId): array
    {
        return $this->update($id, ['start_at' => $startAt, 'end_at' => $endAt], $subInstituteId, $syear, $actorId);
    }

    /** @param array{start_at:string, end_at:string, all_day:bool} $attributes */
    private function checkValidity(array $attributes): array
    {
        if (!empty($attributes['all_day'])) {
            return ['ok' => true, 'reason' => null];
        }

        if (strtotime($attributes['end_at']) < strtotime($attributes['start_at'])) {
            return ['ok' => false, 'reason' => 'An event cannot end before it starts.'];
        }

        return ['ok' => true, 'reason' => null];
    }
}
