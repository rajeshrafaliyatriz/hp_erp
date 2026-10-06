<?php

namespace App\Http\Controllers\Api\TaskManagement;

use App\Http\Controllers\Api\TaskManagement\Concerns\ResolvesTaskContext;
use App\Http\Controllers\Api\TaskManagement\Concerns\ResolvesWorkstreamScope;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * The records that make up a workstream's plan: contributors, responsibilities,
 * scope, deliverables, checkpoints, KPIs, risks and external dependencies.
 *
 * ── SPLIT FROM WorkstreamController ON PURPOSE ──────────────────────────────
 *
 * One controller covering the workstream itself plus seven child record types
 * runs past 900 lines and stops being reviewable. Both halves share
 * ResolvesWorkstreamScope, which is where the tenant boundary lives — every
 * method here resolves through it before touching a row, because none of these
 * tables carries a tenant column of its own.
 *
 * ── TWO WRITE SHAPES, CHOSEN PER RECORD TYPE ────────────────────────────────
 *
 * LIST REPLACE (members, statements): the whole set is sent and rewritten.
 * Correct here because a responsibility list is authored AS a list — you edit
 * eleven lines and save — and the replace is scoped to one kind of one
 * workstream, so two people editing different kinds cannot clobber each other.
 *
 * PER RECORD (deliverables, checkpoints, KPIs, risks, dependencies): each has its
 * own status, owner and dates, so each is created, edited and deleted on its own.
 *
 * The distinction matters. The destructive project-task sync this release removes
 * was a list replace applied to something that should have been per-record: it
 * deleted every link for a project and reinserted whatever the browser happened
 * to be holding.
 */
class WorkstreamRecordController extends Controller
{
    use ResolvesTaskContext;
    use ResolvesWorkstreamScope;

    public const MEMBER_ROLES = ['CONTRIBUTOR', 'LEAD', 'REVIEWER', 'SUPPORT'];

    public const STATEMENT_KINDS = ['RESPONSIBILITY', 'IN_SCOPE', 'OUT_OF_SCOPE'];

    public const DELIVERABLE_STATUSES = ['NOT STARTED', 'IN PROGRESS', 'IN REVIEW', 'DELIVERED', 'ACCEPTED', 'DROPPED'];

    public const CHECKPOINT_STATUSES = ['UPCOMING', 'AT RISK', 'COMPLETED', 'MISSED'];

    /** UNMEASURED is a first-class state, not the absence of one. */
    public const KPI_STATUSES = ['UNMEASURED', 'ON_TRACK', 'AT_RISK', 'OFF_TRACK', 'MET'];

    /** Latency going DOWN is success; a metric is not always bigger-is-better. */
    public const KPI_DIRECTIONS = ['UP', 'DOWN'];

    /** Reused verbatim from TaskExecutionClassifier::RISK_CLASSES. */
    public const RISK_LEVELS = ['Low', 'Medium', 'High', 'Regulated'];

    public const RISK_PROBABILITIES = ['Low', 'Medium', 'High'];

    public const RISK_STATUSES = ['OPEN', 'MITIGATED', 'ACCEPTED', 'CLOSED'];

    public const DEPENDENCY_DIRECTIONS = ['UPSTREAM', 'DOWNSTREAM'];

    public const DEPENDENCY_STATUSES = ['OPEN', 'MET', 'BLOCKED', 'WAIVED'];

    /**
     * probability x impact -> severity.
     *
     * Computed on write and STORED, so the register can ORDER BY it and the
     * roll-up can count "high or above" in one grouped query. Deriving it at read
     * time would mean two implementations of this matrix that could drift.
     *
     * A REGULATED IMPACT IS REGULATED AT ANY PROBABILITY. A compliance breach
     * that is merely unlikely is still a compliance breach; averaging it down
     * with its probability is how regulated risk gets filed as routine.
     */
    private const SEVERITY = [
        'Low'       => ['Low' => 'Low',    'Medium' => 'Low',    'High' => 'Medium'],
        'Medium'    => ['Low' => 'Low',    'Medium' => 'Medium', 'High' => 'High'],
        'High'      => ['Low' => 'Medium', 'Medium' => 'High',   'High' => 'High'],
        'Regulated' => ['Low' => 'Regulated', 'Medium' => 'Regulated', 'High' => 'Regulated'],
    ];

    /* ------------------------------------------------------------------ *
     * 2 — CONTRIBUTORS
     * ------------------------------------------------------------------ */

    /**
     * PUT /task-management/workstreams/{id}/members
     *
     * `lane` is what stops WS02 being split into three workstreams. The source
     * model is explicit: "do not create Frontend, Backend and AI as three
     * independent workstreams ... They are technical lanes inside one delivery
     * workstream." Without a place to record the lane, the pressure to split
     * returns immediately.
     */
    public function saveMembers(Request $request, $id)
    {
        [$context, $scope, $error] = $this->resolve($request, (int) $id);
        if ($error) {
            return $error;
        }

        $validator = Validator::make($request->all(), [
            'members'           => 'present|array|max:200',
            'members.*.user_id' => 'required|integer|min:1',
            'members.*.role'    => ['nullable', Rule::in(self::MEMBER_ROLES)],
            'members.*.lane'    => 'nullable|string|max:191',
        ]);

        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422, $validator->errors()->toArray());
        }

        $members = $request->input('members', []);
        $userIds = array_map(fn ($m) => (int) $m['user_id'], $members);

        if (! $this->usersInTenant($context, $userIds)) {
            return $this->fail('One or more selected people do not belong to this organisation.', 422);
        }

        // Contributors must be on the project, the same rule the owner obeys —
        // otherwise a workstream can name somebody with no access to the project
        // it belongs to.
        if ($userIds !== []) {
            $onProject = DB::table('task_management_project_members')
                ->where('project_id', $scope->project_id)
                ->whereIn('user_id', array_unique($userIds))->count();

            if ($onProject !== count(array_unique($userIds))) {
                return $this->fail('Every contributor must be a member of the project team.', 422);
            }
        }

        DB::transaction(function () use ($scope, $members, $context) {
            DB::table('task_management_workstream_members')
                ->where('workstream_id', $scope->id)->delete();

            $order = 0;
            $seen  = [];

            foreach ($members as $member) {
                $userId = (int) $member['user_id'];

                // The unique key would refuse a duplicate anyway; skipping keeps
                // a repeated id in the payload from failing the whole save.
                if (isset($seen[$userId])) {
                    continue;
                }
                $seen[$userId] = true;

                DB::table('task_management_workstream_members')->insert([
                    'workstream_id' => $scope->id,
                    'user_id'       => $userId,
                    'role'          => $member['role'] ?? 'CONTRIBUTOR',
                    'lane'          => $member['lane'] ?? null,
                    'sort_order'    => $order++,
                    'created_by'    => $context['user_id'],
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);
            }
        });

        return $this->ok('Contributors saved successfully.');
    }

    /* ------------------------------------------------------------------ *
     * 3 + 8 — RESPONSIBILITIES AND SCOPE BOUNDARIES
     * ------------------------------------------------------------------ */

    /**
     * PUT /task-management/workstreams/{id}/statements
     *
     * Scoped to ONE kind per call. Saving responsibilities must not touch the
     * scope lists, and vice versa — they are edited on different parts of the
     * screen by potentially different people.
     */
    public function saveStatements(Request $request, $id)
    {
        [$context, $scope, $error] = $this->resolve($request, (int) $id);
        if ($error) {
            return $error;
        }

        $validator = Validator::make($request->all(), [
            'kind'          => ['required', Rule::in(self::STATEMENT_KINDS)],
            'statements'    => 'present|array|max:200',
            'statements.*'  => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422, $validator->errors()->toArray());
        }

        $kind = (string) $request->input('kind');

        DB::transaction(function () use ($scope, $kind, $request, $context) {
            DB::table('task_management_workstream_statements')
                ->where('workstream_id', $scope->id)->where('kind', $kind)->delete();

            $order = 0;

            foreach ($request->input('statements', []) as $body) {
                $body = trim((string) $body);

                if ($body === '') {
                    continue;
                }

                DB::table('task_management_workstream_statements')->insert([
                    'workstream_id' => $scope->id,
                    'kind'          => $kind,
                    'body'          => $body,
                    'sort_order'    => $order++,
                    'created_by'    => $context['user_id'],
                    'updated_by'    => $context['user_id'],
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);
            }
        });

        return $this->ok('Saved successfully.');
    }

    /* ------------------------------------------------------------------ *
     * 4 — DELIVERABLES
     * ------------------------------------------------------------------ */

    public function storeDeliverable(Request $request, $id)
    {
        return $this->createRecord($request, (int) $id, 'task_management_workstream_deliverables',
            fn ($r) => $this->deliverableRules(), fn ($r, $s) => $this->deliverablePayload($r, $s), 'Deliverable');
    }

    public function updateDeliverable(Request $request, $id, $recordId)
    {
        return $this->updateRecord($request, (int) $id, (int) $recordId, 'task_management_workstream_deliverables',
            fn ($r) => $this->deliverableRules(), fn ($r, $s) => $this->deliverablePayload($r, $s), 'Deliverable');
    }

    public function destroyDeliverable(Request $request, $id, $recordId)
    {
        return $this->deleteRecord($request, (int) $id, (int) $recordId,
            'task_management_workstream_deliverables', 'Deliverable');
    }

    private function deliverableRules(): array
    {
        return [
            'name'                => 'required|string|max:191',
            'description'         => 'nullable|string',
            'acceptance_criteria' => 'nullable|string',
            'status'              => ['required', Rule::in(self::DELIVERABLE_STATUSES)],
            'owner_id'            => 'nullable|integer|min:1',
            'checkpoint_id'       => 'nullable|integer|min:1',
            'due_date'            => 'nullable|date',
            'delivered_at'        => 'nullable|date',
            'sort_order'          => 'nullable|integer|min:0',
        ];
    }

    private function deliverablePayload(Request $request, object $scope): array
    {
        $status = (string) $request->input('status');

        return [
            'name'                => trim((string) $request->input('name')),
            'description'         => $request->input('description'),
            'acceptance_criteria' => $request->input('acceptance_criteria'),
            'status'              => $status,
            'owner_id'            => $this->nullableId($request, 'owner_id'),
            'checkpoint_id'       => $this->nullableId($request, 'checkpoint_id'),
            'due_date'            => $this->dateOnly($request, 'due_date'),
            /*
             * Stamped when it lands, cleared when it is reopened. Without the
             * clear, a deliverable moved back to IN PROGRESS keeps a delivery
             * date and reads as both unfinished and delivered.
             */
            'delivered_at'        => in_array($status, ['DELIVERED', 'ACCEPTED'], true)
                ? ($this->dateOnly($request, 'delivered_at') ?? now()->toDateString())
                : null,
            'sort_order'          => (int) $request->input('sort_order', 0),
        ];
    }

    /* ------------------------------------------------------------------ *
     * 5 — CHECKPOINTS
     * ------------------------------------------------------------------ */

    public function storeCheckpoint(Request $request, $id)
    {
        return $this->createRecord($request, (int) $id, 'task_management_workstream_checkpoints',
            fn ($r) => $this->checkpointRules(), fn ($r, $s) => $this->checkpointPayload($r), 'Checkpoint');
    }

    public function updateCheckpoint(Request $request, $id, $recordId)
    {
        return $this->updateRecord($request, (int) $id, (int) $recordId, 'task_management_workstream_checkpoints',
            fn ($r) => $this->checkpointRules(), fn ($r, $s) => $this->checkpointPayload($r), 'Checkpoint');
    }

    public function destroyCheckpoint(Request $request, $id, $recordId)
    {
        return $this->deleteRecord($request, (int) $id, (int) $recordId,
            'task_management_workstream_checkpoints', 'Checkpoint');
    }

    private function checkpointRules(): array
    {
        return [
            'name'        => 'required|string|max:191',
            'description' => 'nullable|string',
            'target_date' => 'nullable|date',
            'status'      => ['required', Rule::in(self::CHECKPOINT_STATUSES)],
            'is_critical' => 'nullable|boolean',
            'sort_order'  => 'nullable|integer|min:0',
        ];
    }

    private function checkpointPayload(Request $request): array
    {
        $status = (string) $request->input('status');

        return [
            'name'         => trim((string) $request->input('name')),
            'description'  => $request->input('description'),
            'target_date'  => $this->dateOnly($request, 'target_date'),
            'status'       => $status,
            'is_critical'  => (bool) $request->boolean('is_critical'),
            'completed_at' => $status === 'COMPLETED' ? now()->toDateString() : null,
            'sort_order'   => (int) $request->input('sort_order', 0),
        ];
    }

    /* ------------------------------------------------------------------ *
     * 7 — SUCCESS METRICS
     * ------------------------------------------------------------------ */

    public function storeKpi(Request $request, $id)
    {
        return $this->createRecord($request, (int) $id, 'task_management_workstream_kpis',
            fn ($r) => $this->kpiRules(), fn ($r, $s) => $this->kpiPayload($r), 'KPI');
    }

    public function updateKpi(Request $request, $id, $recordId)
    {
        return $this->updateRecord($request, (int) $id, (int) $recordId, 'task_management_workstream_kpis',
            fn ($r) => $this->kpiRules(), fn ($r, $s) => $this->kpiPayload($r), 'KPI');
    }

    public function destroyKpi(Request $request, $id, $recordId)
    {
        return $this->deleteRecord($request, (int) $id, (int) $recordId,
            'task_management_workstream_kpis', 'KPI');
    }

    /**
     * PATCH /task-management/workstreams/{id}/kpis/{recordId}/measurement
     *
     * Recording a reading is a different act from redefining what is measured,
     * and it happens far more often — so it is its own route rather than a full
     * update that would require resending the whole definition.
     *
     * CLEARING A VALUE RETURNS THE KPI TO UNMEASURED, NOT TO OFF_TRACK. Deleting
     * a reading means nobody has measured it, which is exactly the state it
     * started in.
     */
    public function recordMeasurement(Request $request, $id, $recordId)
    {
        [$context, $scope, $error] = $this->resolve($request, (int) $id);
        if ($error) {
            return $error;
        }

        $kpi = DB::table('task_management_workstream_kpis')
            ->where('id', (int) $recordId)->where('workstream_id', $scope->id)->first();

        if (! $kpi) {
            return $this->fail('KPI not found.', 404);
        }

        $validator = Validator::make($request->all(), [
            'current_value' => 'nullable|string|max:100',
            'measured_at'   => 'nullable|date',
            'status'        => ['nullable', Rule::in(self::KPI_STATUSES)],
        ]);

        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422, $validator->errors()->toArray());
        }

        $value = $request->input('current_value');
        $value = $value === null || trim((string) $value) === '' ? null : trim((string) $value);

        DB::table('task_management_workstream_kpis')->where('id', $kpi->id)->update([
            'current_value' => $value,
            'measured_at'   => $value === null ? null : ($this->dateOnly($request, 'measured_at') ?? now()->toDateString()),
            // An explicit status wins; otherwise a cleared value is UNMEASURED and
            // a new reading needs a human judgement rather than a guess from a
            // string comparison against a target that may not be numeric.
            'status'        => $value === null
                ? 'UNMEASURED'
                : ($request->input('status') ?: ($kpi->status === 'UNMEASURED' ? 'ON_TRACK' : $kpi->status)),
            'updated_by'    => $context['user_id'],
            'updated_at'    => now(),
        ]);

        return $this->ok('Measurement recorded successfully.');
    }

    private function kpiRules(): array
    {
        return [
            'name'           => 'required|string|max:191',
            'metric'         => 'nullable|string|max:191',
            'unit'           => 'nullable|string|max:30',
            'direction'      => ['nullable', Rule::in(self::KPI_DIRECTIONS)],
            // Strings, not numbers: "15% reduction in latency", "100 units
            // produced" and "Zero P1 incidents" are all legitimate targets.
            'baseline_value' => 'nullable|string|max:100',
            'target_value'   => 'nullable|string|max:100',
            'current_value'  => 'nullable|string|max:100',
            'status'         => ['nullable', Rule::in(self::KPI_STATUSES)],
            'weightage'      => 'nullable|numeric|min:0|max:100',
            'source'         => 'nullable|string|max:191',
            'owner_id'       => 'nullable|integer|min:1',
            'sort_order'     => 'nullable|integer|min:0',
        ];
    }

    private function kpiPayload(Request $request): array
    {
        $current = $request->input('current_value');
        $current = $current === null || trim((string) $current) === '' ? null : trim((string) $current);

        return [
            'name'           => trim((string) $request->input('name')),
            'metric'         => $request->input('metric'),
            'unit'           => $request->input('unit'),
            'direction'      => $request->input('direction') ?: 'UP',
            'baseline_value' => $request->input('baseline_value'),
            'target_value'   => $request->input('target_value'),
            'current_value'  => $current,
            'measured_at'    => $current === null ? null : ($this->dateOnly($request, 'measured_at') ?? now()->toDateString()),
            // No reading means UNMEASURED, always. This is the difference between
            // "we have not looked" and "it is failing", and collapsing them is how
            // a dashboard ends up asserting a shortfall nobody measured.
            'status'         => $current === null ? 'UNMEASURED' : ($request->input('status') ?: 'ON_TRACK'),
            'weightage'      => (float) $request->input('weightage', 0),
            'source'         => $request->input('source'),
            'owner_id'       => $this->nullableId($request, 'owner_id'),
            'sort_order'     => (int) $request->input('sort_order', 0),
        ];
    }

    /* ------------------------------------------------------------------ *
     * 9 — RISKS
     * ------------------------------------------------------------------ */

    public function storeRisk(Request $request, $id)
    {
        return $this->createRecord($request, (int) $id, 'task_management_workstream_risks',
            fn ($r) => $this->riskRules(), fn ($r, $s) => $this->riskPayload($r), 'Risk');
    }

    public function updateRisk(Request $request, $id, $recordId)
    {
        return $this->updateRecord($request, (int) $id, (int) $recordId, 'task_management_workstream_risks',
            fn ($r) => $this->riskRules(), fn ($r, $s) => $this->riskPayload($r), 'Risk');
    }

    public function destroyRisk(Request $request, $id, $recordId)
    {
        return $this->deleteRecord($request, (int) $id, (int) $recordId,
            'task_management_workstream_risks', 'Risk');
    }

    private function riskRules(): array
    {
        return [
            'title'       => 'required|string|max:191',
            'description' => 'nullable|string',
            'category'    => 'nullable|string|max:30',
            'probability' => ['required', Rule::in(self::RISK_PROBABILITIES)],
            'impact'      => ['required', Rule::in(self::RISK_LEVELS)],
            'mitigation'  => 'nullable|string',
            'contingency' => 'nullable|string',
            'status'      => ['required', Rule::in(self::RISK_STATUSES)],
            'owner_id'    => 'nullable|integer|min:1',
            'due_date'    => 'nullable|date',
            'sort_order'  => 'nullable|integer|min:0',
        ];
    }

    private function riskPayload(Request $request): array
    {
        $probability = (string) $request->input('probability');
        $impact      = (string) $request->input('impact');
        $status      = (string) $request->input('status');

        return [
            'title'       => trim((string) $request->input('title')),
            'description' => $request->input('description'),
            'category'    => $request->input('category'),
            'probability' => $probability,
            'impact'      => $impact,
            // Derived here, stored, never recomputed on read.
            'severity'    => self::SEVERITY[$impact][$probability] ?? 'High',
            'mitigation'  => $request->input('mitigation'),
            'contingency' => $request->input('contingency'),
            'status'      => $status,
            'owner_id'    => $this->nullableId($request, 'owner_id'),
            'due_date'    => $this->dateOnly($request, 'due_date'),
            'closed_at'   => in_array($status, ['CLOSED', 'MITIGATED'], true) ? now()->toDateString() : null,
            'sort_order'  => (int) $request->input('sort_order', 0),
        ];
    }

    /* ------------------------------------------------------------------ *
     * 6 — EXTERNAL DEPENDENCIES
     * ------------------------------------------------------------------ */

    public function storeDependency(Request $request, $id)
    {
        return $this->createRecord($request, (int) $id, 'task_management_workstream_dependencies',
            fn ($r) => $this->dependencyRules(), fn ($r, $s) => $this->dependencyPayload($r), 'Dependency');
    }

    public function updateDependency(Request $request, $id, $recordId)
    {
        return $this->updateRecord($request, (int) $id, (int) $recordId, 'task_management_workstream_dependencies',
            fn ($r) => $this->dependencyRules(), fn ($r, $s) => $this->dependencyPayload($r), 'Dependency');
    }

    public function destroyDependency(Request $request, $id, $recordId)
    {
        return $this->deleteRecord($request, (int) $id, (int) $recordId,
            'task_management_workstream_dependencies', 'Dependency');
    }

    private function dependencyRules(): array
    {
        return [
            'direction'   => ['required', Rule::in(self::DEPENDENCY_DIRECTIONS)],
            'description' => 'required|string|max:500',
            'source'      => 'nullable|string|max:191',
            'needed_by'   => 'nullable|date',
            'status'      => ['required', Rule::in(self::DEPENDENCY_STATUSES)],
            'is_blocking' => 'nullable|boolean',
            'sort_order'  => 'nullable|integer|min:0',
        ];
    }

    private function dependencyPayload(Request $request): array
    {
        return [
            'direction'   => (string) $request->input('direction'),
            'description' => trim((string) $request->input('description')),
            'source'      => $request->input('source'),
            'needed_by'   => $this->dateOnly($request, 'needed_by'),
            'status'      => (string) $request->input('status'),
            'is_blocking' => (bool) $request->boolean('is_blocking'),
            'sort_order'  => (int) $request->input('sort_order', 0),
        ];
    }

    /* ------------------------------------------------------------------ *
     * Shared record machinery
     * ------------------------------------------------------------------ */

    /**
     * Resolve context + workstream + write permission in one step.
     *
     * @return array{0:array|null, 1:object|null, 2:\Illuminate\Http\JsonResponse|null}
     */
    private function resolve(Request $request, int $workstreamId): array
    {
        $context = $this->taskContext($request);
        if (! is_array($context)) {
            return [null, null, $context];
        }

        $scope = $this->workstreamScope($context, $workstreamId);
        if (! $scope) {
            // 404 rather than 403: a 403 would confirm the id exists, which is
            // itself an answer about another organisation's data.
            return [null, null, $this->fail('Workstream not found.', 404)];
        }

        if (! $this->canManageWorkstream($context, $scope)) {
            return [null, null, $this->fail('You cannot manage this workstream.', 403)];
        }

        return [$context, $scope, null];
    }

    private function createRecord(Request $request, int $workstreamId, string $table, callable $rules, callable $payload, string $label)
    {
        [$context, $scope, $error] = $this->resolve($request, $workstreamId);
        if ($error) {
            return $error;
        }

        $validator = Validator::make($request->all(), $rules($request));
        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422, $validator->errors()->toArray());
        }

        $guard = $this->guardRecordRelations($context, $request, $scope);
        if ($guard !== null) {
            return $guard;
        }

        $row = $payload($request, $scope) + [
            'workstream_id' => $scope->id,
            'created_by'    => $context['user_id'],
            'updated_by'    => $context['user_id'],
            'created_at'    => now(),
            'updated_at'    => now(),
        ];

        $newId = (int) DB::table($table)->insertGetId($row);

        return $this->ok($label . ' added successfully.', ['id' => (string) $newId], 201);
    }

    private function updateRecord(Request $request, int $workstreamId, int $recordId, string $table, callable $rules, callable $payload, string $label)
    {
        [$context, $scope, $error] = $this->resolve($request, $workstreamId);
        if ($error) {
            return $error;
        }

        // Scoped to the workstream, not just to the id — otherwise a record id
        // from another workstream (and another tenant) could be edited through
        // a workstream the caller legitimately owns.
        $exists = DB::table($table)->where('id', $recordId)->where('workstream_id', $scope->id)->exists();

        if (! $exists) {
            return $this->fail($label . ' not found.', 404);
        }

        $validator = Validator::make($request->all(), $rules($request));
        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422, $validator->errors()->toArray());
        }

        $guard = $this->guardRecordRelations($context, $request, $scope);
        if ($guard !== null) {
            return $guard;
        }

        DB::table($table)->where('id', $recordId)->update(
            $payload($request, $scope) + ['updated_by' => $context['user_id'], 'updated_at' => now()]
        );

        return $this->ok($label . ' updated successfully.', ['id' => (string) $recordId]);
    }

    private function deleteRecord(Request $request, int $workstreamId, int $recordId, string $table, string $label)
    {
        [$context, $scope, $error] = $this->resolve($request, $workstreamId);
        if ($error) {
            return $error;
        }

        $deleted = DB::table($table)->where('id', $recordId)->where('workstream_id', $scope->id)->delete();

        if ($deleted === 0) {
            // Not a silent 200. The old workstream delete returned success even
            // when it removed nothing, which makes a failed delete look done.
            return $this->fail($label . ' not found.', 404);
        }

        return $this->ok($label . ' deleted successfully.');
    }

    /**
     * Relational checks shared by every record type.
     *
     * @return \Illuminate\Http\JsonResponse|null
     */
    private function guardRecordRelations(array $context, Request $request, object $scope)
    {
        $ownerId = (int) $request->input('owner_id', 0);

        if ($ownerId > 0 && ! $this->usersInTenant($context, [$ownerId])) {
            return $this->fail('The selected owner does not belong to this organisation.', 422);
        }

        $checkpointId = (int) $request->input('checkpoint_id', 0);

        if ($checkpointId > 0) {
            $ok = DB::table('task_management_workstream_checkpoints')
                ->where('id', $checkpointId)->where('workstream_id', $scope->id)->exists();

            if (! $ok) {
                return $this->fail('That checkpoint does not belong to this workstream.', 422);
            }
        }

        return null;
    }

    private function nullableId(Request $request, string $field): ?int
    {
        $value = $request->input($field);

        return $value === null || $value === '' ? null : (int) $value;
    }

    private function dateOnly(Request $request, string $field): ?string
    {
        $value = trim((string) $request->input($field, ''));

        return $value === '' ? null : substr($value, 0, 10);
    }
}
