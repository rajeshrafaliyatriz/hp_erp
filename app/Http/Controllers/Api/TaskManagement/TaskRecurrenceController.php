<?php

namespace App\Http\Controllers\Api\TaskManagement;

use App\Http\Controllers\Api\TaskManagement\Concerns\ResolvesTaskContext;
use App\Http\Controllers\Controller;
use App\Services\TaskManagement\CalendarRecurrenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * A task's recurrence rule.
 *
 * REWIRED, NOT REBUILT. This previously stored a rule in
 * `task_management_recurrences` that nothing ever read back — zero
 * consumers, confirmed by a full-repo search; it stopped the route 500ing
 * and did nothing else. The response shape below (`task_id`, `frequency`,
 * `interval`, `until`) is kept byte-identical on purpose, so no client needs
 * to change; what's new is that saving a rule here now really materializes
 * occurrences, via CalendarRecurrenceService, onto the SAME legacy `task`
 * table every other task-management screen already reads.
 */
class TaskRecurrenceController extends Controller
{
    use ResolvesTaskContext;

    public function __construct(private readonly CalendarRecurrenceService $recurrence)
    {
    }

    public function show(Request $request, int $id)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $row = DB::table('task_management_calendar_recurrences')
            ->where('entry_type', 'TASK')->where('entry_id', $this->seriesAnchorId($id))
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->first();

        return $this->ok('Recurrence retrieved successfully.', [
            'recurrence' => $row ? $this->resource($row, $id) : null,
        ]);
    }

    public function upsert(Request $request, int $id)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
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
            'TASK',
            $id,
            [
                'frequency' => $request->input('frequency'),
                'interval' => (int) $request->input('interval', 1),
                'until' => $request->input('until'),
            ],
            (int) $context['sub_institute_id'],
            (string) $context['syear'],
            (int) $context['user_id']
        );

        if (!$result['ok']) {
            return $this->fail($result['reason'], 404);
        }

        $row = DB::table('task_management_calendar_recurrences')->where('id', $result['recurrence_id'])->first();

        return $this->ok('Recurrence saved.', [
            'recurrence' => $this->resource($row, $id),
            // How many future occurrences were just written, so the UI can
            // say "12 upcoming tasks created" rather than a bare "saved".
            'created_count' => count($result['created_entry_ids']),
        ]);
    }

    /**
     * `scope` defaults to 'all', preserving this route's original behaviour
     * (deleting the one rule that existed). A caller that knows about series
     * scope can pass 'this' or 'this_and_future' to remove only part of it.
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

        $result = $this->recurrence->deleteScoped(
            'TASK', $id, $scope, (int) $context['sub_institute_id'], (string) $context['syear'], (int) $context['user_id']
        );

        return $result['ok']
            ? $this->ok('Recurrence removed.', ['deleted_count' => count($result['deleted_ids'])])
            : $this->fail('No recurrence on this task.', 404);
    }

    /** The series anchor's entry id, or $id itself when it isn't part of a series. */
    private function seriesAnchorId(int $id): int
    {
        $recurrenceId = DB::table('task_management_calendar_occurrences')
            ->where('entry_type', 'TASK')->where('entry_id', $id)->value('recurrence_id');
        if (!$recurrenceId) {
            return $id;
        }

        return (int) DB::table('task_management_calendar_recurrences')->where('id', $recurrenceId)->value('entry_id');
    }

    private function resource(object $row, int $requestedId): array
    {
        return [
            // The id the request named, not always the anchor — so GETting
            // occurrence #5's rule still reports against #5.
            'task_id' => (string) $requestedId,
            'frequency' => (string) $row->frequency,
            'interval' => (int) $row->interval_count,
            'until' => $row->until,
        ];
    }
}
