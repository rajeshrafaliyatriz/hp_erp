<?php

namespace App\Http\Controllers\Api\TaskManagement;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

class DependencyController extends Controller
{
    private const TYPES = ['FS', 'SS', 'FF', 'SF'];
    private const MILESTONE_STATUSES = ['UPCOMING', 'AT RISK', 'COMPLETED'];

    public function index(Request $request)
    {
        $context = $this->context($request);
        if (!is_array($context)) return $context;
        $validator = Validator::make($request->all(), [
            'project_id' => 'nullable|integer', 'assignee_id' => 'nullable|integer',
            'status' => 'nullable|in:PENDING,IN-PROGRESS,ON HOLD,COMPLETED',
        ]);
        if ($validator->fails()) return $this->validationError($validator);

        $query = $this->dependencyQuery($context);
        if ($request->filled('project_id')) $query->where('project.id', $request->integer('project_id'));
        if ($request->filled('assignee_id')) $query->where('successor.task_allocated_to', $request->integer('assignee_id'));
        if ($request->filled('status')) $query->whereRaw('UPPER(successor.status) = ?', [$request->input('status')]);
        $dependencies = $query->orderBy('successor.task_date')->get()->map(fn ($row) => $this->resource($row));

        $taskIds = $dependencies->flatMap(fn ($item) => [$item['predecessor']['id'], $item['successor']['id']])->unique()->values();
        $tasks = $this->taskNodes($context, $taskIds->all());
        $milestones = DB::table('task_management_milestones as m')
            ->join('task_management_projects as p', 'p.id', '=', 'm.project_id')
            ->leftJoin('task_management_workstreams as w', 'w.id', '=', 'm.workstream_id')
            ->where('m.sub_institute_id', $context['sub_institute_id'])->where('m.syear', $context['syear'])
            ->when($request->filled('project_id'), fn ($q) => $q->where('m.project_id', $request->integer('project_id')))
            ->orderBy('m.target_date')->select('m.*', 'p.name as project_name', 'w.name as workstream_name')->get();

        $blocking = $dependencies->filter(fn ($item) => $item['blocking'])->count();
        $atRisk = $tasks->filter(fn ($task) => $task['at_risk'])->count();
        return response()->json(['status' => 1, 'message' => 'Dependencies retrieved successfully.', 'data' => [
            'dependencies' => $dependencies, 'tasks' => $tasks, 'milestones' => $milestones,
            'summary' => [
                'total' => $dependencies->count(), 'blocking' => $blocking, 'at_risk' => $atRisk,
                'on_track' => max(0, $dependencies->count() - $blocking - $atRisk),
                'milestones' => $milestones->count(), 'critical_path' => $this->criticalPathCount($dependencies),
            ],
            'options' => [
                'types' => self::TYPES,
                'projects' => DB::table('task_management_projects')->where('sub_institute_id', $context['sub_institute_id'])
                    ->where('syear', $context['syear'])->whereNull('archived_at')->orderBy('name')->select('id', 'name')->get(),
                'tasks' => $this->taskOptions($context),
                'users' => DB::table('tbluser')->where('sub_institute_id', $context['sub_institute_id'])
                    ->where('status', 1)->whereNull('deleted_at')->orderBy('first_name')
                    ->select('id', DB::raw("TRIM(CONCAT_WS(' ', first_name, middle_name, last_name)) as name"))->get(),
            ],
        ]]);
    }

    public function store(Request $request)
    {
        $context = $this->context($request);
        if (!is_array($context)) return $context;
        $validator = $this->validator($request);
        if ($validator->fails()) return $this->validationError($validator);
        $predecessor = $request->integer('predecessor_task_id');
        $successor = $request->integer('successor_task_id');
        if ($predecessor === $successor) return response()->json(['status' => 0, 'message' => 'A task cannot depend on itself.'], 422);
        if (!$this->validTasks($context, [$predecessor, $successor])) return response()->json(['status' => 0, 'message' => 'One or more selected tasks are invalid.'], 422);
        if (!$this->shareProject($context, $predecessor, $successor)) return response()->json(['status' => 0, 'message' => 'Dependencies require two tasks from the same project.'], 422);
        if ($this->createsCycle($context, $predecessor, $successor)) return response()->json(['status' => 0, 'message' => 'This dependency would create a cycle.'], 422);
        $id = DB::table('task_management_dependencies')->insertGetId($this->payload($request, $context) + [
            'created_by' => $context['user_id'], 'created_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(['status' => 1, 'message' => 'Dependency created successfully.', 'data' => ['id' => (string) $id]], 201);
    }

    public function update(Request $request, int $id)
    {
        $context = $this->context($request);
        if (!is_array($context)) return $context;
        if (!$this->owned($context, $id)) return response()->json(['status' => 0, 'message' => 'Dependency not found.'], 404);
        $validator = $this->validator($request);
        if ($validator->fails()) return $this->validationError($validator);
        $predecessor = $request->integer('predecessor_task_id');
        $successor = $request->integer('successor_task_id');
        if ($predecessor === $successor || !$this->validTasks($context, [$predecessor, $successor]))
            return response()->json(['status' => 0, 'message' => 'Invalid dependency tasks.'], 422);
        if (!$this->shareProject($context, $predecessor, $successor))
            return response()->json(['status' => 0, 'message' => 'Dependencies require two tasks from the same project.'], 422);
        if ($this->createsCycle($context, $predecessor, $successor, $id))
            return response()->json(['status' => 0, 'message' => 'This dependency would create a cycle.'], 422);
        DB::table('task_management_dependencies')->where('id', $id)->update($this->payload($request, $context) + [
            'updated_by' => $context['user_id'], 'updated_at' => now(),
        ]);
        return response()->json(['status' => 1, 'message' => 'Dependency updated successfully.']);
    }

    public function destroy(Request $request, int $id)
    {
        $context = $this->context($request);
        if (!is_array($context)) return $context;
        if (!$this->owned($context, $id)) return response()->json(['status' => 0, 'message' => 'Dependency not found.'], 404);
        DB::table('task_management_dependencies')->where('id', $id)->delete();
        return response()->json(['status' => 1, 'message' => 'Dependency deleted successfully.']);
    }

    public function storeMilestone(Request $request)
    {
        $context = $this->context($request);
        if (!is_array($context)) return $context;
        $validator = $this->milestoneValidator($request);
        if ($validator->fails()) return $this->validationError($validator);
        if (!$this->validProject($context, $request->integer('project_id')))
            return response()->json(['status' => 0, 'message' => 'Project not found.'], 422);
        $id = DB::table('task_management_milestones')->insertGetId($this->milestonePayload($request, $context) + [
            'created_by' => $context['user_id'], 'created_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(['status' => 1, 'message' => 'Milestone created successfully.', 'data' => ['id' => (string) $id]], 201);
    }

    public function updateMilestone(Request $request, int $id)
    {
        $context = $this->context($request);
        if (!is_array($context)) return $context;
        $validator = $this->milestoneValidator($request);
        if ($validator->fails()) return $this->validationError($validator);
        $updated = DB::table('task_management_milestones')->where('id', $id)
            ->where('sub_institute_id', $context['sub_institute_id'])->where('syear', $context['syear'])
            ->update($this->milestonePayload($request, $context) + ['updated_by' => $context['user_id'], 'updated_at' => now()]);
        if (!$updated) return response()->json(['status' => 0, 'message' => 'Milestone not found.'], 404);
        return response()->json(['status' => 1, 'message' => 'Milestone updated successfully.']);
    }

    public function destroyMilestone(Request $request, int $id)
    {
        $context = $this->context($request);
        if (!is_array($context)) return $context;
        $deleted = DB::table('task_management_milestones')->where('id', $id)
            ->where('sub_institute_id', $context['sub_institute_id'])->where('syear', $context['syear'])->delete();
        if (!$deleted) return response()->json(['status' => 0, 'message' => 'Milestone not found.'], 404);
        return response()->json(['status' => 1, 'message' => 'Milestone deleted successfully.']);
    }

    private function dependencyQuery(array $context)
    {
        return DB::table('task_management_dependencies as d')
            ->join('task as predecessor', 'predecessor.id', '=', 'd.predecessor_task_id')
            ->join('task as successor', 'successor.id', '=', 'd.successor_task_id')
            ->leftJoin('tbluser as assignee', 'assignee.id', '=', 'successor.task_allocated_to')
            ->leftJoin('task_management_project_tasks as pt', 'pt.task_id', '=', 'successor.id')
            ->leftJoin('task_management_projects as project', 'project.id', '=', 'pt.project_id')
            ->where('d.sub_institute_id', $context['sub_institute_id'])->where('d.syear', $context['syear'])
            ->whereNull('predecessor.deleted_at')->whereNull('successor.deleted_at')
            ->select('d.*', 'predecessor.task_title as predecessor_title', 'predecessor.status as predecessor_status',
                'predecessor.task_date as predecessor_due_date', 'successor.task_title as successor_title',
                'successor.status as successor_status', 'successor.task_date as successor_due_date',
                'successor.task_allocated_to as assignee_id', 'project.id as project_id', 'project.name as project_name',
                DB::raw("TRIM(CONCAT_WS(' ', assignee.first_name, assignee.middle_name, assignee.last_name)) as assignee_name"));
    }

    private function resource(object $row): array
    {
        $predecessorComplete = strtoupper((string) $row->predecessor_status) === 'COMPLETED';
        $successorComplete = strtoupper((string) $row->successor_status) === 'COMPLETED';
        return [
            'id' => (string) $row->id, 'type' => $row->dependency_type, 'lag_days' => (int) $row->lag_days,
            'notes' => $row->notes, 'project_id' => $row->project_id ? (string) $row->project_id : null,
            'project' => $row->project_name ?: 'Unassigned', 'assignee_id' => $row->assignee_id ? (string) $row->assignee_id : null,
            'assignee' => $row->assignee_name ?: 'Unassigned',
            'predecessor' => ['id' => (string) $row->predecessor_task_id, 'title' => $row->predecessor_title,
                'status' => $row->predecessor_status, 'due_date' => $row->predecessor_due_date],
            'successor' => ['id' => (string) $row->successor_task_id, 'title' => $row->successor_title,
                'status' => $row->successor_status, 'due_date' => $row->successor_due_date],
            'blocking' => !$predecessorComplete && !$successorComplete,
        ];
    }

    private function taskNodes(array $context, array $ids)
    {
        if (!$ids) return collect();
        return DB::table('task as t')->leftJoin('tbluser as u', 'u.id', '=', 't.task_allocated_to')
            ->leftJoin('task_management_project_tasks as pt', 'pt.task_id', '=', 't.id')
            ->leftJoin('task_management_projects as p', 'p.id', '=', 'pt.project_id')
            ->where('t.sub_institute_id', $context['sub_institute_id'])->where('t.syear', $context['syear'])
            ->whereNull('t.deleted_at')->whereIn('t.id', $ids)->select('t.id', 't.task_title as title',
                't.status', 't.task_type as priority', 't.task_date as due_date', 'p.name as project',
                DB::raw("TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) as assignee"))->get()
            ->map(fn ($task) => [
                'id' => (string) $task->id, 'title' => $task->title, 'status' => $task->status,
                'priority' => $task->priority, 'due_date' => $task->due_date, 'project' => $task->project ?: 'Unassigned',
                'assignee' => $task->assignee ?: 'Unassigned',
                'at_risk' => $task->due_date && $task->due_date < now()->toDateString() && strtoupper((string) $task->status) !== 'COMPLETED',
            ])->unique('id')->values();
    }

    private function taskOptions(array $context)
    {
        return DB::table('task as t')->leftJoin('task_management_project_tasks as pt', 'pt.task_id', '=', 't.id')
            ->where('t.sub_institute_id', $context['sub_institute_id'])->where('t.syear', $context['syear'])
            ->whereNull('t.deleted_at')->orderByDesc('t.id')->limit(500)
            ->groupBy('t.id', 't.task_title', 't.status', 't.task_date')
            ->select('t.id', 't.task_title as title', 't.status', 't.task_date as due_date', DB::raw('MIN(pt.project_id) as project_id'))->get();
    }

    private function criticalPathCount($dependencies): int
    {
        $outgoing = [];
        foreach ($dependencies as $dependency) $outgoing[$dependency['predecessor']['id']][] = $dependency['successor']['id'];
        $memo = [];
        $depth = function ($id) use (&$depth, &$memo, $outgoing) {
            if (isset($memo[$id])) return $memo[$id];
            $best = 1;
            foreach ($outgoing[$id] ?? [] as $next) $best = max($best, 1 + $depth($next));
            return $memo[$id] = $best;
        };
        $max = 0;
        foreach (array_keys($outgoing) as $id) $max = max($max, $depth($id));
        return $max;
    }

    private function shareProject(array $context, int $predecessor, int $successor): bool
    {
        $projectIds = DB::table('task_management_project_tasks as first')
            ->join('task_management_project_tasks as second', 'second.project_id', '=', 'first.project_id')
            ->join('task_management_projects as project', 'project.id', '=', 'first.project_id')
            ->where('first.task_id', $predecessor)->where('second.task_id', $successor)
            ->where('project.sub_institute_id', $context['sub_institute_id'])->where('project.syear', $context['syear'])
            ->whereNull('project.archived_at')->exists();
        return $projectIds;
    }
    private function createsCycle(array $context, int $predecessor, int $successor, ?int $ignoreId = null): bool
    {
        $rows = DB::table('task_management_dependencies')->where('sub_institute_id', $context['sub_institute_id'])
            ->where('syear', $context['syear'])->when($ignoreId, fn ($q) => $q->where('id', '<>', $ignoreId))
            ->get(['predecessor_task_id', 'successor_task_id']);
        $graph = [];
        foreach ($rows as $row) $graph[(int) $row->predecessor_task_id][] = (int) $row->successor_task_id;
        $graph[$predecessor][] = $successor;
        $stack = [$successor]; $seen = [];
        while ($stack) {
            $node = array_pop($stack);
            if ($node === $predecessor) return true;
            if (isset($seen[$node])) continue;
            $seen[$node] = true;
            foreach ($graph[$node] ?? [] as $next) $stack[] = $next;
        }
        return false;
    }

    private function validator(Request $request)
    {
        return Validator::make($request->all(), [
            'predecessor_task_id' => 'required|integer', 'successor_task_id' => 'required|integer',
            'dependency_type' => ['required', Rule::in(self::TYPES)], 'lag_days' => 'required|integer|min:-365|max:365',
            'notes' => 'nullable|string|max:5000',
        ]);
    }

    private function milestoneValidator(Request $request)
    {
        return Validator::make($request->all(), [
            'project_id' => 'required|integer', 'workstream_id' => 'nullable|integer',
            'name' => 'required|string|max:191', 'description' => 'nullable|string|max:5000',
            'target_date' => 'required|date', 'status' => ['required', Rule::in(self::MILESTONE_STATUSES)],
        ]);
    }

    private function payload(Request $request, array $context): array
    {
        return ['predecessor_task_id' => $request->integer('predecessor_task_id'),
            'successor_task_id' => $request->integer('successor_task_id'), 'dependency_type' => $request->input('dependency_type'),
            'lag_days' => $request->integer('lag_days'), 'notes' => $request->input('notes'),
            'sub_institute_id' => $context['sub_institute_id'], 'syear' => $context['syear']];
    }

    private function milestonePayload(Request $request, array $context): array
    {
        return ['project_id' => $request->integer('project_id'), 'workstream_id' => $request->input('workstream_id'),
            'name' => trim($request->input('name')), 'description' => $request->input('description'),
            'target_date' => $request->input('target_date'), 'status' => $request->input('status'),
            'sub_institute_id' => $context['sub_institute_id'], 'syear' => $context['syear']];
    }

    private function validTasks(array $context, array $ids): bool
    {
        return DB::table('task')->where('sub_institute_id', $context['sub_institute_id'])->where('syear', $context['syear'])
            ->whereNull('deleted_at')->whereIn('id', $ids)->count() === count(array_unique($ids));
    }

    private function validProject(array $context, int $id): bool
    {
        return DB::table('task_management_projects')->where('id', $id)->where('sub_institute_id', $context['sub_institute_id'])
            ->where('syear', $context['syear'])->whereNull('archived_at')->exists();
    }

    private function owned(array $context, int $id): bool
    {
        return DB::table('task_management_dependencies')->where('id', $id)
            ->where('sub_institute_id', $context['sub_institute_id'])->where('syear', $context['syear'])->exists();
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

    private function validationError($validator)
    {
        return response()->json(['status' => 0, 'message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
    }
}
