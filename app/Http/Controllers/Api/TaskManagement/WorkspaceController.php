<?php

namespace App\Http\Controllers\Api\TaskManagement;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\PersonalAccessToken;

class WorkspaceController extends Controller
{
    public function index(Request $request)
    {
        $context = $this->context($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            'scope' => 'nullable|in:all,my,assigned,created',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
            'search' => 'nullable|string|max:191',
            'status' => 'nullable|string|max:50',
            'priority' => 'nullable|string|max:50',
            'assignee_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 0,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $query = $this->baseQuery($context);

        $scope = $request->input('scope', 'all');
        if ($scope === 'my') {
            $query->where('t.task_allocated_to', $context['user_id']);
        } elseif ($scope === 'assigned') {
            $query->where('t.task_allocated', $context['user_id']);
        } elseif ($scope === 'created') {
            $query->where('t.created_by', $context['user_id']);
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($builder) use ($search) {
                $builder->where('t.task_title', 'like', "%{$search}%")
                    ->orWhere('t.task_description', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->whereRaw('UPPER(t.status) = ?', [strtoupper((string) $request->input('status'))]);
        }

        if ($request->filled('priority')) {
            $query->where('t.task_type', $request->input('priority'));
        }

        if ($request->filled('assignee_id')) {
            $query->where('t.task_allocated_to', $request->integer('assignee_id'));
        }

        $tasks = $query
            ->orderByRaw('CASE WHEN t.task_date IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('t.task_date')
            ->orderByDesc('t.id')
            ->paginate((int) $request->input('per_page', 50));

        $tasks->getCollection()->transform(fn ($task) => $this->resource($task));

        return response()->json([
            'status' => 1,
            'message' => 'Workspace tasks retrieved successfully.',
            'data' => [
                'tasks' => $tasks->items(),
                'pagination' => [
                    'current_page' => $tasks->currentPage(),
                    'last_page' => $tasks->lastPage(),
                    'per_page' => $tasks->perPage(),
                    'total' => $tasks->total(),
                ],
                'filters' => [
                    'scopes' => ['all', 'my', 'assigned', 'created'],
                ],
            ],
        ]);
    }

    public function workload(Request $request)
    {
        $context = $this->context($request);
        if (!is_array($context)) {
            return $context;
        }

        $rows = DB::table('task as t')
            ->where('t.sub_institute_id', $context['sub_institute_id'])
            ->where('t.SYEAR', $context['syear'])
            ->whereNull('t.deleted_at')
            ->selectRaw('COALESCE(t.task_allocated_to, 0) as user_id, COUNT(*) as total')
            ->groupBy('user_id')
            ->get();

        return response()->json([
            'status' => 1,
            'message' => 'Workspace workload retrieved successfully.',
            'data' => $rows,
        ]);
    }

    public function show(Request $request, int $id)
    {
        $context = $this->context($request);
        if (!is_array($context)) {
            return $context;
        }

        $task = $this->baseQuery($context)
            ->where('t.id', $id)
            ->first();

        if (!$task) {
            return response()->json(['status' => 0, 'message' => 'Task not found.'], 404);
        }

        return response()->json([
            'status' => 1,
            'message' => 'Workspace task retrieved successfully.',
            'data' => $this->resource($task, true),
        ]);
    }

    private function context(Request $request)
    {
        $token = trim((string) ($request->Token() ?: $request->input('token')));
        if ($token === '') {
            return response()->json(['status' => 0, 'message' => 'Token is required.'], 422);
        }

        $accessToken = PersonalAccessToken::findToken($token);
        $user = $accessToken?->tokenable;
        if (!$user) {
            return response()->json(['status' => 0, 'message' => 'Unauthenticated.'], 401);
        }

        $syear = trim((string) $request->input('syear'));
        if ($syear === '') {
            return response()->json(['status' => 0, 'message' => 'syear is required.'], 422);
        }

        return [
            'user_id' => (int) $user->id,
            'sub_institute_id' => (int) $request->input('sub_institute_id', $user->sub_institute_id ?? 0),
            'syear' => $syear,
        ];
    }

    private function baseQuery(array $context)
    {
        return DB::table('task as t')
            ->leftJoin('tbluser as allocator', 'allocator.id', '=', 't.task_allocated')
            ->leftJoin('tbluser as assignee', 'assignee.id', '=', 't.task_allocated_to')
            ->leftJoin('tbluser as creator', 'creator.id', '=', 't.created_by')
            ->where('t.sub_institute_id', $context['sub_institute_id'])
            ->where('t.SYEAR', $context['syear'])
            ->whereNull('t.deleted_at')
            ->selectRaw("t.id, t.task_title, t.task_description, t.task_type, t.task_date, t.status, t.task_allocated, t.task_allocated_to, t.created_by, t.created_at, t.updated_at,
                TRIM(CONCAT_WS(' ', allocator.first_name, allocator.middle_name, allocator.last_name)) as allocator_name,
                TRIM(CONCAT_WS(' ', assignee.first_name, assignee.middle_name, assignee.last_name)) as assignee_name,
                TRIM(CONCAT_WS(' ', creator.first_name, creator.middle_name, creator.last_name)) as creator_name");
    }

    private function resource(object $task, bool $includeMeta = false): array
    {
        $data = [
            'id' => (string) $task->id,
            'task_code' => (string) ($task->task_code ?: $task->id),
            'title' => (string) ($task->task_title ?? ''),
            'description' => (string) ($task->task_description ?? ''),
            'priority' => $task->task_type ?? null,
            'due_date' => $task->task_date ?? null,
            'status' => $task->status ?? null,
            'assignee_id' => $task->task_allocated_to ? (string) $task->task_allocated_to : null,
            'assignee_name' => $task->assignee_name ?? null,
            'owner_id' => $task->task_allocated ? (string) $task->task_allocated : null,
            'owner_name' => $task->allocator_name ?? null,
        ];

        if ($includeMeta) {
            $data['created_by'] = $task->created_by ? (string) $task->created_by : null;
            $data['created_by_name'] = $task->creator_name ?? null;
            $data['created_at'] = $task->created_at ?? null;
            $data['updated_at'] = $task->updated_at ?? null;
        }

        return $data;
    }
}