<?php

namespace App\Http\Controllers\front_desk;

use App\Http\Controllers\Controller;
use App\Models\front_desk\taskModel;
use App\Services\TaskManagement\TaskAuditService;
use App\Services\TaskManagement\TaskDependencyResolutionService;
use App\Services\TaskManagement\TaskStatusTransitionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\PersonalAccessToken;

class TaskUpdateController extends Controller
{
    public function __construct(
        private readonly TaskAuditService $taskAudit,
        private readonly TaskStatusTransitionService $statusTransitions,
        private readonly TaskDependencyResolutionService $dependencyResolution
    ) {
    }

    public function updateStatusAndDescription(Request $request, $id)
    {
        // Validate token
        $token = $request->Token() ?: $request->input('token');
        if (!$token) {
            return response()->json(['message' => 'Token not provided'], 401);
        }

        $accessToken = PersonalAccessToken::findToken($token);
        if (!$accessToken) {
            return response()->json(['message' => 'Invalid token'], 401);
        }

        $user = $accessToken->tokenable;
        $user_id = $user->id;

        // Validate inputs
        $validator = Validator::make($request->all(), [
            'row.data.status' => 'required|string',
            'row.data.taskcompletation_remarks' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['status_code' => 0, 'message' => $validator->errors()->first()], 400);
        }

        // Check if task exists
        $task = taskModel::find($id);
        if (!$task) {
            return response()->json(['status_code' => 0, 'message' => 'Task not found'], 404);
        }

        if (!$this->statusTransitions->allows($task->status, $request->input('row.data.status'))) {
            return response()->json([
                'status_code' => 0,
                'message' => $this->statusTransitions->message($task->status, $request->input('row.data.status')),
            ], 422);
        }

        $before = $this->taskAudit->taskSnapshot((int) $id);
        // Update only the specified fields
        $task->update([
            'status' => $request->input('row.data.status'),
            'task_description' => $request->input('row.data.task_description'),
            'taskcompletation_remarks' => $request->input('row.data.taskcompletation_remarks'),
            'updated_at' => now(),
            'updated_by' => $user_id,
        ]);
        $this->taskAudit->taskChanged((int) $id, 'status_changed', $before, (int) $user_id);
        if ($request->input('row.data.status') === 'COMPLETED') $this->dependencyResolution->resolveAfterCompletion((int) $id, (int) $user_id);

        return response()->json([
            'status_code' => 1,
            'message' => 'Task updated successfully',
        ]);
    }
}
