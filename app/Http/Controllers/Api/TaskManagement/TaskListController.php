<?php

namespace App\Http\Controllers\Api\TaskManagement;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\PersonalAccessToken;

class TaskListController extends Controller
{
    public function index(Request $request)
    {
        $context = $this->context($request);
        if (!is_array($context)) return $context;
        $validator = Validator::make($request->all(), [
            'per_page' => 'nullable|integer|min:1|max:100', 'cursor' => 'nullable|string|max:2000',
            'search' => 'nullable|string|max:191', 'status' => 'nullable|string|max:50',
            'priority' => 'nullable|string|max:50', 'assignee_id' => 'nullable|integer',
            'from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from',
        ]);
        if ($validator->fails()) return response()->json(['status' => 0, 'message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);

        $query = DB::table('task')->where('sub_institute_id', $context['sub_institute_id'])
            ->where('SYEAR', $context['syear'])->whereNull('deleted_at');
        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            if (DB::getDriverName() === 'mysql' && mb_strlen($search) >= 3) {
                $query->where(fn ($q) => $q->whereRaw('MATCH(task_title, task_description) AGAINST(? IN BOOLEAN MODE)', [$search.'*'])
                    ->orWhere('task_code', 'like', "%{$search}%"));
            } else {
                $query->where(fn ($q) => $q->where('task_title', 'like', "%{$search}%")
                    ->orWhere('task_description', 'like', "%{$search}%")
                    ->orWhere('task_code', 'like', "%{$search}%"));
            }
        }
        if ($request->filled('status')) $query->whereRaw('UPPER(status) = ?', [strtoupper($request->input('status'))]);
        if ($request->filled('priority')) $query->where('task_type', $request->input('priority'));
        if ($request->filled('assignee_id')) $query->where('task_allocated_to', $request->integer('assignee_id'));
        if ($request->filled('from')) $query->whereDate('task_date', '>=', $request->input('from'));
        if ($request->filled('to')) $query->whereDate('task_date', '<=', $request->input('to'));
        $tasks = $query->orderByDesc('id')->cursorPaginate($request->integer('per_page', 50));
        return response()->json(['status' => 1, 'message' => 'Tasks retrieved successfully.', 'data' => [
            'tasks' => collect($tasks->items())->map(fn ($task) => [
                'id' => (string) $task->id, 'task_code' => $task->task_code ?: (string) $task->id,
                'title' => $task->task_title, 'description' => $task->task_description,
                'assignee_id' => $task->task_allocated_to ? (string) $task->task_allocated_to : null,
                'owner_id' => $task->task_allocated ? (string) $task->task_allocated : null,
                'status' => $task->status, 'priority' => $task->task_type, 'due_date' => $task->task_date,
            ])->all(),
            'pagination' => [
                'per_page' => $tasks->perPage(), 'next_cursor' => $tasks->nextCursor()?->encode(),
                'previous_cursor' => $tasks->previousCursor()?->encode(), 'has_more' => $tasks->hasMorePages(),
            ],
        ]]);
    }

    private function context(Request $request)
    {
        $token = trim((string) ($request->bearerToken() ?: $request->input('token')));
        $accessToken = $token !== '' ? PersonalAccessToken::findToken($token) : null;
        $user = $accessToken?->tokenable;
        if (!$user) return response()->json(['status' => 0, 'message' => 'Unauthenticated.'], 401);
        $syear = trim((string) $request->input('syear'));
        if ($syear === '') return response()->json(['status' => 0, 'message' => 'syear is required.'], 422);
        return ['sub_institute_id' => (int) $user->sub_institute_id, 'syear' => $syear];
    }
}