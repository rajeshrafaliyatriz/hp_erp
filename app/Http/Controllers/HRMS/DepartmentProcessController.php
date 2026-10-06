<?php

namespace App\Http\Controllers\HRMS;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Services\HRMS\DepartmentProcedureAiNormalizer;
use App\Services\HRMS\DepartmentProcedureParser;
use App\Services\Tasks\TaskPublisher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * The Process tab's builder: CRUD for a department's named processes, the
 * canvas (steps + edges) each one holds, and publish/version history.
 *
 * Deliberately NOT a DepartmentContentController subclass. That base class
 * models one flat table; a process is a parent row plus two child
 * collections (steps, edges) that are replaced wholesale on every canvas
 * save, which the shared index/store/update/destroy do not express. What IS
 * shared with it, and kept identical on purpose, is how identity and
 * ownership are resolved: ResolvesApiIdentity for the token, then an
 * in-controller departmentBelongsToTenant() check, then a department_id +
 * sub_institute_id filter on every query - the same shape as
 * DepartmentSopController/DepartmentPolicyController/DepartmentRuleController,
 * with no route-level middleware either.
 */
class DepartmentProcessController extends Controller
{
    use ResolvesApiIdentity;

    public function __construct(
        private readonly DepartmentProcedureParser $parser,
        private readonly DepartmentProcedureAiNormalizer $aiNormalizer,
        private readonly TaskPublisher $taskPublisher,
    ) {
    }

    /**
     * The template picker's data: step-type palette, category list (grouped),
     * and the starter step list for each category. No identity/tenant check -
     * this is the same editable fixture for every tenant, exactly like
     * config/documents.php's types are not tenant-scoped either.
     */
    public function templates()
    {
        return response()->json([
            'status' => 1,
            'data'   => [
                'step_types' => config('department_processes.step_types', []),
                'categories' => config('department_processes.categories', []),
                'templates'  => config('department_processes.templates', []),
            ],
        ]);
    }

    /**
     * Read a pasted SOP procedure into structure, without storing anything.
     *
     * The K12-parity "Source" step: a department describes a process in
     * prose (optionally the rich "Actor | User action | System action |
     * Decision | Result" form, see DepartmentProcedureParser) and gets back
     * a full spec - objective/trigger/preconditions/inputs/completion/
     * outputs/handoffs, ordered steps, resolved business-rule citations, and
     * tasks already sorted into Readiness/Human gate/Workflow step/Handover -
     * to review before Apply to Canvas / Assign and Publish act on it.
     *
     * `use_ai: true` asks DepartmentProcedureAiNormalizer to re-express text
     * the deterministic parser couldn't read, ONLY once that first pass has
     * actually reported issues - never on a clean parse, same as K12. The
     * AI's answer is re-run through the exact same parser, never trusted
     * directly; see that normalizer's docblock.
     */
    public function convertSource(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];

        $departmentId = (int) $request->input('department_id');
        if (!$this->departmentBelongsToTenant($departmentId, $tenantId)) {
            return response()->json(['status' => 0, 'message' => 'Department not found'], 404);
        }

        $text = trim((string) $request->input('source_text', ''));
        if ($text === '') {
            return response()->json([
                'status'  => 0,
                'message' => 'Paste the procedure first.',
                'errors'  => ['source_text' => ['Required.']],
            ], 422);
        }

        $department = DB::table('hrms_departments')->where('id', $departmentId)->first();
        $departmentName = $department->department ?? 'This department';

        $categoryKey = trim((string) $request->input('category', ''));
        $categoryLabel = null;
        foreach (config('department_processes.categories', []) as $category) {
            if (($category['key'] ?? null) === $categoryKey) {
                $categoryLabel = $category['label'] ?? null;
                break;
            }
        }

        $fallbackName = trim((string) $request->input('name', '')) ?: 'Untitled process';

        $spec = $this->parser->parse($text, $departmentId, $departmentName, $categoryLabel, $fallbackName);
        $source = 'deterministic';
        $aiStatus = null;

        if ($spec['issues'] !== [] && $request->boolean('use_ai')) {
            $normalized = $this->aiNormalizer->normalize($text);

            if ($normalized['ok']) {
                $spec = $this->parser->parse($normalized['text'], $departmentId, $departmentName, $categoryLabel, $fallbackName);
                $source = 'ai';
            } else {
                $aiStatus = ['reason' => $normalized['reason'], 'detail' => $normalized['detail']];
            }
        }

        return response()->json([
            'status' => 1,
            'data'   => ['spec' => $spec, 'source' => $source, 'ai_status' => $aiStatus],
        ]);
    }

    /**
     * Raise selected task drafts from a converted process's saved spec as
     * real `task` rows - K12's "Assign and Publish", the one genuinely new
     * write path (Save already persists the process itself via store()/
     * updateCanvas(), same as any other process).
     *
     * The task drafts live in `canvas_meta.tasks` on the saved process (the
     * frontend stashes the full converted spec there when it saves) - never
     * re-sent by the client, so a publish always acts on what was actually
     * saved rather than whatever the browser still has in memory.
     */
    public function publishTasks(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];
        $actorId  = $identity['user_id'];

        $process = $this->findForTenant($id, $tenantId);
        if (!$process) {
            return response()->json(['status' => 0, 'message' => 'Process not found'], 404);
        }

        $assignments = $request->input('assignments');
        if (!is_array($assignments) || $assignments === []) {
            return response()->json([
                'status'  => 0,
                'message' => 'Say who each task is for before publishing.',
                'errors'  => ['assignments' => ['Expected a map of task ref to user id.']],
            ], 422);
        }

        $meta = json_decode((string) $process->canvas_meta, true) ?: [];
        $drafts = collect($meta['tasks'] ?? [])->keyBy('ref');

        $already = DB::table('department_process_task_drafts')->where('process_id', $process->id)->pluck('task_ref')->all();

        $created = [];
        $skipped = [];
        $problems = [];

        foreach ($assignments as $ref => $assigneeId) {
            $ref = (string) $ref;
            $assigneeId = (int) $assigneeId;

            if (!$drafts->has($ref)) {
                $problems[] = '"' . $ref . '" is not a task this process derives.';
                continue;
            }
            if (in_array($ref, $already, true)) {
                $skipped[] = $ref;
                continue;
            }
            if ($assigneeId <= 0) {
                $problems[] = '"' . $ref . '" has nobody assigned.';
                continue;
            }

            // The assignee must be an active member of this tenant - without
            // this a publish could put work in another organisation's queue.
            $inTenant = DB::table('tbluser')
                ->where('id', $assigneeId)
                ->where('sub_institute_id', $tenantId)
                ->where('status', 1)
                ->exists();

            if (!$inTenant) {
                $problems[] = '"' . $ref . '" names somebody who is not an active member of this organisation.';
                continue;
            }

            $task = $drafts->get($ref);
            $key = sprintf('dept-process-convert-%d-%s', $process->id, $ref);

            $taskId = $this->taskPublisher->publish(
                title: (string) $task['title'],
                description: 'Raised from the process "' . mb_substr((string) $process->name, 0, 150) . '".',
                dueDate: now()->addDays(max(1, (int) ($task['due_in_days'] ?? 7))),
                assigneeId: $assigneeId,
                allocatedBy: $actorId,
                subInstituteId: $tenantId,
                idempotencyKey: $key,
                priority: (string) ($task['priority'] ?? 'Medium'),
                kra: isset($task['kra']) ? (string) $task['kra'] : null,
                kpa: isset($task['kpa']) ? (string) $task['kpa'] : null,
            );

            DB::table('department_process_task_drafts')->insert([
                'process_id'       => $process->id,
                'department_id'    => $process->department_id,
                'sub_institute_id' => $tenantId,
                'task_id'          => $taskId,
                'task_ref'         => $ref,
                'category'         => (string) ($task['category'] ?? ''),
                'title'            => mb_substr((string) $task['title'], 0, 255),
                'assignee_id'      => $assigneeId,
                'idempotency_key'  => $key,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            $created[] = ['ref' => $ref, 'task_id' => $taskId];
        }

        return response()->json([
            'status' => 1,
            'data'   => [
                'created'            => $created,
                'already_published'  => $skipped,
                'problems'           => $problems,
            ],
        ]);
    }

    /**
     * Processes for one department, lightest shape the library view needs.
     */
    public function index(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];

        $departmentId = (int) $request->query('department_id');

        if ($departmentId <= 0) {
            return response()->json(['status' => 0, 'message' => 'department_id is required'], 422);
        }

        if (!$this->departmentBelongsToTenant($departmentId, $tenantId)) {
            return response()->json(['status' => 0, 'message' => 'Department not found'], 404);
        }

        $nameExpression = function (string $alias): string {
            return "COALESCE(NULLIF(TRIM(CONCAT_WS(' ', {$alias}.first_name, {$alias}.middle_name, {$alias}.last_name)), ''), {$alias}.user_name)";
        };

        $query = DB::table('department_processes as p')
            ->leftJoin('tbluser as cu', 'cu.id', '=', 'p.created_by')
            ->leftJoin('tbluser as uu', 'uu.id', '=', 'p.updated_by')
            ->where('p.department_id', $departmentId)
            ->where('p.sub_institute_id', $tenantId)
            ->whereNull('p.deleted_at')
            ->select([
                'p.*',
                DB::raw($nameExpression('cu') . ' as created_by_name'),
                DB::raw($nameExpression('uu') . ' as updated_by_name'),
                DB::raw('(SELECT COUNT(*) FROM department_process_steps s WHERE s.process_id = p.id) as step_count'),
            ]);

        if ($status = trim((string) $request->query('status', ''))) {
            $query->where('p.status', $status);
        }

        if ($category = trim((string) $request->query('category', ''))) {
            $query->where('p.category', $category);
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('p.name', 'like', '%' . $search . '%')
                  ->orWhere('p.code', 'like', '%' . $search . '%');
            });
        }

        $processes = $query->orderByDesc('p.updated_at')->orderByDesc('p.id')->get();

        // The library card's animated mini-preview needs each process's own
        // steps/edges, not just a count. Two bulk queries across every listed
        // process rather than one per card avoids an N+1 - a department's
        // process list is small, but opening the tab should still be one
        // round trip's worth of queries, not one per card.
        $ids = $processes->pluck('id');
        $stepsByProcess = DB::table('department_process_steps')
            ->whereIn('process_id', $ids)
            ->orderBy('id')
            ->get()
            ->groupBy('process_id');
        $edgesByProcess = DB::table('department_process_edges')
            ->whereIn('process_id', $ids)
            ->orderBy('order')
            ->get()
            ->groupBy('process_id');

        $processes = $processes->map(function ($process) use ($stepsByProcess, $edgesByProcess) {
            $data = (array) $process;
            $data['steps'] = ($stepsByProcess[$process->id] ?? collect())->values();
            $data['edges'] = ($edgesByProcess[$process->id] ?? collect())->values();

            return $data;
        });

        return response()->json([
            'status' => 1,
            'data'   => $processes,
        ]);
    }

    /**
     * One process with its full graph - what the canvas builder loads.
     */
    public function show(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];

        $process = $this->findForTenant($id, $tenantId);
        if (!$process) {
            return response()->json(['status' => 0, 'message' => 'Process not found'], 404);
        }

        return response()->json([
            'status' => 1,
            'data'   => $this->withGraph($process),
        ]);
    }

    /**
     * Create a process, optionally seeded from a config/department_processes.php
     * template keyed by category - the template picker's "use this template"
     * action. A blank/custom process simply omits template_key.
     */
    public function store(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];
        $actorId  = $identity['user_id'];

        $validator = Validator::make($request->all(), [
            'department_id' => 'required|integer',
            'name'          => 'required|string|max:191',
            'code'          => 'nullable|string|max:50',
            'category'      => 'nullable|string|max:100',
            'description'   => 'nullable|string',
            'template_key'  => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 0,
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $departmentId = (int) $request->input('department_id');

        if (!$this->departmentBelongsToTenant($departmentId, $tenantId)) {
            return response()->json(['status' => 0, 'message' => 'Department not found'], 404);
        }

        $processId = DB::transaction(function () use ($request, $departmentId, $tenantId, $actorId) {
            $processId = DB::table('department_processes')->insertGetId([
                'department_id'    => $departmentId,
                'sub_institute_id' => $tenantId,
                'name'             => trim((string) $request->input('name')),
                'code'             => $request->input('code') ?: null,
                'category'         => $request->input('category') ?: null,
                'description'      => $request->input('description') ?: null,
                'status'           => 'draft',
                'current_version'  => 0,
                'trigger_type'     => 'manual',
                'created_by'       => $actorId,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            $templateKey = $request->input('template_key');
            if ($templateKey) {
                $this->seedFromTemplate($processId, $tenantId, $actorId, (string) $templateKey);
            }

            return $processId;
        });

        return response()->json([
            'status'  => 1,
            'message' => 'Process created successfully',
            'data'    => $this->withGraph($this->findForTenant($processId, $tenantId)),
        ], 201);
    }

    /**
     * Edit a process's meta fields only. Steps/edges go through updateCanvas().
     */
    public function update(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];
        $actorId  = $identity['user_id'];

        $existing = $this->findForTenant($id, $tenantId);
        if (!$existing) {
            return response()->json(['status' => 0, 'message' => 'Process not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name'           => 'sometimes|required|string|max:191',
            'code'           => 'nullable|string|max:50',
            'category'       => 'nullable|string|max:100',
            'description'    => 'nullable|string',
            'status'         => 'nullable|string|in:draft,active,archived',
            'trigger_type'   => 'nullable|string|in:manual,event,scheduled',
            'trigger_config' => 'nullable|array',
            'canvas_meta'    => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 0,
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $updates = [];
        foreach (['name', 'code', 'category', 'description', 'status', 'trigger_type'] as $field) {
            if ($request->has($field)) {
                $value = $request->input($field);
                $updates[$field] = is_string($value) ? (trim($value) ?: null) : $value;
            }
        }
        if ($request->has('trigger_config')) {
            $updates['trigger_config'] = json_encode($request->input('trigger_config'));
        }
        if ($request->has('canvas_meta')) {
            $updates['canvas_meta'] = json_encode($request->input('canvas_meta'));
        }

        if ($updates === []) {
            return response()->json(['status' => 1, 'message' => 'Nothing to update']);
        }

        $updates['updated_by'] = $actorId;
        $updates['updated_at'] = now();

        DB::table('department_processes')
            ->where('id', $existing->id)
            ->where('sub_institute_id', $tenantId)
            ->update($updates);

        return response()->json([
            'status'  => 1,
            'message' => 'Process updated successfully',
            'data'    => $this->withGraph($this->findForTenant($existing->id, $tenantId)),
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];
        $actorId  = $identity['user_id'];

        $existing = $this->findForTenant($id, $tenantId);
        if (!$existing) {
            return response()->json(['status' => 0, 'message' => 'Process not found'], 404);
        }

        DB::table('department_processes')
            ->where('id', $existing->id)
            ->where('sub_institute_id', $tenantId)
            ->update([
                'deleted_by' => $actorId,
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json(['status' => 1, 'message' => 'Process deleted successfully']);
    }

    /**
     * Copy a process - its meta, reset to draft, plus its current steps and
     * edges. node_key values are reused as-is: uniqueness is scoped to
     * process_id (see the migration), so the clone's own node_keys never
     * collide with the source's.
     */
    public function duplicate(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];
        $actorId  = $identity['user_id'];

        $source = $this->findForTenant($id, $tenantId);
        if (!$source) {
            return response()->json(['status' => 0, 'message' => 'Process not found'], 404);
        }

        $newId = DB::transaction(function () use ($source, $tenantId, $actorId) {
            $newId = DB::table('department_processes')->insertGetId([
                'department_id'    => $source->department_id,
                'sub_institute_id' => $tenantId,
                'name'             => $source->name . ' (Copy)',
                'code'             => null,
                'category'         => $source->category,
                'description'      => $source->description,
                'status'           => 'draft',
                'current_version'  => 0,
                'trigger_type'     => $source->trigger_type,
                'trigger_config'   => $source->trigger_config,
                'canvas_meta'      => $source->canvas_meta,
                'created_by'       => $actorId,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            $steps = DB::table('department_process_steps')->where('process_id', $source->id)->get();
            foreach ($steps as $step) {
                DB::table('department_process_steps')->insert([
                    'process_id'       => $newId,
                    'sub_institute_id' => $tenantId,
                    'node_key'         => $step->node_key,
                    'step_type'        => $step->step_type,
                    'title'            => $step->title,
                    'description'      => $step->description,
                    'assignee_type'    => $step->assignee_type,
                    'assignee_value'   => $step->assignee_value,
                    'sla_value'        => $step->sla_value,
                    'sla_unit'         => $step->sla_unit,
                    'linked_sop_id'    => $step->linked_sop_id,
                    'linked_policy_id' => $step->linked_policy_id,
                    'linked_rule_id'   => $step->linked_rule_id,
                    'config'           => $step->config,
                    'position_x'       => $step->position_x,
                    'position_y'       => $step->position_y,
                    'is_required'      => $step->is_required,
                    'created_by'       => $actorId,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);
            }

            $edges = DB::table('department_process_edges')->where('process_id', $source->id)->get();
            foreach ($edges as $edge) {
                DB::table('department_process_edges')->insert([
                    'process_id'       => $newId,
                    'sub_institute_id' => $tenantId,
                    'source_node_key'  => $edge->source_node_key,
                    'target_node_key'  => $edge->target_node_key,
                    'label'            => $edge->label,
                    'condition'        => $edge->condition,
                    'order'            => $edge->order,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);
            }

            return $newId;
        });

        return response()->json([
            'status'  => 1,
            'message' => 'Process duplicated successfully',
            'data'    => $this->withGraph($this->findForTenant($newId, $tenantId)),
        ], 201);
    }

    /**
     * Replace a process's entire graph in one request.
     *
     * The canvas editor holds the full node/edge set client-side and calls
     * this on save - a bulk sync, not per-node CRUD, because that is how the
     * editor's state naturally shapes a request. Steps/edges carry no
     * soft-delete column (see the migration): they are not an independently
     * addressable resource, so a save simply replaces them inside one
     * transaction rather than diffing old vs new.
     */
    public function updateCanvas(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];
        $actorId  = $identity['user_id'];

        $process = $this->findForTenant($id, $tenantId);
        if (!$process) {
            return response()->json(['status' => 0, 'message' => 'Process not found'], 404);
        }

        $stepTypes = array_keys(config('department_processes.step_types', []));

        $validator = Validator::make($request->all(), [
            'steps'                   => 'present|array',
            'steps.*.node_key'        => 'required|string|max:36',
            'steps.*.step_type'       => 'required|string|in:' . implode(',', $stepTypes),
            'steps.*.title'           => 'required|string|max:191',
            'steps.*.description'     => 'nullable|string',
            'steps.*.assignee_type'   => 'nullable|string|max:30',
            'steps.*.assignee_value'  => 'nullable|string|max:191',
            'steps.*.sla_value'       => 'nullable|integer|min:0',
            'steps.*.sla_unit'        => 'nullable|string|max:10',
            'steps.*.linked_sop_id'   => 'nullable|integer',
            'steps.*.linked_policy_id' => 'nullable|integer',
            'steps.*.linked_rule_id'  => 'nullable|integer',
            'steps.*.config'          => 'nullable|array',
            'steps.*.position_x'      => 'nullable|numeric',
            'steps.*.position_y'      => 'nullable|numeric',
            'steps.*.is_required'     => 'nullable|boolean',
            'edges'                   => 'present|array',
            'edges.*.source_node_key' => 'required|string|max:36',
            'edges.*.target_node_key' => 'required|string|max:36',
            'edges.*.label'           => 'nullable|string|max:100',
            'edges.*.condition'       => 'nullable|array',
            'edges.*.order'           => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 0,
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        DB::transaction(function () use ($request, $process, $tenantId, $actorId) {
            DB::table('department_process_steps')->where('process_id', $process->id)->delete();
            DB::table('department_process_edges')->where('process_id', $process->id)->delete();

            foreach ((array) $request->input('steps', []) as $step) {
                DB::table('department_process_steps')->insert([
                    'process_id'       => $process->id,
                    'sub_institute_id' => $tenantId,
                    'node_key'         => $step['node_key'],
                    'step_type'        => $step['step_type'],
                    'title'            => trim((string) $step['title']),
                    'description'      => $step['description'] ?? null,
                    'assignee_type'    => $step['assignee_type'] ?? null,
                    'assignee_value'   => $step['assignee_value'] ?? null,
                    'sla_value'        => $step['sla_value'] ?? null,
                    'sla_unit'         => $step['sla_unit'] ?? null,
                    'linked_sop_id'    => $step['linked_sop_id'] ?? null,
                    'linked_policy_id' => $step['linked_policy_id'] ?? null,
                    'linked_rule_id'   => $step['linked_rule_id'] ?? null,
                    'config'           => isset($step['config']) ? json_encode($step['config']) : null,
                    'position_x'       => $step['position_x'] ?? 0,
                    'position_y'       => $step['position_y'] ?? 0,
                    'is_required'      => $step['is_required'] ?? true,
                    'created_by'       => $actorId,
                    'updated_by'       => $actorId,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);
            }

            foreach ((array) $request->input('edges', []) as $edge) {
                DB::table('department_process_edges')->insert([
                    'process_id'       => $process->id,
                    'sub_institute_id' => $tenantId,
                    'source_node_key'  => $edge['source_node_key'],
                    'target_node_key'  => $edge['target_node_key'],
                    'label'            => $edge['label'] ?? null,
                    'condition'        => isset($edge['condition']) ? json_encode($edge['condition']) : null,
                    'order'            => $edge['order'] ?? 0,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);
            }

            DB::table('department_processes')
                ->where('id', $process->id)
                ->update(['updated_by' => $actorId, 'updated_at' => now()]);
        });

        return response()->json([
            'status'  => 1,
            'message' => 'Canvas saved',
            'data'    => $this->withGraph($this->findForTenant($process->id, $tenantId)),
        ]);
    }

    /**
     * Validate the current graph, snapshot it as the next version, and
     * activate the process. The validation here is deliberately structural
     * only (reachability, branch completeness, assignees present) - it has
     * no opinion on *what* the process does, only that it is a graph an
     * execution engine could actually run end to end.
     */
    public function publish(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];
        $actorId  = $identity['user_id'];

        $process = $this->findForTenant($id, $tenantId);
        if (!$process) {
            return response()->json(['status' => 0, 'message' => 'Process not found'], 404);
        }

        $steps = DB::table('department_process_steps')->where('process_id', $process->id)->get();
        $edges = DB::table('department_process_edges')->where('process_id', $process->id)->get();

        $errors = $this->validateGraph($steps, $edges);
        if ($errors !== []) {
            return response()->json([
                'status'  => 0,
                'message' => 'Process is not ready to publish',
                'errors'  => $errors,
            ], 422);
        }

        $version = (int) $process->current_version + 1;

        DB::transaction(function () use ($process, $steps, $edges, $version, $tenantId, $actorId) {
            DB::table('department_process_versions')->insert([
                'process_id'       => $process->id,
                'sub_institute_id' => $tenantId,
                'version_number'   => $version,
                'snapshot'         => json_encode([
                    'name'        => $process->name,
                    'category'    => $process->category,
                    'description' => $process->description,
                    'steps'       => $steps,
                    'edges'       => $edges,
                ]),
                'published_by'     => $actorId,
                'published_at'     => now(),
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            DB::table('department_processes')
                ->where('id', $process->id)
                ->update([
                    'status'          => 'active',
                    'current_version' => $version,
                    'updated_by'      => $actorId,
                    'updated_at'      => now(),
                ]);
        });

        return response()->json([
            'status'  => 1,
            'message' => 'Process published as version ' . $version,
            'data'    => $this->withGraph($this->findForTenant($process->id, $tenantId)),
        ]);
    }

    public function history(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];

        $process = $this->findForTenant($id, $tenantId);
        if (!$process) {
            return response()->json(['status' => 0, 'message' => 'Process not found'], 404);
        }

        $versions = DB::table('department_process_versions as v')
            ->leftJoin('tbluser as u', 'u.id', '=', 'v.published_by')
            ->where('v.process_id', $process->id)
            ->select(['v.id', 'v.version_number', 'v.published_at', 'v.published_by', 'u.user_name as published_by_name'])
            ->orderByDesc('v.version_number')
            ->get();

        return response()->json(['status' => 1, 'data' => $versions]);
    }

    /**
     * Load an old version's snapshot back into the live, editable graph as a
     * new draft. Deliberately does not re-publish on its own - restoring is
     * "start editing from here", and the user still reviews and publishes.
     */
    public function restoreVersion(Request $request, $id, $version)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];
        $actorId  = $identity['user_id'];

        $process = $this->findForTenant($id, $tenantId);
        if (!$process) {
            return response()->json(['status' => 0, 'message' => 'Process not found'], 404);
        }

        $row = DB::table('department_process_versions')
            ->where('process_id', $process->id)
            ->where('version_number', (int) $version)
            ->first();

        if (!$row) {
            return response()->json(['status' => 0, 'message' => 'Version not found'], 404);
        }

        $snapshot = json_decode($row->snapshot, true) ?: [];

        DB::transaction(function () use ($process, $snapshot, $tenantId, $actorId) {
            DB::table('department_process_steps')->where('process_id', $process->id)->delete();
            DB::table('department_process_edges')->where('process_id', $process->id)->delete();

            foreach ((array) ($snapshot['steps'] ?? []) as $step) {
                $step = (array) $step;
                DB::table('department_process_steps')->insert([
                    'process_id'       => $process->id,
                    'sub_institute_id' => $tenantId,
                    'node_key'         => $step['node_key'],
                    'step_type'        => $step['step_type'],
                    'title'            => $step['title'],
                    'description'      => $step['description'] ?? null,
                    'assignee_type'    => $step['assignee_type'] ?? null,
                    'assignee_value'   => $step['assignee_value'] ?? null,
                    'sla_value'        => $step['sla_value'] ?? null,
                    'sla_unit'         => $step['sla_unit'] ?? null,
                    'linked_sop_id'    => $step['linked_sop_id'] ?? null,
                    'linked_policy_id' => $step['linked_policy_id'] ?? null,
                    'linked_rule_id'   => $step['linked_rule_id'] ?? null,
                    'config'           => $step['config'] ?? null,
                    'position_x'       => $step['position_x'] ?? 0,
                    'position_y'       => $step['position_y'] ?? 0,
                    'is_required'      => $step['is_required'] ?? true,
                    'created_by'       => $actorId,
                    'updated_by'       => $actorId,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);
            }

            foreach ((array) ($snapshot['edges'] ?? []) as $edge) {
                $edge = (array) $edge;
                DB::table('department_process_edges')->insert([
                    'process_id'       => $process->id,
                    'sub_institute_id' => $tenantId,
                    'source_node_key'  => $edge['source_node_key'],
                    'target_node_key'  => $edge['target_node_key'],
                    'label'            => $edge['label'] ?? null,
                    'condition'        => $edge['condition'] ?? null,
                    'order'            => $edge['order'] ?? 0,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);
            }

            DB::table('department_processes')
                ->where('id', $process->id)
                ->update(['status' => 'draft', 'updated_by' => $actorId, 'updated_at' => now()]);
        });

        return response()->json([
            'status'  => 1,
            'message' => 'Version ' . $version . ' restored into the draft',
            'data'    => $this->withGraph($this->findForTenant($process->id, $tenantId)),
        ]);
    }

    // ---------------------------------------------------------------------

    protected function withGraph(object $process): array
    {
        $data = (array) $process;
        $data['steps'] = DB::table('department_process_steps')
            ->where('process_id', $process->id)
            ->orderBy('id')
            ->get();
        $data['edges'] = DB::table('department_process_edges')
            ->where('process_id', $process->id)
            ->orderBy('order')
            ->get();

        return $data;
    }

    /**
     * Structural checks only - see the note on publish(). Returns a flat
     * list of human-readable problems; an empty list means the graph is
     * publishable.
     */
    protected function validateGraph($steps, $edges): array
    {
        $errors = [];

        if ($steps->isEmpty()) {
            return ['Add at least a Start and an End step before publishing.'];
        }

        $byKey = $steps->keyBy('node_key');
        $starts = $steps->where('step_type', 'start');
        $ends   = $steps->where('step_type', 'end');

        if ($starts->count() !== 1) {
            $errors[] = 'A process must have exactly one Start step (found ' . $starts->count() . ').';
        }
        if ($ends->count() < 1) {
            $errors[] = 'A process must have at least one End step.';
        }

        $outgoing = [];
        $incoming = [];
        foreach ($edges as $edge) {
            $outgoing[$edge->source_node_key][] = $edge;
            $incoming[$edge->target_node_key][] = $edge;
        }

        foreach ($steps as $step) {
            $hasOutgoing = !empty($outgoing[$step->node_key]);
            $hasIncoming = !empty($incoming[$step->node_key]);

            if ($step->step_type !== 'end' && !$hasOutgoing) {
                $errors[] = '"' . $step->title . '" has no outgoing connection.';
            }
            if ($step->step_type !== 'start' && !$hasIncoming) {
                $errors[] = '"' . $step->title . '" is not connected from any step.';
            }

            if ($step->step_type === 'decision') {
                $branches = $outgoing[$step->node_key] ?? [];
                $labeled  = collect($branches)->filter(fn ($e) => trim((string) $e->label) !== '');
                if (count($branches) < 2 || $labeled->count() < count($branches)) {
                    $errors[] = 'Decision step "' . $step->title . '" needs at least two labeled branches.';
                }
            }

            if (in_array($step->step_type, ['approval', 'task'], true) && !$step->assignee_type) {
                $errors[] = '"' . $step->title . '" needs an assignee before publishing.';
            }
        }

        // Reachability from Start - a disconnected island is not caught by
        // the per-node incoming/outgoing checks above when it forms its own
        // small loop.
        if ($starts->count() === 1) {
            $reachable = [];
            $queue = [$starts->first()->node_key];
            while ($queue) {
                $key = array_pop($queue);
                if (isset($reachable[$key])) {
                    continue;
                }
                $reachable[$key] = true;
                foreach ($outgoing[$key] ?? [] as $edge) {
                    $queue[] = $edge->target_node_key;
                }
            }
            foreach ($steps as $step) {
                if (!isset($reachable[$step->node_key])) {
                    $errors[] = '"' . $step->title . '" is not reachable from Start.';
                }
            }
        }

        /*
         * No loops. The run engine (DepartmentProcessRunController) creates
         * at most one department_process_run_steps row per (run, node) - a
         * cycle would mean a step tries to activate a second time mid-run,
         * which the unique constraint silently absorbs rather than loops: the
         * run would just stall on the first pass with nothing visibly wrong.
         * Caught here instead, at publish time, with a clear message.
         *
         * Standard white/grey/black DFS: grey means "on the current path" -
         * reaching a grey node again is the back-edge that proves a cycle.
         */
        $color = [];
        foreach ($steps as $step) {
            $color[$step->node_key] = 0;
        }
        $hasCycle = false;
        $visit = function (string $key) use (&$visit, &$color, $outgoing, &$hasCycle) {
            if ($hasCycle) {
                return;
            }
            $color[$key] = 1;
            foreach ($outgoing[$key] ?? [] as $edge) {
                $target = $edge->target_node_key;
                if (!isset($color[$target])) {
                    continue; // dangling edge - already reported above
                }
                if ($color[$target] === 1) {
                    $hasCycle = true;
                    return;
                }
                if ($color[$target] === 0) {
                    $visit($target);
                }
            }
            $color[$key] = 2;
        };
        foreach ($steps as $step) {
            if ($hasCycle) {
                break;
            }
            if ($color[$step->node_key] === 0) {
                $visit($step->node_key);
            }
        }
        if ($hasCycle) {
            $errors[] = 'The process contains a loop - steps must flow forward from Start to an End without looping back.';
        }

        return array_values(array_unique($errors));
    }

    /** Step types publish() requires an assignee on - see validateGraph(). */
    private const ASSIGNABLE_TYPES = ['task', 'approval'];

    /**
     * Seed a new process's graph from config/department_processes.php's flat
     * per-category step list.
     *
     * Two things a plain linear chain cannot express on its own, handled
     * here rather than by complicating the template data:
     *
     *   - A `task`/`approval` step needs SOME assignee before it can publish
     *     (see validateGraph()). Every department can resolve one -
     *     department_head - so that is the default; a user who wants a
     *     specific person or role instead edits it in the builder, same as
     *     any other field a template pre-fills.
     *
     *   - A `decision` step needs at least two LABELED outgoing edges, but
     *     the template is one flat sequence with no branch data. Rather than
     *     reshape every template into a branching structure for the sake of
     *     a handful of decision steps, this fans a decision out to the SAME
     *     next step via two edges labeled "Yes"/"No" - a valid, publishable
     *     graph on creation that still reads correctly (both answers lead to
     *     what the template always meant to happen next), which the builder
     *     is exactly the tool for re-routing if a tenant wants the two
     *     answers to actually diverge.
     */
    protected function seedFromTemplate(int $processId, int $tenantId, int $actorId, string $categoryKey): void
    {
        $template = config('department_processes.templates.' . $categoryKey, []);
        if ($template === []) {
            return;
        }

        $previousKey = null;
        $previousType = null;
        $y = 0;
        foreach ($template as $index => $item) {
            $nodeKey = (string) Str::uuid();

            DB::table('department_process_steps')->insert([
                'process_id'       => $processId,
                'sub_institute_id' => $tenantId,
                'node_key'         => $nodeKey,
                'step_type'        => $item['type'],
                'title'            => $item['title'],
                'assignee_type'    => in_array($item['type'], self::ASSIGNABLE_TYPES, true) ? 'department_head' : null,
                'position_x'       => 0,
                'position_y'       => $y,
                'is_required'      => true,
                'created_by'       => $actorId,
                'updated_by'       => $actorId,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            if ($previousKey !== null) {
                $labels = $previousType === 'decision' ? ['Yes', 'No'] : [null];
                foreach ($labels as $label) {
                    DB::table('department_process_edges')->insert([
                        'process_id'       => $processId,
                        'sub_institute_id' => $tenantId,
                        'source_node_key'  => $previousKey,
                        'target_node_key'  => $nodeKey,
                        'label'            => $label,
                        'order'            => $index,
                        'created_at'       => now(),
                        'updated_at'       => now(),
                    ]);
                }
            }

            $previousKey = $nodeKey;
            $previousType = $item['type'];
            $y += 120;
        }
    }

    protected function findForTenant($id, int $tenantId)
    {
        return DB::table('department_processes')
            ->where('id', $id)
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->first();
    }

    protected function departmentBelongsToTenant(int $departmentId, int $tenantId): bool
    {
        return DB::table('hrms_departments')
            ->where('id', $departmentId)
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->exists();
    }
}
