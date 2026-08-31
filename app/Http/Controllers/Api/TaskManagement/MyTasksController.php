<?php

namespace App\Http\Controllers\Api\TaskManagement;

use App\Http\Controllers\Controller;
use App\Services\TaskManagement\TaskAuditService;
use App\Services\TaskManagement\TaskDependencyResolutionService;
use App\Services\TaskManagement\TaskStatusTransitionService;
use App\Services\TaskManagement\TaskOptionSetService;
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

    public function __construct(
        private readonly TaskAuditService $taskAudit,
        private readonly TaskStatusTransitionService $statusTransitions,
        private readonly TaskDependencyResolutionService $dependencyResolution,
        private readonly TaskOptionSetService $optionSets
    ) {
    }

    public function index(Request $request)
    {
        $context = $this->context($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            'group' => 'nullable|in:all,today,upcoming,recent,subordinates',
            'search' => 'nullable|string|max:191',
            // A system category or a tenant's custom status/priority label;
            // an unknown value simply matches nothing rather than 422-ing.
            'status' => 'nullable|string|max:100',
            'priority' => 'nullable|string|max:100',
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
            $search = $this->escapeLike(trim((string) $request->input('search')));
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
            $status = (string) $request->input('status');
            // Custom statuses are labels on a system category - match the
            // label when the value is not one of the four categories.
            if (in_array(strtoupper($status), self::STATUSES, true)) {
                $query->whereRaw('UPPER(t.status) = ?', [strtoupper($status)]);
            } else {
                $query->where('t.status_label', $status);
            }
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
                    // Same vocabularies the Dashboard ships, so the My Tasks
                    // pickers can offer the tenant's custom labels instead of
                    // only the four system categories.
                    'status_options' => $this->optionSets->statuses($context['sub_institute_id']),
                    'priority_options' => $this->optionSets->priorities($context['sub_institute_id']),
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
            // System categories plus the tenant's custom statuses, resolved below.
            'status' => 'required|string|max:100',
            'remarks' => 'required|string|max:5000',
            'delay_category' => 'nullable|in:Dependency,Resource,Scope,Technical,Approval,External,Other',
            'delay_reason' => 'nullable|string|max:5000',
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

        $resolved = $this->optionSets->resolveStatus($context['sub_institute_id'], (string) $request->input('status'));
        if ($resolved === null) {
            return response()->json(['status' => 0, 'message' => 'Unknown status for this organisation.'], 422);
        }

        if (!$this->statusTransitions->allows($task->status, $resolved['status'])) {
            return response()->json([
                'status' => 0,
                'message' => $this->statusTransitions->message($task->status, $resolved['status']),
            ], 422);
        }

        $before = (array) $task;
        // T-01. The status, its label and the delay fields belong to
        // TaskStatusWriter - it owns the invariant that they move together, and
        // it is the only place that knows both sides of the transition well
        // enough to emit task.status_changed honestly.
        $move = app(\App\Services\TaskManagement\TaskStatusWriter::class)->moveTo(
            (int) $id,
            $resolved['status'],
            (int) $context['sub_institute_id'],
            (int) $context['user_id'],
            [
                'delay_category' => $request->input('delay_category'),
                'delay_reason'   => $request->input('delay_reason'),
                'remarks'        => $request->input('remarks'),
            ]
        );
        if (!$move['ok']) {
            return response()->json(['status' => 0, 'message' => $move['reason']], 422);
        }

        // `reply` is not a status field and stays here.
        DB::table('task')->where('id', $id)->update([
            'reply' => $request->input('remarks'),
        ]);
        $this->taskAudit->taskChanged($id, 'status_changed', $before, $context['user_id']);
        if ($resolved['status'] === 'COMPLETED') $this->dependencyResolution->resolveAfterCompletion($id, $context['user_id']);

        // OUT-OF-ORDER WORK IS REPORTED, NOT REFUSED. The dependency graph has
        // never been consulted on a status change, so starting or finishing a
        // task ahead of its predecessor happened in complete silence. The move
        // still succeeds - the caller is simply told what it stepped over.
        $warnings = [];
        if (in_array($resolved['status'], ['IN-PROGRESS', 'COMPLETED'], true)) {
            $open = $this->dependencyResolution->openPredecessors($id, (int) $context['sub_institute_id'], (string) $context['syear']);
            foreach ($open as $predecessor) {
                $warnings[] = [
                    'code' => 'predecessor_open',
                    'message' => 'This task depends on "' . $predecessor['title'] . '", which is still ' . $predecessor['status'] . '.',
                    'task_id' => $predecessor['id'],
                ];
            }
        }

        return response()->json([
            'status' => 1,
            'message' => 'Task status updated successfully.',
            'data' => [
                'id' => (string) $id,
                'status' => $resolved['status'],
                'status_label' => $resolved['label'],
                'remarks' => $request->input('remarks'),
            ],
            'warnings' => $warnings,
        ]);
    }


    /**
     * Escape LIKE wildcards in a user-supplied search term.
     *
     * Without this, searching for "%" matches every row (the audit measured
     * 417 of 417) and "_" matches any single character - the term stops being
     * a search and becomes a table dump.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }

    private function context(Request $request)
    {
        $token = trim((string) ($request->bearerToken() ?: $request->input('token')));
        if ($token === '') {
            return response()->json(['status' => 0, 'message' => 'Token not provided.'], 401);
        }

        $accessToken = PersonalAccessToken::findToken($token);
        if (!$accessToken || !$accessToken->tokenable) {
            return response()->json(['status' => 0, 'message' => 'Invalid token.'], 401);
        }

        $user = $accessToken->tokenable;
        // G-SEC-28. The request fallback is GONE. It existed for accounts whose
        // own sub_institute_id is NULL or 0 - measured: 0 of 401. It compensated
        // for a condition that does not occur.
        //
        // If identity supplies no tenant this now yields 0 and the guard below
        // REFUSES the request. Failing closed is correct and is the intended
        // failure mode: a refusal, not a read of whatever the caller asked for.
        //
        // The smoke suite asserts no account has a NULL or zero tenant. If that
        // ever fails, this removal was premature - a decision to re-take, not a
        // bug to patch.
        $subInstituteId = (int) $user->sub_institute_id;
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
                't.file_size', 't.file_type', 't.task_date', 't.planned_start_date', 't.estimated_hours', 't.actual_hours', 't.remaining_hours', 't.acceptance_criteria', 't.task_type',
                't.status', 't.status_label', 't.reply', 't.delay_category', 't.delay_reason', 't.observation_point', 't.created_at',
                // THE APPROVAL DECISION, WHICH THE ASSIGNEE COULD NOT SEE.
                // A rejected task silently flipped to ON HOLD with no reason and
                // no badge — the person whose work was sent back was the one
                // person not told. approve_remarks is the reason they are owed.
                't.approve_status', 't.approved_on', 't.approve_remarks',
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
            'status_label' => $task->status_label ?? null,
            // Lowercased on the way out: the column held three spellings before
            // it was normalised, and a client that switches on the value should
            // not have to know which era a row was written in.
            'approve_status' => $task->approve_status
                ? strtolower(trim((string) $task->approve_status))
                : null,
            'approved_on' => $task->approved_on ?? null,
            'approve_remarks' => $task->approve_remarks ?: null,
            'priority' => $task->task_type ?: null,
            'task_type' => $task->task_type,
            'estimated_hours' => $task->estimated_hours !== null ? (float) $task->estimated_hours : null,
            'actual_hours' => $task->actual_hours !== null ? (float) $task->actual_hours : null,
            'remaining_hours' => $task->remaining_hours !== null ? (float) $task->remaining_hours : null,
            'acceptance_criteria' => json_decode((string) ($task->acceptance_criteria ?? '[]'), true) ?: [],
            'planned_start_date' => $task->planned_start_date ? Carbon::parse($task->planned_start_date)->toDateString() : null,
            'due_date' => $task->task_date ? Carbon::parse($task->task_date)->toDateString() : null,
            'delay_category' => $task->delay_category, 'delay_reason' => $task->delay_reason,
            'remarks' => $task->reply,
            'created_at' => $task->created_at ? Carbon::parse($task->created_at)->toIso8601String() : null,
            'updated_at' => $task->updated_at ? Carbon::parse($task->updated_at)->toIso8601String() : null,
        ];

        if ($includeDetails) {
            $resource['observation_point'] = $task->observation_point;

            /*
             * `has_download` IS NOT `name !== null`.
             *
             * The bytes for this column live in one of two places depending on
             * which path uploaded them: task creation writes to DigitalOcean
             * Spaces, while replaceTaskAttachment writes to the local disk via
             * the versions table. The column stores a bare filename either way,
             * so it cannot tell you which store holds the file.
             *
             * Only a row with a matching version record can be served, because
             * only that record carries a real path. Everything else is shown as
             * a name with the download disabled - which is honest, where a dead
             * link that 404s is not.
             */
            $resource['attachment'] = $task->task_attachment ? [
                'name' => $task->task_attachment,
                'type' => $task->file_type,
                'size' => $task->file_size,
                // The version number, not a boolean: the download route is
                // keyed by version, and making the client fetch the list first
                // just to learn it would be a second round trip for one integer.
                // Null means the bytes are not reachable from here.
                'download_version' => DB::table('task_management_attachment_versions')
                    ->where('task_id', $task->id)->max('version'),
            ] : null;
        }

        return $resource;
    }
}
