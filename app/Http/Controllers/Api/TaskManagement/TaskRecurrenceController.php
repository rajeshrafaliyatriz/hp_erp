<?php

namespace App\Http\Controllers\Api\TaskManagement;

use App\Http\Controllers\Api\TaskManagement\Concerns\ResolvesTaskContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * A task's recurrence rule (one per task). This stores the schedule; the
 * legacy `repeat_days` column on task is left untouched because the old
 * screens still read it.
 */
class TaskRecurrenceController extends Controller
{
    use ResolvesTaskContext;

    public function show(Request $request, int $id)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $row = DB::table('task_management_recurrences')
            ->where('task_id', $id)
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->first();

        return $this->ok('Recurrence retrieved successfully.', [
            'recurrence' => $row ? $this->resource($row) : null,
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

        $taskExists = DB::table('task')
            ->where('id', $id)
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->whereNull('deleted_at')
            ->exists();

        if (!$taskExists) {
            return $this->fail('Task not found.', 404);
        }

        DB::table('task_management_recurrences')->updateOrInsert(
            ['task_id' => $id],
            [
                'sub_institute_id' => $context['sub_institute_id'],
                'frequency' => $request->input('frequency'),
                'interval' => (int) $request->input('interval', 1),
                'until' => $request->input('until'),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        $row = DB::table('task_management_recurrences')->where('task_id', $id)->first();

        return $this->ok('Recurrence saved.', ['recurrence' => $this->resource($row)]);
    }

    public function destroy(Request $request, int $id)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $deleted = DB::table('task_management_recurrences')
            ->where('task_id', $id)
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->delete();

        return $deleted
            ? $this->ok('Recurrence removed.')
            : $this->fail('No recurrence on this task.', 404);
    }

    private function resource(object $row): array
    {
        return [
            'task_id' => (string) $row->task_id,
            'frequency' => (string) $row->frequency,
            'interval' => (int) $row->interval,
            'until' => $row->until,
        ];
    }
}
