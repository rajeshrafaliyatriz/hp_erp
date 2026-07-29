<?php

namespace App\Http\Controllers\Api\TaskManagement;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Stateless API for the Next.js Task Management > My Tasks screen.
 *
 * This is additive: legacy /task, /lms/lmsActivityStream and
 * /task/update-status routes remain unchanged.
 */
class MyTasksController extends Controller
{
    private const STATUSES = ['PENDING', 'IN-PROGRESS', 'ON HOLD', 'COMPLETED'];
    private const PRIORITIES = ['High', 'Medium', 'Low'];

    public function index(Request $request)
    {
        $context = $this->context($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            'group' => 'nullable|in:all,today,upcoming,recent,subordinates',
            'search' => 'nullable|string|max:191',
            'status' => 'nullable|in:PENDING,IN-PROGRESS,ON HOLD,COMPLETED',
            'priority' => 'nullable|in:High,Medium,Low',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 0,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $query = $this->baseQuery($context);
        $this->applyGroup($query, $request->input('group', 'all'), $context);

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function (Builder $builder) use ($search) {
                $builder
                    ->where('t.task_title', 'like', "%{$search}%")
                    ->orWhere('t.task_description', 'like', "%{$search}%")
                    ->orWhereRaw(
                        "CONCAT_WS(' ', allocator.first_name, allocator.middle_name, allocator.last_name) like ?",
                        ["%{$search}%"]
                    );
            });
        }

        if ($request->filled('status')) {
            $query->whereRaw('UPPER(t.status) = ?', [strtoupper((string) $request->input('status'))]);
        }
        if ($request->filled('priority')) {
            $query->where('t.task_type', $request->input('priority'));
        }

        $tasks = $query
            ->orderByRaw('CASE WHEN t.task_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('t.task_date')
            ->orderByDesc('t.id')
            ->paginate((int) $request->input('per_page', 20));

        $tasks->getCollection()->transform(fn ($task) => $this->taskResource($task));

        return response()->json([
            'status' => 1,
            'message' => 'Tasks retrieved successfully.',
            'data' => [
                'tasks' => $tasks->items(),
                'summary' => $this->summary($context),
                'pagination' => [
                    'current_page' => $tasks->currentPage(),
                    'last_page' => $tasks->lastPage(),
                    'per_page' => $tasks->perPage(),
                    'total' => $tasks->total(),
                ],
                'filters' => [
                    'statuses' => self::STATUSES,
                    'priorities' => self::PRIORITIES,
                ],
            ],
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
            ->where(function (Builder $query) use ($context) {
                $query
                    ->where('t.task_allocated_to', $context['user_id'])
                    ->orWhere('t.task_allocated', $context['user_id'])
                    ->orWhereIn('t.task_allocated_to', $this->subordinateIds($context));
            })
            ->first();

        if (!$task) {
            return response()->json(['status' => 0, 'message' => 'Task not found.'], 404);
        }

        return response()->json([
            'status' => 1,
            'message' => 'Task retrieved successfully.',
            'data' => $this->taskResource($task, true),
        ]);
    }

    public function updateStatus(Request $request, int $id)
    {
        $context = $this->context($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:PENDING,IN-PROGRESS,ON HOLD,COMPLETED',
            'remarks' => 'required|string|max:5000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 0,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $task = DB::table('task')
            ->where('id', $id)
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->where('syear', $context['syear'])
            ->where('task_allocated_to', $context['user_id'])
            ->whereNull('deleted_at')
            ->first();

        if (!$task) {
            return response()->json(['status' => 0, 'message' => 'Task not found or cannot be updated.'], 404);
        }

        DB::table('task')->where('id', $id)->update([
            'status' => $request->input('status'),
            'reply' => $request->input('remarks'),
            'updated_by' => $context['user_id'],
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => 1,
            'message' => 'Task status updated successfully.',
            'data' => [
                'id' => (string) $id,
                'status' => $request->input('status'),
                'remarks' => $request->input('remarks'),
            ],
        ]);
    }

    private function context(Request $request)
    {
        $token = trim((string) $request->input('token'));
        if ($token === '') {
            return response()->json(['status' => 0, 'message' => 'Token not provided.'], 401);
        }

        $accessToken = PersonalAccessToken::findToken($token);
        if (!$accessToken || !$accessToken->tokenable) {
            return response()->json(['status' => 0, 'message' => 'Invalid token.'], 401);
        }

        $user = $accessToken->tokenable;
        $subInstituteId = (int) ($user->sub_institute_id ?: $request->input('sub_institute_id'));
        $syear = trim((string) $request->input('syear'));

        if (!$subInstituteId || $syear === '') {
            return response()->json([
                'status' => 0,
                'message' => 'sub_institute_id and syear are required.',
            ], 400);
        }

        return [
            'user_id' => (int) $user->id,
            'sub_institute_id' => $subInstituteId,
            'syear' => $syear,
        ];
    }

    private function baseQuery(array $context): Builder
    {
        return DB::table('task as t')
            ->leftJoin('tbluser as assignee', function ($join) use ($context) {
                $join->on('t.task_allocated_to', '=', 'assignee.id')
                    ->where('assignee.sub_institute_id', $context['sub_institute_id']);
            })
            ->leftJoin('tbluser as allocator', function ($join) use ($context) {
                $join->on('t.task_allocated', '=', 'allocator.id')
                    ->where('allocator.sub_institute_id', $context['sub_institute_id']);
            })
            ->leftJoin('hrms_departments as department', 'department.id', '=', 'assignee.department_id')
            ->where('t.sub_institute_id', $context['sub_institute_id'])
            ->where('t.syear', $context['syear'])
            ->whereNull('t.deleted_at')
            ->select([
                't.id', 't.task_title', 't.task_description', 't.task_attachment',
                't.file_size', 't.file_type', 't.task_date', 't.task_type',
                't.status', 't.reply', 't.observation_point', 't.created_at',
                't.updated_at', 't.task_allocated', 't.task_allocated_to',
                DB::raw("TRIM(CONCAT_WS(' ', assignee.first_name, assignee.middle_name, assignee.last_name)) as assignee_name"),
                DB::raw("TRIM(CONCAT_WS(' ', allocator.first_name, allocator.middle_name, allocator.last_name)) as allocator_name"),
                'department.department as department_name',
            ]);
    }

    private function applyGroup(Builder $query, string $group, array $context): void
    {
        if ($group === 'subordinates') {
            $query->whereIn('t.task_allocated_to', $this->subordinateIds($context));
            return;
        }

        $query->where('t.task_allocated_to', $context['user_id']);
        $today = Carbon::today()->toDateString();

        if ($group === 'today') {
            $query->whereDate('t.task_date', $today);
        } elseif ($group === 'upcoming') {
            $query->whereDate('t.task_date', '>', $today)
                ->whereRaw("UPPER(COALESCE(t.status, 'PENDING')) <> 'COMPLETED'");
        } elseif ($group === 'recent') {
            $query->where('t.updated_at', '>=', now()->subDays(7));
        }
    }

    private function summary(array $context): array
    {
        $query = DB::table('task')
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->where('syear', $context['syear'])
            ->where('task_allocated_to', $context['user_id'])
            ->whereNull('deleted_at');

        return [
            'due_today' => (clone $query)
                ->whereDate('task_date', Carbon::today())
                ->whereRaw("UPPER(COALESCE(status, 'PENDING')) <> 'COMPLETED'")
                ->count(),
            'on_hold' => (clone $query)->whereRaw("UPPER(status) = 'ON HOLD'")->count(),
            'in_progress' => (clone $query)
                ->whereRaw("UPPER(status) IN ('IN-PROGRESS', 'IN PROGRESS', 'IN-PROGRES')")
                ->count(),
            'completed_this_month' => (clone $query)
                ->whereRaw("UPPER(status) = 'COMPLETED'")
                ->whereBetween('updated_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->count(),
        ];
    }

    private function subordinateIds(array $context)
    {
        return DB::table('tbluser')
            ->select('id')
            ->where('employee_id', $context['user_id'])
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->where('status', 1)
            ->whereNull('deleted_at');
    }

    private function taskResource(object $task, bool $includeDetails = false): array
    {
        $status = strtoupper(trim((string) ($task->status ?: 'PENDING')));
        $status = match ($status) {
            'IN PROGRESS', 'IN-PROGRES' => 'IN-PROGRESS',
            default => in_array($status, self::STATUSES, true) ? $status : 'PENDING',
        };

        $resource = [
            'id' => (string) $task->id,
            'title' => (string) $task->task_title,
            'description' => (string) ($task->task_description ?? ''),
            'assignee' => (string) ($task->assignee_name ?: 'Unassigned'),
            'assignee_id' => $task->task_allocated_to ? (string) $task->task_allocated_to : null,
            'owner' => (string) ($task->allocator_name ?: 'Unknown'),
            'owner_id' => $task->task_allocated ? (string) $task->task_allocated : null,
            'department' => (string) ($task->department_name ?? ''),
            'status' => $status,
            'priority' => in_array($task->task_type, self::PRIORITIES, true) ? $task->task_type : null,
            'task_type' => $task->task_type,
            'due_date' => $task->task_date ? Carbon::parse($task->task_date)->toDateString() : null,
            'remarks' => $task->reply,
            'created_at' => $task->created_at ? Carbon::parse($task->created_at)->toIso8601String() : null,
            'updated_at' => $task->updated_at ? Carbon::parse($task->updated_at)->toIso8601String() : null,
        ];

        if ($includeDetails) {
            $resource['observation_point'] = $task->observation_point;
            $resource['attachment'] = $task->task_attachment ? [
                'name' => $task->task_attachment,
                'type' => $task->file_type,
                'size' => $task->file_size,
            ] : null;
        }

        return $resource;
    }
}
