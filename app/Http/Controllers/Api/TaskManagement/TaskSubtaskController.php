<?php

namespace App\Http\Controllers\Api\TaskManagement;

use App\Http\Controllers\Api\TaskManagement\Concerns\ResolvesTaskContext;
use App\Http\Controllers\Controller;
use App\Services\TaskManagement\TaskAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Checklist items under a task: list, add, tick off, remove. */
class TaskSubtaskController extends Controller
{
    use ResolvesTaskContext;

    public function __construct(private readonly TaskAuditService $taskAudit)
    {
    }

    public function index(Request $request, int $id)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        if ($guard = $this->guardTask($context, $id)) {
            return $guard;
        }

        $rows = DB::table('task_management_subtasks')
            ->where('task_id', $id)
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => $this->resource($row));

        return $this->ok('Subtasks retrieved successfully.', [
            'subtasks' => $rows->all(),
            'done' => $rows->where('is_done', true)->count(),
            'total' => $rows->count(),
        ]);
    }

    public function store(Request $request, int $id)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), ['title' => 'required|string|max:255']);
        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422, $validator->errors()->toArray());
        }

        if ($guard = $this->guardTask($context, $id)) {
            return $guard;
        }

        $nextOrder = (int) DB::table('task_management_subtasks')
            ->where('task_id', $id)
            ->max('sort_order') + 1;

        $subtaskId = DB::table('task_management_subtasks')->insertGetId([
            'sub_institute_id' => $context['sub_institute_id'],
            'task_id' => $id,
            'title' => $request->input('title'),
            'sort_order' => $nextOrder,
            'created_by' => $context['user_id'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->taskAudit->taskChanged($id, 'subtask_added', [
            'sub_institute_id' => $context['sub_institute_id'],
            'detail' => (string) $request->input('title'),
        ], $context['user_id']);

        return $this->ok('Subtask added.', ['id' => (string) $subtaskId], 201);
    }

    /** PATCH toggles completion - the only mutable state a checklist item has. */
    public function toggle(Request $request, int $id, int $subtask)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $row = DB::table('task_management_subtasks')
            ->where('id', $subtask)
            ->where('task_id', $id)
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->first();

        if (!$row) {
            return $this->fail('Subtask not found.', 404);
        }

        DB::table('task_management_subtasks')->where('id', $subtask)->update([
            'is_done' => !$row->is_done,
            'updated_at' => now(),
        ]);

        $this->taskAudit->taskChanged($id, $row->is_done ? 'subtask_reopened' : 'subtask_completed', [
            'sub_institute_id' => $context['sub_institute_id'],
            'detail' => (string) $row->title,
        ], $context['user_id']);

        return $this->ok('Subtask updated.', ['id' => (string) $subtask, 'is_done' => !$row->is_done]);
    }

    public function destroy(Request $request, int $id, int $subtask)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $row = DB::table('task_management_subtasks')
            ->where('id', $subtask)
            ->where('task_id', $id)
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->first();

        $deleted = $row
            ? DB::table('task_management_subtasks')->where('id', $subtask)->delete()
            : 0;

        if ($deleted) {
            $this->taskAudit->taskChanged($id, 'subtask_removed', [
                'sub_institute_id' => $context['sub_institute_id'],
                'detail' => (string) $row->title,
            ], $context['user_id']);
        }

        return $deleted
            ? $this->ok('Subtask removed.')
            : $this->fail('Subtask not found.', 404);
    }

    private function guardTask(array $context, int $taskId)
    {
        $exists = DB::table('task')
            ->where('id', $taskId)
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->whereNull('deleted_at')
            ->exists();

        return $exists ? null : $this->fail('Task not found.', 404);
    }

    private function resource(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'title' => (string) $row->title,
            'is_done' => (bool) $row->is_done,
            'sort_order' => (int) $row->sort_order,
        ];
    }
}
