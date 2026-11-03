<?php

namespace App\Http\Controllers\Api\Agent;

use App\Domain\AgentGateway\AgentAccess;
use App\Domain\AgentGateway\AgentModel;
use App\Domain\AgentGateway\AgentPresenter;
use App\Domain\AgentGateway\AgentRefused;
use App\Domain\AgentGateway\AutonomyPolicy;
use App\Domain\AgentGateway\AutonomySettings;
use App\Domain\AgentGateway\OrgAgentService;
use App\Domain\AgentGateway\ProposalApplier;
use App\Domain\AgentGateway\ProposalDecisions;
use App\Domain\AgentGateway\ProposalNotFound;
use App\Domain\AgentGateway\ToolRegistry;
use App\Domain\OrgAccounts\OrgAccount;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * The agent API (slice 7, thin of the agentic CRM, Docs/agentic-crm/slices/S07-agent-gateway-thin.md). Routes and rights are in routes/agent_gateway.php.
 *
 * The organisation always comes from the caller's token, never from the request. Every read and write of an identity, a run or a proposal filters by it,
 * and another organisation's id is answered with the same 404 as one that does not exist, so a probe learns nothing. Shapes are built by AgentPresenter.
 * The agent acts AS the caller: every record it writes carries the caller's id, and the caller's rights were checked by the route's middleware.
 */
class AgentGatewayController extends Controller
{
    use ResolvesApiIdentity;

    private const RUN_STATUSES = ['running', 'completed', 'unavailable', 'failed'];

    private const MAX_PER_PAGE = 50;

    /** GET /organisations/resolve?crm_organization_id=N: which Organisation 360 identity a CRM organisation belongs to. */
    public function resolve(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $validator = Validator::make($request->query(), ['crm_organization_id' => ['required', 'integer', 'min:1']]);
        if ($validator->fails()) {
            return $this->invalid($validator->errors()->toArray());
        }
        $id = OrgAccount::query()->where('sub_institute_id', $identity['sub_institute_id'])->where('crm_organization_id', (int) $request->query('crm_organization_id'))->value('id');

        return $id === null ? $this->notFound('Organisation not found.') : response()->json(['status' => 1, 'data' => ['org_account_id' => (int) $id]]);
    }

    /** GET /organisations/{orgAccountId}: what the Run agent panel shows. */
    public function organisation(Request $request, int $orgAccountId): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];
        $account = OrgAccount::query()->where('sub_institute_id', $t)->find($orgAccountId);
        if (! $account) {
            return $this->notFound('Organisation not found.');
        }

        $presenter = new AgentPresenter($t);
        $policy = app(AutonomyPolicy::class);
        $runs = DB::table('g2g_agent_runs')->where('sub_institute_id', $t)->where('subject_type', 'org_account')->where('subject_id', $account->id);
        $last = (clone $runs)->orderByDesc('id')->first();
        $proposals = DB::table('g2g_agent_proposals')->where('sub_institute_id', $t)->where('subject_type', 'org_account')->where('subject_id', $account->id);
        $pending = (clone $proposals)->where('status', 'pending')->orderBy('id')->limit(50)->get();
        $recent = (clone $proposals)->where('status', '!=', 'pending')->orderByDesc('updated_at')->orderByDesc('id')->limit(20)->get();
        $names = $presenter->userNames($pending->pluck('decided_by')->merge($recent->pluck('decided_by'))->all());
        $killed = $policy->killed($t);
        $canEdit = AgentAccess::canOnOrganizations($identity, 'edit');

        return response()->json(['status' => 1, 'data' => [
            'org_account' => ['id' => (int) $account->id, 'name' => (string) $account->name, 'has_crm_organization' => $account->crm_organization_id !== null],
            'can' => ['run' => $canEdit, 'decide' => $canEdit],
            'agent' => [
                'enabled' => ! $killed,
                'unavailable_reason' => $killed ? 'The agent is switched off for your organisation.' : app(AgentModel::class)->readiness($t),
                'runs_today' => (int) (clone $runs)->where('started_at', '>=', now()->startOfDay())->count(),
                'daily_cap' => (int) config('agent_actions.daily_run_cap', 10),
                'autonomy' => collect(ToolRegistry::names())->mapWithKeys(fn (string $a) => [$a => $policy->level($t, $a)])->all(),
            ],
            'last_run' => $last ? $presenter->runDetail($last) : null,
            'pending' => $pending->map(fn ($p) => $presenter->proposal($p, $names))->values()->all(),
            'recent' => $recent->map(fn ($p) => $presenter->proposal($p, $names))->values()->all(),
        ]]);
    }

    /** POST /organisations/{orgAccountId}/run: runs the agent once and answers with the run. */
    public function run(Request $request, int $orgAccountId): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        try {
            $run = app(OrgAgentService::class)->run($identity['sub_institute_id'], $identity['user_id'], $orgAccountId, 'manual');
        } catch (AgentRefused $e) {
            return $this->refused($e);
        }

        return response()->json(['status' => 1, 'data' => (new AgentPresenter($identity['sub_institute_id']))->runDetail($run)], 201);
    }

    /** POST /proposals/{id}/accept */
    public function accept(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];

        try {
            $result = app(ProposalDecisions::class)->accept($t, $identity['user_id'], $id);
        } catch (ProposalNotFound) {
            return $this->notFound('Proposal not found.');
        } catch (AgentRefused $e) {
            return $this->refused($e);
        }

        $proposal = (new AgentPresenter($t))->proposal($result['proposal']);

        return match ($result['outcome']) {
            ProposalApplier::APPLIED, ProposalApplier::ALREADY_APPLIED => response()->json(['status' => 1, 'data' => ['outcome' => $result['outcome'], 'proposal' => $proposal]]),
            ProposalApplier::FAILED => response()->json(['status' => 0, 'code' => 'could_not_apply', 'message' => (string) $result['error'], 'data' => ['proposal' => $proposal]], 409),
            default => response()->json(['status' => 0, 'code' => 'already_decided', 'message' => 'This proposal was already ' . $result['proposal']->status . '.', 'data' => ['proposal' => $proposal]], 409),
        };
    }

    /** POST /proposals/{id}/dismiss {reason?} */
    public function dismiss(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];
        $validator = Validator::make($request->all(), ['reason' => ['nullable', 'string', 'max:500']], ['reason.max' => 'A reason can have at most 500 characters.']);
        if ($validator->fails()) {
            return $this->invalid($validator->errors()->toArray());
        }

        try {
            $result = app(ProposalDecisions::class)->dismiss($t, $identity['user_id'], $id, $request->input('reason'));
        } catch (ProposalNotFound) {
            return $this->notFound('Proposal not found.');
        }

        $proposal = (new AgentPresenter($t))->proposal($result['proposal']);
        if (in_array($result['outcome'], [ProposalDecisions::DISMISSED, ProposalDecisions::ALREADY_DISMISSED], true)) {
            return response()->json(['status' => 1, 'data' => ['outcome' => $result['outcome'], 'proposal' => $proposal]]);
        }

        return response()->json(['status' => 0, 'code' => 'already_decided', 'message' => 'This proposal was already ' . $result['proposal']->status . '.', 'data' => ['proposal' => $proposal]], 409);
    }

    /** GET /runs: ?status, ?date_from, ?date_to (Y-m-d, on the day the run started), ?page, ?per_page (at most 50). */
    public function runs(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];

        $validator = Validator::make($request->query(), [
            'status' => ['nullable', 'in:' . implode(',', self::RUN_STATUSES)],
            'date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1'],
        ]);
        if ($validator->fails()) {
            return $this->invalid($validator->errors()->toArray());
        }
        $f = $validator->validated();

        $query = DB::table('g2g_agent_runs')->where('sub_institute_id', $t);
        if (! empty($f['status'])) {
            $query->where('status', $f['status']);
        }
        if (! empty($f['date_from'])) {
            $query->where('started_at', '>=', $f['date_from'] . ' 00:00:00');
        }
        if (! empty($f['date_to'])) {
            $query->where('started_at', '<=', $f['date_to'] . ' 23:59:59');
        }

        $perPage = max(1, min(self::MAX_PER_PAGE, (int) ($f['per_page'] ?? 25)));
        $page = max(1, (int) ($f['page'] ?? 1));
        $total = (int) (clone $query)->count();
        $rows = $query->orderByDesc('id')->forPage($page, $perPage)->get();

        $presenter = new AgentPresenter($t);
        $names = $presenter->userNames($rows->pluck('user_id')->all());
        $orgNames = $presenter->orgNames($rows->pluck('subject_id')->map(fn ($v) => (int) $v)->unique()->values()->all());
        $counts = $presenter->countsFor($rows);

        $by = DB::table('g2g_agent_runs')->where('sub_institute_id', $t)->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status')->all();
        $proposals = DB::table('g2g_agent_proposals')->where('sub_institute_id', $t)->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status')->all();

        return response()->json([
            'status' => 1,
            'data' => $rows->map(fn ($r) => $presenter->runSummary($r, $names, $orgNames, $counts[(int) $r->id] ?? []))->values()->all(),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $perPage))],
            'summary' => [
                'total' => (int) array_sum($by), 'completed' => (int) ($by['completed'] ?? 0), 'failed' => (int) ($by['failed'] ?? 0), 'unavailable' => (int) ($by['unavailable'] ?? 0),
                'running' => (int) ($by['running'] ?? 0), 'proposals_pending' => (int) ($proposals['pending'] ?? 0), 'actions_applied' => (int) ($proposals['applied'] ?? 0),
            ],
        ]);
    }

    /** GET /runs/{id}: one run with its brief, proposals and audit trail. */
    public function showRun(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];
        $run = DB::table('g2g_agent_runs')->where('sub_institute_id', $t)->where('id', $id)->first();
        if (! $run) {
            return $this->notFound('Run not found.');
        }

        return response()->json(['status' => 1, 'data' => (new AgentPresenter($t))->runDetail($run)]);
    }

    // ---- the waiting queue (S7b) ----

    private const PROPOSAL_STATUSES = ['pending', 'applied', 'dismissed', 'failed'];

    /**
     * GET /proposals?status=pending&page=&per_page= : the organisation's proposals, newest first, across all its organisations, so a person can clear the
     * day's without opening them one by one. Read-only: a decision goes through POST /proposals/{id}/accept or /dismiss, the one apply path, and nothing
     * here applies, dismisses or writes anything. Rights: VIEW on Agent Run History (the route); `can.decide` says whether the caller may use those two.
     */
    public function proposals(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];

        $validator = Validator::make($request->query(), [
            'status' => ['nullable', 'in:' . implode(',', self::PROPOSAL_STATUSES)],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1'],
        ]);
        if ($validator->fails()) {
            return $this->invalid($validator->errors()->toArray());
        }
        $f = $validator->validated();
        $perPage = max(1, min(self::MAX_PER_PAGE, (int) ($f['per_page'] ?? 25)));
        $page = max(1, (int) ($f['page'] ?? 1));

        $query = DB::table('g2g_agent_proposals')->where('sub_institute_id', $t)->where('status', $f['status'] ?? 'pending');
        $total = (int) (clone $query)->count();
        $rows = $query->orderByDesc('id')->forPage($page, $perPage)->get();

        $presenter = new AgentPresenter($t);
        $names = $presenter->userNames($rows->pluck('decided_by')->all());
        $organisations = $presenter->organisations($rows->pluck('subject_id')->map(fn ($v) => (int) $v)->all());
        $canDecide = AgentAccess::canOnOrganizations($identity, 'edit');

        return response()->json(['status' => 1, 'data' => [
            'rows' => $rows->map(fn ($r) => $presenter->queueRow($r, $names, $organisations, $canDecide))->values()->all(),
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $perPage))],
        ]]);
    }

    // ---- autonomy settings (S7b) ----

    /** GET /autonomy: the kill switch and every action with its level, its default and what the agent did with it in the last 30 days. */
    public function autonomy(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        return response()->json(['status' => 1, 'data' => app(AutonomySettings::class)->state($identity['sub_institute_id']) + ['can' => ['change' => AgentAccess::canChangeAutonomy($identity)]]]);
    }

    /** PUT /autonomy/{actionKey} {level, reason?}: an administrator sets how much the agent may do alone for one kind of action. Answers with that action. */
    public function setAutonomy(Request $request, string $actionKey): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        if (! AgentAccess::isAdministrator($identity)) {
            return $this->administratorsOnly();
        }
        $validator = Validator::make($request->all(), ['level' => ['required', 'string'], 'reason' => ['nullable', 'string', 'max:' . AutonomySettings::MAX_REASON]],
            ['reason.max' => 'A reason can have at most ' . AutonomySettings::MAX_REASON . ' characters.']);
        if ($validator->fails()) {
            return $this->invalid($validator->errors()->toArray());
        }

        try {
            $action = app(AutonomySettings::class)->setLevel($identity['sub_institute_id'], $identity['user_id'], $actionKey, (string) $request->input('level'), $request->input('reason'));
        } catch (AgentRefused $e) {
            return $this->refused($e);
        }

        return response()->json(['status' => 1, 'data' => $action]);
    }

    /** PUT /autonomy-kill-switch {on, reason?}: an administrator switches the agent off for their organisation, or back on. The shared switch is not reachable here. */
    public function setKillSwitch(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        if (! AgentAccess::isAdministrator($identity)) {
            return $this->administratorsOnly();
        }
        $validator = Validator::make($request->all(), ['on' => ['required', 'boolean'], 'reason' => ['nullable', 'string', 'max:' . AutonomySettings::MAX_REASON]],
            ['reason.max' => 'A reason can have at most ' . AutonomySettings::MAX_REASON . ' characters.']);
        if ($validator->fails()) {
            return $this->invalid($validator->errors()->toArray());
        }

        try {
            $switch = app(AutonomySettings::class)->setKillSwitch($identity['sub_institute_id'], $identity['user_id'], $request->boolean('on'), $request->input('reason'));
        } catch (AgentRefused $e) {
            return $this->refused($e);
        }

        return response()->json(['status' => 1, 'data' => ['kill_switch' => $switch]]);
    }

    // ---- helpers ----

    private function administratorsOnly(): JsonResponse
    {
        return response()->json(['status' => 0, 'code' => 'administrators_only', 'message' => 'Only an administrator can change how much the agent may do on its own.'], 403);
    }

    private function refused(AgentRefused $e): JsonResponse
    {
        return response()->json(['status' => 0, 'code' => $e->reason, 'message' => $e->getMessage()], $e->status);
    }

    private function notFound(string $message): JsonResponse
    {
        return response()->json(['status' => 0, 'message' => $message], 404);
    }

    /** @param array<string, list<string>> $errors field => messages */
    private function invalid(array $errors): JsonResponse
    {
        return response()->json(['status' => 0, 'message' => (string) (collect($errors)->flatten()->first() ?? 'The request is not valid.'), 'errors' => $errors], 422);
    }
}
