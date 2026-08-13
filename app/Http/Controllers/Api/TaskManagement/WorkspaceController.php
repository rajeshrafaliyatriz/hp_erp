<?php

namespace App\Http\Controllers\Api\TaskManagement;

use App\Http\Controllers\Controller;
use App\Services\TaskManagement\TaskAuditService;
use App\Services\TaskManagement\TaskStatusTransitionService;
use App\Services\TaskManagement\TaskOptionSetService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Stateless API behind the Next.js Task Management > Dashboard screen.
 *
 * Serves the org-wide task workspace over the legacy `task` table: KPI
 * summary, scoped/filtered list, single-task detail, edit, archive and the
 * approve/reject review flow. Additive to the legacy /task routes, which are
 * untouched.
 *
 * The response field names are the contract of types/task-management.ts
 * (WorkspaceTask / WorkspaceResponse) in the new frontend - change them there
 * and here together or not at all.
 */
class WorkspaceController extends Controller
{
    private const STATUSES = ['PENDING', 'IN-PROGRESS', 'ON HOLD', 'COMPLETED'];
    private const PRIORITIES = ['High', 'Medium', 'Low'];

    /** approve_status spellings that count as an approved task. */
    private const APPROVED_VALUES = ['approved', '1', 'yes'];

    public function __construct(
        private readonly TaskAuditService $taskAudit,
        private readonly TaskStatusTransitionService $statusTransitions,
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
            // 'my'/'assigned' are kept for compatibility with early callers;
            // the current frontend sends all|mine|created|team|department|archived.
            'scope' => 'nullable|in:all,my,mine,assigned,created,team,department,archived',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
            'search' => 'nullable|string|max:191',
            'status' => 'nullable|string|max:50',
            'priority' => 'nullable|string|max:50',
            'assignee_id' => 'nullable|integer',
            'project_id' => 'nullable|integer',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 0,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $scope = (string) $request->input('scope', 'all');

        $query = $this->baseQuery($context, $scope);
        $this->applyScope($query, $scope, $context);

        if ($request->filled('search')) {
            $search = $this->escapeLike(trim((string) $request->input('search')));
            $query->where(function ($builder) use ($search) {
                $builder->where('t.task_title', 'like', "%{$search}%")
                    ->orWhere('t.task_description', 'like', "%{$search}%")
                    ->orWhereRaw(
                        "TRIM(CONCAT_WS(' ', assignee.first_name, assignee.middle_name, assignee.last_name)) like ?",
                        ["%{$search}%"]
                    )
                    ->orWhere('proj.name', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $status = (string) $request->input('status');
            // A custom status is a tenant label sitting on one of the four
            // system categories, so a filter value that is not a category is
            // matched against the label - otherwise picking "Awaiting Client"
            // from the dropdown returns nothing.
            if (in_array(strtoupper($status), self::STATUSES, true)) {
                $query->whereRaw('UPPER(t.status) = ?', [strtoupper($status)]);
            } else {
                $query->where('t.status_label', $status);
            }
        }

        if ($request->filled('priority')) {
            $query->where('t.task_type', $request->input('priority'));
        }

        if ($request->filled('assignee_id')) {
            $query->where('t.task_allocated_to', $request->integer('assignee_id'));
        }

        if ($request->filled('project_id')) {
            $query->where('pt.project_id', $request->integer('project_id'));
        }

        if ($request->filled('from')) {
            $query->whereDate('t.task_date', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('t.task_date', '<=', $request->input('to'));
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
                'summary' => $this->summary($context, $scope),
                'pagination' => [
                    'current_page' => $tasks->currentPage(),
                    'last_page' => $tasks->lastPage(),
                    'per_page' => $tasks->perPage(),
                    'total' => $tasks->total(),
                ],
                'filters' => [
                    'statuses' => self::STATUSES,
                    'priorities' => self::PRIORITIES,
                    'users' => $this->assignableUsers($context),
                    // The tenant's full vocabularies (system + custom), for
                    // pickers that want labels rather than raw categories.
                    'status_options' => $this->optionSets->statuses($context['sub_institute_id']),
                    'priority_options' => $this->optionSets->priorities($context['sub_institute_id']),
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

        $task = $this->baseQuery($context, 'all')
            ->where('t.id', $id)
            ->first();

        if (!$task) {
            return response()->json(['status' => 0, 'message' => 'Task not found.'], 404);
        }

        // The drawer shows the thread, so the single-task read carries it.
        $data = $this->resource($task, true);
        $data['comments'] = $this->comments($context, $id);

        return response()->json([
            'status' => 1,
            'message' => 'Workspace task retrieved successfully.',
            'data' => $data,
        ]);
    }

    public function update(Request $request, int $id)
    {
        $context = $this->context($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:10000',
            'assignee_id' => 'required|integer',
            'owner_id' => 'required|integer',
            // Free strings on purpose: system values plus the tenant's own
            // custom statuses/priorities are resolved below, so a new custom
            // entry is usable without touching this controller.
            'status' => 'required|string|max:100',
            'priority' => 'required|string|max:100',
            'due_date' => 'nullable|date',
            'remarks' => 'nullable|string|max:5000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 0,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $task = $this->findTenantTask($context, $id);
        if (!$task) {
            return response()->json(['status' => 0, 'message' => 'Task not found.'], 404);
        }

        if (!$this->canEditTask($context, $task)) {
            return response()->json([
                'status' => 0,
                'message' => 'You can only edit tasks you own, created, or are assigned to.',
            ], 403);
        }

        $resolvedStatus = $this->optionSets->resolveStatus($context['sub_institute_id'], (string) $request->input('status'));
        if ($resolvedStatus === null) {
            return response()->json(['status' => 0, 'message' => 'Unknown status for this organisation.'], 422);
        }

        $resolvedPriority = $this->optionSets->resolvePriority($context['sub_institute_id'], (string) $request->input('priority'));
        if ($resolvedPriority === null) {
            return response()->json(['status' => 0, 'message' => 'Unknown priority for this organisation.'], 422);
        }

        if (!$this->statusTransitions->allows($task->status, $resolvedStatus['status'])) {
            return response()->json([
                'status' => 0,
                'message' => $this->statusTransitions->message($task->status, $resolvedStatus['status']),
            ], 422);
        }

        DB::table('task')->where('id', $id)->update([
            'task_title' => $request->input('title'),
            'task_description' => $request->input('description'),
            'task_allocated_to' => $request->integer('assignee_id'),
            'task_allocated' => $request->integer('owner_id'),
            'task_type' => $resolvedPriority,
            'task_date' => $request->input('due_date') ?: $task->task_date,
            'reply' => $request->filled('remarks') ? $request->input('remarks') : $task->reply,
            'updated_by' => $context['user_id'],
            'updated_at' => now(),
        ]);

        // T-01. Status moves through its owner, not inline with the other fields.
        // The bulk update above carries everything that is NOT a status concern.
        $move = app(\App\Services\TaskManagement\TaskStatusWriter::class)->moveTo(
            (int) $id,
            $resolvedStatus['status'],
            (int) $context['sub_institute_id'],
            (int) $context['user_id']
        );
        if (!$move['ok']) {
            return response()->json(['status' => 0, 'message' => $move['reason']], 422);
        }

        $this->taskAudit->taskChanged($id, 'workspace_updated', (array) $task, $context['user_id']);

        $fresh = $this->baseQuery($context, 'all')->where('t.id', $id)->first();

        return response()->json([
            'status' => 1,
            'message' => 'Task updated successfully.',
            'data' => $this->resource($fresh, true),
        ]);
    }

    public function destroy(Request $request, int $id)
    {
        $context = $this->context($request);
        if (!is_array($context)) {
            return $context;
        }

        $task = $this->findTenantTask($context, $id);
        if (!$task) {
            return response()->json(['status' => 0, 'message' => 'Task not found.'], 404);
        }

        // Archive, not erase: deleted_at hides it from every list while the
        // row (and its audit trail) survives.
        DB::table('task')->where('id', $id)->update([
            'deleted_by' => $context['user_id'],
            'deleted_at' => now(),
            'updated_at' => now(),
        ]);

        $this->taskAudit->taskChanged($id, 'archived', (array) $task, $context['user_id']);

        return response()->json(['status' => 1, 'message' => 'Task archived successfully.']);
    }

    public function approve(Request $request, int $id)
    {
        $context = $this->context($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            'decision' => 'required|in:approve,reject',
            'remarks' => 'nullable|string|max:5000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 0,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $task = $this->findTenantTask($context, $id);
        if (!$task) {
            return response()->json(['status' => 0, 'message' => 'Task not found.'], 404);
        }

        if (strtoupper((string) $task->status) !== 'COMPLETED') {
            return response()->json([
                'status' => 0,
                'message' => 'Only completed tasks can be reviewed.',
            ], 422);
        }

        $approving = $request->input('decision') === 'approve';

        DB::table('task')->where('id', $id)->update([
            'approve_status' => $approving ? 'Approved' : 'Rejected',
            'approved_by' => $context['user_id'],
            'approved_on' => now(),
            'approve_remarks' => $request->input('remarks'),
            // A rejected task goes back into the working pile: ON HOLD is how
            // the board and the old screens represent "rework required".
            'status' => $approving ? $task->status : 'ON HOLD',
            'updated_by' => $context['user_id'],
            'updated_at' => now(),
        ]);

        $this->taskAudit->taskChanged($id, $approving ? 'approved' : 'rejected', (array) $task, $context['user_id']);

        return response()->json([
            'status' => 1,
            'message' => $approving ? 'Task approved.' : 'Task sent back for rework.',
        ]);
    }

    public function comment(Request $request, int $id)
    {
        $context = $this->context($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            'content' => 'required|string|max:5000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 0,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        if (!$this->findTenantTask($context, $id)) {
            return response()->json(['status' => 0, 'message' => 'Task not found.'], 404);
        }

        $commentId = DB::table('task_management_comments')->insertGetId([
            'sub_institute_id' => $context['sub_institute_id'],
            'task_id' => $id,
            'author_id' => $context['user_id'],
            'content' => $request->input('content'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => 1,
            'message' => 'Comment added.',
            'data' => ['id' => (string) $commentId],
        ], 201);
    }

    /** The comment thread, newest first, shaped for WorkspaceTask.comments. */
    private function comments(array $context, int $taskId): array
    {
        return DB::table('task_management_comments as c')
            ->leftJoin('tbluser as author', 'author.id', '=', 'c.author_id')
            ->where('c.task_id', $taskId)
            ->where('c.sub_institute_id', $context['sub_institute_id'])
            ->orderByDesc('c.id')
            ->limit(100)
            ->selectRaw("c.id, c.content, c.created_at,
                TRIM(CONCAT_WS(' ', author.first_name, author.middle_name, author.last_name)) as author_name")
            ->get()
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'author' => (string) ($row->author_name ?: 'Unknown'),
                'content' => (string) $row->content,
                'created_at' => (string) $row->created_at,
            ])
            ->all();
    }

    /* ------------------------------------------------------------------ */


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
            'department_id' => (int) ($user->department_id ?? 0),
            // User-first, NOT request-first. Taking sub_institute_id from the
            // request when present let any authenticated user read another
            // organisation's data by changing one query parameter. The token's
            // own user decides the tenant; the request is only a fallback for
            // accounts that carry no tenant of their own.
            'sub_institute_id' => (int) ($user->sub_institute_id ?: $request->input('sub_institute_id', 0)),
            'syear' => $syear,
        ];
    }

    /**
     * Who may edit a task from the workspace.
     *
     * Employees edit their own work - assigned to them, allocated by them, or
     * created by them - mirroring the scoping MyTasksController already does.
     * Anyone above the Employee profile (Admin / HR) manages the whole tenant,
     * which is what the Dashboard is for. task.update is deliberately not a
     * privileged ability, so this check is what stops an employee editing a
     * colleague's task through the API.
     */
    private function canEditTask(array $context, object $task): bool
    {
        $userId = $context['user_id'];

        if ((int) $task->task_allocated_to === $userId
            || (int) $task->task_allocated === $userId
            || (int) $task->created_by === $userId) {
            return true;
        }

        return !$this->isEmployeeProfile($userId);
    }

    /** True when the user sits on the Employee profile (the non-managing role). */
    private function isEmployeeProfile(int $userId): bool
    {
        $profile = DB::table('tbluser')
            ->leftJoin('tbluserprofilemaster as p', 'p.id', '=', 'tbluser.user_profile_id')
            ->where('tbluser.id', $userId)
            ->value('p.name');

        return strcasecmp(trim((string) $profile), 'Employee') === 0;
    }

    private function findTenantTask(array $context, int $id): ?object
    {
        return DB::table('task')
            ->where('id', $id)
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->where('SYEAR', $context['syear'])
            ->whereNull('deleted_at')
            ->first();
    }

    /**
     * The list query. `$scope` decides soft-delete handling: 'archived' shows
     * only trashed rows, everything else hides them.
     */
    private function baseQuery(array $context, string $scope)
    {
        $query = DB::table('task as t')
            ->leftJoin('tbluser as allocator', 'allocator.id', '=', 't.task_allocated')
            ->leftJoin('tbluser as assignee', 'assignee.id', '=', 't.task_allocated_to')
            ->leftJoin('hrms_departments as department', 'department.id', '=', 'assignee.department_id')
            // One project per task for display. The link table allows several,
            // so joining it raw would duplicate task rows in the list.
            ->leftJoin(DB::raw('(SELECT task_id, MIN(project_id) AS project_id FROM task_management_project_tasks GROUP BY task_id) pt'), 'pt.task_id', '=', 't.id')
            ->leftJoin('task_management_projects as proj', 'proj.id', '=', 'pt.project_id')
            ->where('t.sub_institute_id', $context['sub_institute_id'])
            ->where('t.SYEAR', $context['syear'])
            ->selectRaw("t.id, t.task_title, t.task_description, t.task_type, t.task_date, t.status,
                t.task_allocated, t.task_allocated_to, t.created_by, t.created_at, t.updated_at,
                t.reply, t.approve_status, t.approved_on, t.task_attachment, t.file_size, t.file_type, t.status_label,
                pt.project_id, proj.name as project_name, department.department as department_name,
                TRIM(CONCAT_WS(' ', allocator.first_name, allocator.middle_name, allocator.last_name)) as allocator_name,
                TRIM(CONCAT_WS(' ', assignee.first_name, assignee.middle_name, assignee.last_name)) as assignee_name");

        if ($scope === 'archived') {
            $query->whereNotNull('t.deleted_at');
        } else {
            $query->whereNull('t.deleted_at');
        }

        return $query;
    }

    private function applyScope($query, string $scope, array $context): void
    {
        // 'mine' is the current frontend's spelling of 'my'.
        if ($scope === 'my' || $scope === 'mine') {
            $query->where('t.task_allocated_to', $context['user_id']);
        } elseif ($scope === 'assigned') {
            $query->where('t.task_allocated', $context['user_id']);
        } elseif ($scope === 'created') {
            $query->where('t.created_by', $context['user_id']);
        } elseif ($scope === 'team') {
            // The user plus everyone who reports to them (tbluser.employee_id
            // holds the supervisor id - same convention as MyTasksController).
            $query->where(function ($builder) use ($context) {
                $builder->where('t.task_allocated_to', $context['user_id'])
                    ->orWhereIn('t.task_allocated_to', DB::table('tbluser')
                        ->select('id')
                        ->where('employee_id', $context['user_id'])
                        ->where('sub_institute_id', $context['sub_institute_id'])
                        ->where('status', 1)
                        ->whereNull('deleted_at'));
            });
        } elseif ($scope === 'department') {
            if ($context['department_id'] > 0) {
                $query->where('assignee.department_id', $context['department_id']);
            }
        }
        // 'all' and 'archived' add no assignment filter.
    }

    /**
     * The four KPI cards, in one round trip.
     *
     * Conditional aggregates instead of four COUNT queries: the database is
     * remote and each round trip costs ~300ms, so per-count queries would put
     * more than a second on every dashboard load. Respects the scope (the
     * cards say "across this scope") but not the list filters - filtering the
     * list to COMPLETED should not zero the Active card.
     */
    private function summary(array $context, string $scope): array
    {
        // Archived is a trash view; its KPIs would be meaningless.
        $effectiveScope = $scope === 'archived' ? 'all' : $scope;

        $query = DB::table('task as t')
            ->leftJoin('tbluser as assignee', 'assignee.id', '=', 't.task_allocated_to')
            ->where('t.sub_institute_id', $context['sub_institute_id'])
            ->where('t.SYEAR', $context['syear'])
            ->whereNull('t.deleted_at');

        $this->applyScope($query, $effectiveScope, $context);

        $approvedList = "'" . implode("','", self::APPROVED_VALUES) . "'";
        $today = Carbon::today()->toDateString();
        $monthStart = now()->startOfMonth()->toDateTimeString();
        $monthEnd = now()->endOfMonth()->toDateTimeString();

        $row = $query->selectRaw(
            "SUM(CASE WHEN UPPER(COALESCE(t.status,'PENDING')) <> 'COMPLETED' THEN 1 ELSE 0 END) as active,
             SUM(CASE WHEN UPPER(COALESCE(t.status,'')) = 'COMPLETED'
                       AND LOWER(COALESCE(t.approve_status,'')) NOT IN ({$approvedList}) THEN 1 ELSE 0 END) as pending_review,
             SUM(CASE WHEN UPPER(COALESCE(t.status,'')) = 'ON HOLD'
                       OR (t.task_date < ? AND UPPER(COALESCE(t.status,'PENDING')) <> 'COMPLETED') THEN 1 ELSE 0 END) as blocked_overdue,
             SUM(CASE WHEN UPPER(COALESCE(t.status,'')) = 'COMPLETED'
                       AND t.updated_at BETWEEN ? AND ? THEN 1 ELSE 0 END) as completed_this_month",
            [$today, $monthStart, $monthEnd]
        )->first();

        return [
            'active' => (int) ($row->active ?? 0),
            'pending_review' => (int) ($row->pending_review ?? 0),
            'blocked_overdue' => (int) ($row->blocked_overdue ?? 0),
            'completed_this_month' => (int) ($row->completed_this_month ?? 0),
        ];
    }

    /** Active users of the tenant, for the assignee filter dropdown. */
    private function assignableUsers(array $context): array
    {
        return DB::table('tbluser')
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->where('status', 1)
            ->whereNull('deleted_at')
            ->orderBy('first_name')
            ->limit(200)
            ->selectRaw("id, TRIM(CONCAT_WS(' ', first_name, middle_name, last_name)) as name")
            ->get()
            ->map(fn ($user) => ['id' => (string) $user->id, 'name' => (string) $user->name])
            ->all();
    }

    /** Shapes a row to the frontend's WorkspaceTask type - names must match it. */
    private function resource(object $task, bool $includeMeta = false): array
    {
        $status = strtoupper(trim((string) ($task->status ?: 'PENDING')));
        $status = match ($status) {
            'IN PROGRESS', 'IN-PROGRES' => 'IN-PROGRESS',
            default => in_array($status, self::STATUSES, true) ? $status : 'PENDING',
        };

        $data = [
            'id' => (string) $task->id,
            'title' => (string) ($task->task_title ?? ''),
            'description' => (string) ($task->task_description ?? ''),
            'project_id' => $task->project_id ? (string) $task->project_id : null,
            'project' => (string) ($task->project_name ?? ''),
            'department' => (string) ($task->department_name ?? ''),
            'assignee_id' => $task->task_allocated_to ? (string) $task->task_allocated_to : null,
            'assignee' => (string) ($task->assignee_name ?: 'Unassigned'),
            'owner_id' => $task->task_allocated ? (string) $task->task_allocated : null,
            'owner' => (string) ($task->allocator_name ?: 'Unknown'),
            'status' => $status,
            // The tenant's display label for the status; the category above
            // stays what boards and summaries key off.
            'status_label' => $task->status_label ?? null,
            // Custom priorities pass through; the FE renders unknowns as text.
            'priority' => $task->task_type ?: null,
            'due_date' => $task->task_date ? Carbon::parse($task->task_date)->toDateString() : null,
            'remarks' => $task->reply ?? null,
            'approved' => in_array(strtolower(trim((string) ($task->approve_status ?? ''))), self::APPROVED_VALUES, true),
            'approved_on' => $task->approved_on ?? null,
            'created_at' => $task->created_at ? Carbon::parse($task->created_at)->toIso8601String() : null,
            'updated_at' => $task->updated_at ? Carbon::parse($task->updated_at)->toIso8601String() : null,
            'attachment' => null,
        ];

        if ($includeMeta) {
            $data['attachment'] = $task->task_attachment ? [
                'name' => $task->task_attachment,
                'type' => $task->file_type ?? null,
                'size' => $task->file_size ?? null,
            ] : null;
        }

        return $data;
    }
}
