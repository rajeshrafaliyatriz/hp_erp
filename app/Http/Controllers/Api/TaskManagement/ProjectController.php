<?php

namespace App\Http\Controllers\Api\TaskManagement;

use App\Http\Controllers\Controller;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
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
        $projects->getCollection()->transform(fn ($project) => $this->resource($project));

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
        $tasks = DB::table('task')->where('sub_institute_id', $context['sub_institute_id'])
            ->where('syear', $context['syear'])->whereNull('deleted_at')
            ->orderByDesc('id')->limit(200)->select('id', 'task_title as title', 'status')->get();
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
        return response()->json(['status' => 1, 'message' => 'Project team updated successfully.', 'data' => $this->members($id)]);
    }

    public function storeWorkstream(Request $request, int $id)
    {
        $context = $this->context($request);
        if (!is_array($context)) return $context;
        if (!$this->canManage($context, $id)) return response()->json(['status' => 0, 'message' => 'You cannot manage workstreams.'], 403);
        $validator = $this->workstreamValidator($request);
        if ($validator->fails()) return $this->validationError($validator);
        $workstreamId = DB::table('task_management_workstreams')->insertGetId($this->workstreamPayload($request) + [
            'project_id' => $id, 'created_by' => $context['user_id'], 'created_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(['status' => 1, 'message' => 'Workstream created successfully.', 'data' => $this->workstream($id, $workstreamId)], 201);
    }

    public function updateWorkstream(Request $request, int $projectId, int $workstreamId)
    {
        $context = $this->context($request);
        if (!is_array($context)) return $context;
        if (!$this->canManage($context, $projectId)) return response()->json(['status' => 0, 'message' => 'You cannot manage workstreams.'], 403);
        $validator = $this->workstreamValidator($request);
        if ($validator->fails()) return $this->validationError($validator);
        DB::table('task_management_workstreams')->where(['id' => $workstreamId, 'project_id' => $projectId])
            ->update($this->workstreamPayload($request) + ['updated_by' => $context['user_id'], 'updated_at' => now()]);
        return response()->json(['status' => 1, 'message' => 'Workstream updated successfully.', 'data' => $this->workstream($projectId, $workstreamId)]);
    }

    public function destroyWorkstream(Request $request, int $projectId, int $workstreamId)
    {
        $context = $this->context($request);
        if (!is_array($context)) return $context;
        if (!$this->canManage($context, $projectId)) return response()->json(['status' => 0, 'message' => 'You cannot manage workstreams.'], 403);
        DB::table('task_management_workstreams')->where(['id' => $workstreamId, 'project_id' => $projectId])->delete();
        return response()->json(['status' => 1, 'message' => 'Workstream deleted successfully.']);
    }

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
            DB::table('task_management_project_tasks')->where('project_id', $id)->delete();
            foreach ($valid as $taskId) DB::table('task_management_project_tasks')->insert([
                'project_id' => $id, 'task_id' => $taskId, 'created_by' => $context['user_id'],
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        return response()->json(['status' => 1, 'message' => 'Project tasks updated successfully.']);
    }

    private function context(Request $request)
    {
        $token = trim((string) $request->input('token'));
        $accessToken = $token ? PersonalAccessToken::findToken($token) : null;
        if (!$accessToken || !$accessToken->tokenable) return response()->json(['status' => 0, 'message' => $token ? 'Invalid token.' : 'Token not provided.'], 401);
        $user = $accessToken->tokenable;
        $subInstituteId = (int) ($user->sub_institute_id ?: $request->input('sub_institute_id'));
        $syear = trim((string) $request->input('syear'));
        if (!$subInstituteId || !$syear) return response()->json(['status' => 0, 'message' => 'sub_institute_id and syear are required.'], 400);
        return ['user_id' => (int) $user->id, 'sub_institute_id' => $subInstituteId, 'syear' => $syear];
    }

    private function projectValidator(Request $request, array $context)
    {
        return Validator::make($request->all(), [
            'name' => 'required|string|max:191', 'category' => ['nullable', Rule::in(self::CATEGORIES)],
            'description' => 'required|string', 'department_id' => 'nullable|integer',
            'sponsor_id' => 'nullable|integer', 'manager_id' => 'required|integer',
            'team_size' => 'nullable|string|max:20', 'member_ids' => 'nullable|array',
            'member_ids.*' => 'integer', 'priority' => ['required', Rule::in(self::PRIORITIES)],
            'status' => ['nullable', Rule::in(self::STATUSES)], 'start_date' => 'required|date',
            'due_date' => 'required|date|after_or_equal:start_date', 'budget_estimate' => 'nullable|numeric|min:0',
            'client_name' => 'nullable|string|max:191', 'regulatory_flags' => 'nullable|array',
            'regulatory_flags.*' => 'string|max:30',
        ])->after(function ($validator) use ($request, $context) {
            try { $this->validateTenantUsers(array_filter(array_merge($request->input('member_ids', []), [$request->input('manager_id'), $request->input('sponsor_id')])), $context); }
            catch (\InvalidArgumentException $e) { $validator->errors()->add('member_ids', $e->getMessage()); }
        });
    }

    private function projectPayload(Request $request, array $context): array
    {
        return [
            'name' => trim($request->input('name')), 'category' => $request->input('category'),
            'description' => trim($request->input('description')), 'department_id' => $request->input('department_id'),
            'sponsor_id' => $request->input('sponsor_id'), 'manager_id' => $request->input('manager_id'),
            'team_size' => $request->input('team_size'), 'priority' => $request->input('priority'),
            'status' => $request->input('status', 'PLANNING'), 'start_date' => $request->input('start_date'),
            'due_date' => $request->input('due_date'), 'budget_estimate' => $request->input('budget_estimate'),
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
                DB::raw('(SELECT COUNT(*) FROM task_management_project_tasks pt WHERE pt.project_id = p.id) as tasks_total'),
                DB::raw("(SELECT COUNT(*) FROM task_management_project_tasks pt JOIN task t ON t.id = pt.task_id WHERE pt.project_id = p.id AND UPPER(t.status) = 'COMPLETED') as tasks_completed"));
    }

    private function findProject(array $context, int $id, bool $details = false)
    {
        $row = $this->projectQuery($context)->where('p.id', $id)->first();
        if (!$row) return null;
        $resource = $this->resource($row);
        if ($details) {
            $resource['members'] = $this->members($id);
            $resource['workstreams'] = DB::table('task_management_workstreams as w')->leftJoin('tbluser as owner', 'owner.id', '=', 'w.owner_id')
                ->where('w.project_id', $id)->orderBy('w.sort_order')->orderBy('w.id')
                ->select('w.*', DB::raw("TRIM(CONCAT_WS(' ', owner.first_name, owner.middle_name, owner.last_name)) as owner_name"))->get();
            $resource['task_ids'] = DB::table('task_management_project_tasks')->where('project_id', $id)->pluck('task_id')->map(fn ($id) => (string) $id)->all();
        }
        return $resource;
    }

    private function resource(object $row): array
    {
        $total = (int) $row->tasks_total; $completed = (int) $row->tasks_completed;
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
            'tasks_completed' => $completed, 'progress' => $total ? (int) round($completed * 100 / $total) : 0,
            'archived_at' => $row->archived_at,
        ];
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

    private function workstreamValidator(Request $request)
    {
        return Validator::make($request->all(), [
            'name' => 'required|string|max:191', 'description' => 'nullable|string', 'owner_id' => 'nullable|integer',
            'status' => ['required', Rule::in(self::STATUSES)], 'start_date' => 'nullable|date',
            'due_date' => 'nullable|date|after_or_equal:start_date', 'sort_order' => 'nullable|integer|min:0',
        ]);
    }

    private function workstreamPayload(Request $request): array
    {
        return $request->only(['name', 'description', 'owner_id', 'status', 'start_date', 'due_date', 'sort_order']);
    }

    private function workstream(int $projectId, int $id)
    {
        return DB::table('task_management_workstreams')->where(['project_id' => $projectId, 'id' => $id])->first();
    }

    private function validationError($validator)
    {
        return response()->json(['status' => 0, 'message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
    }
}
