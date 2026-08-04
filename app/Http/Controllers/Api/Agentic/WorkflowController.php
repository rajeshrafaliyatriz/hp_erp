<?php

namespace App\Http\Controllers\Api\Agentic;

use App\Http\Controllers\Api\Agentic\Concerns\ResolvesAgenticContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Multi-agent coordination.
 *
 *   GET    /api/agentic/workflows                    list with step counts
 *   POST   /api/agentic/workflows                    create
 *   GET    /api/agentic/workflows/{id}               steps + latest run state
 *   PUT    /api/agentic/workflows/{id}               update
 *   DELETE /api/agentic/workflows/{id}               soft delete
 *   POST   /api/agentic/workflows/{id}/steps         add a step
 *   PUT    /api/agentic/workflows/{id}/steps/{step}  reorder / retarget a step
 *   DELETE /api/agentic/workflows/{id}/steps/{step}  remove a step
 *   POST   /api/agentic/workflows/{id}/run           start a workflow run
 *   GET    /api/agentic/workflow-runs/{id}           live step states
 *   PUT    /api/agentic/workflow-runs/{id}/steps/{stepRun}  report a step outcome
 *   GET    /api/agentic/messages                     inter-agent message feed
 *   POST   /api/agentic/messages                     record a message
 *
 * The screen this backs animated fixture agents on a timer and reset every 15
 * seconds. These are real workflows over the tenant's own agents: a run records
 * which step reached which state and when.
 */
class WorkflowController extends Controller
{
    use ResolvesAgenticContext;

    private const MODES = ['sequential', 'parallel'];
    private const STATUSES = ['draft', 'active', 'archived'];
    private const STEP_STATES = ['idle', 'processing', 'completed', 'error'];

    private function baseQuery(int $sid)
    {
        return DB::table('agentic_workflows')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at');
    }

    /* ----------------------------- workflows ---------------------------- */

    public function index(Request $request)
    {
        $context = $this->agenticContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $query = $this->baseQuery($sid);

        if ($search = $this->activeFilter($request->input('search'))) {
            $query->where('name', 'like', "%{$search}%");
        }
        if ($status = $this->activeFilter($request->input('status'))) {
            $query->where('status', $status);
        }
        if ($mode = $this->activeFilter($request->input('mode'))) {
            $query->where('mode', $mode);
        }

        $rows = $query->orderByDesc('id')->limit(200)->get();
        $ids = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();

        $stepCounts = $ids === [] ? collect() : DB::table('agentic_workflow_steps')
            ->where('sub_institute_id', $sid)->whereNull('deleted_at')
            ->whereIn('workflow_id', $ids)
            ->select('workflow_id', DB::raw('count(*) as total'))
            ->groupBy('workflow_id')->pluck('total', 'workflow_id');

        $lastRuns = $ids === [] ? collect() : DB::table('agentic_workflow_runs')
            ->where('sub_institute_id', $sid)
            ->whereIn('workflow_id', $ids)
            ->select('workflow_id', DB::raw('MAX(created_at) as last_run'))
            ->groupBy('workflow_id')->pluck('last_run', 'workflow_id');

        return $this->ok('Workflows fetched successfully', $rows->map(fn ($row) => [
            'id'          => (int) $row->id,
            'name'        => $row->name,
            'description' => $row->description,
            'mode'        => $row->mode,
            'status'      => $row->status,
            'step_count'  => (int) ($stepCounts[$row->id] ?? 0),
            'last_run_at' => $lastRuns[$row->id] ?? null,
            'created_at'  => $row->created_at,
        ])->all());
    }

    public function show(Request $request, $id)
    {
        $context = $this->agenticContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $workflow = $this->baseQuery($sid)->where('id', (int) $id)->first();
        if (!$workflow) {
            return $this->fail('Workflow not found.', 404);
        }

        $steps = DB::table('agentic_workflow_steps as s')
            ->leftJoin('agentic_agents as a', 'a.id', '=', 's.agent_id')
            ->where('s.sub_institute_id', $sid)
            ->where('s.workflow_id', $workflow->id)
            ->whereNull('s.deleted_at')
            ->orderBy('s.sequence')
            ->get(['s.id', 's.agent_id', 's.sequence', 's.name', 's.instruction', 'a.name as agent_name', 'a.model', 'a.description as agent_description', 'a.tools'])
            ->map(fn ($step) => [
                'id'                => (int) $step->id,
                'agent_id'          => (int) $step->agent_id,
                'agent_name'        => $step->agent_name ?? 'Deleted agent',
                'agent_description' => $step->agent_description,
                'model'             => $step->model,
                'tools'             => $this->decodeList($step->tools),
                'sequence'          => (int) $step->sequence,
                'name'              => $step->name,
                'instruction'       => $step->instruction,
            ])->all();

        $latestRun = DB::table('agentic_workflow_runs')
            ->where('sub_institute_id', $sid)
            ->where('workflow_id', $workflow->id)
            ->orderByDesc('id')
            ->first();

        return $this->ok('Workflow fetched successfully', [
            'id'          => (int) $workflow->id,
            'name'        => $workflow->name,
            'description' => $workflow->description,
            'mode'        => $workflow->mode,
            'status'      => $workflow->status,
            'steps'       => $steps,
            'latest_run'  => $latestRun ? $this->runPayload($sid, (int) $latestRun->id) : null,
            'created_at'  => $workflow->created_at,
        ]);
    }

    public function store(Request $request)
    {
        $context = $this->agenticContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $validator = Validator::make($request->all(), [
            'name'        => 'required|string|max:191',
            'description' => 'nullable|string',
            'mode'        => 'nullable|string|in:' . implode(',', self::MODES),
            'status'      => 'nullable|string|in:' . implode(',', self::STATUSES),
        ]);
        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422);
        }

        $name = trim((string) $request->input('name'));
        if ($this->baseQuery($sid)->where('name', $name)->exists()) {
            return $this->fail('A workflow called "' . $name . '" already exists.', 422);
        }

        $id = DB::table('agentic_workflows')->insertGetId([
            'sub_institute_id' => $sid,
            'name'             => $name,
            'description'      => $request->input('description'),
            'mode'             => $request->input('mode', 'sequential'),
            'status'           => $request->input('status', 'draft'),
            'created_by'       => $context['user_id'],
            'updated_by'       => $context['user_id'],
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        return $this->ok('Workflow created successfully', ['id' => (int) $id]);
    }

    public function update(Request $request, $id)
    {
        $context = $this->agenticContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $workflow = $this->baseQuery($sid)->where('id', (int) $id)->first();
        if (!$workflow) {
            return $this->fail('Workflow not found.', 404);
        }

        $validator = Validator::make($request->all(), [
            'name'        => 'sometimes|required|string|max:191',
            'description' => 'nullable|string',
            'mode'        => 'nullable|string|in:' . implode(',', self::MODES),
            'status'      => 'nullable|string|in:' . implode(',', self::STATUSES),
        ]);
        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422);
        }

        $update = ['updated_by' => $context['user_id'], 'updated_at' => now()];
        foreach (['name', 'description', 'mode', 'status'] as $field) {
            if ($request->has($field)) {
                $update[$field] = is_string($request->input($field)) ? trim($request->input($field)) : $request->input($field);
            }
        }

        if (isset($update['name']) && $update['name'] !== $workflow->name
            && $this->baseQuery($sid)->where('name', $update['name'])->where('id', '!=', $workflow->id)->exists()) {
            return $this->fail('Another workflow already uses that name.', 422);
        }

        DB::table('agentic_workflows')->where('id', $workflow->id)->update($update);

        return $this->ok('Workflow updated successfully', ['id' => (int) $workflow->id]);
    }

    public function destroy(Request $request, $id)
    {
        $context = $this->agenticContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $workflow = $this->baseQuery($sid)->where('id', (int) $id)->first();
        if (!$workflow) {
            return $this->fail('Workflow not found.', 404);
        }

        DB::table('agentic_workflows')->where('id', $workflow->id)
            ->update(['deleted_by' => $context['user_id'], 'deleted_at' => now()]);
        DB::table('agentic_workflow_steps')->where('workflow_id', $workflow->id)
            ->update(['deleted_at' => now()]);

        return $this->ok('Workflow deleted successfully', ['id' => (int) $workflow->id]);
    }

    /* ------------------------------- steps ------------------------------ */

    public function addStep(Request $request, $id)
    {
        $context = $this->agenticContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $workflow = $this->baseQuery($sid)->where('id', (int) $id)->first();
        if (!$workflow) {
            return $this->fail('Workflow not found.', 404);
        }

        $validator = Validator::make($request->all(), [
            'agent_id'    => 'required|integer|min:1',
            'name'        => 'nullable|string|max:191',
            'instruction' => 'nullable|string',
        ]);
        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422);
        }

        $agent = DB::table('agentic_agents')
            ->where('sub_institute_id', $sid)->where('id', (int) $request->input('agent_id'))
            ->whereNull('deleted_at')->first();
        if (!$agent) {
            return $this->fail('Agent not found.', 404);
        }

        $sequence = (int) DB::table('agentic_workflow_steps')
            ->where('sub_institute_id', $sid)->where('workflow_id', $workflow->id)
            ->whereNull('deleted_at')->max('sequence');

        $stepId = DB::table('agentic_workflow_steps')->insertGetId([
            'sub_institute_id' => $sid,
            'workflow_id'      => $workflow->id,
            'agent_id'         => $agent->id,
            'sequence'         => $sequence + 1,
            'name'             => $request->input('name') ?: $agent->name,
            'instruction'      => $request->input('instruction'),
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        return $this->ok('Step added successfully', ['id' => (int) $stepId, 'sequence' => $sequence + 1]);
    }

    public function updateStep(Request $request, $id, $stepId)
    {
        $context = $this->agenticContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $step = DB::table('agentic_workflow_steps')
            ->where('sub_institute_id', $sid)->where('workflow_id', (int) $id)->where('id', (int) $stepId)
            ->whereNull('deleted_at')->first();
        if (!$step) {
            return $this->fail('Step not found.', 404);
        }

        $validator = Validator::make($request->all(), [
            'agent_id'    => 'nullable|integer|min:1',
            'sequence'    => 'nullable|integer|min:1',
            'name'        => 'nullable|string|max:191',
            'instruction' => 'nullable|string',
        ]);
        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422);
        }

        $update = ['updated_at' => now()];
        foreach (['agent_id', 'sequence', 'name', 'instruction'] as $field) {
            if ($request->has($field)) {
                $update[$field] = $request->input($field);
            }
        }

        if (isset($update['agent_id'])) {
            $exists = DB::table('agentic_agents')
                ->where('sub_institute_id', $sid)->where('id', $update['agent_id'])
                ->whereNull('deleted_at')->exists();
            if (!$exists) {
                return $this->fail('Agent not found.', 404);
            }
        }

        DB::table('agentic_workflow_steps')->where('id', $step->id)->update($update);

        return $this->ok('Step updated successfully', ['id' => (int) $step->id]);
    }

    public function deleteStep(Request $request, $id, $stepId)
    {
        $context = $this->agenticContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $step = DB::table('agentic_workflow_steps')
            ->where('sub_institute_id', $sid)->where('workflow_id', (int) $id)->where('id', (int) $stepId)
            ->whereNull('deleted_at')->first();
        if (!$step) {
            return $this->fail('Step not found.', 404);
        }

        DB::table('agentic_workflow_steps')->where('id', $step->id)->update(['deleted_at' => now()]);

        // Close the gap so the remaining steps stay 1..n.
        DB::table('agentic_workflow_steps')
            ->where('sub_institute_id', $sid)->where('workflow_id', (int) $id)
            ->whereNull('deleted_at')->where('sequence', '>', $step->sequence)
            ->decrement('sequence');

        return $this->ok('Step removed successfully', ['id' => (int) $step->id]);
    }

    /* -------------------------------- runs ------------------------------ */

    /**
     * Starts a workflow run and opens a step-run per step.
     *
     * A sequential run starts its first step; a parallel run starts them all.
     * Reporting each outcome is the executor's job through updateStepRun.
     */
    public function run(Request $request, $id)
    {
        $context = $this->agenticContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $workflow = $this->baseQuery($sid)->where('id', (int) $id)->first();
        if (!$workflow) {
            return $this->fail('Workflow not found.', 404);
        }

        $steps = DB::table('agentic_workflow_steps')
            ->where('sub_institute_id', $sid)->where('workflow_id', $workflow->id)
            ->whereNull('deleted_at')->orderBy('sequence')->get();

        if ($steps->isEmpty()) {
            return $this->fail('Add at least one step before running this workflow.', 422);
        }

        $runId = DB::table('agentic_workflow_runs')->insertGetId([
            'sub_institute_id' => $sid,
            'workflow_id'      => $workflow->id,
            'status'           => 'running',
            'started_at'       => now(),
            'created_by'       => $context['user_id'],
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        $parallel = $workflow->mode === 'parallel';

        foreach ($steps as $index => $step) {
            DB::table('agentic_workflow_step_runs')->insert([
                'sub_institute_id' => $sid,
                'workflow_run_id'  => $runId,
                'workflow_step_id' => $step->id,
                'agent_id'         => $step->agent_id,
                'sequence'         => $step->sequence,
                'status'           => ($parallel || $index === 0) ? 'processing' : 'idle',
                'started_at'       => ($parallel || $index === 0) ? now() : null,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);
        }

        return $this->ok('Workflow run started', $this->runPayload($sid, (int) $runId));
    }

    public function showRun(Request $request, $runId)
    {
        $context = $this->agenticContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $payload = $this->runPayload($context['sub_institute_id'], (int) $runId);
        if (!$payload) {
            return $this->fail('Workflow run not found.', 404);
        }

        return $this->ok('Workflow run fetched successfully', $payload);
    }

    /** @return array<string, mixed>|null */
    private function runPayload(int $sid, int $runId): ?array
    {
        $run = DB::table('agentic_workflow_runs')
            ->where('sub_institute_id', $sid)->where('id', $runId)->first();
        if (!$run) {
            return null;
        }

        $steps = DB::table('agentic_workflow_step_runs as sr')
            ->leftJoin('agentic_agents as a', 'a.id', '=', 'sr.agent_id')
            ->where('sr.sub_institute_id', $sid)
            ->where('sr.workflow_run_id', $run->id)
            ->orderBy('sr.sequence')
            ->get(['sr.id', 'sr.workflow_step_id', 'sr.agent_id', 'sr.sequence', 'sr.status', 'sr.output', 'sr.error', 'sr.started_at', 'sr.completed_at', 'a.name as agent_name', 'a.model', 'a.description as agent_description', 'a.tools'])
            ->map(fn ($step) => [
                'id'                => (int) $step->id,
                'workflow_step_id'  => $step->workflow_step_id !== null ? (int) $step->workflow_step_id : null,
                'agent_id'          => $step->agent_id !== null ? (int) $step->agent_id : null,
                'agent_name'        => $step->agent_name ?? 'Deleted agent',
                'agent_description' => $step->agent_description,
                'model'             => $step->model,
                'tools'             => $this->decodeList($step->tools),
                'sequence'          => (int) $step->sequence,
                'status'            => $step->status,
                'output'            => $step->output,
                'error'             => $step->error,
                'started_at'        => $step->started_at,
                'completed_at'      => $step->completed_at,
            ])->all();

        return [
            'id'           => (int) $run->id,
            'workflow_id'  => (int) $run->workflow_id,
            'status'       => $run->status,
            'started_at'   => $run->started_at,
            'completed_at' => $run->completed_at,
            'steps'        => $steps,
        ];
    }

    /**
     * Report a step outcome. In a sequential workflow, completing a step starts
     * the next one; an error stops the run rather than silently continuing.
     */
    public function updateStepRun(Request $request, $runId, $stepRunId)
    {
        $context = $this->agenticContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $validator = Validator::make($request->all(), [
            'status' => 'required|string|in:' . implode(',', self::STEP_STATES),
            'output' => 'nullable|string',
            'error'  => 'nullable|string',
        ]);
        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422);
        }

        $stepRun = DB::table('agentic_workflow_step_runs')
            ->where('sub_institute_id', $sid)
            ->where('workflow_run_id', (int) $runId)
            ->where('id', (int) $stepRunId)
            ->first();
        if (!$stepRun) {
            return $this->fail('Workflow step not found.', 404);
        }

        $status = $request->input('status');
        $terminal = in_array($status, ['completed', 'error'], true);

        DB::table('agentic_workflow_step_runs')->where('id', $stepRun->id)->update([
            'status'       => $status,
            'output'       => $request->input('output'),
            'error'        => $request->input('error'),
            'started_at'   => $stepRun->started_at ?: ($status === 'processing' ? now() : $stepRun->started_at),
            'completed_at' => $terminal ? now() : null,
            'updated_at'   => now(),
        ]);

        $run = DB::table('agentic_workflow_runs')->where('id', (int) $runId)->first();
        $workflow = DB::table('agentic_workflows')->where('id', $run->workflow_id)->first();

        if ($status === 'error') {
            DB::table('agentic_workflow_runs')->where('id', $run->id)
                ->update(['status' => 'error', 'completed_at' => now(), 'updated_at' => now()]);
        } elseif ($status === 'completed') {
            if ($workflow && $workflow->mode === 'sequential') {
                $next = DB::table('agentic_workflow_step_runs')
                    ->where('workflow_run_id', $run->id)
                    ->where('sequence', '>', $stepRun->sequence)
                    ->where('status', 'idle')
                    ->orderBy('sequence')
                    ->first();

                if ($next) {
                    DB::table('agentic_workflow_step_runs')->where('id', $next->id)
                        ->update(['status' => 'processing', 'started_at' => now(), 'updated_at' => now()]);
                }
            }

            $outstanding = DB::table('agentic_workflow_step_runs')
                ->where('workflow_run_id', $run->id)
                ->whereIn('status', ['idle', 'processing'])
                ->count();

            if ($outstanding === 0) {
                DB::table('agentic_workflow_runs')->where('id', $run->id)
                    ->update(['status' => 'completed', 'completed_at' => now(), 'updated_at' => now()]);
            }
        }

        return $this->ok('Step updated successfully', $this->runPayload($sid, (int) $runId));
    }

    /* ----------------------------- messages ----------------------------- */

    public function messages(Request $request)
    {
        $context = $this->agenticContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $rows = DB::table('agentic_messages as m')
            ->leftJoin('agentic_agents as f', 'f.id', '=', 'm.from_agent_id')
            ->leftJoin('agentic_agents as t', 't.id', '=', 'm.to_agent_id')
            ->where('m.sub_institute_id', $sid)
            ->when($this->activeFilter($request->input('workflow_run_id')), fn ($q, $id) => $q->where('m.workflow_run_id', $id))
            ->orderByDesc('m.created_at')
            ->limit(50)
            ->get(['m.id', 'm.message', 'm.created_at', 'm.workflow_run_id', 'f.name as from_agent', 't.name as to_agent'])
            ->map(fn ($row) => [
                'id'              => (int) $row->id,
                'from_agent'      => $row->from_agent ?? 'System',
                'to_agent'        => $row->to_agent ?? 'System',
                'message'         => $row->message,
                'workflow_run_id' => $row->workflow_run_id !== null ? (int) $row->workflow_run_id : null,
                'created_at'      => $row->created_at,
            ])->all();

        return $this->ok('Messages fetched successfully', $rows);
    }

    public function storeMessage(Request $request)
    {
        $context = $this->agenticContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $validator = Validator::make($request->all(), [
            'from_agent_id'   => 'nullable|integer|min:1',
            'to_agent_id'     => 'nullable|integer|min:1',
            'workflow_run_id' => 'nullable|integer|min:1',
            'message'         => 'required|string|max:2000',
        ]);
        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422);
        }

        $id = DB::table('agentic_messages')->insertGetId([
            'sub_institute_id' => $sid,
            'workflow_run_id'  => $request->input('workflow_run_id'),
            'from_agent_id'    => $request->input('from_agent_id'),
            'to_agent_id'      => $request->input('to_agent_id'),
            'message'          => $request->input('message'),
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        return $this->ok('Message recorded', ['id' => (int) $id]);
    }
}
