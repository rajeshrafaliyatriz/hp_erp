<?php

namespace App\Http\Controllers\Api\TaskManagement;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
            // Scoped to the caller's own tenant where it is applied, so an id
            // from another organisation matches nothing rather than leaking.
            'department_id' => 'nullable|integer',
            'status' => 'nullable|in:PENDING,IN-PROGRESS,ON HOLD,COMPLETED',
        ]);
        if ($validator->fails()) return $this->validationError($validator);

        $query = $this->dependencyQuery($context);
        // FILTERED ON THE STORED COLUMN, not the join. The migration justified
        // the column by saying it "earns its place by being usable in a WHERE
        // clause" — until now it was not used in one. Rows written before the
        // column existed fall back to the derived value so nothing disappears.
        if ($request->filled('project_id')) {
            $projectId = $request->integer('project_id');
            $query->where(function ($q) use ($projectId) {
                $q->where('d.project_id', $projectId)
                  ->orWhere(function ($legacy) use ($projectId) {
                      $legacy->whereNull('d.project_id')->where('project.id', $projectId);
                  });
            });
        }
        if ($request->filled('assignee_id')) $query->where('successor.task_allocated_to', $request->integer('assignee_id'));
        /*
         * BY THE ASSIGNEE'S DEPARTMENT, not the project's.
         *
         * The two are different questions and conflating them is a documented
         * bug elsewhere in this module. WorkspaceController and MyTasksController
         * both already mean the assignee's department when they say "department",
         * so this agrees with them rather than introducing a second meaning.
         */
        if ($request->filled('department_id')) {
            $query->whereIn('successor.task_allocated_to', function ($sub) use ($request, $context) {
                $sub->select('id')->from('tbluser')
                    ->where('sub_institute_id', $context['sub_institute_id'])
                    ->where('department_id', $request->integer('department_id'));
            });
        }
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
        $milestones = $this->withMilestoneCounts($context, $milestones);

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
        // DUPLICATES USED TO RETURN 500. The unique index
        // tm_dependency_pair_unique was the only thing stopping them, and it
        // threw an uncaught QueryException — the user saw a server error for
        // what is simply "you already linked these two".
        if ($this->duplicate($context, $predecessor, $successor)) {
            return response()->json(['status' => 0, 'message' => 'These two tasks are already linked in that direction.'], 422);
        }
        if (!$this->projectMatchesTasks($context, $request, $predecessor, $successor)) {
            return response()->json(['status' => 0, 'message' => 'The selected project does not contain both of these tasks.'], 422);
        }
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
        if ($this->duplicate($context, $predecessor, $successor, $id))
            return response()->json(['status' => 0, 'message' => 'These two tasks are already linked in that direction.'], 422);
        if (!$this->projectMatchesTasks($context, $request, $predecessor, $successor))
            return response()->json(['status' => 0, 'message' => 'The selected project does not contain both of these tasks.'], 422);
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

    /**
     * LIST MILESTONES ON THEIR OWN.
     *
     * They were reachable only as a side-effect of GET /dependencies, so a
     * screen showing milestones had to fetch the entire dependency graph to
     * find them - and a tenant with no dependencies at all could still never
     * manage a milestone, because no GET existed to list one. POST/PUT/DELETE
     * have been implemented here all along with nothing calling them; this
     * completes the set.
     */
    public function indexMilestones(Request $request)
    {
        $context = $this->context($request);
        if (!is_array($context)) return $context;

        $milestones = DB::table('task_management_milestones as m')
            ->join('task_management_projects as p', 'p.id', '=', 'm.project_id')
            ->leftJoin('task_management_workstreams as w', 'w.id', '=', 'm.workstream_id')
            ->where('m.sub_institute_id', $context['sub_institute_id'])->where('m.syear', $context['syear'])
            ->when($request->filled('project_id'), fn ($q) => $q->where('m.project_id', $request->integer('project_id')))
            ->orderBy('m.target_date')->select('m.*', 'p.name as project_name', 'w.name as workstream_name')->get();

        return response()->json(['status' => 1, 'message' => 'Milestones retrieved successfully.', 'data' => [
            'milestones' => $this->withMilestoneCounts($context, $milestones),
            'options' => [
                'projects' => DB::table('task_management_projects')->where('sub_institute_id', $context['sub_institute_id'])
                    ->where('syear', $context['syear'])->whereNull('archived_at')->orderBy('name')->select('id', 'name')->get(),
                'workstreams' => DB::table('task_management_workstreams as w')
                    ->join('task_management_projects as p', 'p.id', '=', 'w.project_id')
                    ->where('p.sub_institute_id', $context['sub_institute_id'])->where('p.syear', $context['syear'])
                    ->orderBy('w.name')->select('w.id', 'w.name', 'w.project_id')->get(),
                // The legal vocabulary comes from the server. A form that
                // hardcodes its own list is one release away from posting a
                // word the validator rejects.
                'statuses' => self::MILESTONE_STATUSES,
            ],
        ]]);
    }

    /**
     * PER-MILESTONE TASK COUNTS.
     *
     * The cards used to print the tenant-wide blocking-dependency count on
     * EVERY milestone, so three different milestones all read "Blocked: 3
     * Tasks" - one number, repeated, describing none of them.
     *
     * A milestone's scope is its project, narrowed to its workstream when it
     * has one. Two queries serve all milestones: the task rows in the projects
     * involved, and the set of task ids currently blocked by an open
     * predecessor. Counting happens in PHP so the number of milestones does not
     * become a number of queries.
     *
     * TENANT SCOPING: task_management_project_tasks has no tenant column, so
     * the project join carries it - the same rule as everywhere else here.
     */
    private function withMilestoneCounts(array $context, $milestones)
    {
        if ($milestones->isEmpty()) return $milestones;

        $projectIds = $milestones->pluck('project_id')->filter()->unique()->values()->all();

        $rows = DB::table('task_management_project_tasks as pt')
            ->join('task as t', 't.id', '=', 'pt.task_id')
            ->join('task_management_projects as p', 'p.id', '=', 'pt.project_id')
            ->where('p.sub_institute_id', $context['sub_institute_id'])->where('p.syear', $context['syear'])
            ->whereNull('t.deleted_at')->whereIn('pt.project_id', $projectIds)
            ->select('pt.project_id', 'pt.workstream_id', 't.id', 't.status', 't.task_date')->get();

        $blockedIds = DB::table('task_management_dependencies as d')
            ->join('task as pred', 'pred.id', '=', 'd.predecessor_task_id')
            ->where('d.sub_institute_id', $context['sub_institute_id'])->where('d.syear', $context['syear'])
            ->whereNull('pred.deleted_at')
            ->whereRaw("UPPER(COALESCE(pred.status, 'PENDING')) <> 'COMPLETED'")
            ->pluck('d.successor_task_id')->map(fn ($id) => (int) $id)->flip();

        $today = now()->toDateString();

        return $milestones->map(function ($milestone) use ($rows, $blockedIds, $today) {
            $scope = $rows->filter(function ($row) use ($milestone) {
                if ((int) $row->project_id !== (int) $milestone->project_id) return false;
                // A milestone with no workstream covers the whole project.
                return $milestone->workstream_id === null
                    || (int) $row->workstream_id === (int) $milestone->workstream_id;
            });

            $completed = $scope->filter(fn ($row) => strtoupper((string) $row->status) === 'COMPLETED');
            $milestone->counts = [
                'total' => $scope->count(),
                'completed' => $completed->count(),
                'open' => $scope->count() - $completed->count(),
                'blocked' => $scope->filter(fn ($row) => $blockedIds->has((int) $row->id))->count(),
                'overdue' => $scope->filter(fn ($row) => $row->task_date && $row->task_date < $today
                    && strtoupper((string) $row->status) !== 'COMPLETED')->count(),
            ];
            return $milestone;
        });
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
        // THE PROJECT MUST BE VALIDATED HERE TOO. storeMilestone checks it;
        // this method only scoped by the milestone's own tenant, so an update
        // could repoint project_id at ANOTHER TENANT'S project — the row stayed
        // in this tenant while pointing across the boundary.
        if (!$this->validProject($context, $request->integer('project_id')))
            return response()->json(['status' => 0, 'message' => 'Project not found.'], 422);
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
            // COLUMNS ARE LISTED EXPLICITLY, and `project.id` is aliased.
            //
            // This used to select `d.*` AND `project.id as project_id`. Two
            // columns of the same name: PDO's object hydration keeps the LAST,
            // so `$row->project_id` was always the join-derived value and the
            // stored d.project_id could never be read back — a column that was
            // written on every save and returned by nothing.
            ->select(
                'd.id', 'd.predecessor_task_id', 'd.successor_task_id', 'd.dependency_type', 'd.lag_days',
                'd.notes', 'd.project_id', 'd.workstream_id',
                'predecessor.task_title as predecessor_title', 'predecessor.status as predecessor_status',
                'predecessor.task_date as predecessor_due_date', 'predecessor.planned_start_date as predecessor_start_date',
                'successor.task_title as successor_title',
                'successor.status as successor_status', 'successor.task_date as successor_due_date',
                'successor.planned_start_date as successor_start_date',
                'successor.task_allocated_to as assignee_id',
                'project.id as derived_project_id', 'project.name as project_name',
                DB::raw("TRIM(CONCAT_WS(' ', assignee.first_name, assignee.middle_name, assignee.last_name)) as assignee_name"));
    }

    private function resource(object $row): array
    {
        $predecessorComplete = strtoupper((string) $row->predecessor_status) === 'COMPLETED';
        $successorComplete = strtoupper((string) $row->successor_status) === 'COMPLETED';
        return [
            'id' => (string) $row->id, 'type' => $row->dependency_type, 'lag_days' => (int) $row->lag_days,
            'notes' => $row->notes,
            // The STORED column, now actually readable. `derived_project_id` is
            // the one inferred from the successor's project link; they are kept
            // in step by projectMatchesTasks() on write, and both are returned
            // so a divergence would be visible rather than silent.
            'project_id' => $row->project_id ? (string) $row->project_id : null,
            'derived_project_id' => $row->derived_project_id ? (string) $row->derived_project_id : null,
            'workstream_id' => $row->workstream_id ? (string) $row->workstream_id : null,
            'project' => $row->project_name ?: 'Unassigned', 'assignee_id' => $row->assignee_id ? (string) $row->assignee_id : null,
            'assignee' => $row->assignee_name ?: 'Unassigned',
            'predecessor' => ['id' => (string) $row->predecessor_task_id, 'title' => $row->predecessor_title,
                'status' => $row->predecessor_status, 'due_date' => $row->predecessor_due_date,
                'start_date' => $row->predecessor_start_date],
            'successor' => ['id' => (string) $row->successor_task_id, 'title' => $row->successor_title,
                'status' => $row->successor_status, 'due_date' => $row->successor_due_date,
                'start_date' => $row->successor_start_date],
            'blocking' => !$predecessorComplete && !$successorComplete,
            // WHAT THE TYPE AND LAG ACTUALLY IMPLY — see schedule() below.
            'schedule' => $this->schedule($row),
        ];
    }

    /**
     * TURN `dependency_type` + `lag_days` INTO A DATE.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * THESE TWO COLUMNS HAVE NEVER DONE ANYTHING
     * ═══════════════════════════════════════════════════════════════════════
     *
     * A whole-repo trace found four references to `lag_days`: the schema, the
     * validation rule, the write, and one line echoing it back to the client.
     * No arithmetic, anywhere. `dependency_type` never branched any logic, so
     * FS, SS, FF and SF were behaviourally identical. Setting "lag 2 days"
     * moved no date, on any task, ever.
     *
     * This computes what the successor's date SHOULD be:
     *
     *   FS  finish -> start   predecessor due  + lag + 1 day  -> successor START
     *   SS  start  -> start   predecessor start + lag         -> successor START
     *   FF  finish -> finish  predecessor due  + lag          -> successor DUE
     *   SF  start  -> finish  predecessor start + lag         -> successor DUE
     *
     * NOTHING IS MOVED HERE. The value is returned with `violates`, and the UI
     * offers an Apply button. A tool that silently rewrites a deadline someone
     * is measured against is a tool people stop trusting.
     *
     * SS and SF anchor on the predecessor's `planned_start_date`, which no
     * screen currently sets. Rather than guess, those return a null date and a
     * `reason` naming what is missing.
     */
    private function schedule(object $row): array
    {
        $type = strtoupper((string) $row->dependency_type) ?: 'FS';
        $lag = (int) $row->lag_days;

        $anchorsOnStart = in_array($type, ['SS', 'SF'], true);
        $anchor = $anchorsOnStart ? $row->predecessor_start_date : $row->predecessor_due_date;

        if (!$anchor) {
            return [
                'type' => $type, 'lag_days' => $lag, 'target_field' => null,
                'implied_date' => null, 'current_date' => null, 'violates' => false,
                'reason' => $anchorsOnStart
                    ? 'This relationship is measured from the predecessor\'s planned start date, which has not been set.'
                    : 'The predecessor has no due date, so no date can be implied.',
            ];
        }

        // FS is the only one with an implicit +1: "starts after it finishes"
        // means the next day, not the same day.
        $offset = $lag + ($type === 'FS' ? 1 : 0);
        $implied = Carbon::parse($anchor)->addDays($offset)->toDateString();

        // FS and SS drive the successor's START; FF and SF drive its DUE date.
        $targetField = in_array($type, ['FS', 'SS'], true) ? 'planned_start_date' : 'due_date';
        $current = $targetField === 'due_date' ? $row->successor_due_date : $row->successor_start_date;

        return [
            'type' => $type,
            'lag_days' => $lag,
            'target_field' => $targetField,
            'implied_date' => $implied,
            'current_date' => $current,
            // Only a date EARLIER than implied breaks the relationship. Starting
            // later than required is a delay, not a contradiction.
            'violates' => $current !== null && $current < $implied,
            'reason' => $current === null
                ? 'The successor has no ' . ($targetField === 'due_date' ? 'due date' : 'planned start date') . ' set yet.'
                : null,
        ];
    }

    private function taskNodes(array $context, array $ids)
    {
        if (!$ids) return collect();
        return DB::table('task as t')->leftJoin('tbluser as u', 'u.id', '=', 't.task_allocated_to')
            // LEFT join: a task whose assignee has no department still
            // appears on the map, it simply carries no department.
            ->leftJoin('hrms_departments as dept', 'dept.id', '=', 'u.department_id')
            ->leftJoin('task_management_project_tasks as pt', 'pt.task_id', '=', 't.id')
            ->leftJoin('task_management_projects as p', 'p.id', '=', 'pt.project_id')
            // THE WORKSTREAM BOARD GROUPED BY PROJECT because the workstream
            // never reached the client. task_management_project_tasks has
            // carried workstream_id all along - it was simply never selected,
            // so a board named after workstreams was drawing project columns.
            ->leftJoin('task_management_workstreams as w', 'w.id', '=', 'pt.workstream_id')
            ->where('t.sub_institute_id', $context['sub_institute_id'])->where('t.syear', $context['syear'])
            ->whereNull('t.deleted_at')->whereIn('t.id', $ids)->select('t.id', 't.task_title as title',
                't.status', 't.task_type as priority', 't.task_date as due_date',
                // Needed by the Gantt tab to draw a real span. Where it is
                // NULL the timeline shows a point marker on the due date
                // rather than inventing a start.
                't.planned_start_date', 'p.name as project', 'p.id as project_id',
                'pt.workstream_id', 'w.name as workstream', 't.task_allocated_to as assignee_id',
                // The assignee's department, so the client can group and filter
                // by it without a second round trip per node.
                'u.department_id',
                // THE NAME AS WELL AS THE ID. The id alone let the client filter
                // but gave it nothing to label the menu with, so the department
                // filter could not be built at all. Same table and alias
                // MyTasksController and ProjectController already use, so one
                // department reads identically on every screen in this module.
                'dept.department as department_name',
                DB::raw("TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) as assignee"))->get()
            ->map(fn ($task) => [
                'id' => (string) $task->id, 'title' => $task->title, 'status' => $task->status,
                'priority' => $task->priority, 'due_date' => $task->due_date,
                'planned_start_date' => $task->planned_start_date, 'project' => $task->project ?: 'Unassigned',
                'project_id' => $task->project_id ? (string) $task->project_id : null,
                'workstream_id' => $task->workstream_id ? (string) $task->workstream_id : null,
                'workstream' => $task->workstream,
                // The map filters on the ID. Matching the rendered NAME meant a
                // filter could only ever work while two independently-built
                // strings happened to agree.
                'assignee_id' => $task->assignee_id ? (string) $task->assignee_id : null,
                'assignee' => $task->assignee ?: 'Unassigned',
                // Same rule as assignee_id: the client filters on the ID, never
                // on a rendered name that two code paths must agree about.
                'department_id' => $task->department_id ? (string) $task->department_id : null,
                // Nullable and left null rather than defaulted: a person with no
                // department set is not in a department called "Unassigned", and
                // the filter menu simply does not offer one for them.
                'department' => $task->department_name ?: null,
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

    /**
     * Does this exact directed pair already exist?
     *
     * Mirrors the unique index tm_dependency_pair_unique — tenant, syear and
     * BOTH task ids in order. The index is directional by design: A→B and B→A
     * are different rows, and createsCycle() is what refuses the reverse.
     *
     * $ignoreId lets update() exclude the row being edited.
     */
    private function duplicate(array $context, int $predecessor, int $successor, ?int $ignoreId = null): bool
    {
        $query = DB::table('task_management_dependencies')
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->where('syear', $context['syear'])
            ->where('predecessor_task_id', $predecessor)
            ->where('successor_task_id', $successor);

        if ($ignoreId !== null) {
            $query->where('id', '<>', $ignoreId);
        }

        return $query->exists();
    }

    private function criticalPathCount($dependencies): int
    {
        $outgoing = [];
        foreach ($dependencies as $dependency) $outgoing[$dependency['predecessor']['id']][] = $dependency['successor']['id'];
        $memo = [];
        // `$visiting` IS A HANG GUARD, not an optimisation. $memo is only
        // written after the recursion returns, so a cycle already present in
        // the table would recurse forever and take the request with it. The
        // store/update cycle checks are the only thing that has been keeping
        // this safe — and they cannot protect rows inserted before they existed,
        // or by any other writer.
        $visiting = [];
        $depth = function ($id) use (&$depth, &$memo, &$visiting, $outgoing) {
            if (isset($memo[$id])) return $memo[$id];
            if (isset($visiting[$id])) return 1; // cycle: stop descending
            $visiting[$id] = true;
            $best = 1;
            foreach ($outgoing[$id] ?? [] as $next) $best = max($best, 1 + $depth($next));
            unset($visiting[$id]);
            return $memo[$id] = $best;
        };
        $max = 0;
        foreach (array_keys($outgoing) as $id) $max = max($max, $depth($id));
        return $max;
    }

    /**
     * THE STORED PROJECT MUST AGREE WITH THE TASKS.
     *
     * project_id is now a column AND still derivable by joining
     * task_management_project_tasks on the successor — two sources for one
     * fact. This is what stops them drifting: a submitted project is accepted
     * only if BOTH tasks actually belong to it, so the column can never hold an
     * answer the join would contradict.
     *
     * Omitting project_id is allowed; shareProject() has already proved the two
     * tasks share SOME project, and the backfill/read path can resolve it.
     *
     * The workstream, if given, must belong to that same project — otherwise a
     * dependency could be filed under a workstream from an unrelated project.
     */
    private function projectMatchesTasks(array $context, Request $request, int $predecessor, int $successor): bool
    {
        $projectId = (int) $request->input('project_id');

        if ($projectId <= 0) {
            return true;
        }

        $holdsBoth = DB::table('task_management_project_tasks as first')
            ->join('task_management_project_tasks as second', 'second.project_id', '=', 'first.project_id')
            ->join('task_management_projects as project', 'project.id', '=', 'first.project_id')
            ->where('first.task_id', $predecessor)
            ->where('second.task_id', $successor)
            ->where('first.project_id', $projectId)
            ->where('project.sub_institute_id', $context['sub_institute_id'])
            ->where('project.syear', $context['syear'])
            ->exists();

        if (!$holdsBoth) {
            return false;
        }

        $workstreamId = (int) $request->input('workstream_id');

        if ($workstreamId <= 0) {
            return true;
        }

        return DB::table('task_management_workstreams')
            ->where('id', $workstreamId)
            ->where('project_id', $projectId)
            ->exists();
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
            // The modal has always sent these; until now nothing accepted them
            // and both were silently dropped on every save.
            'project_id' => 'nullable|integer',
            'workstream_id' => 'nullable|integer',
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
            // Persisted at last: the modal has always sent these and the
            // controller has always dropped them.
            'project_id' => $request->input('project_id') ?: null,
            'workstream_id' => $request->input('workstream_id') ?: null,
            'sub_institute_id' => $context['sub_institute_id'], 'syear' => $context['syear']];
    }

    /**
     * A date-only value, pinned to a calendar day.
     *
     * See ProjectController::dateOnly() for the full reasoning — briefly:
     * `required|date` accepts an ISO datetime with a Z suffix, and letting
     * MySQL truncate that into a `date` column stores the previous day for
     * every user east of Greenwich.
     */
    private function dateOnly(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function milestonePayload(Request $request, array $context): array
    {
        return ['project_id' => $request->integer('project_id'), 'workstream_id' => $request->input('workstream_id'),
            'name' => trim($request->input('name')), 'description' => $request->input('description'),
            // Normalised for the same reason as project dates: `required|date`
            // accepts an ISO datetime with a Z suffix, and MySQL would truncate
            // it into this `date` column a day early for any UTC+ user.
            'target_date' => $this->dateOnly($request->input('target_date')), 'status' => $request->input('status'),
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
