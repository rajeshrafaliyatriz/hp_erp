<?php

namespace Tests\Feature;

use App\Domain\AgentGateway\AgentAccess;
use App\Domain\AgentGateway\OrgAgentService;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsAgentWorld;
use Tests\TestCase;

/**
 * The cross-tenant sweep of the agent API (slice 7, thin). It does not name the routes it tests: it READS them from the router, so a route added to
 * routes/agent_gateway.php later is swept automatically, and one that forgets the tenant filter, the rights or whereNumber fails here without anybody
 * remembering to extend the test (the same design as OrgAccountsTenantSweepTest).
 *
 *   - every route with an id is called as organisation A with organisation B's identity, proposal and run, and must answer 404, the same body as for an id
 *     that never existed, change nothing of B's, and never reach the model;
 *   - every route has the token check and the right of the screen it belongs to, a write needs more than view, and every id is numeric;
 *   - a route WITHOUT an id cannot be swept this way, so each is named below: adding one fails the test until it has been looked at;
 *   - the runs list never shows B's runs, whatever the caller puts in the request.
 */
class AgentGatewayTenantSweepTest extends TestCase
{
    use BuildsAgentWorld;

    private const PREFIX = 'api/agent';

    /**
     * The routes with no id, each checked by name below or in AgentGatewayApiTest (the runs list and its filters; resolving a CRM organisation to its
     * identity) or in AgentAutonomyApiTest (the waiting queue's tenant filter, the autonomy settings: they read and write the CALLER's organisation only, which
     * `test_the_autonomy_routes_cannot_be_aimed_at_another_organisation` below proves).
     */
    private const NO_ID_ROUTES = [
        'GET api/agent/organisations/resolve', 'GET api/agent/runs', 'GET api/agent/proposals', 'GET api/agent/autonomy', 'PUT api/agent/autonomy-kill-switch',
    ];

    /** The routes whose parameter is a KEY from config/agent_actions.php and not an id: the controller answers a key that is not an action with 404. */
    private const KEY_ROUTES = ['PUT api/agent/autonomy/{actionKey}'];

    /** @var array{id: int, h: array<string, string>} organisation A's caller: every right the agent API asks for, so a 404 can only come from the tenant check */
    private array $attacker;

    /** @var array{identity: int, crm: ?int, company: ?int, gtm: ?int, signals: list<int>} */
    private array $victimOrg;

    private int $victimRun;

    private int $victimProposal;

    private int $ownDeletedIdentity;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAgentWorld();

        $this->attacker = $this->editor(self::T);
        // Organisation B has an organisation that was run on, with one proposal waiting for a person.
        $this->victimOrg = $this->makeOrg(self::T2, 'Victim School', ['signals' => 1]);
        DB::table('g2g_agent_autonomy')->insert(['sub_institute_id' => self::T2, 'action_key' => 'add_note', 'level' => 'approval']);
        $signal = [['type' => 'signal', 'id' => (string) $this->victimOrg['signals'][0]]];
        $this->model->respond = ['brief' => 'Victim brief.', 'brief_evidence' => $signal, 'proposals' => [['action' => 'add_note', 'text' => 'Victim note', 'reason' => 'r', 'evidence' => $signal]]];
        $run = app(OrgAgentService::class)->run(self::T2, $this->editor(self::T2)['id'], $this->victimOrg['identity']);
        $this->victimRun = (int) $run->id;
        $this->victimProposal = (int) DB::table('g2g_agent_proposals')->where('sub_institute_id', self::T2)->value('id');
        $this->model->calls = [];

        // Organisation A's own deleted identity: a deleted row is no more reachable than somebody else's.
        $this->ownDeletedIdentity = $this->makeOrg(self::T, 'Own Deleted School')['identity'];
        DB::table('g2g_org_accounts')->where('id', $this->ownDeletedIdentity)->update(['deleted_at' => now()]);
        $this->makeOrg(self::T, 'Own School');
    }

    /** @return list<Route> */
    private function agentRoutes(): array
    {
        return array_values(array_filter(app('router')->getRoutes()->getRoutes(), fn (Route $r) => $r->uri() === self::PREFIX || str_starts_with($r->uri(), self::PREFIX . '/')));
    }

    /** @return list<string> the methods a route answers, without the HEAD that Laravel adds to every GET */
    private function methods(Route $route): array
    {
        return array_values(array_diff($route->methods(), ['HEAD', 'OPTIONS']));
    }

    /** @return array<string, mixed> everything of organisation B's that a request could change */
    private function victimFootprint(): array
    {
        $rows = fn (string $table) => DB::table($table)->where('sub_institute_id', self::T2)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        return [
            'runs' => $rows('g2g_agent_runs'), 'proposals' => $rows('g2g_agent_proposals'), 'calls' => $rows('g2g_agent_tool_calls'), 'comments' => $rows('crm_organization_comments'),
            'tasks' => $rows('task_management_calendar_events'), 'identities' => $rows('g2g_org_accounts'), 'events' => DB::table('g2g_event')->where('sub_institute_id', self::T2)->count(),
        ];
    }

    private function isKeyRoute(Route $route): bool
    {
        return array_intersect(array_map(fn (string $m) => "{$m} {$route->uri()}", $this->methods($route)), self::KEY_ROUTES) !== [];
    }

    /** The id placeholder of a route, whichever name it has: `{orgAccountId}` on the organisation routes, `{id}` on the others. */
    private function idParameter(Route $route): ?string
    {
        return $route->parameterNames()[0] ?? null;
    }

    /** What B's id stands for on this route: an identity, a proposal or a run. */
    private function victimIdFor(Route $route): int
    {
        return match (true) {
            str_contains($route->uri(), '/organisations/') => $this->victimOrg['identity'],
            str_contains($route->uri(), '/proposals/') => $this->victimProposal,
            str_contains($route->uri(), '/runs/') => $this->victimRun,
            default => $this->fail("the sweep does not know what id route {$route->uri()} takes: say so in victimIdFor()"),
        };
    }

    public function test_every_id_route_answers_another_organisations_record_with_a_404_that_reveals_nothing_changes_nothing_and_never_reaches_the_model(): void
    {
        $before = $this->victimFootprint();
        $body = ['reason' => 'Hijack', 'sub_institute_id' => self::T2, 'user_id' => $this->attacker['id']];

        $swept = [];
        foreach ($this->agentRoutes() as $route) {
            $param = $this->idParameter($route);
            // A key route has no id to aim at another organisation's record: its own test follows. Everything else that has a parameter is an id.
            if ($param === null || ! str_contains($route->uri(), '{') || $this->isKeyRoute($route)) {
                continue;
            }
            foreach ($this->methods($route) as $method) {
                $url = fn (int $id) => '/' . str_replace('{' . $param . '}', (string) $id, $route->uri());
                $none = $this->json($method, $url(999999), $body, $this->attacker['h'])->assertStatus(404);
                $ids = ['B\'s own id' => $this->victimIdFor($route)];
                if (str_contains($route->uri(), '/organisations/')) {
                    $ids['the caller\'s own deleted identity'] = $this->ownDeletedIdentity;
                }
                foreach ($ids as $what => $id) {
                    $res = $this->json($method, $url($id), $body, $this->attacker['h']);
                    $this->assertSame(404, $res->getStatusCode(), "{$method} {$route->uri()} with {$what}");
                    $this->assertSame($none->json(), $res->json(), "{$method} {$route->uri()}: the answer is the one for an id that never existed");
                }
                $swept[] = "{$method} {$route->uri()}";
            }
        }

        $this->assertEqualsCanonicalizing([
            'GET api/agent/organisations/{orgAccountId}', 'POST api/agent/organisations/{orgAccountId}/run', 'POST api/agent/proposals/{id}/accept', 'POST api/agent/proposals/{id}/dismiss',
            'GET api/agent/runs/{id}',
        ], $swept, 'the sweep found the id routes (a new one is swept automatically, and this list is then updated on purpose)');
        $this->assertSame($before, $this->victimFootprint(), "nothing of organisation B's changed");
        $this->assertSame([], $this->model->calls, 'the model was never asked about another organisation');
    }

    public function test_the_caller_cannot_choose_the_organisation_with_a_parameter_or_the_body(): void
    {
        $before = $this->victimFootprint();
        $h = $this->attacker['h'];
        $inBody = ['sub_institute_id' => self::T2];

        $this->json('GET', "/api/agent/organisations/{$this->victimOrg['identity']}?sub_institute_id=" . self::T2, [], $h)->assertStatus(404);
        $this->json('POST', "/api/agent/organisations/{$this->victimOrg['identity']}/run?sub_institute_id=" . self::T2, $inBody, $h)->assertStatus(404);
        $this->json('POST', "/api/agent/proposals/{$this->victimProposal}/accept", $inBody, $h)->assertStatus(404);
        $this->json('POST', "/api/agent/proposals/{$this->victimProposal}/dismiss", $inBody + ['reason' => 'x'], $h)->assertStatus(404);
        $this->assertSame($before, $this->victimFootprint());
        $this->assertSame([], $this->model->calls);
    }

    public function test_the_runs_list_never_shows_another_organisations_runs_whatever_the_request_says(): void
    {
        $h = $this->attacker['h'];
        $this->model->respond = ['brief' => '', 'proposals' => []];
        $mine = $this->makeOrg(self::T, 'Own School 2');
        $own = (int) app(OrgAgentService::class)->run(self::T, $this->attacker['id'], $mine['identity'])->id;

        $queries = ['', '?sub_institute_id=' . self::T2, '?status=completed', '?per_page=50', '?date_from=2000-01-01&date_to=2999-12-31', '?page=1&per_page=1'];
        foreach ($queries as $query) {
            $res = $this->json('GET', "/api/agent/runs{$query}", ['sub_institute_id' => self::T2], $h)->assertOk();
            $this->assertNotContains($this->victimRun, array_column($res->json('data'), 'id'), "runs{$query}: B's run is not listed");
            $this->assertNotContains('Victim School', array_column(array_column($res->json('data'), 'subject'), 'name'), "runs{$query}: nor B's organisation's name");
            $this->assertSame(1, $res->json('meta.total'), "runs{$query}: only A's own run is counted");
        }
        $this->assertSame([$own], array_column($this->getJson('/api/agent/runs', $h)->json('data'), 'id'));
        $this->assertSame(['total' => 1, 'completed' => 1, 'failed' => 0, 'unavailable' => 0, 'running' => 0, 'proposals_pending' => 0, 'actions_applied' => 0], $this->getJson('/api/agent/runs', $h)->json('summary'),
            "the summary counts A's runs and proposals only (B has a waiting proposal that must not appear)");
    }

    /**
     * Ids are unique across organisations, so rows of one organisation never point at another's in normal use. This is the worst case, a pointer that was ever
     * mis-set (a bad import, a hand edit): every lookup the API makes carries the caller's organisation, so nothing of the other organisation shows through it.
     */
    public function test_rows_of_another_organisation_that_point_into_this_ones_ids_show_nothing_of_themselves(): void
    {
        $h = $this->attacker['h'];
        $mine = $this->makeOrg(self::T, 'Mine School', ['signals' => 1]);
        DB::table('g2g_agent_autonomy')->insert(['sub_institute_id' => self::T, 'action_key' => 'add_note', 'level' => 'approval']);
        $signal = [['type' => 'signal', 'id' => (string) $mine['signals'][0]]];
        $this->model->respond = ['brief' => 'Mine.', 'brief_evidence' => $signal, 'proposals' => [['action' => 'add_note', 'text' => 'My note.', 'reason' => 'r', 'evidence' => $signal]]];
        $run = app(OrgAgentService::class)->run(self::T, $this->attacker['id'], $mine['identity']);

        // 1. a decision whose decider is a person of the OTHER organisation
        $stranger = $this->user([], self::T2, 'Mallory', 'Villain');
        DB::table('g2g_agent_proposals')->where('run_id', $run->id)->update(['status' => 'applied', 'applied_via' => 'user', 'decided_by' => $stranger['id'], 'decided_at' => now()]);
        // 2. a run of this organisation whose subject is the other organisation's identity
        $stray = (int) DB::table('g2g_agent_runs')->insertGetId([
            'sub_institute_id' => self::T, 'agent_key' => 'organisation', 'subject_type' => 'org_account', 'subject_id' => $this->victimOrg['identity'], 'trigger_source' => 'manual',
            'status' => 'completed', 'started_at' => now()->subMinute(), 'tool_calls' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        // 3 and 4. the other organisation's waiting proposal, re-pointed at this organisation's run and identity
        DB::table('g2g_agent_proposals')->where('id', $this->victimProposal)->update(['run_id' => $run->id, 'subject_id' => $mine['identity']]);

        $state = $this->getJson("/api/agent/organisations/{$mine['identity']}", $h)->assertOk()->json('data');
        $list = $this->getJson('/api/agent/runs', $h)->assertOk();
        $ours = collect($list->json('data'))->firstWhere('id', $run->id);
        $strayRow = collect($list->json('data'))->firstWhere('id', $stray);

        $this->assertSame([], $state['pending'], "the other organisation's waiting proposal is not offered to this one");
        $this->assertSame(1, count($state['recent']));
        $this->assertNull($state['recent'][0]['decided_by_name'], "a person of the other organisation is not named, whatever the pointer says");
        $this->assertSame(['pending' => 0, 'applied' => 1, 'dismissed' => 0, 'failed' => 0], $ours['proposal_counts'], "the other organisation's proposal is not counted on this run");
        $this->assertNull($strayRow['subject']['name'], "the other organisation's name is not shown for a run that points at it");
        $everything = json_encode($state) . json_encode($list->json());
        $this->assertStringNotContainsString('Mallory', $everything, 'no trace of the stranger anywhere in either answer');
        $this->assertStringNotContainsString('Victim School', $everything, 'nor of the other organisation');
    }

    public function test_resolving_another_organisations_crm_organisation_is_a_404(): void
    {
        $this->getJson("/api/agent/organisations/resolve?crm_organization_id={$this->victimOrg['crm']}", $this->attacker['h'])->assertStatus(404);
        $this->assertSame([], $this->model->calls);
    }

    public function test_every_route_carries_the_token_check_and_the_right_of_its_screen_and_every_id_is_numeric(): void
    {
        $routes = $this->agentRoutes();
        $this->assertGreaterThanOrEqual(11, count($routes));

        foreach ($routes as $route) {
            $label = implode('|', $this->methods($route)) . ' ' . $route->uri();
            $middleware = $route->gatherMiddleware();
            $this->assertContains('api.token', $middleware, "{$label} needs a token");

            // The organisation routes are gated by the Organizations rows (either, as the CRM's own routes accept), the history routes by Agent Run History:
            // the runs, and the two tabs of that screen (the waiting queue's LIST and the autonomy settings). A DECISION on a queued proposal is
            // /proposals/{id}/accept|dismiss, which stays on the Organizations right.
            $isHistory = str_starts_with($route->uri(), self::PREFIX . '/runs') || str_starts_with($route->uri(), self::PREFIX . '/autonomy') || $route->uri() === self::PREFIX . '/proposals';
            $link = $isHistory ? AgentAccess::RUN_HISTORY : AgentAccess::ORG_RIGHT;
            $right = collect($middleware)->first(fn ($m) => is_string($m) && str_starts_with($m, 'platformright:'));
            $this->assertNotNull($right, "{$label} needs a menu right");
            $this->assertStringStartsWith("platformright:{$link},", (string) $right, "{$label}: the right of the wrong screen");
            $action = substr((string) $right, strlen("platformright:{$link},"));
            $this->assertContains($action, ['view', 'add', 'edit', 'delete'], $label);
            if ($this->methods($route) !== ['GET']) {
                $this->assertSame('edit', $action, "{$label} changes things, so it needs the edit right and not only view");
            } else {
                $this->assertSame('view', $action, "{$label} only reads");
            }
            foreach ($route->parameterNames() as $name) {
                if ($this->isKeyRoute($route)) {
                    $this->assertSame('actionKey', $name, "{$label}: the only non-id parameter is the action key");

                    continue;
                }
                $this->assertSame('[0-9]+', $route->wheres[$name] ?? null, "{$label}: {{$name}} must be whereNumber");
            }
        }
    }

    public function test_the_routes_whose_parameter_is_a_key_and_not_an_id_are_named_so_a_new_one_is_noticed(): void
    {
        $found = [];
        foreach ($this->agentRoutes() as $route) {
            foreach ($route->parameterNames() as $name) {
                if (($route->wheres[$name] ?? null) !== '[0-9]+') {
                    foreach ($this->methods($route) as $method) {
                        $found[] = "{$method} {$route->uri()}";
                    }
                }
            }
        }

        $this->assertEqualsCanonicalizing(self::KEY_ROUTES, $found, 'a parameter that is not whereNumber is an action key: it must be named here and answered with 404 by the controller when it is not an action');
    }

    /**
     * The autonomy settings have no id of another organisation to aim at: they read and write the CALLER's organisation, taken from the token. So the proof is
     * that a request which names another organisation (in the body, the query or a header) changes only the caller's own rows and leaves the other's, and its
     * trail, exactly as they were.
     */
    public function test_the_autonomy_routes_cannot_be_aimed_at_another_organisation(): void
    {
        $admin = $this->administrator(self::T);
        DB::table('g2g_agent_autonomy')->insert([
            ['sub_institute_id' => self::T2, 'action_key' => '*', 'level' => 'off', 'updated_by' => null, 'updated_at' => null],
            ['sub_institute_id' => 0, 'action_key' => 'add_task', 'level' => 'approval', 'updated_by' => null, 'updated_at' => null],
        ]);
        $theirs = fn () => [
            DB::table('g2g_agent_autonomy')->where('sub_institute_id', '!=', self::T)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            DB::table('g2g_agent_tool_calls')->where('sub_institute_id', self::T2)->count(), DB::table('g2g_event')->where('sub_institute_id', self::T2)->where('type', 'agent.autonomy.changed')->count(),
        ];
        $before = $theirs();
        $aim = ['sub_institute_id' => self::T2, 'tenant' => self::T2, 'user_id' => $this->attacker['id']];
        $h = $admin['h'] + ['sub_institute_id' => (string) self::T2];

        $this->json('PUT', '/api/agent/autonomy/add_note?sub_institute_id=' . self::T2, $aim + ['level' => 'off'], $h)->assertOk();
        $this->json('PUT', '/api/agent/autonomy/add_task?sub_institute_id=' . self::T2, $aim + ['level' => 'alone'], $h)->assertOk();
        $this->json('PUT', '/api/agent/autonomy-kill-switch?sub_institute_id=' . self::T2, $aim + ['on' => false], $h)->assertOk();
        $this->json('PUT', '/api/agent/autonomy-kill-switch?sub_institute_id=' . self::T2, $aim + ['on' => true], $h)->assertOk();

        $this->assertSame($before, $theirs(), "organisation B's settings (its own switch, the shared row) and its trail are as they were");
        $mine = DB::table('g2g_agent_autonomy')->where('sub_institute_id', self::T)->orderBy('action_key')->pluck('level', 'action_key')->all();
        $this->assertSame(['*' => 'off', 'add_note' => 'off', 'add_task' => 'alone'], $mine, "the rows went to the caller's own organisation");

        // and the two READS answer for the caller's organisation whatever organisation the request names
        $state = $this->json('GET', '/api/agent/autonomy?sub_institute_id=' . self::T2, $aim, $h)->assertOk()->json('data');
        $this->assertSame(['on' => true, 'scope' => 'organisation'], $state['kill_switch'], "A's switch, not B's");
        $this->assertSame('off', collect($state['actions'])->firstWhere('key', 'add_note')['level'], "A's level (B's is approval)");
        $queue = $this->json('GET', '/api/agent/proposals?status=pending&sub_institute_id=' . self::T2, $aim, $h)->assertOk()->json('data');
        $this->assertSame([], $queue['rows'], "B has a proposal waiting; A has none, and A is asking");
        $this->assertSame(0, $queue['pagination']['total']);
    }

    public function test_a_route_without_an_id_is_named_in_this_test_so_a_new_one_is_noticed(): void
    {
        $found = [];
        foreach ($this->agentRoutes() as $route) {
            if (! str_contains($route->uri(), '{')) {
                foreach ($this->methods($route) as $method) {
                    $found[] = "{$method} {$route->uri()}";
                }
            }
        }

        $this->assertEqualsCanonicalizing(self::NO_ID_ROUTES, $found, 'a route with no id cannot be swept by id: check its tenant filtering by hand, then name it in NO_ID_ROUTES');
    }
}
