<?php

namespace App\Http\Controllers\Api\TaskManagement;

use App\Http\Controllers\Api\TaskManagement\Concerns\ResolvesTaskContext;
use App\Http\Controllers\Controller;
use App\Services\TaskManagement\TaskAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * A task's schedule window: planned start, due date and the effort envelope.
 * Reads and writes columns on `task` itself - the planning columns exist for
 * exactly this, so a separate store would just duplicate them.
 */
class TaskScheduleController extends Controller
{
    use ResolvesTaskContext;

    public function __construct(private readonly TaskAuditService $taskAudit)
    {
    }

    public function show(Request $request, int $id)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $task = $this->find($context, $id);
        if (!$task) {
            return $this->fail('Task not found.', 404);
        }

        return $this->ok('Schedule retrieved successfully.', ['schedule' => $this->resource($task)]);
    }

    public function update(Request $request, int $id)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            'planned_start_date' => 'nullable|date',
            'due_date' => 'nullable|date|after_or_equal:planned_start_date',
            'estimated_hours' => 'nullable|numeric|min:0|max:10000',
            'remaining_hours' => 'nullable|numeric|min:0|max:10000',
        ]);

        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422, $validator->errors()->toArray());
        }

        $task = $this->find($context, $id);
        if (!$task) {
            return $this->fail('Task not found.', 404);
        }

        // Only the fields actually sent change; absent keys keep their values.
        $update = ['updated_by' => $context['user_id'], 'updated_at' => now()];
        if ($request->has('planned_start_date')) {
            $update['planned_start_date'] = $request->input('planned_start_date');
        }
        if ($request->has('due_date')) {
            $update['task_date'] = $request->input('due_date');
        }
        if ($request->has('estimated_hours')) {
            $update['estimated_hours'] = $request->input('estimated_hours');
        }
        if ($request->has('remaining_hours')) {
            $update['remaining_hours'] = $request->input('remaining_hours');
        }

        DB::table('task')->where('id', $id)->update($update);

        // Moving a due date is the change people most often need to trace, so
        // the previous schedule is what gets snapshotted here.
        $this->taskAudit->taskChanged($id, 'schedule_updated', [
            'sub_institute_id' => $context['sub_institute_id'],
            'task_date' => $task->task_date,
            'detail' => 'planned_start ' . ($task->planned_start_date ?? '-')
                . ', due ' . ($task->task_date ?? '-')
                . ', estimated ' . ($task->estimated_hours ?? '-') . 'h',
        ], $context['user_id']);

        return $this->ok('Schedule updated.', ['schedule' => $this->resource($this->find($context, $id))]);
    }

    private function find(array $context, int $id): ?object
    {
        return DB::table('task')
            ->where('id', $id)
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->whereNull('deleted_at')
            ->first();
    }

    private function resource(object $task): array
    {
        return [
            'task_id' => (string) $task->id,
            'planned_start_date' => $task->planned_start_date,
            'due_date' => $task->task_date,
            'estimated_hours' => $task->estimated_hours !== null ? (float) $task->estimated_hours : null,
            'actual_hours' => $task->actual_hours !== null ? (float) $task->actual_hours : null,
            'remaining_hours' => $task->remaining_hours !== null ? (float) $task->remaining_hours : null,
        ];
    }
}
