<?php

namespace Tests\Feature;

use App\Domain\AgentGateway\OrgAgentService;
use App\Domain\OrgAccounts\OrgLinkConflict;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsAgentWorld;
use Tests\TestCase;

/**
 * The agent API (slice 7, thin): rights, shapes, refusals and the decisions, over HTTP with real tokens. The model is the fake of the other agent tests.
 *
 * The key lists below are the field names of g2gv0 services/agent/types.ts: a renamed or dropped field fails here before the screen breaks.
 * The cross-tenant sweep is its own file (AgentGatewayTenantSweepTest).
 */
class AgentGatewayApiTest extends TestCase
{
    use BuildsAgentWorld;

    private const P = '/api/agent';

    private const RUN_SUMMARY_KEYS = ['id', 'agent_key', 'trigger', 'status', 'subject', 'user_id', 'user_name', 'started_at', 'finished_at', 'provider', 'model', 'input_tokens', 'output_tokens',
        'cost', 'cost_currency', 'tool_calls', 'error_code', 'message', 'proposal_counts'];

    private const PROPOSAL_KEYS = ['id', 'run_id', 'action', 'status', 'summary', 'payload', 'reason', 'evidence', 'applied_via', 'applied_at', 'decided_by', 'decided_by_name', 'decided_at',
        'decision_note', 'result_ref', 'error', 'created_at'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAgentWorld();
    }

    private function due(): string
    {
        return now()->addDays(3)->toDateString();
    }

    /** @return array<string, mixed> */
    private function answer(array $org): array
    {
        $signal = [['type' => 'signal', 'id' => (string) $org['signals'][0]]];

        return ['brief' => 'Fresh hiring signals.', 'brief_evidence' => $signal, 'proposals' => [
            ['action' => 'add_note', 'text' => 'Hiring a principal.', 'reason' => 'Signal', 'evidence' => $signal],
            ['action' => 'add_task', 'title' => 'Call the trustee', 'due_date' => $this->due(), 'reason' => 'Signal', 'evidence' => $signal],
        ]];
    }

    private function level(string $action, string $level, int $tenant = self::T): void
    {
        DB::table('g2g_agent_autonomy')->updateOrInsert(['sub_institute_id' => $tenant, 'action_key' => $action], ['level' => $level]);
    }

    /** @return array{org: array<string, mixed>, user: array{id: int, h: array<string, string>}, run: object} an organisation with a finished run and its proposals (note waiting, task applied) */
    private function seeded(): array
    {
        $org = $this->makeOrg(self::T, 'Greenfield', ['signals' => 2]);
        $user = $this->editor();
        $this->level('add_note', 'approval');
        $this->model->respond = $this->answer($org);
        $run = app(OrgAgentService::class)->run(self::T, $user['id'], $org['identity']);

        return ['org' => $org, 'user' => $user, 'run' => $run];
    }

    /** @param list<string> $expected */
    private function assertKeys(array $expected, array $actual, string $what): void
    {
        sort($expected);
        $keys = array_keys($actual);
        sort($keys);
        $this->assertSame($expected, $keys, "the keys of {$what}");
    }

    // ---- rights ----

    public function test_every_route_needs_a_token(): void
    {
        $s = $this->seeded();
        foreach ([
            ['GET', "/organisations/{$s['org']['identity']}"], ['POST', "/organisations/{$s['org']['identity']}/run"], ['POST', '/proposals/1/accept'], ['POST', '/proposals/1/dismiss'],
            ['GET', '/runs'], ['GET', "/runs/{$s['run']->id}"], ['GET', '/organisations/resolve?crm_organization_id=1'],
        ] as [$method, $path]) {
            $this->json($method, self::P . $path)->assertStatus(401);
        }
    }

    /** A waiting `add_note` proposal of its own, so one user's accept or dismiss cannot change what the next user of a test meets. */
    private function waitingNote(array $org, int $runId, string $text): int
    {
        return (int) DB::table('g2g_agent_proposals')->insertGetId([
            'sub_institute_id' => self::T, 'run_id' => $runId, 'subject_type' => 'org_account', 'subject_id' => $org['identity'], 'action' => 'add_note',
            'entity_type' => 'organization', 'entity_id' => $org['crm'], 'payload' => json_encode(['text' => $text]), 'payload_hash' => sha1('add_note|' . strtolower($text)),
            'evidence' => json_encode([]), 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array<string, array<string, mixed>> the state of the whole agent layer, as arrays (objects from two queries are never identical) */
    private function agentState(): array
    {
        return $this->writes() + [
            'proposals' => DB::table('g2g_agent_proposals')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'runs' => DB::table('g2g_agent_runs')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'calls' => DB::table('g2g_agent_tool_calls')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        ];
    }

    public function test_the_rights_matrix_view_edit_the_support_row_and_the_run_history_each_open_exactly_their_own_routes(): void
    {
        $s = $this->seeded();
        $id = $s['org']['identity'];
        $this->model->respond = ['brief' => 'x', 'brief_evidence' => [['type' => 'org_account', 'id' => (string) $id]], 'proposals' => []];

        $nobody = $this->user([]);
        $viewer = $this->user([self::ORG_LINK => ['view']]);
        $editOnly = $this->user([self::ORG_LINK => ['edit']]);
        $supportEditor = $this->user([self::ORG_LINK_SUPPORT => ['view', 'edit']]);
        $historyOnly = $this->user([self::HISTORY_LINK => ['view']]);
        $addOnly = $this->user([self::ORG_LINK => ['add', 'delete']]);
        $full = $this->editor();

        // user => [expected status of: state, run, accept, dismiss, runs list, run detail; whether the person may decide a proposal]. Each user gets two
        // proposals of their own (one to accept, one to dismiss): a decision is final, so a shared one would answer the second user "already decided".
        $matrix = [
            'nobody' => [$nobody, [403, 403, 403, 403, 403, 403], false],
            'organization viewer' => [$viewer, [200, 403, 403, 403, 403, 403], false],
            'edit without view' => [$editOnly, [403, 201, 200, 200, 403, 403], true],
            'support-row editor (the CRM accepts either row)' => [$supportEditor, [200, 201, 200, 200, 403, 403], true],
            'run history only' => [$historyOnly, [403, 403, 403, 403, 200, 200], false],
            'add and delete only' => [$addOnly, [403, 403, 403, 403, 403, 403], false],
            'organization editor with history' => [$full, [200, 201, 200, 200, 200, 200], true],
        ];

        $n = 0;
        foreach ($matrix as $label => [$user, $expected, $decides]) {
            $n++;
            $toAccept = $this->waitingNote($s['org'], $s['run']->id, "Accept me {$n}");
            $toDismiss = $this->waitingNote($s['org'], $s['run']->id, "Dismiss me {$n}");
            $calls = [
                $this->getJson(self::P . "/organisations/{$id}", $user['h']),
                $this->postJson(self::P . "/organisations/{$id}/run", [], $user['h']),
                $this->postJson(self::P . "/proposals/{$toAccept}/accept", [], $user['h']),
                $this->postJson(self::P . "/proposals/{$toDismiss}/dismiss", [], $user['h']),
                $this->getJson(self::P . '/runs', $user['h']),
                $this->getJson(self::P . "/runs/{$s['run']->id}", $user['h']),
            ];
            $this->assertSame($expected, array_map(fn ($r) => $r->getStatusCode(), $calls), $label);
            // A refusal left the proposals as they were; a permitted decision really decided them.
            $this->assertSame($decides ? ['applied', 'dismissed'] : ['pending', 'pending'],
                [DB::table('g2g_agent_proposals')->find($toAccept)->status, DB::table('g2g_agent_proposals')->find($toDismiss)->status], $label . ': what became of the proposals');
        }
    }

    public function test_a_refused_request_changes_nothing(): void
    {
        $s = $this->seeded();
        $note = DB::table('g2g_agent_proposals')->where('action', 'add_note')->value('id');
        $before = $this->agentState();
        $viewer = $this->user([self::ORG_LINK => ['view']]);

        $this->postJson(self::P . "/organisations/{$s['org']['identity']}/run", [], $viewer['h'])->assertStatus(403);
        $this->postJson(self::P . "/proposals/{$note}/accept", [], $viewer['h'])->assertStatus(403);
        $this->postJson(self::P . "/proposals/{$note}/dismiss", [], $viewer['h'])->assertStatus(403);

        $this->assertSame($before, $this->agentState());
    }

    // ---- the organisation panel ----

    public function test_the_organisation_state_has_the_last_run_the_waiting_proposals_the_recent_actions_and_what_the_caller_may_do(): void
    {
        $s = $this->seeded();
        $id = $s['org']['identity'];

        $res = $this->getJson(self::P . "/organisations/{$id}", $s['user']['h'])->assertOk();
        $d = $res->json('data');

        $this->assertKeys(['org_account', 'can', 'agent', 'last_run', 'pending', 'recent'], $d, 'the organisation state');
        $this->assertSame(['id' => $id, 'name' => 'Greenfield', 'has_crm_organization' => true], $d['org_account']);
        $this->assertSame(['run' => true, 'decide' => true], $d['can']);
        $this->assertKeys(['enabled', 'unavailable_reason', 'runs_today', 'daily_cap', 'autonomy'], $d['agent'], 'agent');
        $this->assertSame(['enabled' => true, 'unavailable_reason' => null, 'runs_today' => 1, 'daily_cap' => 10], array_diff_key($d['agent'], ['autonomy' => 1]));
        $this->assertSame(['add_note' => 'approval', 'add_task' => 'alone', 'confirm_link' => 'approval'], $d['agent']['autonomy']);
        $this->assertKeys([...self::RUN_SUMMARY_KEYS, 'brief', 'brief_evidence', 'proposals', 'trace'], $d['last_run'], 'the last run');
        $this->assertSame('Fresh hiring signals.', $d['last_run']['brief']);
        $this->assertSame('signal', $d['last_run']['brief_evidence'][0]['type']);
        $this->assertCount(1, $d['pending']);
        $this->assertKeys(self::PROPOSAL_KEYS, $d['pending'][0], 'a proposal');
        $this->assertSame(['add_note', 'pending'], [$d['pending'][0]['action'], $d['pending'][0]['status']]);
        $this->assertSame('Add a note: Hiring a principal.', $d['pending'][0]['summary']);
        $this->assertCount(1, $d['recent']);
        $this->assertSame(['add_task', 'applied', 'policy'], [$d['recent'][0]['action'], $d['recent'][0]['status'], $d['recent'][0]['applied_via']]);
        $this->assertSame(['ref', 'tool', 'outcome'], array_keys(array_intersect_key(['ref' => 1, 'tool' => 1, 'outcome' => 1], $d['last_run']['trace'][0] + ['ref' => 1])));
    }

    public function test_the_viewer_sees_the_state_but_is_told_the_buttons_are_not_theirs(): void
    {
        $s = $this->seeded();
        $viewer = $this->user([self::ORG_LINK => ['view']]);

        $res = $this->getJson(self::P . "/organisations/{$s['org']['identity']}", $viewer['h'])->assertOk();

        $this->assertSame(['run' => false, 'decide' => false], $res->json('data.can'));
    }

    public function test_the_state_says_why_the_agent_cannot_run_and_whether_it_is_switched_off(): void
    {
        $org = $this->makeOrg(self::T, 'Greenfield', ['signals' => 1]);
        $user = $this->editor();

        $this->model->unavailable = 'The model "x" has no price set.';
        $d = $this->getJson(self::P . "/organisations/{$org['identity']}", $user['h'])->assertOk()->json('data.agent');
        $this->assertSame(['enabled' => true, 'unavailable_reason' => 'The model "x" has no price set.'], ['enabled' => $d['enabled'], 'unavailable_reason' => $d['unavailable_reason']]);

        $this->level('*', 'off');
        $d = $this->getJson(self::P . "/organisations/{$org['identity']}", $user['h'])->assertOk()->json('data.agent');
        $this->assertFalse($d['enabled']);
        $this->assertSame('The agent is switched off for your organisation.', $d['unavailable_reason']);
        $this->assertSame(['add_note' => 'off', 'add_task' => 'off', 'confirm_link' => 'off'], $d['autonomy']);
        $this->assertNull($this->getJson(self::P . "/organisations/{$org['identity']}", $user['h'])->json('data.last_run'), 'no run yet');
    }

    public function test_an_organisation_with_no_run_has_an_empty_state(): void
    {
        $org = $this->makeOrg(self::T, 'Fresh', ['crm' => false]);

        $d = $this->getJson(self::P . "/organisations/{$org['identity']}", $this->editor()['h'])->assertOk()->json('data');

        $this->assertNull($d['last_run']);
        $this->assertSame([], $d['pending']);
        $this->assertSame([], $d['recent']);
        $this->assertFalse($d['org_account']['has_crm_organization']);
        $this->assertSame(0, $d['agent']['runs_today']);
    }

    // ---- run ----

    public function test_running_the_agent_answers_with_the_run_and_the_work_it_did(): void
    {
        $org = $this->makeOrg(self::T, 'Greenfield', ['signals' => 1]);
        $user = $this->editor();
        $this->model->respond = $this->answer($org);

        $res = $this->postJson(self::P . "/organisations/{$org['identity']}/run", [], $user['h'])->assertStatus(201);

        $d = $res->json('data');
        $this->assertKeys([...self::RUN_SUMMARY_KEYS, 'brief', 'brief_evidence', 'proposals', 'trace'], $d, 'a run');
        $this->assertSame(['completed', 'manual'], [$d['status'], $d['trigger']]);
        $this->assertSame(['type' => 'org_account', 'id' => $org['identity'], 'name' => 'Greenfield'], $d['subject']);
        $this->assertSame($user['id'], $d['user_id']);
        $this->assertSame('Asha Rao', $d['user_name']);
        $this->assertSame(['INR', 1200, 300], [$d['cost_currency'], $d['input_tokens'], $d['output_tokens']]);
        $this->assertEqualsWithDelta(0.0691, $d['cost'], 0.00001);
        $this->assertSame(['pending' => 0, 'applied' => 2, 'dismissed' => 0, 'failed' => 0], $d['proposal_counts']);
        $this->assertCount(2, $d['proposals']);
        $this->assertSame(['read_context', 'model_call', 'write_brief', 'add_note', 'add_task'], array_column($d['trace'], 'tool'));
        $this->assertSame(1, DB::table('crm_organization_comments')->count());
    }

    public function test_running_it_when_the_model_is_unavailable_is_a_201_with_status_unavailable_and_the_reason(): void
    {
        $org = $this->makeOrg(self::T, 'Greenfield', ['signals' => 1]);
        $this->model->unavailable = 'No AI model is set up for your organisation, so the agent cannot run.';

        $d = $this->postJson(self::P . "/organisations/{$org['identity']}/run", [], $this->editor()['h'])->assertStatus(201)->json('data');

        $this->assertSame(['unavailable', 'model_unavailable'], [$d['status'], $d['error_code']]);
        $this->assertSame('No AI model is set up for your organisation, so the agent cannot run.', $d['message']);
        $this->assertNull($d['brief']);
        $this->assertSame([], $d['proposals']);
    }

    public function test_the_refusals_before_a_run_have_their_own_status_and_code(): void
    {
        $org = $this->makeOrg(self::T, 'Greenfield', ['signals' => 1]);
        $theirs = $this->makeOrg(self::T2, 'Their School');
        $user = $this->editor();
        $this->model->respond = ['brief' => '', 'proposals' => []];
        $run = fn (int $id) => $this->postJson(self::P . "/organisations/{$id}/run", [], $user['h']);

        $run($theirs['identity'])->assertStatus(404)->assertJson(['status' => 0, 'message' => 'Organisation not found.']);
        $run(999999)->assertStatus(404);

        config(['agent_actions.daily_run_cap' => 1]);
        $run($org['identity'])->assertStatus(201);
        $run($org['identity'])->assertStatus(429)->assertJson(['status' => 0, 'code' => 'daily_cap']);

        config(['agent_actions.daily_run_cap' => 10]);
        DB::table('g2g_agent_runs')->insert(['sub_institute_id' => self::T, 'agent_key' => 'organisation', 'subject_type' => 'org_account', 'subject_id' => $org['identity'], 'trigger_source' => 'manual',
            'status' => 'running', 'started_at' => now(), 'tool_calls' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $run($org['identity'])->assertStatus(409)->assertJson(['status' => 0, 'code' => 'already_running']);

        $this->level('*', 'off');
        $run($org['identity'])->assertStatus(403)->assertJson(['status' => 0, 'code' => 'agent_off']);
        $this->assertCount(1, $this->model->calls, 'only the first run reached the model');
    }

    // ---- decisions ----

    public function test_accepting_applies_once_and_says_so_the_second_time_and_a_dismissed_one_cannot_be_accepted(): void
    {
        $s = $this->seeded();
        $note = DB::table('g2g_agent_proposals')->where('action', 'add_note')->value('id');
        $accepter = $this->editor(self::T, 'Bela', 'Nair');

        $first = $this->postJson(self::P . "/proposals/{$note}/accept", [], $accepter['h'])->assertOk();
        $second = $this->postJson(self::P . "/proposals/{$note}/accept", [], $accepter['h'])->assertOk();

        $this->assertSame('applied', $first->json('data.outcome'));
        $this->assertSame('already_applied', $second->json('data.outcome'));
        $this->assertKeys(self::PROPOSAL_KEYS, $first->json('data.proposal'), 'the proposal');
        $this->assertSame(['applied', 'user', 'Bela Nair'], [$first->json('data.proposal.status'), $first->json('data.proposal.applied_via'), $first->json('data.proposal.decided_by_name')]);
        $this->assertSame(1, DB::table('crm_organization_comments')->count());
        $this->assertSame($accepter['id'], (int) DB::table('crm_organization_comments')->value('created_by'));
        $this->assertSame(0, (int) DB::table('g2g_agent_proposals')->where('status', 'pending')->count());
    }

    public function test_accepting_what_has_been_switched_off_since_is_a_409_with_its_code_and_writes_nothing(): void
    {
        $s = $this->seeded();
        $note = DB::table('g2g_agent_proposals')->where('action', 'add_note')->value('id');
        $this->level('add_note', 'off');
        $before = $this->agentState();

        $res = $this->postJson(self::P . "/proposals/{$note}/accept", [], $s['user']['h'])->assertStatus(409);

        $res->assertJson(['status' => 0, 'code' => 'policy_off', 'message' => 'This kind of action is switched off for your organisation.']);
        $this->assertSame($before, $this->agentState());
        // The person can still say no to it.
        $this->postJson(self::P . "/proposals/{$note}/dismiss", [], $s['user']['h'])->assertOk()->assertJsonPath('data.outcome', 'dismissed');
    }

    public function test_dismissing_keeps_the_reason_and_cannot_be_undone_by_accepting_and_is_the_same_twice(): void
    {
        $s = $this->seeded();
        $note = DB::table('g2g_agent_proposals')->where('action', 'add_note')->value('id');

        $first = $this->postJson(self::P . "/proposals/{$note}/dismiss", ['reason' => 'Already knew this'], $s['user']['h'])->assertOk();
        $again = $this->postJson(self::P . "/proposals/{$note}/dismiss", ['reason' => 'Changed my mind'], $s['user']['h'])->assertOk();

        $this->assertSame('dismissed', $first->json('data.outcome'));
        $this->assertSame('already_dismissed', $again->json('data.outcome'));
        $this->assertSame('Already knew this', $again->json('data.proposal.decision_note'));
        $this->postJson(self::P . "/proposals/{$note}/accept", [], $s['user']['h'])->assertStatus(409)->assertJson(['code' => 'already_decided']);
        $this->assertSame(0, DB::table('crm_organization_comments')->count());

        $task = DB::table('g2g_agent_proposals')->where('action', 'add_task')->value('id');
        $this->postJson(self::P . "/proposals/{$task}/dismiss", [], $s['user']['h'])->assertStatus(409)->assertJson(['code' => 'already_decided']);
    }

    public function test_a_reason_over_500_characters_is_a_422_and_nothing_is_dismissed(): void
    {
        $s = $this->seeded();
        $note = DB::table('g2g_agent_proposals')->where('action', 'add_note')->value('id');

        $this->postJson(self::P . "/proposals/{$note}/dismiss", ['reason' => str_repeat('r', 501)], $s['user']['h'])->assertStatus(422)->assertJsonPath('message', 'A reason can have at most 500 characters.');
        $this->assertSame('pending', DB::table('g2g_agent_proposals')->find($note)->status);
    }

    public function test_a_link_the_system_refuses_is_a_409_could_not_apply_with_the_reason_and_the_proposal_is_failed(): void
    {
        $org = $this->makeOrg(self::T, 'Greenfield Academy');
        $user = $this->editor();
        $key = "crm_organization:{$org['crm']}|g2g_company:77";
        $this->resolver->suggestions = [['key' => $key, 'basis' => 'name_similarity', 'score' => 0.9, 'place_conflict' => false, 'confirmable' => true,
            'left' => ['type' => 'crm_organization', 'id' => $org['crm'], 'name' => 'Greenfield Academy', 'place' => null], 'right' => ['type' => 'g2g_company', 'id' => 77, 'name' => 'Greenfield Pvt', 'place' => null]]];
        $this->model->respond = ['brief' => 'Same school.', 'brief_evidence' => [['type' => 'org_account', 'id' => (string) $org['identity']]],
            'proposals' => [['action' => 'confirm_link', 'pair_key' => $key, 'reason' => 'Same name', 'evidence' => [['type' => 'link_suggestion', 'id' => $key]]]]];
        $this->postJson(self::P . "/organisations/{$org['identity']}/run", [], $user['h'])->assertStatus(201);
        $proposal = DB::table('g2g_agent_proposals')->first();
        $this->linker->throws = new OrgLinkConflict(OrgLinkConflict::SAME_SOURCE, 'Both organisations already have a record from the same source, so they cannot be one.');

        $res = $this->postJson(self::P . "/proposals/{$proposal->id}/accept", [], $user['h'])->assertStatus(409);

        $res->assertJson(['status' => 0, 'code' => 'could_not_apply', 'message' => 'Both organisations already have a record from the same source, so they cannot be one.']);
        $this->assertSame('failed', $res->json('data.proposal.status'));
        $this->assertSame('confirm_link', $res->json('data.proposal.action'));
    }

    // ---- the history screen's data ----

    public function test_the_runs_list_has_the_summary_filters_and_pages_and_only_this_organisations_runs(): void
    {
        $s = $this->seeded();
        $this->model->unavailable = 'No model.';
        app(OrgAgentService::class)->run(self::T, $s['user']['id'], $s['org']['identity']);
        $this->model->unavailable = null;
        $this->model->throws = new \RuntimeException('down');
        app(OrgAgentService::class)->run(self::T, $s['user']['id'], $s['org']['identity']);
        $this->model->throws = null;
        $theirs = $this->makeOrg(self::T2, 'Their School', ['signals' => 1]);
        $this->model->respond = ['brief' => '', 'proposals' => []];
        app(OrgAgentService::class)->run(self::T2, $this->editor(self::T2)['id'], $theirs['identity']);
        $h = $s['user']['h'];

        $all = $this->getJson(self::P . '/runs', $h)->assertOk();

        $this->assertSame(3, $all->json('meta.total'), "the other organisation's run is not counted");
        $this->assertSame(['failed', 'unavailable', 'completed'], array_column($all->json('data'), 'status'), 'newest first');
        $this->assertKeys(self::RUN_SUMMARY_KEYS, $all->json('data.0'), 'a run in the list');
        $this->assertSame(['page' => 1, 'per_page' => 25, 'total' => 3, 'last_page' => 1], $all->json('meta'));
        $this->assertSame(['total' => 3, 'completed' => 1, 'failed' => 1, 'unavailable' => 1, 'running' => 0, 'proposals_pending' => 1, 'actions_applied' => 1], $all->json('summary'));
        $this->assertSame('Greenfield', $all->json('data.2.subject.name'));
        $this->assertSame(['pending' => 1, 'applied' => 1, 'dismissed' => 0, 'failed' => 0], $all->json('data.2.proposal_counts'));

        $this->assertSame(['unavailable'], array_column($this->getJson(self::P . '/runs?status=unavailable', $h)->assertOk()->json('data'), 'status'));
        $this->assertSame(3, $this->getJson(self::P . '/runs?date_from=' . now()->toDateString() . '&date_to=' . now()->toDateString(), $h)->json('meta.total'));
        $this->assertSame(0, $this->getJson(self::P . '/runs?date_to=' . now()->subDays(2)->toDateString(), $h)->json('meta.total'));
        $this->assertSame(0, $this->getJson(self::P . '/runs?date_from=' . now()->addDay()->toDateString(), $h)->json('meta.total'));

        $page = $this->getJson(self::P . '/runs?per_page=2&page=2', $h)->assertOk();
        $this->assertSame(['completed'], array_column($page->json('data'), 'status'));
        $this->assertSame(['page' => 2, 'per_page' => 2, 'total' => 3, 'last_page' => 2], $page->json('meta'));
        $this->assertSame(50, $this->getJson(self::P . '/runs?per_page=500', $h)->json('meta.per_page'), 'capped at 50');

        foreach (['status=bogus', 'date_from=yesterday', 'date_to=2026-13-40', 'page=0', 'per_page=0'] as $bad) {
            $this->getJson(self::P . "/runs?{$bad}", $h)->assertStatus(422);
        }
    }

    public function test_one_run_comes_with_its_brief_proposals_and_audit_trail(): void
    {
        $s = $this->seeded();

        $d = $this->getJson(self::P . "/runs/{$s['run']->id}", $s['user']['h'])->assertOk()->json('data');

        $this->assertKeys([...self::RUN_SUMMARY_KEYS, 'brief', 'brief_evidence', 'proposals', 'trace'], $d, 'a run');
        $this->assertSame($s['run']->id, $d['id']);
        $this->assertSame(['add_note', 'add_task'], array_column($d['proposals'], 'action'));
        $this->assertSame(['read_context', 'model_call', 'write_brief', 'add_note', 'add_task'], array_column($d['trace'], 'tool'));
        $this->assertSame(['seq', 'tool', 'outcome', 'deny_reason', 'proposal_id', 'result_ref', 'latency_ms'], array_keys($d['trace'][0]));
        $this->getJson(self::P . '/runs/999999', $s['user']['h'])->assertStatus(404);
    }

    // ---- resolving a CRM organisation to its identity ----

    public function test_a_crm_organisation_resolves_to_its_identity_and_another_organisations_does_not(): void
    {
        $mine = $this->makeOrg(self::T, 'Greenfield');
        $theirs = $this->makeOrg(self::T2, 'Their School');
        $nolink = $this->makeOrg(self::T, 'No CRM', ['crm' => false]);
        $h = $this->editor()['h'];

        $this->getJson(self::P . "/organisations/resolve?crm_organization_id={$mine['crm']}", $h)->assertOk()->assertJson(['status' => 1, 'data' => ['org_account_id' => $mine['identity']]]);
        $this->getJson(self::P . "/organisations/resolve?crm_organization_id={$theirs['crm']}", $h)->assertStatus(404);
        $this->getJson(self::P . '/organisations/resolve?crm_organization_id=999999', $h)->assertStatus(404);
        $this->getJson(self::P . '/organisations/resolve', $h)->assertStatus(422);
        $this->getJson(self::P . '/organisations/resolve?crm_organization_id=abc', $h)->assertStatus(422);
        $this->assertNotNull($nolink['identity']);
    }
}
