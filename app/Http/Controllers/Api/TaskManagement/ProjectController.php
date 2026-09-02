<?php

namespace App\Http\Controllers\Api\TaskManagement;

use App\Http\Controllers\Controller;
use App\Services\TaskManagement\ProjectProgress;
use App\Services\TaskManagement\WorkstreamRollup;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

class ProjectController extends Controller
{
    private const STATUSES = ['PLANNING', 'IN PROGRESS', 'AT RISK', 'COMPLETED', 'ARCHIVED'];
    private const PRIORITIES = ['High', 'Medium', 'Low'];
    private const CATEGORIES = ['it', 'healthcare', 'finance', 'real_estate', 'rd', 'marketing'];
    private const MEMBER_ROLES = ['MEMBER', 'LEAD', 'SPONSOR'];

    public function index(Request $request)
    {
        $context = $this->context($request);
        if (!is_array($context)) return $context;

        $validator = Validator::make($request->all(), [
            'search' => 'nullable|string|max:191',
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
            'include_archived' => 'nullable|boolean',
        ]);
        if ($validator->fails()) return $this->validationError($validator);

        $query = $this->projectQuery($context);
        if (!$request->boolean('include_archived')) $query->whereNull('p.archived_at');
        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(fn (Builder $q) => $q
                ->where('p.name', 'like', "%{$search}%")
                ->orWhere('p.code', 'like', "%{$search}%")
                ->orWhereRaw("CONCAT_WS(' ', manager.first_name, manager.middle_name, manager.last_name) like ?", ["%{$search}%"]));
        }
        if ($request->filled('status')) $query->where('p.status', $request->input('status'));

        $projects = $query->orderByDesc('p.updated_at')->paginate((int) $request->input('per_page', 12));

        // One rollup for the whole page, not one per project — three queries
        // whatever the page size, so paginating cannot turn this into an N+1.
        $progress = $this->progressFor($context, $projects->getCollection()->pluck('id')->all());
        $projects->getCollection()->transform(fn ($project) => $this->resource($project, $progress[(int) $project->id] ?? null));

        return response()->json(['status' => 1, 'message' => 'Projects retrieved successfully.', 'data' => [
            'projects' => $projects->items(),
            'pagination' => [
                'current_page' => $projects->currentPage(), 'last_page' => $projects->lastPage(),
                'per_page' => $projects->perPage(), 'total' => $projects->total(),
            ],
            'filters' => ['statuses' => self::STATUSES, 'priorities' => self::PRIORITIES],
        ]]);
    }

    public function store(Request $request)
    {
        $context = $this->context($request);
        if (!is_array($context)) return $context;
        $validator = $this->projectValidator($request, $context);
        if ($validator->fails()) return $this->validationError($validator);

        $id = DB::transaction(function () use ($request, $context) {
            $id = DB::table('task_management_projects')->insertGetId($this->projectPayload($request, $context) + [
                'code' => $this->nextCode($context),
                'created_by' => $context['user_id'],
                'created_at' => now(),
            ]);
            $members = array_values(array_unique(array_filter(array_merge(
                $request->input('member_ids', []),
                [$request->input('manager_id'), $request->input('sponsor_id')]
            ))));
            $this->syncMembers($id, $members, $context['user_id']);
            $this->syncDepartments($id, $request, $context['user_id']);
            return $id;
        });

        return response()->json(['status' => 1, 'message' => 'Project created successfully.', 'data' => $this->findProject($context, $id)], 201);
    }

    public function show(Request $request, int $id)
    {
        $context = $this->context($request);
        if (!is_array($context)) return $context;
        $project = $this->findProject($context, $id, true);
        if (!$project) return response()->json(['status' => 0, 'message' => 'Project not found.'], 404);
        return response()->json(['status' => 1, 'message' => 'Project retrieved successfully.', 'data' => $project]);
    }

    public function update(Request $request, int $id)
    {
        $context = $this->context($request);
        if (!is_array($context)) return $context;
        if (!$this->canManage($context, $id)) return response()->json(['status' => 0, 'message' => 'You cannot manage this project.'], 403);
        $validator = $this->projectValidator($request, $context);
        if ($validator->fails()) return $this->validationError($validator);

        DB::transaction(function () use ($request, $context, $id) {
            DB::table('task_management_projects')->where('id', $id)->update(
                $this->projectPayload($request, $context) + ['updated_by' => $context['user_id'], 'updated_at' => now()]
            );
            $members = array_values(array_unique(array_filter(array_merge(
                $request->input('member_ids', []),
                [$request->input('manager_id'), $request->input('sponsor_id')]
            ))));
            $this->syncMembers($id, $members, $context['user_id']);
            $this->syncDepartments($id, $request, $context['user_id']);
        });
        return response()->json(['status' => 1, 'message' => 'Project updated successfully.', 'data' => $this->findProject($context, $id, true)]);
    }

    public function archive(Request $request, int $id)
    {
        $context = $this->context($request);
        if (!is_array($context)) return $context;
        if (!$this->canManage($context, $id)) return response()->json(['status' => 0, 'message' => 'You cannot archive this project.'], 403);
        DB::table('task_management_projects')->where('id', $id)->update([
            'status' => 'ARCHIVED', 'archived_at' => now(), 'archived_by' => $context['user_id'],
            'updated_by' => $context['user_id'], 'updated_at' => now(),
        ]);
        return response()->json(['status' => 1, 'message' => 'Project archived successfully.']);
    }

    public function options(Request $request)
    {
        $context = $this->context($request);
        if (!is_array($context)) return $context;
        $users = DB::table('tbluser')->where('sub_institute_id', $context['sub_institute_id'])
            ->where('status', 1)->whereNull('deleted_at')->orderBy('first_name')
            ->select('id', DB::raw("TRIM(CONCAT_WS(' ', first_name, middle_name, last_name)) as name"))->get();
        $departments = DB::table('hrms_departments')->where('sub_institute_id', $context['sub_institute_id'])
            ->orderBy('department')->select('id', 'department as name')->get();
        $tasks = DB::table('task as t')
            ->leftJoin('tbluser as assignee', 'assignee.id', '=', 't.task_allocated_to')
            ->where('t.sub_institute_id', $context['sub_institute_id'])
            ->where('t.syear', $context['syear'])->whereNull('t.deleted_at')
            ->orderByDesc('t.id')->limit(200)
            ->select('t.id', 't.task_title as title', 't.status', 'assignee.department_id')->get();
        return response()->json(['status' => 1, 'message' => 'Project options retrieved successfully.', 'data' => [
            'users' => $users, 'departments' => $departments, 'tasks' => $tasks,
            'categories' => self::CATEGORIES, 'statuses' => self::STATUSES, 'priorities' => self::PRIORITIES,
        ]]);
    }

    public function syncProjectMembers(Request $request, int $id)
    {
        $context = $this->context($request);
        if (!is_array($context)) return $context;
        if (!$this->canManage($context, $id)) return response()->json(['status' => 0, 'message' => 'You cannot manage this team.'], 403);
        $validator = Validator::make($request->all(), ['member_ids' => 'required|array', 'member_ids.*' => 'integer']);
        if ($validator->fails()) return $this->validationError($validator);
        $this->validateTenantUsers($request->input('member_ids'), $context);
        $this->syncMembers($id, $request->input('member_ids'), $context['user_id']);
        $memberIds = array_values(array_unique(array_map('intval', $request->input('member_ids'))));

        /*
         * REMOVING SOMEONE FROM THE TEAM REMOVES THEM FROM ITS WORKSTREAMS TOO.
         *
         * Clearing the owner has always happened here. The contributors table is
         * new and needs the same treatment in the same transaction — otherwise a
         * person taken off the project keeps appearing as a contributor on its
         * workstreams, which is both wrong on screen and a stale reference to
         * somebody who may have left the organisation entirely.
         */
        DB::transaction(function () use ($id, $memberIds, $context) {
            DB::table('task_management_workstreams')->where('project_id', $id)
                ->when($memberIds, fn ($query) => $query->whereNotIn('owner_id', $memberIds))
                ->when(!$memberIds, fn ($query) => $query->whereNotNull('owner_id'))
                ->update(['owner_id' => null, 'updated_by' => $context['user_id'], 'updated_at' => now()]);

            DB::table('task_management_workstream_members')
                ->whereIn('workstream_id', function ($sub) use ($id) {
                    $sub->select('id')->from('task_management_workstreams')->where('project_id', $id);
                })
                ->when($memberIds, fn ($query) => $query->whereNotIn('user_id', $memberIds))
                ->delete();
        });

        return response()->json(['status' => 1, 'message' => 'Project team updated successfully.', 'data' => $this->members($id)]);
    }

    /*
     * WORKSTREAM CRUD MOVED TO WorkstreamController (2026-09-01).
     *
     * storeWorkstream / updateWorkstream / destroyWorkstream lived here, with no
     * GET of any kind alongside them, which is why every workstream dropdown in
     * the product fetched a whole project record to obtain a list of names. The
     * three URLs are unchanged; only the controller behind them moved.
     *
     * The old destroy was a bare delete() with no checks: it returned 200 even
     * when it removed nothing, took sub-workstreams with it silently, and left
     * task_management_milestones.workstream_id pointing at a row that no longer
     * existed — that column has no foreign key, so nothing caught it. The
     * replacement refuses while children exist and releases milestones in the
     * same transaction.
     */

    /**
     * Candidate tasks to link, with whether each is already spoken for.
     *
     * ── WHY THIS IS NOT `options()` ────────────────────────────────────────
     *
     * The Tasks tab used to render `options()`'s task list — the tenant's 200
     * most recent tasks, with no project filter and no search — as a permanent
     * checkbox grid under the project's OWN tasks. Most of what it offered
     * belonged to other projects, and it was the larger half of the tab.
     *
     * Linking belongs in a picker somebody opens deliberately, so it is its own
     * endpoint: `options()` is fetched on every list render and has no business
     * carrying a search parameter or a per-task subquery.
     *
     * ── ALREADY-LINKED IS REPORTED, NOT HIDDEN ─────────────────────────────
     *
     * A task can be linked to two projects. Hiding those would make a task
     * somebody is looking for simply absent; the workspace list already suffers
     * from double-linked tasks being masked by a MIN(project_id). So each
     * candidate says which project holds it, and the client can show
     * "already in PRJ-00003" rather than silently creating a second link.
     */
    public function linkableTasks(Request $request, int $id)
    {
        $context = $this->context($request);
        if (!is_array($context)) return $context;

        $project = DB::table('task_management_projects')
            ->where('id', $id)
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->where('syear', $context['syear'])
            ->first(['id']);

        if (!$project) return response()->json(['status' => 0, 'message' => 'Project not found.'], 404);

        $search = trim((string) $request->input('search', ''));

        $rows = DB::table('task as t')
            ->leftJoin('tbluser as assignee', 'assignee.id', '=', 't.task_allocated_to')
            // The project each task is ALREADY linked to, if any.
            ->leftJoin('task_management_project_tasks as pt', 'pt.task_id', '=', 't.id')
            ->leftJoin('task_management_projects as p', 'p.id', '=', 'pt.project_id')
            ->where('t.sub_institute_id', $context['sub_institute_id'])
            ->where('t.syear', $context['syear'])
            ->whereNull('t.deleted_at')
            // Not already on THIS project — those are in the list above the picker.
            ->whereNotIn('t.id', function ($sub) use ($id) {
                $sub->select('task_id')->from('task_management_project_tasks')->where('project_id', $id);
            })
            ->when($search !== '', fn ($q) => $q->where('t.task_title', 'like', '%' . $search . '%'))
            ->orderByDesc('t.id')
            ->limit(50)
            ->get([
                't.id', 't.task_title as title', 't.status', 't.task_date as due_date',
                'p.id as linked_project_id', 'p.code as linked_project_code', 'p.name as linked_project_name',
                DB::raw("TRIM(CONCAT_WS(' ', assignee.first_name, assignee.middle_name, assignee.last_name)) as assignee"),
            ]);

        return response()->json(['status' => 1, 'message' => 'Success', 'data' => [
            'tasks' => $rows->map(fn ($t) => [
                'id' => (string) $t->id,
                'title' => $t->title,
                'status' => $t->status,
                'due_date' => $t->due_date,
                'assignee' => $t->assignee ?: null,
                'already_linked_project_id' => $t->linked_project_id ? (string) $t->linked_project_id : null,
                // The NAME, on its own. The code travels beside it rather than
                // glued in front, so a screen can show "already in G2G" and keep
                // PRJ-00001 as a reference the reader can ask for.
                'already_linked_project' => $t->linked_project_id ? $t->linked_project_name : null,
                'already_linked_project_code' => $t->linked_project_code ?: null,
            ])->values(),
            // Said out loud rather than letting a capped list look complete.
            'capped' => $rows->count() >= 50,
        ]]);
    }

    /**
     * @deprecated 2026-09-01 — use attachTask / detachTask instead.
     *
     * THIS REPLACES A PROJECT'S ENTIRE TASK LIST. Its first act is
     * `DELETE FROM task_management_project_tasks WHERE project_id = ?`, so it
     * writes whatever ids the caller happened to be holding and unlinks
     * everything else. Two people editing one project, or one stale tab, silently
     * loses work.
     *
     * Its only browser caller was the project drawer's "Save Linked Tasks"
     * button, which is gone. It is NOT deleted here: there may be callers this
     * codebase cannot see, and silently changing a write endpoint's meaning is
     * worse than carrying a deprecated one. Remove it once logs show no hits.
     */
    public function syncTasks(Request $request, int $id)
    {
        $context = $this->context($request);
        if (!is_array($context)) return $context;
        if (!$this->canManage($context, $id)) return response()->json(['status' => 0, 'message' => 'You cannot link tasks.'], 403);
        $validator = Validator::make($request->all(), ['task_ids' => 'required|array', 'task_ids.*' => 'integer']);
        if ($validator->fails()) return $this->validationError($validator);
        $valid = DB::table('task')->where('sub_institute_id', $context['sub_institute_id'])->where('syear', $context['syear'])
            ->whereIn('id', $request->input('task_ids'))->pluck('id')->map(fn ($id) => (int) $id)->all();
        DB::transaction(function () use ($id, $valid, $context) {
            // This replaces the whole list, so a task's workstream would be
            // lost on every save. Carry the existing placements over instead.
            $existingWorkstreams = DB::table('task_management_project_tasks')
                ->where('project_id', $id)->pluck('workstream_id', 'task_id');
            DB::table('task_management_project_tasks')->where('project_id', $id)->delete();
            foreach ($valid as $taskId) DB::table('task_management_project_tasks')->insert([
                'project_id' => $id, 'task_id' => $taskId,
                'workstream_id' => $existingWorkstreams[$taskId] ?? null,
                'created_by' => $context['user_id'],
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        return response()->json(['status' => 1, 'message' => 'Project tasks updated successfully.']);
    }

    /**
     * Attach one task to this project, optionally inside a workstream.
     *
     * syncTasks replaces the project's entire task list, which is right for
     * the project editor but unusable when a single new task needs adding -
     * a partial list there silently unlinks everything else. This adds one
     * task and leaves the rest alone, and is what lets a task created from
     * the Add Task drawer land in a project at all.
     */
    public function attachTask(Request $request, int $id)
    {
        $context = $this->context($request);
        if (!is_array($context)) return $context;
        if (!$this->canManage($context, $id)) return response()->json(['status' => 0, 'message' => 'You cannot link tasks.'], 403);

        $validator = Validator::make($request->all(), [
            'task_id' => 'required|integer',
            'workstream_id' => 'nullable|integer',
        ]);
        if ($validator->fails()) return $this->validationError($validator);

        $taskId = $request->integer('task_id');
        $taskExists = DB::table('task')->where('id', $taskId)
            ->where('sub_institute_id', $context['sub_institute_id'])->where('syear', $context['syear'])
            ->whereNull('deleted_at')->exists();
        if (!$taskExists) return response()->json(['status' => 0, 'message' => 'Task not found.'], 404);

        // A workstream only means anything inside its own project.
        $workstreamId = $request->filled('workstream_id') ? $request->integer('workstream_id') : null;
        if ($workstreamId !== null && !DB::table('task_management_workstreams')
                ->where('id', $workstreamId)->where('project_id', $id)->exists()) {
            return response()->json(['status' => 0, 'message' => 'That workstream does not belong to this project.'], 422);
        }

        // Re-attaching should move the task, not duplicate the row.
        $existing = DB::table('task_management_project_tasks')
            ->where('project_id', $id)->where('task_id', $taskId)->first();

        if ($existing) {
            DB::table('task_management_project_tasks')->where('id', $existing->id)
                ->update(['workstream_id' => $workstreamId, 'updated_at' => now()]);
        } else {
            DB::table('task_management_project_tasks')->insert([
                'project_id' => $id, 'task_id' => $taskId, 'workstream_id' => $workstreamId,
                'created_by' => $context['user_id'], 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return response()->json([
            'status' => 1,
            'message' => 'Task linked to the project.',
            'data' => [
                'project_id' => (string) $id,
                'task_id' => (string) $taskId,
                'workstream_id' => $workstreamId !== null ? (string) $workstreamId : null,
            ],
        ], $existing ? 200 : 201);
    }

    /**
     * DELETE /task-management/projects/{id}/tasks/{taskId} - unlink ONE task.
     *
     * ── WHY THIS EXISTS ────────────────────────────────────────────────────
     *
     * `attachTask` above only ever moves a task WITHIN one project: its lookup
     * is scoped to `project_id = $id`, and `task_management_project_tasks` is
     * many-to-many. So attaching a task to a second project left the first
     * link in place and the task belonged to both - which the workspace list
     * then hid, because its query takes `MIN(project_id)` and shows one.
     *
     * `syncTasks` could detach, but it DELETES a project's entire task list and
     * re-inserts what you send, so removing one task means resending every
     * other one. Two people editing different tasks at the same time would
     * silently unlink each other's work. Moving one task should touch one row.
     *
     * Guarded `project.manage` like every sibling, and idempotent: unlinking a
     * task that is not linked is a 200 with `removed: 0`, not a 404. The caller
     * asked for it to be gone, and it is.
     */
    public function detachTask(Request $request, int $id, int $taskId)
    {
        $context = $this->context($request);
        if (!is_array($context)) return $context;
        if (!$this->canManage($context, $id)) {
            return response()->json(['status' => 0, 'message' => 'You cannot unlink tasks.'], 403);
        }

        // Tenant-checked through the PROJECT, which canManage() already scoped.
        // The link row carries no tenant of its own, so the project is what
        // makes this safe - never the task id from the URL.
        $removed = DB::table('task_management_project_tasks')
            ->where('project_id', $id)
            ->where('task_id', $taskId)
            ->delete();

        return response()->json([
            'status' => 1,
            'message' => $removed ? 'Task unlinked from the project.' : 'That task was not linked to this project.',
            'data' => ['project_id' => (string) $id, 'task_id' => (string) $taskId, 'removed' => $removed],
        ]);
    }

    private function context(Request $request)
    {
        $token = trim((string) ($request->bearerToken() ?: $request->input('token')));
        $accessToken = $token ? PersonalAccessToken::findToken($token) : null;
        if (!$accessToken || !$accessToken->tokenable) return response()->json(['status' => 0, 'message' => $token ? 'Invalid token.' : 'Token not provided.'], 401);
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
        if (!$subInstituteId || !$syear) return response()->json(['status' => 0, 'message' => 'sub_institute_id and syear are required.'], 400);
        return ['user_id' => (int) $user->id, 'sub_institute_id' => $subInstituteId, 'syear' => $syear];
    }

    private function projectValidator(Request $request, array $context)
    {
        return Validator::make($request->all(), [
            'name' => 'required|string|max:191', 'category' => ['nullable', Rule::in(self::CATEGORIES)],
            'description' => 'required|string', 'department_id' => 'nullable|integer',
            // department_id is THE PRIMARY department; department_ids is the
            // full set. Both are tenant-checked in the after() hook below - the
            // rule was `nullable|integer` alone, so any integer was accepted
            // and stored, including another organisation's department.
            'department_ids' => 'nullable|array', 'department_ids.*' => 'integer',
            'sponsor_id' => 'nullable|integer', 'manager_id' => 'required|integer',
            'team_size' => 'nullable|string|max:20', 'member_ids' => 'nullable|array',
            'member_ids.*' => 'integer', 'priority' => ['required', Rule::in(self::PRIORITIES)],
            'status' => ['nullable', Rule::in(self::STATUSES)], 'start_date' => 'required|date',
            'due_date' => 'required|date|after_or_equal:start_date', 'budget_estimate' => 'nullable|numeric|min:0',
            'client_name' => 'nullable|string|max:191', 'regulatory_flags' => 'nullable|array',
            'regulatory_flags.*' => 'string|max:30',
        ])->after(function ($validator) use ($request, $context) {
            // Every department named must belong to THIS tenant. Without this a
            // caller could attach a project to another organisation's
            // department and it would render under their name.
            $departments = array_values(array_unique(array_filter(array_merge(
                $request->input('department_ids', []),
                [$request->input('department_id')]
            ))));

            if ($departments) {
                $valid = DB::table('hrms_departments')
                    ->whereIn('id', $departments)
                    ->where('sub_institute_id', $context['sub_institute_id'])
                    ->whereNull('deleted_at')
                    ->pluck('id')->all();

                if (count($valid) !== count($departments)) {
                    $validator->errors()->add('department_ids', 'One or more selected departments do not belong to this organisation.');
                }
            }

            try { $this->validateTenantUsers(array_filter(array_merge($request->input('member_ids', []), [$request->input('manager_id'), $request->input('sponsor_id')])), $context); }
            catch (\InvalidArgumentException $e) { $validator->errors()->add('member_ids', $e->getMessage()); }
        });
    }

    /**
     * NORMALISE A DATE-ONLY VALUE BEFORE IT REACHES A `date` COLUMN.
     *
     * The validator is `required|date`, which happily accepts a full ISO
     * datetime with a Z suffix — exactly what a browser's toISOString()
     * produces. Handing "2026-01-01T18:30:00.000Z" straight to MySQL lets it
     * truncate the time away, storing 2026-01-01 when the user picked 2 Jan in
     * IST. Parsing first and formatting as Y-m-d removes the ambiguity: the
     * date the caller meant is the date that gets stored.
     *
     * The frontend now sends a clean Y-m-d too, but this is the layer that has
     * to be right — it is the one nearest the column, and it is what protects
     * every other client (mobile, imports, a future integration).
     */
    private function dateOnly(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable $e) {
            // Validation already rejected unparseable input; if something
            // exotic still arrives, store nothing rather than a wrong day.
            return null;
        }
    }

    /**
     * REPLACE THIS PROJECT'S DEPARTMENT SET.
     *
     * Mirrors syncMembers(): delete-and-reinsert, so the request describes the
     * whole set rather than a diff. `department_id` on the project row is
     * rewritten from whichever department is primary, which keeps the single
     * column and the join table from ever disagreeing — the project list, the
     * Home dashboard and every existing report still read that column.
     *
     * The primary is the explicit `department_id` when given, otherwise the
     * first of `department_ids`. A project with no departments clears both.
     */
    private function syncDepartments(int $projectId, Request $request, int $userId): void
    {
        $all = array_values(array_unique(array_filter(array_merge(
            $request->input('department_ids', []),
            [$request->input('department_id')]
        ))));

        $primary = $request->input('department_id') ?: ($all[0] ?? null);

        DB::table('task_management_project_departments')->where('project_id', $projectId)->delete();

        if ($all) {
            DB::table('task_management_project_departments')->insert(
                array_map(fn ($departmentId) => [
                    'project_id'    => $projectId,
                    'department_id' => (int) $departmentId,
                    'is_primary'    => (int) $departmentId === (int) $primary,
                    'created_by'    => $userId,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ], $all)
            );
        }

        // Keep the scalar column in step with the primary row.
        DB::table('task_management_projects')->where('id', $projectId)
            ->update(['department_id' => $primary ? (int) $primary : null]);
    }

    /** Every department on a project, primary first. */
    private function departments(int $projectId): array
    {
        return DB::table('task_management_project_departments as pd')
            ->leftJoin('hrms_departments as d', 'd.id', '=', 'pd.department_id')
            ->where('pd.project_id', $projectId)
            ->orderByDesc('pd.is_primary')->orderBy('d.department')
            ->get(['pd.department_id', 'd.department as name', 'pd.is_primary'])
            ->map(fn ($row) => [
                'id' => (string) $row->department_id,
                'name' => $row->name,
                'is_primary' => (bool) $row->is_primary,
            ])->all();
    }

    private function projectPayload(Request $request, array $context): array
    {
        return [
            'name' => trim($request->input('name')), 'category' => $request->input('category'),
            'description' => trim($request->input('description')), 'department_id' => $request->input('department_id'),
            'sponsor_id' => $request->input('sponsor_id'), 'manager_id' => $request->input('manager_id'),
            'team_size' => $request->input('team_size'), 'priority' => $request->input('priority'),
            'status' => $request->input('status', 'PLANNING'), 'start_date' => $this->dateOnly($request->input('start_date')),
            'due_date' => $this->dateOnly($request->input('due_date')), 'budget_estimate' => $request->input('budget_estimate'),
            'client_name' => $request->input('client_name'), 'regulatory_flags' => json_encode($request->input('regulatory_flags', [])),
            'sub_institute_id' => $context['sub_institute_id'], 'syear' => $context['syear'],
        ];
    }

    private function projectQuery(array $context): Builder
    {
        return DB::table('task_management_projects as p')
            ->leftJoin('tbluser as manager', 'manager.id', '=', 'p.manager_id')
            ->leftJoin('tbluser as sponsor', 'sponsor.id', '=', 'p.sponsor_id')
            ->leftJoin('hrms_departments as department', 'department.id', '=', 'p.department_id')
            ->where('p.sub_institute_id', $context['sub_institute_id'])->where('p.syear', $context['syear'])
            ->select('p.*', 'department.department as department_name',
                DB::raw("TRIM(CONCAT_WS(' ', manager.first_name, manager.middle_name, manager.last_name)) as manager_name"),
                DB::raw("TRIM(CONCAT_WS(' ', sponsor.first_name, sponsor.middle_name, sponsor.last_name)) as sponsor_name"),
                DB::raw('(SELECT COUNT(*) FROM task_management_project_members pm WHERE pm.project_id = p.id) as members_count'),
                /*
                 * ── A DELETED TASK IS NOT AN OUTSTANDING TASK ──────────────
                 *
                 * `tasks_total` never joined `task` at all, and neither count
                 * filtered `deleted_at`. So a task that was soft-deleted stayed
                 * in the denominator forever: nobody can see it, nobody can
                 * complete it, and it holds the project's progress down
                 * permanently.
                 *
                 * Measured on live project 7 (G2G): 4 of its 9 link rows point
                 * at soft-deleted tasks, so it reported 1/9 = 11% when the real
                 * figure is 1/5 = 20%.
                 *
                 * Both counts now join `task` and both exclude soft-deleted
                 * rows, so numerator and denominator finally describe the same
                 * set of tasks.
                 */
                DB::raw('(SELECT COUNT(*) FROM task_management_project_tasks pt JOIN task t ON t.id = pt.task_id WHERE pt.project_id = p.id AND t.deleted_at IS NULL AND t.sub_institute_id = p.sub_institute_id AND t.syear = p.syear) as tasks_total'),
                DB::raw("(SELECT COUNT(*) FROM task_management_project_tasks pt JOIN task t ON t.id = pt.task_id WHERE pt.project_id = p.id AND t.deleted_at IS NULL AND t.sub_institute_id = p.sub_institute_id AND t.syear = p.syear AND UPPER(COALESCE(t.status, '')) = 'COMPLETED') as tasks_completed"));
    }

    private function findProject(array $context, int $id, bool $details = false)
    {
        $row = $this->projectQuery($context)->where('p.id', $id)->first();
        if (!$row) return null;
        $resource = $this->resource($row, $this->progressFor($context, [$id])[$id] ?? null);
        if ($details) {
            $resource['members'] = $this->members($id);
            $resource['workstreams'] = DB::table('task_management_workstreams as w')->leftJoin('tbluser as owner', 'owner.id', '=', 'w.owner_id')
                ->where('w.project_id', $id)->orderBy('w.sort_order')->orderBy('w.id')
                ->select('w.*', DB::raw("TRIM(CONCAT_WS(' ', owner.first_name, owner.middle_name, owner.last_name)) as owner_name"))->get();
            $resource['task_ids'] = DB::table('task_management_project_tasks')->where('project_id', $id)->pluck('task_id')->map(fn ($id) => (string) $id)->all();
            $resource['tasks'] = $this->linkedTasks($context, $id);
            $resource['departments'] = $this->departments($id);
        }
        return $resource;
    }

    /**
     * THE TASKS ACTUALLY LINKED TO THIS PROJECT, hydrated.
     *
     * The drawer used to build its Tasks tab by filtering the global 200-task
     * option list by the project's department — and that option list carries
     * the ASSIGNEE's department, not the task's. So the tab was empty whenever
     * the project had no department, or when no assignee among the newest 200
     * tasks happened to match, even though tasks were linked. Worse, tasks that
     * WERE linked but fell outside the department filter were invisible.
     *
     * The link has always existed in task_management_project_tasks; only the
     * bare ids were returned, so the client had nothing to render. This returns
     * the whole row, including `workstream_id` — which was written in four
     * places and never read back by anything, making workstream placement
     * invisible across the entire product.
     *
     * TENANT SCOPING: task_management_project_tasks has NO sub_institute_id.
     * The `task` join is filtered on tenant + syear so a link row pointing at
     * another tenant's task cannot surface it here.
     */
    private function linkedTasks(array $context, int $projectId)
    {
        return DB::table('task_management_project_tasks as pt')
            ->join('task as t', 't.id', '=', 'pt.task_id')
            ->leftJoin('tbluser as assignee', 'assignee.id', '=', 't.task_allocated_to')
            ->leftJoin('task_management_workstreams as w', 'w.id', '=', 'pt.workstream_id')
            ->where('pt.project_id', $projectId)
            ->where('t.sub_institute_id', $context['sub_institute_id'])
            ->where('t.syear', $context['syear'])
            ->whereNull('t.deleted_at')
            ->orderBy('w.sort_order')->orderByDesc('t.id')
            ->select(
                't.id', 't.task_title as title', 't.status', 't.task_date as due_date',
                'pt.workstream_id', 'w.name as workstream_name',
                DB::raw("TRIM(CONCAT_WS(' ', assignee.first_name, assignee.middle_name, assignee.last_name)) as assignee")
            )
            ->get()
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'title' => $row->title,
                'status' => $row->status,
                'due_date' => $row->due_date,
                'assignee' => $row->assignee ?: null,
                'workstream_id' => $row->workstream_id ? (string) $row->workstream_id : null,
                'workstream_name' => $row->workstream_name,
            ])
            ->all();
    }

    /**
     * @param  array|null  $progress  from ProjectProgress::evaluate(), when the
     *   caller batched the rollup. Null falls back to the task-ledger figure,
     *   which is what a project with no workstreams reduces to anyway.
     */
    private function resource(object $row, ?array $progress = null): array
    {
        $total = (int) $row->tasks_total; $completed = (int) $row->tasks_completed;

        /*
         * `progress_basis` is what makes the number answerable. A bare 12% is
         * a claim; "3 of 24 — 0 of 19 deliverables, 3 of 5 tasks" is a
         * statement somebody can check, and `source` distinguishes "0% because
         * nothing is done" from "0% because there is nothing to measure".
         */
        $basis = $progress['basis'] ?? [
            'done' => $completed, 'total' => $total,
            'deliverables' => ['done' => 0, 'total' => 0],
            'tasks' => ['done' => $completed, 'total' => $total],
            'unplaced_tasks' => ['done' => $completed, 'total' => $total],
            'delivery_workstreams' => 0,
            'source' => $total > 0 ? 'TASKS' : 'NONE',
        ];
        return [
            'id' => (string) $row->id, 'code' => $row->code, 'name' => $row->name,
            'category' => $row->category, 'description' => $row->description,
            'department_id' => $row->department_id ? (string) $row->department_id : null,
            'department' => $row->department_name, 'sponsor_id' => $row->sponsor_id ? (string) $row->sponsor_id : null,
            'sponsor' => $row->sponsor_name, 'manager_id' => $row->manager_id ? (string) $row->manager_id : null,
            'manager' => $row->manager_name, 'team_size' => $row->team_size, 'priority' => $row->priority,
            'status' => $row->status, 'start_date' => $row->start_date, 'due_date' => $row->due_date,
            'budget_estimate' => $row->budget_estimate, 'client_name' => $row->client_name,
            'regulatory_flags' => json_decode($row->regulatory_flags ?: '[]', true) ?: [],
            'members_count' => (int) $row->members_count, 'tasks_total' => $total,
            'tasks_completed' => $completed,
            'progress' => $progress['percent'] ?? ($total ? (int) round($completed * 100 / $total) : 0),
            'progress_basis' => $basis,
            'archived_at' => $row->archived_at,
        ];
    }

    /**
     * Deliverable and task totals → a percentage, for every project at once.
     *
     * @param  int[]  $projectIds
     * @return array<int, array{percent:int, basis:array}>
     */
    private function progressFor(array $context, array $projectIds): array
    {
        if ($projectIds === []) {
            return [];
        }

        $totals   = app(WorkstreamRollup::class)->projectTotals($context, $projectIds);
        $progress = app(ProjectProgress::class);

        $out = [];
        foreach ($totals as $projectId => $t) {
            $out[(int) $projectId] = $progress->evaluate($t);
        }

        return $out;
    }

    private function members(int $id)
    {
        return DB::table('task_management_project_members as pm')->join('tbluser as u', 'u.id', '=', 'pm.user_id')
            ->where('pm.project_id', $id)->orderBy('u.first_name')
            ->select('pm.user_id as id', 'pm.role', DB::raw("TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) as name"))->get();
    }

    private function syncMembers(int $projectId, array $memberIds, int $actor): void
    {
        DB::table('task_management_project_members')->where('project_id', $projectId)->delete();
        foreach (array_unique(array_map('intval', $memberIds)) as $userId) DB::table('task_management_project_members')->insert([
            'project_id' => $projectId, 'user_id' => $userId, 'role' => 'MEMBER',
            'created_by' => $actor, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function validateTenantUsers(array $ids, array $context): void
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($ids))));
        if (!$ids) return;
        $count = DB::table('tbluser')->where('sub_institute_id', $context['sub_institute_id'])->where('status', 1)->whereIn('id', $ids)->count();
        if ($count !== count($ids)) throw new \InvalidArgumentException('One or more selected users do not belong to this organization.');
    }

    private function canManage(array $context, int $id): bool
    {
        return DB::table('task_management_projects')->where('id', $id)
            ->where('sub_institute_id', $context['sub_institute_id'])->where('syear', $context['syear'])
            ->where(fn ($q) => $q->where('created_by', $context['user_id'])->orWhere('manager_id', $context['user_id'])->orWhere('sponsor_id', $context['user_id']))->exists();
    }

    private function nextCode(array $context): string
    {
        $next = (int) DB::table('task_management_projects')->where('sub_institute_id', $context['sub_institute_id'])->max('id') + 1;
        return 'PRJ-' . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }

    private function isProjectMember(int $projectId, int $userId): bool
    {
        return DB::table('task_management_project_members')
            ->where('project_id', $projectId)->where('user_id', $userId)->exists();
    }

    // workstreamValidator / workstreamPayload / workstream() removed with the
    // three CRUD methods they served — see the note above syncTasks().
    // WorkstreamController owns them now, with the wider field set the lifecycle
    // model needs.

    private function validationError($validator)
    {
        return response()->json(['status' => 0, 'message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
    }
}
