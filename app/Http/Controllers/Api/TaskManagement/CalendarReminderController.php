<?php

namespace App\Http\Controllers\Api\TaskManagement;

use App\Http\Controllers\Api\TaskManagement\Concerns\ResolvesTaskContext;
use App\Http\Controllers\Controller;
use App\Services\TaskManagement\CalendarReminderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Per-entity reminder CRUD mirrors the recurrence endpoints exactly
 * (`/workspace/{id}/reminder`, `/calendar/events/{id}/reminder` - one
 * reminder per user per entry, same shape as `/recurrence`). The polling
 * surface (`/calendar/reminders/due`, `/seen`, `/snooze`) is entity-agnostic,
 * since a user polling for "what's due right now" does not know or care
 * which task/event each delivery belongs to until it tells them.
 *
 * No "fetch marks fired" - see CalendarReminderService's and the migration's
 * docblocks. `seen`/`snooze` are the only writes to a delivery's status.
 */
class CalendarReminderController extends Controller
{
    use ResolvesTaskContext;

    public function __construct(private readonly CalendarReminderService $reminders)
    {
    }

    public function taskShow(Request $request, int $id)
    {
        return $this->show($request, 'TASK', $id);
    }

    public function taskUpsert(Request $request, int $id)
    {
        return $this->upsert($request, 'TASK', $id);
    }

    public function taskDestroy(Request $request, int $id)
    {
        return $this->destroy($request, 'TASK', $id);
    }

    public function eventShow(Request $request, int $id)
    {
        return $this->show($request, 'EVENT', $id);
    }

    public function eventUpsert(Request $request, int $id)
    {
        return $this->upsert($request, 'EVENT', $id);
    }

    public function eventDestroy(Request $request, int $id)
    {
        return $this->destroy($request, 'EVENT', $id);
    }

    private function show(Request $request, string $entryType, int $id)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $row = DB::table('task_management_calendar_reminders')
            ->where(['entry_type' => $entryType, 'entry_id' => $id, 'user_id' => $context['user_id']])->first();

        return $this->ok('Reminder retrieved successfully.', [
            'reminder' => $row ? ['minutes_before' => (int) $row->minutes_before] : null,
        ]);
    }

    private function upsert(Request $request, string $entryType, int $id)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            'minutes_before' => 'required|integer|min:0|max:10080', // up to a week
        ]);
        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422, $validator->errors()->toArray());
        }

        $result = $this->reminders->upsert(
            $entryType, $id, (int) $context['user_id'], (int) $request->input('minutes_before'),
            (int) $context['sub_institute_id'], (string) $context['syear']
        );

        return $result['ok']
            ? $this->ok('Reminder saved.', ['reminder' => ['minutes_before' => (int) $request->input('minutes_before')]])
            : $this->fail($result['reason'], 404);
    }

    private function destroy(Request $request, string $entryType, int $id)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        return $this->reminders->delete($entryType, $id, (int) $context['user_id'])
            ? $this->ok('Reminder removed.')
            : $this->fail('No reminder on this entry.', 404);
    }

    /** Everything due now for the caller - what the frontend's polling hook asks for. */
    public function due(Request $request)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $due = $this->reminders->due((int) $context['user_id'], (int) $context['sub_institute_id']);

        $resource = $due->map(function ($row) {
            $title = $row->entry_type === 'TASK'
                ? DB::table('task')->where('id', $row->entry_id)->value('task_title')
                : DB::table('task_management_calendar_events')->where('id', $row->entry_id)->value('title');

            return [
                'delivery_id' => (string) $row->id,
                'entry_type' => $row->entry_type,
                'entry_id' => (string) $row->entry_id,
                'title' => $title ?? '(deleted)',
                'fire_at' => $row->fire_at,
                'snoozed_until' => $row->snoozed_until,
            ];
        })->values();

        return $this->ok('Due reminders retrieved successfully.', ['reminders' => $resource]);
    }

    public function seen(Request $request, int $deliveryId)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $result = $this->reminders->markSeen($deliveryId, (int) $context['user_id'], (int) $context['sub_institute_id']);

        return $result['ok'] ? $this->ok('Marked seen.') : $this->fail($result['reason'], 404);
    }

    public function snooze(Request $request, int $deliveryId)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), ['minutes' => 'required|integer|min:1|max:1440']);
        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422, $validator->errors()->toArray());
        }

        $result = $this->reminders->snooze(
            $deliveryId, (int) $context['user_id'], (int) $context['sub_institute_id'], (int) $request->input('minutes')
        );

        return $result['ok'] ? $this->ok('Snoozed.') : $this->fail($result['reason'], 404);
    }
}
