<?php

namespace App\Http\Controllers\HRMS;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Services\Events\EventRecorder;
use App\Services\Tasks\TaskPublisher;
use App\Support\RoleKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * The execution engine: launches a published department process as a real,
 * trackable run and walks it forward one step action at a time.
 *
 * A run always executes the PUBLISHED snapshot it started from
 * (department_process_versions), decoded once per request into
 * $stepsByKey/$edgesBySource and passed through the private helpers below -
 * never the live department_process_steps/edges, which may already be mid-
 * edit for the next version. See the migration's note.
 *
 * Every transition is recorded via EventRecorder::record() under new
 * `process.*` event types - a complete audit trail from day one. Those types
 * are deliberately NOT yet added to EventCatalogue::SHIPPED: that catalogue's
 * named-consumer test and assertInvariants() require a real, wired consumer
 * (a NotificationDispatcher entry, a seeded g2g_notification_template row)
 * for everything it lists, and guessing at that shared, invariant-checked
 * infrastructure to rush in-app notifications is a worse outcome than simply
 * not having them yet. The event rows are already being written, so wiring
 * live notification delivery later is additive, not a migration.
 */
class DepartmentProcessRunController extends Controller
{
    use ResolvesApiIdentity;

    /** Step types that require no human action - they complete the instant they're reached. */
    private const AUTO_COMPLETE_TYPES = ['start', 'end', 'sop_reference', 'policy_reference', 'rule_reference', 'notification'];

    /** Step types a person explicitly completes, skips, or claims. */
    private const ACTIONABLE_TYPES = ['task', 'approval', 'decision', 'milestone', 'sub_process'];

    public function __construct(private readonly TaskPublisher $taskPublisher, private readonly EventRecorder $events)
    {
    }

    /**
     * Runs for a process (or a whole department), newest first.
     */
    public function index(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];

        $processId = (int) $request->query('process_id');
        $departmentId = (int) $request->query('department_id');

        if ($processId <= 0 && $departmentId <= 0) {
            return response()->json(['status' => 0, 'message' => 'process_id or department_id is required'], 422);
        }

        $query = DB::table('department_process_runs as r')
            ->leftJoin('department_processes as p', 'p.id', '=', 'r.process_id')
            ->where('r.sub_institute_id', $tenantId)
            ->select(['r.*', 'p.name as process_name']);

        if ($processId > 0) {
            $query->where('r.process_id', $processId);
        }
        if ($departmentId > 0) {
            $query->where('r.department_id', $departmentId);
        }
        if ($status = trim((string) $request->query('status', ''))) {
            $query->where('r.status', $status);
        }

        return response()->json([
            'status' => 1,
            'data'   => $query->orderByDesc('r.id')->limit(200)->get(),
        ]);
    }

    /**
     * One run with its steps and recent activity - what the live monitor view loads.
     */
    public function show(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];

        $run = $this->findRun($id, $tenantId);
        if (!$run) {
            return response()->json(['status' => 0, 'message' => 'Run not found'], 404);
        }

        $steps = DB::table('department_process_run_steps as rs')
            ->leftJoin('tbluser as u', 'u.id', '=', 'rs.assignee_user_id')
            ->where('rs.run_id', $run->id)
            ->select([
                'rs.*',
                DB::raw("COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.user_name) as assignee_name"),
            ])
            ->orderBy('rs.id')
            ->get();

        $events = DB::table('department_process_run_events as e')
            ->leftJoin('tbluser as u', 'u.id', '=', 'e.actor_user_id')
            ->where('e.run_id', $run->id)
            ->select(['e.*', 'u.user_name as actor_name'])
            ->orderByDesc('e.id')
            ->limit(100)
            ->get();

        // The run_steps rows above are only the steps actually reached so
        // far. The monitor view draws the WHOLE process - pending steps
        // included - so it also gets the pinned published snapshot's full
        // step/edge list, the same definition activateStep() executes
        // against.
        $version = DB::table('department_process_versions')
            ->where('process_id', $run->process_id)
            ->where('version_number', $run->process_version)
            ->first();
        $snapshot = $version ? (json_decode((string) $version->snapshot, true) ?: []) : [];

        $data = (array) $run;
        $data['steps'] = $steps;
        $data['events'] = $events;
        $data['definition'] = [
            'steps' => $snapshot['steps'] ?? [],
            'edges' => $snapshot['edges'] ?? [],
        ];

        return response()->json(['status' => 1, 'data' => $data]);
    }

    /**
     * Launch a run from a process's current PUBLISHED version.
     */
    public function start(Request $request, $processId)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];
        $actorId  = $identity['user_id'];

        $process = DB::table('department_processes')
            ->where('id', $processId)
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->first();

        if (!$process) {
            return response()->json(['status' => 0, 'message' => 'Process not found'], 404);
        }
        if ($process->status !== 'active' || (int) $process->current_version <= 0) {
            return response()->json(['status' => 0, 'message' => 'Publish the process before starting a run.'], 422);
        }

        $version = DB::table('department_process_versions')
            ->where('process_id', $process->id)
            ->where('version_number', $process->current_version)
            ->first();

        if (!$version) {
            return response()->json(['status' => 0, 'message' => 'Published version could not be found.'], 500);
        }

        $validator = Validator::make($request->all(), [
            'subject_type' => 'nullable|string|max:60',
            'subject_id'   => 'nullable|integer',
            'name'         => 'nullable|string|max:191',
            'context'      => 'nullable|array',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $snapshot = json_decode((string) $version->snapshot, true) ?: [];
        [$stepsByKey, $edgesBySource] = $this->indexSnapshot($snapshot);

        $start = collect($stepsByKey)->first(fn ($s) => $s['step_type'] === 'start');
        if (!$start) {
            return response()->json(['status' => 0, 'message' => 'This version has no Start step.'], 500);
        }

        $runId = DB::transaction(function () use (
            $process, $tenantId, $actorId, $request, $stepsByKey, $edgesBySource, $start
        ) {
            $runId = DB::table('department_process_runs')->insertGetId([
                'process_id'              => $process->id,
                'process_version'         => $process->current_version,
                'department_id'           => $process->department_id,
                'sub_institute_id'        => $tenantId,
                'subject_type'            => $request->input('subject_type') ?: null,
                'subject_id'              => $request->input('subject_id') ?: null,
                'name'                    => $request->input('name') ?: $process->name,
                'status'                  => 'running',
                'current_step_node_keys'  => json_encode([]),
                'context'                 => json_encode($request->input('context', [])),
                'started_by'              => $actorId,
                'started_at'              => now(),
                'created_by'              => $actorId,
                'created_at'              => now(),
                'updated_at'              => now(),
            ]);

            $this->emit($runId, null, $tenantId, 'process.run.started', $actorId, [
                'process_id' => $process->id, 'process_name' => $process->name,
            ]);

            $current = [];
            $context = (array) $request->input('context', []);
            $this->activateStep(
                $runId, $tenantId, (int) $process->department_id, $start['node_key'],
                $stepsByKey, $edgesBySource, $current, $actorId, (string) $process->name, $context
            );

            DB::table('department_process_runs')->where('id', $runId)->update([
                'current_step_node_keys' => json_encode(array_values(array_unique($current))),
                'updated_at' => now(),
            ]);

            return $runId;
        });

        return response()->json([
            'status'  => 1,
            'message' => 'Process run started.',
            'data'    => $this->runPayload($runId, $tenantId),
        ], 201);
    }

    public function cancel(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];
        $actorId  = $identity['user_id'];

        $run = $this->findRun($id, $tenantId);
        if (!$run) {
            return response()->json(['status' => 0, 'message' => 'Run not found'], 404);
        }
        if ($run->status !== 'running') {
            return response()->json(['status' => 0, 'message' => 'Only a running process can be cancelled.'], 422);
        }

        DB::table('department_process_runs')->where('id', $run->id)->update([
            'status' => 'cancelled', 'completed_at' => now(), 'updated_by' => $actorId, 'updated_at' => now(),
        ]);
        $this->emit($run->id, null, $tenantId, 'process.run.cancelled', $actorId, []);

        return response()->json(['status' => 1, 'message' => 'Run cancelled.', 'data' => $this->runPayload($run->id, $tenantId)]);
    }

    /**
     * Take an unassigned step. The only gate: a role-targeted step may only
     * be claimed by someone who actually holds that role (RoleKey::forUserId()
     * - never a raw role_key column read, per the resolver's own contract).
     */
    public function claimStep(Request $request, $id, $nodeKey)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];
        $actorId  = $identity['user_id'];

        $run = $this->findRun($id, $tenantId);
        if (!$run) {
            return response()->json(['status' => 0, 'message' => 'Run not found'], 404);
        }

        $runStep = $this->findRunStep($run->id, $nodeKey, $tenantId);
        if (!$runStep) {
            return response()->json(['status' => 0, 'message' => 'Step not found on this run'], 404);
        }
        if ($runStep->assignee_user_id) {
            return response()->json(['status' => 0, 'message' => 'This step is already assigned.'], 422);
        }

        [$stepsByKey] = $this->snapshotFor($run);
        $step = $stepsByKey[$nodeKey] ?? null;

        if ($step && ($step['assignee_type'] ?? null) === 'role') {
            $required = (string) $step['assignee_value'];
            if ($required !== '' && RoleKey::forUserId($actorId) !== $required) {
                return response()->json(['status' => 0, 'message' => 'Your role does not match who this step is for.'], 403);
            }
        }

        DB::table('department_process_run_steps')->where('id', $runStep->id)->update([
            'assignee_user_id' => $actorId,
            'status'           => 'in_progress',
            'started_at'       => $runStep->started_at ?? now(),
            'updated_at'       => now(),
        ]);

        if (($step['step_type'] ?? null) === 'task' && !$runStep->task_id) {
            $taskId = $this->raiseTaskForStep($run, $step, $actorId, $actorId);
            DB::table('department_process_run_steps')->where('id', $runStep->id)->update(['task_id' => $taskId]);
        }

        $this->emit($run->id, $runStep->id, $tenantId, 'process.step.claimed', $actorId, ['step_node_key' => $nodeKey]);

        return response()->json(['status' => 1, 'message' => 'Step claimed.', 'data' => $this->runPayload($run->id, $tenantId)]);
    }

    /**
     * Complete the current step and advance the run along the matching edge.
     *
     * Completing an unassigned step claims it for the caller in the same
     * action (subject to the same role check as claimStep()) rather than
     * forcing Claim then Complete as two separate clicks - the run's default
     * path should be the easy one.
     */
    public function completeStep(Request $request, $id, $nodeKey)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];
        $actorId  = $identity['user_id'];

        $run = $this->findRun($id, $tenantId);
        if (!$run) {
            return response()->json(['status' => 0, 'message' => 'Run not found'], 404);
        }
        if ($run->status !== 'running') {
            return response()->json(['status' => 0, 'message' => 'This run is no longer active.'], 422);
        }

        $runStep = $this->findRunStep($run->id, $nodeKey, $tenantId);
        if (!$runStep || !in_array($runStep->status, ['pending', 'in_progress'], true)) {
            return response()->json(['status' => 0, 'message' => 'Step is not awaiting action'], 422);
        }

        [$stepsByKey, $edgesBySource] = $this->snapshotFor($run);
        $step = $stepsByKey[$nodeKey] ?? null;
        if (!$step) {
            return response()->json(['status' => 0, 'message' => 'Step definition not found in this run\'s version'], 500);
        }

        if ($runStep->assignee_user_id && (int) $runStep->assignee_user_id !== $actorId) {
            return response()->json(['status' => 0, 'message' => 'This step is assigned to somebody else.'], 403);
        }
        if (!$runStep->assignee_user_id && ($step['assignee_type'] ?? null) === 'role') {
            $required = (string) $step['assignee_value'];
            if ($required !== '' && RoleKey::forUserId($actorId) !== $required) {
                return response()->json(['status' => 0, 'message' => 'Your role does not match who this step is for.'], 403);
            }
        }

        $outcome = $request->input('outcome');
        $outcome = $outcome !== null ? trim((string) $outcome) : null;
        $outgoing = $edgesBySource[$nodeKey] ?? [];

        $targets = $this->resolveOutgoing($outgoing, $outcome);
        if ($targets === null) {
            return response()->json([
                'status'  => 0,
                'message' => 'Choose which branch this step takes.',
                'errors'  => array_values(array_unique(array_filter(array_map(fn ($e) => $e['label'] ?? null, $outgoing)))),
            ], 422);
        }

        DB::transaction(function () use (
            $run, $runStep, $step, $nodeKey, $edgesBySource, $stepsByKey, $targets, $outcome, $actorId, $tenantId, $request
        ) {
            DB::table('department_process_run_steps')->where('id', $runStep->id)->update([
                'assignee_user_id' => $runStep->assignee_user_id ?: $actorId,
                'status'           => 'completed',
                'completed_at'     => now(),
                'completed_by'     => $actorId,
                'outcome'          => $outcome,
                'notes'            => $request->input('notes') ?: $runStep->notes,
                'updated_at'       => now(),
            ]);

            $this->emit($run->id, $runStep->id, $tenantId, 'process.step.completed', $actorId, [
                'step_node_key' => $nodeKey, 'outcome' => $outcome,
            ]);

            $current = json_decode((string) $run->current_step_node_keys, true) ?: [];
            $current = array_values(array_diff($current, [$nodeKey]));
            $context = json_decode((string) $run->context, true) ?: [];

            $reachedEnd = false;
            foreach ($targets as $targetKey) {
                if ($reachedEnd) {
                    break;
                }
                $this->activateStep(
                    $run->id, $tenantId, (int) $run->department_id, $targetKey,
                    $stepsByKey, $edgesBySource, $current, $actorId, (string) $run->name, $context, $reachedEnd
                );
            }

            DB::table('department_process_runs')->where('id', $run->id)->update([
                'current_step_node_keys' => json_encode(array_values(array_unique($current))),
                'updated_at' => now(),
            ]);
        });

        return response()->json(['status' => 1, 'message' => 'Step completed.', 'data' => $this->runPayload($run->id, $tenantId)]);
    }

    /**
     * Skip a step the snapshot marked optional (is_required = false).
     * Decision/approval steps cannot be skipped - skipping which branch a
     * decision takes is not a meaningful action.
     */
    public function skipStep(Request $request, $id, $nodeKey)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];
        $actorId  = $identity['user_id'];

        $run = $this->findRun($id, $tenantId);
        if (!$run) {
            return response()->json(['status' => 0, 'message' => 'Run not found'], 404);
        }

        $runStep = $this->findRunStep($run->id, $nodeKey, $tenantId);
        if (!$runStep || !in_array($runStep->status, ['pending', 'in_progress'], true)) {
            return response()->json(['status' => 0, 'message' => 'Step is not awaiting action'], 422);
        }

        [$stepsByKey, $edgesBySource] = $this->snapshotFor($run);
        $step = $stepsByKey[$nodeKey] ?? null;

        if (!$step || !empty($step['is_required'])) {
            return response()->json(['status' => 0, 'message' => 'This step is required and cannot be skipped.'], 422);
        }
        if (in_array($step['step_type'], ['decision', 'approval'], true)) {
            return response()->json(['status' => 0, 'message' => 'A decision or approval step cannot be skipped.'], 422);
        }

        DB::transaction(function () use ($run, $runStep, $step, $nodeKey, $stepsByKey, $edgesBySource, $actorId, $tenantId) {
            DB::table('department_process_run_steps')->where('id', $runStep->id)->update([
                'status' => 'skipped', 'completed_at' => now(), 'completed_by' => $actorId, 'updated_at' => now(),
            ]);
            $this->emit($run->id, $runStep->id, $tenantId, 'process.step.skipped', $actorId, ['step_node_key' => $nodeKey]);

            $current = json_decode((string) $run->current_step_node_keys, true) ?: [];
            $current = array_values(array_diff($current, [$nodeKey]));
            $context = json_decode((string) $run->context, true) ?: [];

            $reachedEnd = false;
            foreach ($edgesBySource[$nodeKey] ?? [] as $edge) {
                if ($reachedEnd) {
                    break;
                }
                $this->activateStep(
                    $run->id, $tenantId, (int) $run->department_id, $edge['target_node_key'],
                    $stepsByKey, $edgesBySource, $current, $actorId, (string) $run->name, $context, $reachedEnd
                );
            }

            DB::table('department_process_runs')->where('id', $run->id)->update([
                'current_step_node_keys' => json_encode(array_values(array_unique($current))),
                'updated_at' => now(),
            ]);
        });

        return response()->json(['status' => 1, 'message' => 'Step skipped.', 'data' => $this->runPayload($run->id, $tenantId)]);
    }

    /**
     * System-initiated completion of a wait_delay step whose due_at has
     * elapsed. Called only by process-runs:scan-due (never by an HTTP
     * request), which is why this takes no Request and performs none of the
     * human authorization checks completeStep() does - the passage of time
     * is not somebody's action, the same reasoning ScanCertificationExpiry
     * records its emissions with a null actor.
     *
     * Any task a cascading auto-step raises past this point is attributed to
     * whoever started the run (the only real, tenant-valid user available to
     * a scheduler) rather than to nobody - App\Services\Tasks\TaskPublisher's
     * `allocatedBy` is a real column other screens join against.
     */
    public function completeWaitStep(int $runId, string $nodeKey): bool
    {
        $run = DB::table('department_process_runs')->where('id', $runId)->first();
        if (!$run || $run->status !== 'running') {
            return false;
        }

        $tenantId = (int) $run->sub_institute_id;
        $runStep = $this->findRunStep($runId, $nodeKey, $tenantId);
        if (!$runStep || $runStep->status !== 'in_progress') {
            return false;
        }

        [$stepsByKey, $edgesBySource] = $this->snapshotFor($run);
        $systemActorId = (int) $run->started_by;

        DB::transaction(function () use ($run, $runStep, $nodeKey, $stepsByKey, $edgesBySource, $tenantId, $systemActorId) {
            DB::table('department_process_run_steps')->where('id', $runStep->id)->update([
                'status' => 'completed', 'completed_at' => now(), 'updated_at' => now(),
            ]);
            $this->emit($run->id, $runStep->id, $tenantId, 'process.step.completed', null, [
                'step_node_key' => $nodeKey, 'reason' => 'sla_elapsed',
            ]);

            $current = json_decode((string) $run->current_step_node_keys, true) ?: [];
            $current = array_values(array_diff($current, [$nodeKey]));
            $context = json_decode((string) $run->context, true) ?: [];

            $reachedEnd = false;
            foreach ($edgesBySource[$nodeKey] ?? [] as $edge) {
                if ($reachedEnd) {
                    break;
                }
                $this->activateStep(
                    $run->id, $tenantId, (int) $run->department_id, $edge['target_node_key'],
                    $stepsByKey, $edgesBySource, $current, $systemActorId, (string) $run->name, $context, $reachedEnd
                );
            }

            DB::table('department_process_runs')->where('id', $run->id)->update([
                'current_step_node_keys' => json_encode(array_values(array_unique($current))),
                'updated_at' => now(),
            ]);
        });

        return true;
    }

    // ---------------------------------------------------------------------

    /**
     * Create (or re-use, via the run-step row's uniqueness) the step's
     * tracking row, then either complete it immediately (auto-complete
     * types), leave it open for a timer (wait_delay), or leave it open for a
     * human (actionable types) - recursing into every outgoing edge of
     * whatever just auto-completed, so a chain of notifications/references
     * between two real decision points activates in one pass.
     *
     * @param array<string,array> $stepsByKey
     * @param array<string,array<int,array>> $edgesBySource
     * @param array<int,string> $current
     * @param array<string,mixed> $context
     */
    private function activateStep(
        int $runId, int $tenantId, int $departmentId, string $nodeKey,
        array $stepsByKey, array $edgesBySource, array &$current,
        int $actorId, string $processName, array $context, bool &$reachedEnd = false
    ): void {
        $step = $stepsByKey[$nodeKey] ?? null;
        if (!$step) {
            return;
        }

        // Already tracked (a fan-in from two branches reaching the same
        // node) - do not re-activate or double-count it.
        if (DB::table('department_process_run_steps')->where('run_id', $runId)->where('step_node_key', $nodeKey)->exists()) {
            return;
        }

        $type = $step['step_type'];
        $isAuto = in_array($type, self::AUTO_COMPLETE_TYPES, true);
        $isWait = $type === 'wait_delay';

        $assigneeId = $this->resolveAssignee($step, $departmentId, $context);
        $dueAt = $this->resolveDueAt($step);

        $status = 'pending';
        if ($isAuto) {
            $status = 'completed';
        } elseif ($isWait) {
            $status = 'in_progress';
        } elseif ($assigneeId) {
            $status = 'in_progress';
        }

        $runStepId = DB::table('department_process_run_steps')->insertGetId([
            'run_id'              => $runId,
            'sub_institute_id'    => $tenantId,
            'step_node_key'       => $nodeKey,
            'step_title_snapshot' => $step['title'],
            'step_type_snapshot'  => $type,
            'assignee_user_id'    => $assigneeId,
            'status'              => $status,
            'due_at'              => $dueAt,
            'started_at'          => ($isAuto || $isWait || $assigneeId) ? now() : null,
            'completed_at'        => $isAuto ? now() : null,
            'completed_by'        => null,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        $this->emit($runId, $runStepId, $tenantId, 'process.step.activated', null, ['step_node_key' => $nodeKey]);

        if ($type === 'task' && $assigneeId) {
            $taskId = $this->raiseTaskForRun($runId, $tenantId, $departmentId, $step, $assigneeId, $actorId, $processName);
            DB::table('department_process_run_steps')->where('id', $runStepId)->update(['task_id' => $taskId]);
        }

        if ($type === 'end') {
            $this->emit($runId, $runStepId, $tenantId, 'process.step.completed', null, ['step_node_key' => $nodeKey]);
            DB::table('department_process_runs')->where('id', $runId)->update([
                'status' => 'completed', 'completed_at' => now(), 'updated_at' => now(),
            ]);
            $this->emit($runId, null, $tenantId, 'process.run.completed', null, []);
            $reachedEnd = true;
            return;
        }

        if ($isAuto) {
            $this->emit($runId, $runStepId, $tenantId, 'process.step.completed', null, ['step_node_key' => $nodeKey]);
            foreach ($edgesBySource[$nodeKey] ?? [] as $edge) {
                if ($reachedEnd) {
                    break;
                }
                $this->activateStep(
                    $runId, $tenantId, $departmentId, $edge['target_node_key'],
                    $stepsByKey, $edgesBySource, $current, $actorId, $processName, $context, $reachedEnd
                );
            }
            return;
        }

        $current[] = $nodeKey;
    }

    /** role/dynamic_field unresolved => null (awaiting claim); specific_user/department_head resolve directly. */
    private function resolveAssignee(array $step, int $departmentId, array $context): ?int
    {
        $type = $step['assignee_type'] ?? null;
        $value = $step['assignee_value'] ?? null;

        return match ($type) {
            'specific_user'  => $value ? (int) $value : null,
            'department_head' => (int) DB::table('hrms_departments')->where('id', $departmentId)->value('head_user_id') ?: null,
            'dynamic_field'  => isset($context[$value]) && is_numeric($context[$value]) ? (int) $context[$value] : null,
            default          => null, // 'role', null, or anything else: awaits a claim
        };
    }

    private function resolveDueAt(array $step): ?string
    {
        $value = $step['sla_value'] ?? null;
        if (!$value) {
            return null;
        }
        $unit = ($step['sla_unit'] ?? 'days') === 'hours' ? 'addHours' : 'addDays';

        return now()->{$unit}((int) $value)->toDateTimeString();
    }

    /**
     * Which outgoing edge(s) a completion takes.
     *
     * One edge: always taken, $outcome is recorded but never required to
     * match. Two or more: $outcome must be given and must match a label -
     * returns null (meaning "ask the caller") if it does not, which
     * completeStep() turns into a 422 listing the real branch labels rather
     * than silently taking the first one.
     *
     * @return array<int,string>|null
     */
    private function resolveOutgoing(array $outgoing, ?string $outcome): ?array
    {
        if (count($outgoing) <= 1) {
            return array_map(fn ($e) => $e['target_node_key'], $outgoing);
        }
        if ($outcome === null || $outcome === '') {
            return null;
        }
        $matches = array_values(array_filter($outgoing, fn ($e) => ($e['label'] ?? null) === $outcome));
        if ($matches === []) {
            return null;
        }

        return array_map(fn ($e) => $e['target_node_key'], $matches);
    }

    private function raiseTaskForRun(int $runId, int $tenantId, int $departmentId, array $step, int $assigneeId, int $actorId, string $processName): int
    {
        return $this->taskPublisher->publish(
            title: $step['title'],
            description: 'Raised from the process "' . mb_substr($processName, 0, 150) . '".',
            dueDate: isset($step['sla_value']) && $step['sla_value']
                ? now()->{($step['sla_unit'] ?? 'days') === 'hours' ? 'addHours' : 'addDays'}((int) $step['sla_value'])
                : now()->addDays(3),
            assigneeId: $assigneeId,
            allocatedBy: $actorId,
            subInstituteId: $tenantId,
            idempotencyKey: sprintf('dept-process-run-%d-%s', $runId, $step['node_key']),
        );
    }

    private function raiseTaskForStep(object $run, array $step, int $assigneeId, int $actorId): int
    {
        return $this->raiseTaskForRun((int) $run->id, (int) $run->sub_institute_id, (int) $run->department_id, $step, $assigneeId, $actorId, (string) $run->name);
    }

    /** @return array{0: array<string,array>, 1: array<string,array<int,array>>} */
    private function indexSnapshot(array $snapshot): array
    {
        $stepsByKey = [];
        foreach ((array) ($snapshot['steps'] ?? []) as $step) {
            $step = (array) $step;
            $stepsByKey[$step['node_key']] = $step;
        }

        $edgesBySource = [];
        foreach ((array) ($snapshot['edges'] ?? []) as $edge) {
            $edge = (array) $edge;
            $edgesBySource[$edge['source_node_key']][] = $edge;
        }

        return [$stepsByKey, $edgesBySource];
    }

    /** @return array{0: array<string,array>, 1: array<string,array<int,array>>} */
    private function snapshotFor(object $run): array
    {
        $version = DB::table('department_process_versions')
            ->where('process_id', $run->process_id)
            ->where('version_number', $run->process_version)
            ->first();

        return $this->indexSnapshot($version ? (json_decode((string) $version->snapshot, true) ?: []) : []);
    }

    /**
     * One transition, written twice on purpose: a row in
     * department_process_run_events (this run's own detailed timeline, what
     * the monitor view's activity feed reads) and, via EventRecorder, a row
     * in g2g_event (the org-wide history every other feature's notifications
     * and projections read). See the class docblock for why these `process.*`
     * types are not yet in EventCatalogue::SHIPPED.
     */
    private function emit(int $runId, ?int $runStepId, int $tenantId, string $type, ?int $actorId, array $payload): void
    {
        DB::table('department_process_run_events')->insert([
            'run_id' => $runId, 'run_step_id' => $runStepId, 'sub_institute_id' => $tenantId,
            'event_type' => $type, 'actor_user_id' => $actorId, 'payload' => json_encode($payload), 'created_at' => now(),
        ]);

        $this->events->record($type, $tenantId, 'department_process_run', $runId, $actorId, $payload);
    }

    private function runPayload(int $runId, int $tenantId): array
    {
        $run = DB::table('department_process_runs')->where('id', $runId)->first();
        $steps = DB::table('department_process_run_steps')->where('run_id', $runId)->orderBy('id')->get();
        $data = (array) $run;
        $data['steps'] = $steps;

        return $data;
    }

    private function findRun($id, int $tenantId): ?object
    {
        return DB::table('department_process_runs')->where('id', $id)->where('sub_institute_id', $tenantId)->first();
    }

    private function findRunStep(int $runId, string $nodeKey, int $tenantId): ?object
    {
        return DB::table('department_process_run_steps')
            ->where('run_id', $runId)->where('step_node_key', $nodeKey)->where('sub_institute_id', $tenantId)
            ->first();
    }
}
