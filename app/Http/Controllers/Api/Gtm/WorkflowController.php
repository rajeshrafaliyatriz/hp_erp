<?php

namespace App\Http\Controllers\Api\Gtm;

use App\Domain\Gtm\GtmAudit;
use App\Domain\Gtm\GtmEvents;
use App\Domain\Gtm\Workflows\ConditionEvaluator;
use App\Domain\Gtm\Workflows\WorkflowCatalog;
use App\Domain\Gtm\Workflows\WorkflowContext;
use App\Domain\Gtm\Workflows\WorkflowEngine;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Workflow definitions and their run history. Definitions are validated against WorkflowCatalog on every save and again
 * on activation (a campaign they use may have closed since). Nothing a workflow does can be triggered from here except
 * the explicit run decisions (approve / reject / retry / cancel), all administrator-only and audited.
 */
class WorkflowController extends Controller
{
    use ResolvesApiIdentity;

    private const TRANSITIONS = ['draft' => ['active', 'archived'], 'active' => ['paused', 'archived'], 'paused' => ['active', 'archived'], 'archived' => []];

    public function catalog(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        return response()->json(['status' => 1, 'data' => [
            'triggers' => WorkflowCatalog::triggers(), 'operators' => ConditionEvaluator::OPS, 'actions' => WorkflowCatalog::actions(),
            'notes' => [
                'A workflow reacts only to events recorded after it is activated.',
                'Events are delivered by the platform event runner (events:react), so a workflow starts within minutes of the event, not instantly.',
                'Workflows never send email. The Outreach agent can save DRAFTS, which still need a person to approve them.',
                'A run that includes a consequential action, or a workflow marked "requires approval", waits for an administrator.',
            ],
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];
        $rows = DB::table('gtm_workflows')->where('sub_institute_id', $t)->where('status', '!=', 'archived')->orderByDesc('id')->get();
        $runs = DB::table('gtm_workflow_runs')->where('sub_institute_id', $t)->where('created_at', '>=', now()->subDays(7))->selectRaw('workflow_id, status, COUNT(*) c')->groupBy('workflow_id', 'status')->get()->groupBy('workflow_id');
        foreach ($rows as $r) {
            $r->conditions = json_decode((string) $r->conditions, true) ?: [];
            $r->actions = json_decode((string) $r->actions, true) ?: [];
            $r->runs_7d = ($runs[$r->id] ?? collect())->pluck('c', 'status');
            $r->trigger_label = GtmEvents::TYPES[$r->trigger_event]['label'] ?? $r->trigger_event;
        }

        return response()->json(['status' => 1, 'data' => ['items' => $rows]]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];
        $w = $this->find($t, $id);
        if (! $w) {
            return $this->notFound();
        }
        $w->conditions = json_decode((string) $w->conditions, true) ?: [];
        $w->actions = json_decode((string) $w->actions, true) ?: [];
        $w->needs_approval = (bool) $w->requires_approval || WorkflowCatalog::hasConsequential($w->actions);

        return response()->json(['status' => 1, 'data' => ['workflow' => $w, 'transitions' => self::TRANSITIONS[$w->status] ?? [],
            'runs' => DB::table('gtm_workflow_runs')->where('sub_institute_id', $t)->where('workflow_id', $id)->orderByDesc('id')->limit(25)->get(['id', 'event_type', 'entity_type', 'entity_id', 'status', 'error', 'attempts', 'next_attempt_at', 'created_at', 'finished_at'])]]);
    }

    public function store(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];
        [$data, $err] = $this->validated($request, $t, true);
        if ($err) {
            return $err;
        }
        $id = DB::table('gtm_workflows')->insertGetId($data + ['sub_institute_id' => $t, 'status' => 'draft', 'created_by' => $identity['user_id'], 'updated_by' => $identity['user_id'], 'created_at' => now(), 'updated_at' => now()]);
        GtmAudit::record('gtm.workflow.created', $t, 'gtm_workflows', $id, $identity['user_id'], ['name' => $data['name'], 'trigger' => $data['trigger_event']]);

        return response()->json(['status' => 1, 'data' => ['workflow' => $this->presented($t, $id)]], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];
        $w = $this->find($t, $id);
        if (! $w) {
            return $this->notFound();
        }
        if (! in_array($w->status, ['draft', 'paused'], true)) {
            return response()->json(['status' => 0, 'code' => 'pause_first', 'message' => $w->status === 'active' ? 'Pause the workflow before editing it, so it is never half-edited while live.' : "A {$w->status} workflow cannot be edited."], 422);
        }
        [$data, $err] = $this->validated($request, $t, false, $w);
        if ($err) {
            return $err;
        }
        DB::table('gtm_workflows')->where('id', $id)->where('sub_institute_id', $t)->update($data + ['updated_by' => $identity['user_id'], 'updated_at' => now()]);
        GtmAudit::record('gtm.workflow.updated', $t, 'gtm_workflows', $id, $identity['user_id'], ['changed' => array_keys($data)]);

        return response()->json(['status' => 1, 'data' => ['workflow' => $this->presented($t, $id)]]);
    }

    public function status(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];
        $w = $this->find($t, $id);
        if (! $w) {
            return $this->notFound();
        }
        $v = Validator::make($request->all(), ['status' => 'required|in:active,paused,archived']);
        if ($v->fails()) {
            return response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $v->errors()], 422);
        }
        $to = (string) $request->input('status');
        if (! in_array($to, self::TRANSITIONS[$w->status] ?? [], true)) {
            return response()->json(['status' => 0, 'code' => 'bad_transition', 'message' => "A {$w->status} workflow cannot become {$to}."], 422);
        }
        if ($to === 'archived' && ! $request->boolean('confirm')) {
            return response()->json(['status' => 0, 'code' => 'confirmation_required', 'message' => 'Archiving is final, and cancels runs that have not started. Confirm to continue.'], 409);
        }
        if ($to === 'active') {
            $errors = WorkflowCatalog::validate($t, $w->trigger_event, json_decode((string) $w->conditions, true) ?: [], json_decode((string) $w->actions, true) ?: []);
            if ($errors !== []) {
                return response()->json(['status' => 0, 'code' => 'invalid_definition', 'message' => 'This workflow cannot be activated: '.$errors[0], 'errors' => ['definition' => $errors]], 422);
            }
        }
        $cancelled = 0;
        DB::transaction(function () use ($id, $t, $to, &$cancelled) {
            DB::table('gtm_workflows')->where('id', $id)->where('sub_institute_id', $t)->update(['status' => $to, 'activated_at' => $to === 'active' ? now() : DB::raw('activated_at'), 'updated_at' => now()]);
            if ($to === 'archived') {
                $cancelled = DB::table('gtm_workflow_runs')->where('sub_institute_id', $t)->where('workflow_id', $id)->whereIn('status', ['pending_approval', 'queued', 'retry_wait'])->update(['status' => 'cancelled', 'finished_at' => now(), 'updated_at' => now()]);
            }
        });
        GtmAudit::record('gtm.workflow.status_changed', $t, 'gtm_workflows', $id, $identity['user_id'], ['from' => $w->status, 'to' => $to, 'runs_cancelled' => $cancelled]);

        return response()->json(['status' => 1, 'data' => ['workflow' => $this->presented($t, $id), 'runs_cancelled' => $cancelled]]);
    }

    /** What the workflow would do for one real record. No side effects. */
    public function test(Request $request, int $id, WorkflowEngine $engine): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];
        $w = $this->find($t, $id);
        if (! $w) {
            return $this->notFound();
        }
        $v = Validator::make($request->all(), ['entity_id' => 'required|integer', 'payload' => 'nullable|array']);
        if ($v->fails()) {
            return response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $v->errors()], 422);
        }
        $entity = GtmEvents::TYPES[$w->trigger_event]['entity'] ?? null;
        $ctx = WorkflowContext::load($t, $w->trigger_event, $entity, (int) $request->input('entity_id'), (array) $request->input('payload', []));
        if (! $ctx) {
            return response()->json(['status' => 0, 'code' => 'record_not_found', 'message' => 'That record does not exist in your organisation.'], 404);
        }

        return response()->json(['status' => 1, 'data' => ['dry_run' => true] + $engine->dryRun($w, $ctx)]);
    }

    public function runs(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];
        $q = DB::table('gtm_workflow_runs as r')->join('gtm_workflows as w', fn ($j) => $j->on('w.id', '=', 'r.workflow_id')->where('w.sub_institute_id', '=', $t))->where('r.sub_institute_id', $t);
        if ($request->filled('status')) {
            $q->where('r.status', (string) $request->input('status'));
        }
        if ($request->filled('workflow_id')) {
            $q->where('r.workflow_id', (int) $request->input('workflow_id'));
        }
        $counts = DB::table('gtm_workflow_runs')->where('sub_institute_id', $t)->selectRaw('status, COUNT(*) c')->groupBy('status')->pluck('c', 'status');

        return response()->json(['status' => 1, 'data' => [
            'items' => $q->orderByDesc('r.id')->limit(min(200, max(10, (int) $request->input('limit', 50))))
                ->get(['r.id', 'r.workflow_id', 'w.name as workflow_name', 'r.event_type', 'r.entity_type', 'r.entity_id', 'r.status', 'r.error', 'r.attempts', 'r.next_attempt_at', 'r.decision_note', 'r.created_at', 'r.finished_at']),
            'counts' => $counts,
        ]]);
    }

    public function run(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];
        $r = DB::table('gtm_workflow_runs')->where('sub_institute_id', $t)->find($id);
        if (! $r) {
            return response()->json(['status' => 0, 'message' => 'Run not found'], 404);
        }
        $r->context = json_decode((string) $r->context, true);
        $steps = DB::table('gtm_workflow_run_steps')->where('sub_institute_id', $t)->where('run_id', $id)->orderBy('step')->get();
        foreach ($steps as $s) {
            $s->result = json_decode((string) $s->result, true);
        }

        return response()->json(['status' => 1, 'data' => ['run' => $r, 'workflow' => DB::table('gtm_workflows')->where('sub_institute_id', $t)->find($r->workflow_id, ['id', 'name', 'trigger_event', 'status']), 'steps' => $steps]]);
    }

    public function approve(Request $request, int $id, WorkflowEngine $engine): JsonResponse
    {
        return $this->decide($request, $id, $engine, true);
    }

    public function reject(Request $request, int $id, WorkflowEngine $engine): JsonResponse
    {
        if (trim((string) $request->input('note')) === '') {
            return response()->json(['status' => 0, 'code' => 'reason_required', 'message' => 'Say why this run is being rejected.'], 422);
        }

        return $this->decide($request, $id, $engine, false);
    }

    public function retry(Request $request, int $id, WorkflowEngine $engine): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        return $this->respond($engine->retry($identity['sub_institute_id'], $id, $identity['user_id']), $identity['sub_institute_id'], $id);
    }

    public function cancel(Request $request, int $id, WorkflowEngine $engine): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        return $this->respond($engine->cancel($identity['sub_institute_id'], $id, $identity['user_id']), $identity['sub_institute_id'], $id);
    }

    private function decide(Request $request, int $id, WorkflowEngine $engine, bool $approve): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        if ($approve && ! $request->boolean('confirm')) {
            return response()->json(['status' => 0, 'code' => 'confirmation_required', 'message' => 'Approving lets this workflow run its actions on this record now. Confirm to continue.'], 409);
        }

        return $this->respond($engine->decide($identity['sub_institute_id'], $id, $identity['user_id'], $approve, $request->input('note')), $identity['sub_institute_id'], $id);
    }

    /** @param array<string, mixed> $r */
    private function respond(array $r, int $tenant, int $runId): JsonResponse
    {
        if (! $r['ok']) {
            return response()->json(['status' => 0, 'code' => $r['code'] ?? 'error', 'message' => $r['message'] ?? 'Not possible.'], $r['http']);
        }

        return response()->json(['status' => 1, 'data' => ['status' => $r['status'] ?? DB::table('gtm_workflow_runs')->where('id', $runId)->value('status'),
            'run' => DB::table('gtm_workflow_runs')->where('sub_institute_id', $tenant)->find($runId, ['id', 'workflow_id', 'status', 'error', 'attempts', 'next_attempt_at', 'finished_at'])]]);
    }

    /** @return array{0: ?array<string, mixed>, 1: ?JsonResponse} */
    private function validated(Request $request, int $t, bool $creating, ?object $existing = null): array
    {
        $v = Validator::make($request->all(), [
            'name' => ($creating ? 'required' : 'sometimes').'|string|max:191', 'description' => 'nullable|string|max:2000',
            'trigger_event' => ($creating ? 'required' : 'prohibited').'|string|max:60', 'conditions' => 'nullable|array', 'actions' => ($creating ? 'required' : 'sometimes').'|array|min:1',
            'requires_approval' => 'sometimes|boolean', 'max_runs_per_day' => 'sometimes|integer|min:1|max:1000',
        ]);
        if ($v->fails()) {
            return [null, response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $v->errors()], 422)];
        }
        $d = $v->validated();
        $trigger = $existing->trigger_event ?? $d['trigger_event'];
        $conditions = array_key_exists('conditions', $d) ? (array) $d['conditions'] : (json_decode((string) ($existing->conditions ?? '[]'), true) ?: []);
        $actions = array_key_exists('actions', $d) ? (array) $d['actions'] : (json_decode((string) ($existing->actions ?? '[]'), true) ?: []);
        $errors = WorkflowCatalog::validate($t, $trigger, array_values($conditions), array_values($actions));
        if ($errors !== []) {
            return [null, response()->json(['status' => 0, 'message' => 'Invalid workflow: '.$errors[0], 'errors' => ['definition' => $errors]], 422)];
        }
        foreach (['conditions' => $conditions, 'actions' => $actions] as $k => $val) {
            if (array_key_exists($k, $d) || $creating) {
                $d[$k] = json_encode(array_values($val), JSON_UNESCAPED_UNICODE);
            }
        }

        return [$d, null];
    }

    private function find(int $t, int $id): ?object
    {
        return DB::table('gtm_workflows')->where('sub_institute_id', $t)->find($id);
    }

    private function presented(int $t, int $id): object
    {
        $w = DB::table('gtm_workflows')->where('sub_institute_id', $t)->find($id);
        $w->conditions = json_decode((string) $w->conditions, true) ?: [];
        $w->actions = json_decode((string) $w->actions, true) ?: [];

        return $w;
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['status' => 0, 'message' => 'Workflow not found'], 404);
    }
}
