<?php

namespace App\Http\Controllers\Api\TaskManagement;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Update-task with optimistic locking: the caller states the updated_at it
 * last saw, and a mismatch means someone else changed the task in between -
 * refused with 409 so the second editor merges instead of silently
 * overwriting the first.
 */
class VersionedLegacyTaskController extends LegacyTaskController
{
    public function update(Request $request, int $id)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make(
            $request->all(),
            $this->rules() + ['expected_updated_at' => 'required|date']
        );

        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422, $validator->errors()->toArray());
        }

        $task = $this->find($context, $id);
        if (!$task) {
            return $this->fail('Task not found.', 404);
        }

        $expected = strtotime((string) $request->input('expected_updated_at'));
        $actual = $task->updated_at ? strtotime((string) $task->updated_at) : null;

        if ($actual !== null && $expected !== $actual) {
            return response()->json([
                'status' => 0,
                'message' => 'This task was modified by someone else. Reload it and reapply your changes.',
                'current_updated_at' => $task->updated_at,
            ], 409);
        }

        return parent::update($request, $id);
    }
}
