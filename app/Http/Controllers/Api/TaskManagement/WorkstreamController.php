<?php

namespace App\Http\Controllers\Api\TaskManagement;

use App\Http\Controllers\Api\TaskManagement\Concerns\ResolvesTaskContext;
use App\Http\Controllers\Api\TaskManagement\Concerns\ResolvesWorkstreamScope;
use App\Http\Controllers\Controller;
use App\Services\TaskManagement\WorkstreamHealth;
use App\Services\TaskManagement\WorkstreamRollup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * A workstream as a plan, not a label.
 *
 * ── WHY THIS CONTROLLER EXISTS AT ALL ───────────────────────────────────────
 *
 * There was no workstream controller. Three routes hung off ProjectController —
 * store, update, destroy — and there was NO GET of any kind. Every workstream
 * dropdown in the product therefore fetched the entire project record (members,
 * task ids, hydrated tasks, departments) to obtain a list of names.
 *
 * The three write routes keep their existing URLs so no client changes; they have
 * simply moved off ProjectController, whose workstream methods are deleted.
 *
 * ── READS ARE NOT GATED, WRITES ARE ─────────────────────────────────────────
 *
 * Reading a plan is not a privileged act — the same call DependencyController
 * makes for milestones. Tenancy still binds absolutely, through
 * ResolvesWorkstreamScope; read that trait's header before adding a route.
 */
class WorkstreamController extends Controller
{
    use ResolvesTaskContext;
    use ResolvesWorkstreamScope;

    /**
     * The lifecycle vocabularies, owned by the server.
     *
     * VARCHAR columns plus a const here, never an ENUM in DDL: adding a value
     * later would otherwise mean an ALTER TABLE rebuild on live. This is already
     * the module's pattern for task statuses and priorities.
     */
    public const KINDS = ['DELIVERY', 'GOVERNANCE'];

    public const STATUSES = ['PLANNING', 'IN PROGRESS', 'AT RISK', 'COMPLETED', 'ARCHIVED'];

    /**
     * FLOW is the delivery chain, FEEDBACK is the loop that closes it, GOVERNS is
     * the horizontal layer. The distinction is what lets the lifecycle diagram
     * draw a governance workstream ACROSS the flow rather than as another stage
     * in it — the customer's model is explicit that its WS03 "is deliberately
     * horizontal ... instead of becoming another sequential stage".
     */
    public const LINK_TYPES = ['FLOW', 'FEEDBACK', 'GOVERNS'];

    public function __construct(
        private WorkstreamRollup $rollup,
        private WorkstreamHealth $health,
    ) {
    }

    /**
     * GET /task-management/projects/{id}/workstreams
     *
     * The whole graph in ONE request: every workstream with its computed health,
     * every link between them, and a project-level summary. The diagram, the
     * cards and the roll-up strip are all fed from this — three requests for one
     * screen is how they end up disagreeing with each other.
     */
    public function index(Request $request, $id)
    {
        $context = $this->taskContext($request);
        if (! is_array($context)) {
            return $context;
        }

        $project = $this->projectScope($context, (int) $id);
        if (! $project) {
            return $this->fail('Project not found.', 404);
        }

        $workstreams = $this->workstreamRows((int) $project->id);
        $ids         = $workstreams->pluck('id')->map(fn ($v) => (int) $v)->all();

        $counts = $this->rollup->forProject($context, (int) $project->id, $ids);
        $childCounts = $this->childCounts($ids);

        $shaped = $workstreams->map(fn ($row) => $this->summaryResource(
            $row,
            $counts[(int) $row->id] ?? null,
            (int) ($childCounts[(int) $row->id] ?? 0)
        ))->values();

        return $this->ok('Workstreams retrieved successfully.', [
            'workstreams' => $shaped->all(),
            'links'       => $this->linkRows((int) $project->id),
            'summary'     => $this->projectSummary($shaped),
            'project'     => [
                'id'         => (string) $project->id,
                'code'       => $project->code,
                'name'       => $project->name,
                'start_date' => $project->start_date,
                'due_date'   => $project->due_date,
            ],
        ]);
    }

    /**
     * GET /task-management/workstreams/{id}
     *
     * All nine fields of the model in one response, because the screen shows all
     * nine at once — that is what makes it a 360 view rather than a form with
     * tabs. Nine round trips would guarantee a half-rendered page.
     */
    public function show(Request $request, $id)
    {
        $context = $this->taskContext($request);
        if (! is_array($context)) {
            return $context;
        }

        $scope = $this->workstreamScope($context, (int) $id);
        if (! $scope) {
            return $this->fail('Workstream not found.', 404);
        }

        $wsId = (int) $scope->id;

        $row     = $this->workstreamRows((int) $scope->project_id, $wsId)->first();
        $counts  = $this->rollup->forProject($context, (int) $scope->project_id, [$wsId]);
        $childIds = DB::table('task_management_workstreams')->where('parent_id', $wsId)->pluck('id')
            ->map(fn ($v) => (int) $v)->all();

        $statements = DB::table('task_management_workstream_statements')
            ->where('workstream_id', $wsId)
            ->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'kind', 'body', 'sort_order']);

        $detail = $this->summaryResource($row, $counts[$wsId] ?? null, count($childIds));

        // ── 2 Contributors ─────────────────────────────────────────────────
        $detail['members'] = DB::table('task_management_workstream_members as m')
            ->leftJoin('tbluser as u', 'u.id', '=', 'm.user_id')
            ->where('m.workstream_id', $wsId)
            ->orderBy('m.sort_order')->orderBy('m.id')
            ->get([
                'm.id', 'm.user_id', 'm.role', 'm.lane', 'm.sort_order',
                DB::raw("TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) as user_name"),
            ])->map(fn ($m) => [
                'id'        => (string) $m->id,
                'user_id'   => (string) $m->user_id,
                'user_name' => $m->user_name ?: null,
                'role'      => $m->role,
                'lane'      => $m->lane,
            ])->all();

        // ── 3 + 8 Responsibilities and scope, split by kind ────────────────
        $detail['statements'] = [
            'responsibilities' => $this->statementsOf($statements, 'RESPONSIBILITY'),
            'in_scope'         => $this->statementsOf($statements, 'IN_SCOPE'),
            'out_of_scope'     => $this->statementsOf($statements, 'OUT_OF_SCOPE'),
        ];

        // ── 4 Deliverables ─────────────────────────────────────────────────
        $detail['deliverables'] = DB::table('task_management_workstream_deliverables as d')
            ->leftJoin('tbluser as u', 'u.id', '=', 'd.owner_id')
            ->leftJoin('task_management_workstream_checkpoints as c', 'c.id', '=', 'd.checkpoint_id')
            ->where('d.workstream_id', $wsId)
            ->orderBy('d.sort_order')->orderBy('d.id')
            ->get([
                'd.id', 'd.name', 'd.description', 'd.acceptance_criteria', 'd.status',
                'd.due_date', 'd.delivered_at', 'd.owner_id', 'd.checkpoint_id', 'd.milestone_id',
                'c.name as checkpoint_name',
                DB::raw("TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) as owner_name"),
            ])->map(fn ($d) => [
                'id'                  => (string) $d->id,
                'name'                => $d->name,
                'description'         => $d->description,
                'acceptance_criteria' => $d->acceptance_criteria,
                'status'              => $d->status,
                'due_date'            => $d->due_date,
                'delivered_at'        => $d->delivered_at,
                'owner_id'            => $d->owner_id ? (string) $d->owner_id : null,
                'owner_name'          => $d->owner_name ?: null,
                'checkpoint_id'       => $d->checkpoint_id ? (string) $d->checkpoint_id : null,
                'checkpoint_name'     => $d->checkpoint_name,
            ])->all();

        // ── 5 Checkpoints ──────────────────────────────────────────────────
        $detail['checkpoints'] = DB::table('task_management_workstream_checkpoints')
            ->where('workstream_id', $wsId)
            ->orderByRaw('target_date IS NULL')   // dated ones first, undated last
            ->orderBy('target_date')->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'name', 'description', 'target_date', 'status', 'is_critical', 'completed_at'])
            ->map(fn ($c) => [
                'id'           => (string) $c->id,
                'name'         => $c->name,
                'description'  => $c->description,
                'target_date'  => $c->target_date,
                'status'       => $c->status,
                'is_critical'  => (bool) $c->is_critical,
                'completed_at' => $c->completed_at,
            ])->all();

        // ── 7 KPIs ─────────────────────────────────────────────────────────
        $detail['kpis'] = DB::table('task_management_workstream_kpis')
            ->where('workstream_id', $wsId)
            ->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'name', 'metric', 'unit', 'direction', 'baseline_value', 'target_value',
                   'current_value', 'measured_at', 'status', 'weightage', 'source', 'owner_id'])
            ->map(fn ($k) => [
                'id'             => (string) $k->id,
                'name'           => $k->name,
                'metric'         => $k->metric,
                'unit'           => $k->unit,
                'direction'      => $k->direction,
                'baseline_value' => $k->baseline_value,
                'target_value'   => $k->target_value,
                // NULL travels as null, never as 0 or "". The client renders
                // "Not yet measured"; a zero would assert a reading nobody took.
                'current_value'  => $k->current_value,
                'measured_at'    => $k->measured_at,
                'status'         => $k->status,
                'weightage'      => (float) $k->weightage,
                'source'         => $k->source,
                'owner_id'       => $k->owner_id ? (string) $k->owner_id : null,
            ])->all();

        // ── 9 Risks ────────────────────────────────────────────────────────
        $detail['risks'] = DB::table('task_management_workstream_risks as r')
            ->leftJoin('tbluser as u', 'u.id', '=', 'r.owner_id')
            ->where('r.workstream_id', $wsId)
            ->orderByRaw("FIELD(r.severity, 'Regulated', 'High', 'Medium', 'Low')")
            ->orderBy('r.id')
            ->get(['r.id', 'r.title', 'r.description', 'r.category', 'r.probability', 'r.impact',
                   'r.severity', 'r.mitigation', 'r.contingency', 'r.status', 'r.due_date',
                   'r.closed_at', 'r.owner_id',
                   DB::raw("TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) as owner_name")])
            ->map(fn ($r) => [
                'id'          => (string) $r->id,
                'title'       => $r->title,
                'description' => $r->description,
                'category'    => $r->category,
                'probability' => $r->probability,
                'impact'      => $r->impact,
                'severity'    => $r->severity,
                'mitigation'  => $r->mitigation,
                'contingency' => $r->contingency,
                'status'      => $r->status,
                'due_date'    => $r->due_date,
                'closed_at'   => $r->closed_at,
                'owner_id'    => $r->owner_id ? (string) $r->owner_id : null,
                'owner_name'  => $r->owner_name ?: null,
            ])->all();

        // ── 6 Dependencies: the free-text half and the graph half ──────────
        $external = DB::table('task_management_workstream_dependencies')
            ->where('workstream_id', $wsId)
            ->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'direction', 'description', 'source', 'needed_by', 'status', 'is_blocking']);

        $detail['dependencies'] = [
            'upstream'   => $this->dependenciesOf($external, 'UPSTREAM'),
            'downstream' => $this->dependenciesOf($external, 'DOWNSTREAM'),
        ];

        $links = collect($this->linkRows((int) $scope->project_id));

        $detail['upstream']     = $links->where('to_id', (string) $wsId)->whereIn('link_type', ['FLOW', 'FEEDBACK'])->values()->all();
        $detail['downstream']   = $links->where('from_id', (string) $wsId)->whereIn('link_type', ['FLOW', 'FEEDBACK'])->values()->all();
        $detail['governed_by']  = $links->where('to_id', (string) $wsId)->where('link_type', 'GOVERNS')->values()->all();
        $detail['governs']      = $links->where('from_id', (string) $wsId)->where('link_type', 'GOVERNS')->values()->all();

        // ── Sub-workstreams and linked tasks ───────────────────────────────
        $detail['children'] = $childIds === [] ? [] : $this->workstreamRows((int) $scope->project_id)
            ->whereIn('id', $childIds)
            ->map(fn ($row) => [
                'id'   => (string) $row->id,
                'code' => $row->code,
                'name' => $row->name,
                'kind' => $row->kind,
            ])->values()->all();

        $detail['tasks'] = DB::table('task_management_project_tasks as pt')
            ->join('task as t', 't.id', '=', 'pt.task_id')
            ->leftJoin('tbluser as a', 'a.id', '=', 't.task_allocated_to')
            ->where('pt.workstream_id', $wsId)
            ->whereNull('t.deleted_at')
            ->orderBy('t.task_date')
            ->limit(200)
            ->get(['t.id', 't.task_title as title', 't.status', 't.task_date as due_date',
                   DB::raw("TRIM(CONCAT_WS(' ', a.first_name, a.middle_name, a.last_name)) as assignee")])
            ->map(fn ($t) => [
                'id'       => (string) $t->id,
                'title'    => $t->title,
                'status'   => $t->status,
                'due_date' => $t->due_date,
                'assignee' => $t->assignee ?: null,
            ])->all();

        $detail['project'] = [
            'id'   => (string) $scope->project_id,
            'code' => $scope->project_code,
            'name' => $scope->project_name,
        ];

        $detail['can_manage'] = $this->canManageWorkstream($context, $scope);

        return $this->ok('Workstream retrieved successfully.', $detail);
    }

    /**
     * GET /task-management/workstreams/options
     *
     * Every vocabulary the editors need, from the server. A client that hardcodes
     * these drifts the moment one changes, and there is no way to tell from the
     * outside that it has.
     */
    public function options(Request $request)
    {
        $context = $this->taskContext($request);
        if (! is_array($context)) {
            return $context;
        }

        return $this->ok('Options retrieved successfully.', [
            'kinds'                => self::KINDS,
            'statuses'             => self::STATUSES,
            'link_types'           => self::LINK_TYPES,
            'member_roles'         => WorkstreamRecordController::MEMBER_ROLES,
            'statement_kinds'      => WorkstreamRecordController::STATEMENT_KINDS,
            'deliverable_statuses' => WorkstreamRecordController::DELIVERABLE_STATUSES,
            'checkpoint_statuses'  => WorkstreamRecordController::CHECKPOINT_STATUSES,
            'kpi_statuses'         => WorkstreamRecordController::KPI_STATUSES,
            'kpi_directions'       => WorkstreamRecordController::KPI_DIRECTIONS,
            'risk_levels'          => WorkstreamRecordController::RISK_LEVELS,
            'risk_probabilities'   => WorkstreamRecordController::RISK_PROBABILITIES,
            'risk_statuses'        => WorkstreamRecordController::RISK_STATUSES,
            'dependency_directions' => WorkstreamRecordController::DEPENDENCY_DIRECTIONS,
            'dependency_statuses'  => WorkstreamRecordController::DEPENDENCY_STATUSES,
        ]);
    }

    /** POST /task-management/projects/{id}/workstreams */
    public function store(Request $request, $id)
    {
        $context = $this->taskContext($request);
        if (! is_array($context)) {
            return $context;
        }

        $project = $this->projectScope($context, (int) $id);
        if (! $project) {
            return $this->fail('Project not found.', 404);
        }
        if (! $this->canManageProject($context, $project)) {
            return $this->fail('You cannot manage workstreams on this project.', 403);
        }

        $validator = $this->validator($request, (int) $project->id, null);
        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422, $validator->errors()->toArray());
        }

        $guard = $this->guardRelations($context, $request, (int) $project->id, null);
        if ($guard !== null) {
            return $guard;
        }

        $payload = $this->payload($request);
        // CREATE ONLY. A new workstream with no kind is a delivery stage; an
        // EXISTING one with no kind in the payload is one whose kind was simply
        // not part of this request, and must keep what it has.
        $payload['kind'] ??= 'DELIVERY';
        $payload['project_id'] = (int) $project->id;
        $payload['created_by'] = $context['user_id'];
        $payload['created_at'] = now();
        $payload['updated_at'] = now();

        // Appended, not slotted in: sort_order defaults to the end of the list
        // rather than to a count, so re-ordering later is a deliberate act.
        $payload['sort_order'] ??= (int) DB::table('task_management_workstreams')
            ->where('project_id', $project->id)->max('sort_order') + 1;

        $newId = (int) DB::table('task_management_workstreams')->insertGetId($payload);

        return $this->ok('Workstream created successfully.', ['id' => (string) $newId], 201);
    }

    /** PUT /task-management/projects/{projectId}/workstreams/{workstreamId} */
    public function update(Request $request, $projectId, $workstreamId)
    {
        $context = $this->taskContext($request);
        if (! is_array($context)) {
            return $context;
        }

        $scope = $this->workstreamScope($context, (int) $workstreamId);
        if (! $scope || (int) $scope->project_id !== (int) $projectId) {
            return $this->fail('Workstream not found.', 404);
        }
        if (! $this->canManageWorkstream($context, $scope)) {
            return $this->fail('You cannot manage this workstream.', 403);
        }

        $validator = $this->validator($request, (int) $scope->project_id, (int) $scope->id);
        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422, $validator->errors()->toArray());
        }

        $guard = $this->guardRelations($context, $request, (int) $scope->project_id, (int) $scope->id);
        if ($guard !== null) {
            return $guard;
        }

        $payload = $this->payload($request);
        $payload['updated_by'] = $context['user_id'];
        $payload['updated_at'] = now();

        DB::table('task_management_workstreams')->where('id', $scope->id)->update($payload);

        return $this->ok('Workstream updated successfully.', ['id' => (string) $scope->id]);
    }

    /**
     * DELETE /task-management/projects/{projectId}/workstreams/{workstreamId}
     *
     * ── TWO THINGS THAT USED TO HAPPEN SILENTLY ────────────────────────────
     *
     * The old implementation was a bare delete() with no checks at all. It
     * returned 200 even when nothing was deleted, and it left
     * task_management_milestones.workstream_id pointing at a row that no longer
     * existed — that column has no foreign key, so nothing caught it. Project
     * tasks were fine only because THEIR column does have one.
     *
     * Now: a sub-workstream blocks the delete outright rather than being taken
     * with it, and milestones are released inside the same transaction.
     */
    public function destroy(Request $request, $projectId, $workstreamId)
    {
        $context = $this->taskContext($request);
        if (! is_array($context)) {
            return $context;
        }

        $scope = $this->workstreamScope($context, (int) $workstreamId);
        if (! $scope || (int) $scope->project_id !== (int) $projectId) {
            return $this->fail('Workstream not found.', 404);
        }

        $project = $this->projectScope($context, (int) $scope->project_id);
        if (! $project || ! $this->canManageProject($context, $project)) {
            return $this->fail('You cannot delete workstreams on this project.', 403);
        }

        $children = (int) DB::table('task_management_workstreams')
            ->where('parent_id', $scope->id)->count();

        if ($children > 0) {
            return $this->fail(sprintf(
                'This workstream has %d sub-workstream%s. Move or delete %s first.',
                $children, $children === 1 ? '' : 's', $children === 1 ? 'it' : 'them'
            ), 422);
        }

        DB::transaction(function () use ($scope) {
            // Released, not deleted: the milestone is the project's, not the
            // workstream's, and losing it would destroy a dated commitment.
            DB::table('task_management_milestones')
                ->where('workstream_id', $scope->id)
                ->update(['workstream_id' => null, 'updated_at' => now()]);

            // Child rows go by cascade; links go by cascade on both ends.
            DB::table('task_management_workstreams')->where('id', $scope->id)->delete();
        });

        return $this->ok('Workstream deleted successfully.');
    }

    /**
     * POST /task-management/projects/{id}/workstream-links
     *
     * ── CYCLES ARE REFUSED FOR FLOW ONLY ───────────────────────────────────
     *
     * A delivery chain that loops is a planning error. A FEEDBACK edge that loops
     * is the entire point of a 360 model — the customer's own diagram runs
     * WS04 back into WS01. A validator that refused every cycle would make their
     * model unrepresentable in the product built to hold it.
     */
    public function storeLink(Request $request, $id)
    {
        $context = $this->taskContext($request);
        if (! is_array($context)) {
            return $context;
        }

        $project = $this->projectScope($context, (int) $id);
        if (! $project) {
            return $this->fail('Project not found.', 404);
        }
        if (! $this->canManageProject($context, $project)) {
            return $this->fail('You cannot manage workstreams on this project.', 403);
        }

        $validator = Validator::make($request->all(), [
            'predecessor_workstream_id' => 'required|integer|min:1',
            'successor_workstream_id'   => 'required|integer|min:1|different:predecessor_workstream_id',
            'link_type'                 => ['required', Rule::in(self::LINK_TYPES)],
            'label'                     => 'nullable|string|max:100',
            'note'                      => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422, $validator->errors()->toArray());
        }

        $from = (int) $request->input('predecessor_workstream_id');
        $to   = (int) $request->input('successor_workstream_id');
        $type = (string) $request->input('link_type');

        // Both ends must belong to THIS project — not merely to this tenant.
        $belong = DB::table('task_management_workstreams')
            ->whereIn('id', [$from, $to])->where('project_id', $project->id)->count();

        if ($belong !== 2) {
            return $this->fail('Both workstreams must belong to this project.', 422);
        }

        if ($type === 'FLOW' && $this->wouldCycle($from, $to)) {
            return $this->fail(
                'That would create a loop in the delivery flow. Use a Feedback link if the later '
                . 'workstream feeds back into the earlier one.',
                422
            );
        }

        $exists = DB::table('task_management_workstream_links')
            ->where('predecessor_workstream_id', $from)
            ->where('successor_workstream_id', $to)
            ->where('link_type', $type)->exists();

        if ($exists) {
            return $this->fail('That link already exists.', 422);
        }

        $newId = (int) DB::table('task_management_workstream_links')->insertGetId([
            'project_id'                => (int) $project->id,
            'predecessor_workstream_id' => $from,
            'successor_workstream_id'   => $to,
            'link_type'                 => $type,
            'label'                     => $request->input('label'),
            'note'                      => $request->input('note'),
            'created_by'                => $context['user_id'],
            'created_at'                => now(),
            'updated_at'                => now(),
        ]);

        return $this->ok('Link created successfully.', ['id' => (string) $newId], 201);
    }

    /** DELETE /task-management/workstream-links/{id} */
    public function destroyLink(Request $request, $id)
    {
        $context = $this->taskContext($request);
        if (! is_array($context)) {
            return $context;
        }

        $link = DB::table('task_management_workstream_links as l')
            ->join('task_management_projects as p', 'p.id', '=', 'l.project_id')
            ->where('l.id', (int) $id)
            ->where('p.sub_institute_id', $context['sub_institute_id'])
            ->where('p.syear', $context['syear'])
            ->first(['l.id', 'p.id as project_id', 'p.created_by', 'p.manager_id', 'p.sponsor_id']);

        if (! $link) {
            return $this->fail('Link not found.', 404);
        }
        if (! $this->canManageProject($context, $link)) {
            return $this->fail('You cannot manage workstreams on this project.', 403);
        }

        DB::table('task_management_workstream_links')->where('id', $link->id)->delete();

        return $this->ok('Link deleted successfully.');
    }

    /* ------------------------------------------------------------------ *
     * Internals
     * ------------------------------------------------------------------ */

    private function workstreamRows(int $projectId, ?int $only = null)
    {
        return DB::table('task_management_workstreams as w')
            ->leftJoin('tbluser as o', 'o.id', '=', 'w.owner_id')
            ->where('w.project_id', $projectId)
            ->when($only !== null, fn ($q) => $q->where('w.id', $only))
            // GOVERNANCE last within the same sort_order, so a flow list reads as
            // the flow even before the client groups by kind.
            ->orderBy('w.sort_order')->orderBy('w.id')
            ->get([
                'w.id', 'w.project_id', 'w.parent_id', 'w.code', 'w.kind', 'w.name',
                'w.purpose', 'w.core_question', 'w.description', 'w.owner_id', 'w.status',
                'w.start_date', 'w.due_date', 'w.sort_order',
                DB::raw("TRIM(CONCAT_WS(' ', o.first_name, o.middle_name, o.last_name)) as owner_name"),
            ]);
    }

    private function linkRows(int $projectId): array
    {
        return DB::table('task_management_workstream_links')
            ->where('project_id', $projectId)
            ->orderBy('id')
            ->get(['id', 'project_id', 'predecessor_workstream_id', 'successor_workstream_id',
                   'link_type', 'label', 'note'])
            ->map(fn ($l) => [
                'id'         => (string) $l->id,
                'project_id' => (string) $l->project_id,
                'from_id'    => (string) $l->predecessor_workstream_id,
                'to_id'      => (string) $l->successor_workstream_id,
                'link_type'  => $l->link_type,
                'label'      => $l->label,
                'note'       => $l->note,
            ])->all();
    }

    /** Sub-workstream counts for a whole project in one query. */
    private function childCounts(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('task_management_workstreams')
            ->whereIn('parent_id', $ids)
            ->selectRaw('parent_id, COUNT(*) AS n')
            ->groupBy('parent_id')
            ->pluck('n', 'parent_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    private function summaryResource($row, ?array $counts, int $children): array
    {
        $counts ??= [
            'deliverables' => ['total' => 0, 'done' => 0, 'in_flight' => 0, 'open' => 0, 'overdue' => 0],
            'kpis'         => ['total' => 0, 'met' => 0, 'on_track' => 0, 'at_risk' => 0, 'off_track' => 0, 'unmeasured' => 0],
            'risks'        => ['open' => 0, 'closed' => 0, 'severe_open' => 0, 'moderate_open' => 0],
            'tasks'        => ['total' => 0, 'completed' => 0, 'overdue' => 0, 'blocked' => 0],
            'milestones'   => ['total' => 0, 'completed' => 0, 'overdue' => 0],
        ];

        $verdict = $this->health->evaluate($counts);

        return [
            'id'            => (string) $row->id,
            'project_id'    => (string) $row->project_id,
            'parent_id'     => $row->parent_id ? (string) $row->parent_id : null,
            'code'          => $row->code,
            'kind'          => $row->kind,
            'name'          => $row->name,
            // `purpose` is the field; `description` is the retired column it was
            // migrated from and is no longer part of the contract.
            'purpose'       => $row->purpose,
            'core_question' => $row->core_question,
            'owner_id'      => $row->owner_id ? (string) $row->owner_id : null,
            'owner_name'    => $row->owner_name ?: null,
            'status'        => $row->status,
            'start_date'    => $row->start_date,
            'due_date'      => $row->due_date,
            'sort_order'    => (int) $row->sort_order,
            'children_count' => $children,
            // null, not 0, when there are no deliverables to measure.
            'progress'      => $verdict['progress'],
            'health'        => [
                'state'        => $verdict['state'],
                'state_reason' => $verdict['state_reason'],
                'deliverables' => $counts['deliverables'],
                'kpis'         => $counts['kpis'],
                'risks'        => $counts['risks'],
                'tasks'        => $counts['tasks'],
                'milestones'   => $counts['milestones'],
            ],
        ];
    }

    /** Project-level totals, summed from the same numbers the cards show. */
    private function projectSummary($shaped): array
    {
        $states = [];
        $risks = $deliverables = $done = $kpisOff = 0;

        foreach ($shaped as $ws) {
            $states[$ws['health']['state']] = ($states[$ws['health']['state']] ?? 0) + 1;
            $risks        += $ws['health']['risks']['open'];
            $deliverables += $ws['health']['deliverables']['total'];
            $done         += $ws['health']['deliverables']['done'];
            $kpisOff      += $ws['health']['kpis']['off_track'] + $ws['health']['kpis']['at_risk'];
        }

        return [
            'workstreams'         => count($shaped),
            'by_state'            => $states,
            'open_risks'          => $risks,
            'deliverables_total'  => $deliverables,
            'deliverables_done'   => $done,
            'kpis_needing_attention' => $kpisOff,
        ];
    }

    private function statementsOf($statements, string $kind): array
    {
        return $statements->where('kind', $kind)->values()->map(fn ($s) => [
            'id'   => (string) $s->id,
            'body' => $s->body,
        ])->all();
    }

    private function dependenciesOf($rows, string $direction): array
    {
        return $rows->where('direction', $direction)->values()->map(fn ($d) => [
            'id'          => (string) $d->id,
            'description' => $d->description,
            'source'      => $d->source,
            'needed_by'   => $d->needed_by,
            'status'      => $d->status,
            'is_blocking' => (bool) $d->is_blocking,
        ])->all();
    }

    private function validator(Request $request, int $projectId, ?int $workstreamId)
    {
        return Validator::make($request->all(), [
            'name'          => 'required|string|max:191',
            'code'          => 'nullable|string|max:20',
            'kind'          => ['nullable', Rule::in(self::KINDS)],
            'purpose'       => 'nullable|string',
            'core_question' => 'nullable|string|max:191',
            'owner_id'      => 'nullable|integer|min:1',
            'parent_id'     => 'nullable|integer|min:1',
            'status'        => ['required', Rule::in(self::STATUSES)],
            'start_date'    => 'nullable|date',
            'due_date'      => 'nullable|date|after_or_equal:start_date',
            'sort_order'    => 'nullable|integer|min:0',
        ]);
    }

    /**
     * The relational checks a Validator cannot express.
     *
     * @return \Illuminate\Http\JsonResponse|null  null when everything is fine
     */
    private function guardRelations(array $context, Request $request, int $projectId, ?int $workstreamId)
    {
        $ownerId = (int) $request->input('owner_id', 0);

        if ($ownerId > 0) {
            $isMember = DB::table('task_management_project_members')
                ->where('project_id', $projectId)->where('user_id', $ownerId)->exists();

            if (! $isMember) {
                return $this->fail('The selected owner must be a project team member.', 422);
            }
        }

        $code = trim((string) $request->input('code', ''));

        if ($code !== '') {
            $clash = DB::table('task_management_workstreams')
                ->where('project_id', $projectId)->where('code', $code)
                ->when($workstreamId !== null, fn ($q) => $q->where('id', '!=', $workstreamId))
                ->exists();

            if ($clash) {
                return $this->fail('Another workstream in this project already uses the code "' . $code . '".', 422);
            }
        }

        $parentId = (int) $request->input('parent_id', 0);

        if ($parentId > 0) {
            if ($workstreamId !== null && $parentId === $workstreamId) {
                return $this->fail('A workstream cannot be its own parent.', 422);
            }

            $parent = DB::table('task_management_workstreams')
                ->where('id', $parentId)->where('project_id', $projectId)
                ->first(['id', 'parent_id']);

            if (! $parent) {
                return $this->fail('The parent workstream must belong to this project.', 422);
            }

            // ONE LEVEL ONLY. The model calls for sub-workstreams "when
            // complexity warrants", e.g. WS02.1 — not a tree. Allowing depth
            // would make the lifecycle diagram unrenderable and the roll-up
            // recursive, for a nesting nobody has asked for.
            if ($parent->parent_id !== null) {
                return $this->fail('Sub-workstreams cannot be nested more than one level deep.', 422);
            }
        }

        return null;
    }

    private function payload(Request $request): array
    {
        $payload = [];

        foreach (['name', 'code', 'kind', 'purpose', 'core_question', 'status'] as $field) {
            if ($request->has($field)) {
                $value = $request->input($field);

                /*
                 * `=== ''`, NOT `?:`. In PHP the string "0" is falsy, so
                 * `trim($value) ?: null` silently discards a code, purpose or
                 * core question of "0". Only an actually-empty string means
                 * "cleared".
                 */
                $payload[$field] = is_string($value)
                    ? (trim($value) === '' ? null : trim($value))
                    : $value;
            }
        }

        // Name is NOT NULL; an all-whitespace name would have become null above.
        if (array_key_exists('name', $payload) && $payload['name'] === null) {
            $payload['name'] = trim((string) $request->input('name'));
        }

        /*
         * NO `kind` DEFAULT HERE — see store(), which applies it on create only.
         *
         * This method used to end with `$payload['kind'] ??= 'DELIVERY';`, which
         * is right for a new workstream and data loss for an existing one: any
         * update that omitted `kind` — a partial save, or a form that simply does
         * not carry the field — rewrote GOVERNANCE to DELIVERY.
         *
         * That is the one distinction this whole feature rests on. WS03 would
         * stop being the horizontal governance layer, the lifecycle diagram would
         * redraw it as a fourth delivery stage, and nothing would error.
         */

        foreach (['owner_id', 'parent_id', 'sort_order'] as $field) {
            if ($request->has($field)) {
                $payload[$field] = $request->input($field) !== null && $request->input($field) !== ''
                    ? (int) $request->input($field) : null;
            }
        }

        foreach (['start_date', 'due_date'] as $field) {
            if ($request->has($field)) {
                $value = trim((string) $request->input($field));
                $payload[$field] = $value !== '' ? substr($value, 0, 10) : null;
            }
        }

        return $payload;
    }

    /**
     * Would adding from -> to close a loop in the FLOW graph?
     *
     * Walks forward from `to` looking for `from`, over FLOW edges only, with a
     * visited set so an existing loop cannot spin here.
     */
    private function wouldCycle(int $from, int $to): bool
    {
        $edges = DB::table('task_management_workstream_links')
            ->where('link_type', 'FLOW')
            ->get(['predecessor_workstream_id', 'successor_workstream_id'])
            ->groupBy('predecessor_workstream_id');

        $queue   = [$to];
        $visited = [];

        while ($queue !== []) {
            $node = array_shift($queue);

            if ($node === $from) {
                return true;
            }
            if (isset($visited[$node])) {
                continue;
            }

            $visited[$node] = true;

            foreach ($edges[$node] ?? [] as $edge) {
                $queue[] = (int) $edge->successor_workstream_id;
            }
        }

        return false;
    }
}
