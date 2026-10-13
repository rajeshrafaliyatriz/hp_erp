<?php

namespace App\Http\Controllers\Api\TaskManagement;

use App\Http\Controllers\Api\TaskManagement\Concerns\ResolvesTaskContext;
use App\Http\Controllers\Controller;
use App\Services\TaskManagement\CalendarEventWriter;
use App\Services\TaskManagement\CalendarFeedService;
use App\Services\TaskManagement\CalendarIcsService;
use App\Services\TaskManagement\CalendarRecurrenceService;
use App\Support\SubjectAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * The Task Calendar: a merged read-model over tasks/events/milestones/
 * checkpoints (CalendarFeedService), plus the write path for the one new
 * entity this module introduces — a genuine "Event" (meeting/call), kept
 * deliberately separate from `task`. See CalendarEventWriter's docblock for
 * why.
 *
 * Permission shape mirrors WorkspaceController exactly, for consistency
 * across the module: creating/viewing is open to any authenticated tenant
 * member (route ungated); editing your own event needs no privileged
 * ability either, checked here the same way WorkspaceController::canEditTask
 * does; DELETING is privileged-only (`task.delete`) even for your own event —
 * that already-established house rule (no employee route anywhere deletes a
 * task outright, only WorkspaceController/LegacyTaskController's privileged
 * routes do) is kept rather than carved out an exception for events.
 */
class CalendarController extends Controller
{
    use ResolvesTaskContext;

    public function __construct(
        private readonly CalendarEventWriter $writer,
        private readonly CalendarFeedService $feed,
        private readonly CalendarRecurrenceService $recurrence,
        private readonly CalendarIcsService $ics,
    ) {
    }

    public function index(Request $request)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
        ]);
        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422, $validator->errors()->toArray());
        }

        $entries = $this->feed->range(
            (string) $request->input('from'),
            (string) $request->input('to'),
            $context['user_id'],
            $context['sub_institute_id'],
            $context['syear']
        );

        return $this->ok('Calendar entries retrieved successfully.', ['entries' => $entries]);
    }

    public function storeEvent(Request $request)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), $this->eventRules());
        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422, $validator->errors()->toArray());
        }

        if ($request->filled('linked_type')
            && !$this->linkedTargetExists($context, (string) $request->input('linked_type'), (int) $request->input('linked_id'))) {
            return $this->fail('The linked record was not found.', 422);
        }

        // Any tenant member may set their own calendar's owner; setting
        // someone else's requires the same elevated tier WorkspaceController
        // leans on everywhere else in this module.
        $ownerId = $request->filled('owner_id') ? $request->integer('owner_id') : $context['user_id'];
        if ($ownerId !== $context['user_id'] && !SubjectAuthority::userSatisfies($context['user_id'], SubjectAuthority::TASK_PRIVILEGED)) {
            return $this->fail('You can only create events on your own calendar.', 403);
        }

        $result = $this->writer->create(
            $this->eventAttributes($request) + ['owner_id' => $ownerId],
            $context['sub_institute_id'],
            $context['syear'],
            $context['user_id']
        );

        if (!$result['ok']) {
            return $this->fail($result['reason'], 422);
        }

        return $this->ok('Event created successfully.', ['id' => (string) $result['id']], 201);
    }

    public function show(Request $request, int $id)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $event = $this->findTenantEvent($context, $id);
        if (!$event) {
            return $this->fail('Event not found.', 404);
        }

        return $this->ok('Event retrieved successfully.', $this->resource($event));
    }

    public function update(Request $request, int $id)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $event = $this->findTenantEvent($context, $id);
        if (!$event) {
            return $this->fail('Event not found.', 404);
        }

        if (!$this->canEdit($context, $event)) {
            return $this->fail('You can only edit events you own.', 403);
        }

        $validator = Validator::make($request->all(), $this->eventRules(true));
        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422, $validator->errors()->toArray());
        }

        if ($request->filled('linked_type')
            && !$this->linkedTargetExists($context, (string) $request->input('linked_type'), (int) $request->input('linked_id'))) {
            return $this->fail('The linked record was not found.', 422);
        }

        $result = $this->writer->update(
            $id,
            $this->eventAttributes($request, true),
            $context['sub_institute_id'],
            $context['syear'],
            $context['user_id']
        );

        if (!$result['ok']) {
            return $this->fail($result['reason'], 422);
        }

        $fresh = $this->findTenantEvent($context, $id);

        return $this->ok('Event updated successfully.', $this->resource($fresh));
    }

    public function reschedule(Request $request, int $id)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $event = $this->findTenantEvent($context, $id);
        if (!$event) {
            return $this->fail('Event not found.', 404);
        }

        if (!$this->canEdit($context, $event)) {
            return $this->fail('You can only reschedule events you own.', 403);
        }

        $validator = Validator::make($request->all(), [
            'start_at' => 'required|date',
            'end_at' => 'required|date|after_or_equal:start_at',
        ]);
        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422, $validator->errors()->toArray());
        }

        $result = $this->writer->reschedule(
            $id,
            (string) $request->input('start_at'),
            (string) $request->input('end_at'),
            $context['sub_institute_id'],
            $context['syear'],
            $context['user_id']
        );

        if (!$result['ok']) {
            return $this->fail($result['reason'], 422);
        }

        return $this->ok('Event rescheduled successfully.');
    }

    /**
     * DELETE is privileged-only, even for your own event — see class
     * docblock. `scope` defaults to 'all' (today's whole-event behaviour);
     * pass 'this'/'this_and_future' when the event is part of a recurring
     * series and the caller resolved a scope choice from the user.
     */
    public function destroy(Request $request, int $id)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $scope = $request->input('scope', 'all');
        if (!in_array($scope, ['this', 'this_and_future', 'all'], true)) {
            return $this->fail('scope must be this, this_and_future, or all.', 422);
        }

        $event = $this->findTenantEvent($context, $id);
        if (!$event) {
            return $this->fail('Event not found.', 404);
        }

        $result = $this->recurrence->deleteScoped(
            'EVENT', $id, $scope, $context['sub_institute_id'], $context['syear'], $context['user_id']
        );

        return $result['ok']
            ? $this->ok('Event deleted successfully.', ['deleted_count' => count($result['deleted_ids'])])
            : $this->fail($result['reason'], 404);
    }

    public function recurrenceShow(Request $request, int $id)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $anchorId = $this->seriesAnchorEventId($id);
        $row = DB::table('task_management_calendar_recurrences')
            ->where('entry_type', 'EVENT')->where('entry_id', $anchorId)
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->first();

        return $this->ok('Recurrence retrieved successfully.', [
            'recurrence' => $row ? $this->recurrenceResource($row, $id) : null,
        ]);
    }

    public function recurrenceUpsert(Request $request, int $id)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $event = $this->findTenantEvent($context, $id);
        if (!$event) {
            return $this->fail('Event not found.', 404);
        }
        if (!$this->canEdit($context, $event)) {
            return $this->fail('You can only set recurrence on events you own.', 403);
        }

        $validator = Validator::make($request->all(), [
            'frequency' => 'required|in:daily,weekly,monthly',
            'interval' => 'nullable|integer|min:1|max:52',
            'until' => 'nullable|date',
        ]);
        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422, $validator->errors()->toArray());
        }

        $result = $this->recurrence->materialize(
            'EVENT', $id,
            [
                'frequency' => $request->input('frequency'),
                'interval' => (int) $request->input('interval', 1),
                'until' => $request->input('until'),
            ],
            $context['sub_institute_id'], $context['syear'], $context['user_id']
        );

        if (!$result['ok']) {
            return $this->fail($result['reason'], 422);
        }

        $row = DB::table('task_management_calendar_recurrences')->where('id', $result['recurrence_id'])->first();

        return $this->ok('Recurrence saved.', [
            'recurrence' => $this->recurrenceResource($row, $id),
            'created_count' => count($result['created_entry_ids']),
        ]);
    }

    public function recurrenceDestroy(Request $request, int $id)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $event = $this->findTenantEvent($context, $id);
        if (!$event) {
            return $this->fail('Event not found.', 404);
        }
        if (!$this->canEdit($context, $event)) {
            return $this->fail('You can only remove recurrence from events you own.', 403);
        }

        $result = $this->recurrence->deleteScoped(
            'EVENT', $id, 'all', $context['sub_institute_id'], $context['syear'], $context['user_id']
        );

        return $result['ok']
            ? $this->ok('Recurrence removed.', ['deleted_count' => count($result['deleted_ids'])])
            : $this->fail('No recurrence on this event.', 404);
    }

    /** Single-event .ics — the email-invite attachment shape. */
    public function exportEventIcs(Request $request, int $id)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $ics = $this->ics->exportEvent($id, $context['sub_institute_id'], $context['syear']);
        if ($ics === null) {
            return $this->fail('Event not found.', 404);
        }

        return $this->icsDownload($ics, "event-{$id}.ics");
    }

    /**
     * A date range — tasks and events both, respecting the SAME visibility
     * rules as the on-screen feed (CalendarFeedService), so an export can
     * never leak more than the calendar already shows this viewer.
     */
    public function exportRangeIcs(Request $request)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), ['from' => 'required|date', 'to' => 'required|date|after_or_equal:from']);
        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422, $validator->errors()->toArray());
        }

        $entries = $this->feed->range(
            (string) $request->input('from'), (string) $request->input('to'),
            $context['user_id'], $context['sub_institute_id'], $context['syear']
        );

        return $this->icsDownload($this->ics->exportEntries($entries), 'calendar-export.ics');
    }

    public function importIcs(Request $request)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), ['file' => 'required|file|max:5120']);
        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422, $validator->errors()->toArray());
        }

        $contents = file_get_contents($request->file('file')->getRealPath());
        $result = $this->ics->import($contents, $context['sub_institute_id'], $context['syear'], $context['user_id'], $context['user_id']);

        return $this->ok(
            "Imported {$result['imported']} event(s), skipped {$result['skipped']}.",
            $result
        );
    }

    public function listAttendees(Request $request, int $id)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $event = $this->findTenantEvent($context, $id);
        if (!$event) {
            return $this->fail('Event not found.', 404);
        }

        $attendees = $this->ics->attendees($id)->map(fn ($row) => [
            'id' => (string) $row->id,
            'name' => $row->user_name ?: $row->external_email,
            'status' => $row->status,
        ]);

        return $this->ok('Attendees retrieved successfully.', ['attendees' => $attendees]);
    }

    public function inviteAttendee(Request $request, int $id)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $event = $this->findTenantEvent($context, $id);
        if (!$event) {
            return $this->fail('Event not found.', 404);
        }
        if (!$this->canEdit($context, $event)) {
            return $this->fail('You can only invite attendees to events you own.', 403);
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'nullable|integer',
            'external_email' => 'nullable|email',
        ]);
        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422, $validator->errors()->toArray());
        }

        $result = $this->ics->invite(
            $id, $request->input('user_id') ? (int) $request->input('user_id') : null,
            $request->input('external_email'), $context['sub_institute_id'], $context['syear']
        );

        return $result['ok']
            ? $this->ok('Attendee invited.', ['attendee_id' => (string) $result['attendee_id']], 201)
            : $this->fail($result['reason'], 422);
    }

    public function removeAttendee(Request $request, int $id, int $attendeeId)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $event = $this->findTenantEvent($context, $id);
        if (!$event) {
            return $this->fail('Event not found.', 404);
        }
        if (!$this->canEdit($context, $event)) {
            return $this->fail('You can only remove attendees from events you own.', 403);
        }

        $removed = $this->ics->removeAttendee($attendeeId, $id, $context['sub_institute_id']);

        return $removed
            ? $this->ok('Attendee removed.')
            : $this->fail('Attendee not found.', 404);
    }

    /** PUBLIC — no auth, matching CRM's own accept-only short-link. The token is the only credential. */
    public function acceptInvite(string $token)
    {
        $result = $this->ics->accept($token);

        return $result['ok'] ? $this->ok('Invitation accepted.') : $this->fail($result['reason'], 404);
    }

    private function icsDownload(string $ics, string $filename)
    {
        return response($ics, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /** The series anchor's entry id, or $id itself when it isn't part of a series. */
    private function seriesAnchorEventId(int $id): int
    {
        $recurrenceId = DB::table('task_management_calendar_occurrences')
            ->where('entry_type', 'EVENT')->where('entry_id', $id)->value('recurrence_id');
        if (!$recurrenceId) {
            return $id;
        }

        return (int) DB::table('task_management_calendar_recurrences')->where('id', $recurrenceId)->value('entry_id');
    }

    private function recurrenceResource(object $row, int $requestedId): array
    {
        return [
            'event_id' => (string) $requestedId,
            'frequency' => (string) $row->frequency,
            'interval' => (int) $row->interval_count,
            'until' => $row->until,
        ];
    }

    private function eventRules(bool $isUpdate = false): array
    {
        $required = $isUpdate ? 'sometimes|required' : 'required';

        return [
            'title' => "{$required}|string|max:191",
            'description' => 'nullable|string|max:10000',
            'location' => 'nullable|string|max:191',
            'start_at' => "{$required}|date",
            'end_at' => "{$required}|date|after_or_equal:start_at",
            'all_day' => 'nullable|boolean',
            'owner_id' => 'nullable|integer',
            'visibility' => 'nullable|in:PUBLIC,PRIVATE',
            'linked_type' => 'nullable|in:PROJECT,WORKSTREAM,TASK,BACKLOG_ITEM|required_with:linked_id',
            'linked_id' => 'nullable|integer|min:1|required_with:linked_type',
        ];
    }

    private function eventAttributes(Request $request, bool $isUpdate = false): array
    {
        $attributes = array_filter([
            'title' => $request->input('title'),
            'description' => $request->input('description'),
            'location' => $request->input('location'),
            'start_at' => $request->input('start_at'),
            'end_at' => $request->input('end_at'),
            'all_day' => $request->boolean('all_day'),
            'visibility' => $request->input('visibility'),
            'linked_type' => $request->input('linked_type'),
            'linked_id' => $request->input('linked_id'),
        ], fn ($value) => $value !== null);

        if (!$isUpdate) {
            // Required fields must be present even if array_filter would have
            // dropped a falsy-but-valid one (all_day=false is valid and real).
            $attributes['all_day'] = $request->boolean('all_day');
        }

        return $attributes;
    }

    /**
     * The linked row must actually exist, in THIS tenant, or the pointer is
     * a lie nobody can click through. Mirrors DependencyController's own
     * validTasks()/validProject() idiom (plain tenant-scoped exists() checks)
     * rather than a declarative Rule::exists.
     *
     * Deliberately does NOT reject an archived PROJECT - a retrospective
     * meeting about an already-archived project is a legitimate thing to
     * link, unlike a brand-new dependency, which DependencyController's own
     * stricter rule is right to refuse.
     */
    private function linkedTargetExists(array $context, string $type, int $id): bool
    {
        return match ($type) {
            'PROJECT' => DB::table('task_management_projects')
                ->where('id', $id)
                ->where('sub_institute_id', $context['sub_institute_id'])
                ->where('syear', $context['syear'])
                ->exists(),
            'WORKSTREAM' => DB::table('task_management_workstreams as w')
                ->join('task_management_projects as p', 'p.id', '=', 'w.project_id')
                ->where('w.id', $id)
                ->where('p.sub_institute_id', $context['sub_institute_id'])
                ->where('p.syear', $context['syear'])
                ->exists(),
            'TASK' => DB::table('task')
                ->where('id', $id)
                ->where('sub_institute_id', $context['sub_institute_id'])
                ->where('SYEAR', $context['syear'])
                ->whereNull('deleted_at')
                ->exists(),
            'BACKLOG_ITEM' => DB::table('task_management_backlog_items')
                ->where('id', $id)
                ->where('sub_institute_id', $context['sub_institute_id'])
                ->where('syear', $context['syear'])
                ->exists(),
            default => false,
        };
    }

    private function findTenantEvent(array $context, int $id): ?object
    {
        return DB::table('task_management_calendar_events')
            ->where('id', $id)
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->where('syear', $context['syear'])
            ->whereNull('deleted_at')
            ->first();
    }

    /** Mirrors WorkspaceController::canEditTask: owner, or an elevated role. */
    private function canEdit(array $context, object $event): bool
    {
        return (int) $event->owner_id === $context['user_id']
            || SubjectAuthority::userSatisfies($context['user_id'], SubjectAuthority::TASK_PRIVILEGED);
    }

    private function resource(object $event): array
    {
        return [
            'id' => (string) $event->id,
            'title' => (string) $event->title,
            'description' => (string) ($event->description ?? ''),
            'location' => $event->location,
            'start_at' => (string) $event->start_at,
            'end_at' => (string) $event->end_at,
            'all_day' => (bool) $event->all_day,
            'status' => (string) $event->status,
            'visibility' => (string) $event->visibility,
            'owner_id' => (string) $event->owner_id,
            'linked_type' => $event->linked_type,
            'linked_id' => $event->linked_id ? (string) $event->linked_id : null,
            'recurrence_id' => $event->recurrence_id ? (string) $event->recurrence_id : null,
        ];
    }
}
