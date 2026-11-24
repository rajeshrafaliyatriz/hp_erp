<?php

namespace Tests\Feature\Intelligence;

use App\Domain\Intelligence\Access\IntelligenceCaller;
use App\Domain\Intelligence\DetectorRegistry;
use App\Http\Middleware\IntelligenceTokenAuthenticator;
use Carbon\CarbonImmutable;
use Tests\Intelligence\Fixtures\ApiTables;
use Tests\Intelligence\IntelligenceTestCase;

/** The real route stack and middleware, with the token lookup faked (the production lookup needs a token table and is exercised by the route sweep + a unit check). */
class IntelligenceApiTest extends IntelligenceTestCase
{
    /** @var array<string, object> */
    private array $tokens = [];

    /** @var list<IntelligenceCaller> */
    private array $seen = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPeopleTables();
        ApiTables::createProfileTable();
        \App\Support\RoleKey::flushCache();

        $this->db()->table('tbluserprofilemaster')->insert([
            ['id' => 1, 'sub_institute_id' => 1, 'name' => 'Admin', 'role_key' => 'administrator'],
            ['id' => 2, 'sub_institute_id' => 1, 'name' => 'Employee', 'role_key' => 'employee'],
            ['id' => 3, 'sub_institute_id' => 1, 'name' => 'Manager', 'role_key' => 'reporting_manager'],
            ['id' => 4, 'sub_institute_id' => 1, 'name' => 'Executive', 'role_key' => 'executive'],
        ]);

        $tokens = &$this->tokens;
        $this->app->instance(IntelligenceTokenAuthenticator::class, new class($tokens) implements IntelligenceTokenAuthenticator {
            public function __construct(private array &$tokens)
            {
            }

            public function find(string $plainToken): ?object
            {
                return $this->tokens[$plainToken] ?? null;
            }
        });
        $this->app->instance(\App\Domain\Intelligence\Access\RightsChecker::class, ApiTables::rights());

        $seen = &$this->seen;
        DetectorRegistry::overrideForTests(function () use (&$seen) {
            return [
                ApiTables::detector('hr.alpha', 'hr', [
                    ApiTables::insight('alpha-1', ['severity' => 'high', 'category' => 'attendance', 'surfaces' => ['attendance']]),
                    ApiTables::insight('alpha-2', ['severity' => 'info', 'category' => 'leave', 'surfaces' => ['leave']]),
                ], null, function (IntelligenceCaller $c) use (&$seen) {
                    $seen[] = $c;
                }),
                ApiTables::detector('commercial.beta', 'commercial', [
                    ApiTables::insight('beta-1', ['domain' => 'commercial', 'severity' => 'critical', 'category' => 'deal', 'surfaces' => ['gtm_deals']]),
                ], fn (IntelligenceCaller $c) => $c->hasRole('administrator')),
            ];
        });
        config()->set('intelligence.enabled', true);
    }

    protected function tearDown(): void
    {
        DetectorRegistry::overrideForTests(null);
        parent::tearDown();
    }

    private function user(array $attributes = []): object
    {
        return (object) array_merge(['id' => 10, 'sub_institute_id' => 1, 'user_profile_id' => 1, 'department_id' => 3, 'status' => 1, 'terminated_date' => null, 'deleted_at' => null], $attributes);
    }

    private function token(string $plain, ?object $user, ?CarbonImmutable $expires = null): array
    {
        $this->tokens[$plain] = (object) ['tokenable' => $user, 'expires_at' => $expires];

        return ['Authorization' => 'Bearer ' . $plain];
    }

    public function test_no_token_is_401_and_a_query_string_token_is_not_accepted(): void
    {
        $this->token('good', $this->user());

        $this->getJson('/api/intelligence/overview')->assertStatus(401)->assertJson(['status' => 0]);
        $this->getJson('/api/intelligence/overview?token=good')->assertStatus(401);
    }

    public function test_unknown_token_is_401(): void
    {
        $this->getJson('/api/intelligence/insights', ['Authorization' => 'Bearer nothing'])->assertStatus(401)->assertJson(['message' => 'Invalid token']);
    }

    public function test_token_without_an_owner_is_401(): void
    {
        $headers = $this->token('orphan', null);

        $this->getJson('/api/intelligence/insights', $headers)->assertStatus(401);
    }

    public function test_expired_token_is_401(): void
    {
        $headers = $this->token('old', $this->user(), CarbonImmutable::now()->subMinute());

        $this->getJson('/api/intelligence/insights', $headers)->assertStatus(401)->assertJson(['message' => 'Token expired']);
    }

    public function test_tenantless_user_is_403_and_a_request_supplied_tenant_does_not_rescue_it(): void
    {
        foreach ([null, 0] as $tenant) {
            $headers = $this->token('t' . (int) $tenant, $this->user(['sub_institute_id' => $tenant]));

            $this->getJson('/api/intelligence/insights', $headers)->assertStatus(403)->assertJson(['status' => 0]);
            $this->getJson('/api/intelligence/insights?sub_institute_id=1', $headers + ['sub_institute_id' => '1'])->assertStatus(403);
            $this->getJson('/api/intelligence/overview?sub_institute_id=1&tenant=1&user_id=10', $headers)->assertStatus(403);
        }
        $this->assertSame([], $this->seen, 'no detector ran for a tenant-less caller');
    }

    public function test_inactive_terminated_or_deleted_users_are_refused(): void
    {
        foreach ([['status' => 0], ['status' => null], ['terminated_date' => '2026-01-01'], ['deleted_at' => '2026-01-01 00:00:00']] as $i => $override) {
            $headers = $this->token('x' . $i, $this->user($override));

            $this->getJson('/api/intelligence/insights', $headers)->assertStatus(403);
        }
    }

    public function test_request_supplied_tenant_user_role_and_scope_are_ignored_for_a_normal_user(): void
    {
        $headers = $this->token('emp', $this->user(['id' => 20, 'user_profile_id' => 2, 'department_id' => 9]));

        $this->getJson('/api/intelligence/insights?sub_institute_id=99&tenant_id=99&user_id=1&role=administrator&role_key=administrator&scope=organization&profile_id=1', $headers + ['sub_institute_id' => '99', 'user_id' => '1', 'X-Role' => 'administrator'])
            ->assertOk()
            ->assertJsonPath('data.meta.scope', 'self');

        $this->assertCount(1, $this->seen);
        $caller = $this->seen[0];
        $this->assertSame(1, $caller->tenantId);
        $this->assertSame(20, $caller->userId);
        $this->assertSame('employee', $caller->roleKey);
        $this->assertSame(9, $caller->departmentId);
        $this->assertSame('self', $caller->scope());

        // CONTRACT CHANGE (access_restricted): the administrator-only detector is no longer omitted for the employee; it is listed as a bare access_restricted entry
        // (identity and a fixed reason only, no counts, no coverage) even though the request claimed to be an administrator.
        $response = $this->getJson('/api/intelligence/detectors', $headers)->assertOk();
        $rows = $response->json('data.detectors');
        $this->assertSame(['hr.alpha', 'commercial.beta'], array_column($rows, 'detector'));
        $this->assertSame(['ok', 'access_restricted'], array_column($rows, 'state'));
        $this->assertNull($rows[1]['insight_count']);
        $this->assertNull($rows[1]['coverage']);
        $this->assertSame(1, $response->json('data.meta.states.access_restricted'));
        $this->assertSame(1, $response->json('data.meta.detectors_run'));
    }

    public function test_insights_envelope_and_shape(): void
    {
        $headers = $this->token('adm', $this->user());

        $json = $this->getJson('/api/intelligence/insights', $headers)->assertOk()->json();

        $this->assertSame(1, $json['status']);
        $this->assertSame(['insights', 'pagination', 'meta'], array_keys($json['data']));
        $this->assertSame(['page', 'per_page', 'total', 'total_pages'], array_keys($json['data']['pagination']));
        $this->assertSame(3, $json['data']['pagination']['total']);
        $this->assertSame(['beta-1', 'alpha-1', 'alpha-2'], array_column($json['data']['insights'], 'key'));
        $expected = ['key', 'detector', 'domain', 'category', 'severity', 'title', 'what_happened', 'why_it_matters', 'evidence', 'coverage', 'as_of', 'suggested_action', 'deep_link', 'confidence', 'audience', 'surfaces', 'status', 'provisional'];
        $this->assertSame($expected, array_keys($json['data']['insights'][0]));
        $this->assertArrayNotHasKey('population', $json['data']['insights'][0]);
        $this->assertSame(['generated_at', 'scope', 'provisional', 'thresholds_note', 'suppressed_count', 'suppression_note', 'detectors_run', 'states', 'filters'], array_keys($json['data']['meta']));
        $this->assertSame('organization', $json['data']['meta']['scope']);
    }

    public function test_overview_shape(): void
    {
        $headers = $this->token('adm', $this->user());

        $data = $this->getJson('/api/intelligence/overview', $headers)->assertOk()->json('data');

        $this->assertSame(['summary', 'top', 'meta'], array_keys($data));
        $this->assertSame(3, $data['summary']['total']);
        $this->assertSame(['critical' => 1, 'high' => 1, 'attention' => 0, 'info' => 1], $data['summary']['by_severity']);
        $this->assertSame(['hr' => 2, 'commercial' => 1, 'platform' => 0], $data['summary']['by_domain']);
        $this->assertCount(3, $data['top']);
    }

    public function test_detectors_endpoint_exposes_state_and_coverage_for_authorised_detectors_only(): void
    {
        $headers = $this->token('adm', $this->user());

        $detectors = $this->getJson('/api/intelligence/detectors', $headers)->assertOk()->json('data.detectors');

        $this->assertSame(['hr.alpha', 'commercial.beta'], array_column($detectors, 'detector'));
        $this->assertSame(['detector', 'domain', 'title', 'state', 'insight_count', 'coverage', 'as_of', 'reason'], array_keys($detectors[0]));
        $this->assertSame('ok', $detectors[0]['state']);
        $this->assertSame(['observed', 'expected', 'percent', 'basis', 'sufficient', 'reason'], array_keys($detectors[0]['coverage']));
    }

    public function test_filters_and_pagination_through_the_api(): void
    {
        $headers = $this->token('adm', $this->user());

        $this->assertSame(['beta-1'], array_column($this->getJson('/api/intelligence/insights?domain=commercial', $headers)->json('data.insights'), 'key'));
        $this->assertSame(['beta-1', 'alpha-1'], array_column($this->getJson('/api/intelligence/insights?severity=high', $headers)->json('data.insights'), 'key'));
        $this->assertSame(['alpha-2'], array_column($this->getJson('/api/intelligence/insights?surface=leave', $headers)->json('data.insights'), 'key'));
        $this->assertSame(['alpha-1'], array_column($this->getJson('/api/intelligence/insights?category=attendance', $headers)->json('data.insights'), 'key'));
        $this->assertSame(['alpha-1'], array_column($this->getJson('/api/intelligence/insights?page=2&per_page=1', $headers)->json('data.insights'), 'key'));
        $this->assertSame(50, $this->getJson('/api/intelligence/insights?per_page=9999', $headers)->json('data.pagination.per_page'));
    }

    public function test_invalid_query_values_are_422(): void
    {
        $headers = $this->token('adm', $this->user());

        foreach (['domain=payroll', 'severity=apocalyptic', 'surface=nowhere', 'page=0', 'per_page=0', 'page=abc', 'detector=' . urlencode('x; DROP TABLE tbluser'), 'category=' . str_repeat('x', 65)] as $query) {
            $this->getJson('/api/intelligence/insights?' . $query, $headers)->assertStatus(422)->assertJsonStructure(['status', 'message', 'errors']);
        }
    }

    public function test_the_disabled_flag_answers_503_and_runs_nothing(): void
    {
        $headers = $this->token('adm', $this->user());
        config()->set('intelligence.enabled', false);

        foreach (['overview', 'insights', 'detectors'] as $endpoint) {
            $this->getJson('/api/intelligence/' . $endpoint, $headers)->assertStatus(503)->assertJson(['status' => 0]);
        }
        $this->assertSame([], $this->seen);
    }

    public function test_only_get_is_allowed(): void
    {
        $headers = $this->token('adm', $this->user());

        foreach (['postJson', 'putJson', 'patchJson', 'deleteJson'] as $verb) {
            $this->{$verb}('/api/intelligence/insights', [], $headers)->assertStatus(405);
        }
    }

    public function test_a_small_group_caller_gets_no_suppressed_count_and_hidden_coverage_over_http(): void
    {
        DetectorRegistry::overrideForTests(fn () => [
            ApiTables::detector("hr.pop", "hr", [ApiTables::insight("small", ["population" => 3]), ApiTables::insight("big", ["population" => 9])], null, null, new \App\Domain\Intelligence\Coverage(3, 4, "people", true, null)),
        ]);
        $manager = $this->token("mgr", $this->user(["id" => 30, "user_profile_id" => 3]));
        $admin = $this->token("adm", $this->user());

        $m = $this->getJson("/api/intelligence/insights", $manager)->assertOk()->json("data.meta");
        $this->assertNull($m["suppressed_count"]);
        $this->assertSame("Some results are withheld to protect small groups.", $m["suppression_note"]);
        $cov = $this->getJson("/api/intelligence/detectors", $manager)->assertOk()->json("data.detectors.0.coverage");
        $this->assertNull($cov["observed"]);
        $this->assertNull($cov["expected"]);
        $this->assertNull($cov["percent"]);

        $a = $this->getJson("/api/intelligence/insights", $admin)->assertOk()->json("data.meta");
        $this->assertSame(0, $a["suppressed_count"]);
        $this->assertNull($a["suppression_note"]);
        $this->assertSame(3, $this->getJson("/api/intelligence/detectors", $admin)->json("data.detectors.0.coverage.observed"));
    }

    public function test_insight_coverage_is_masked_over_http_for_small_group_callers_and_exact_for_others(): void
    {
        DetectorRegistry::overrideForTests(fn () => [
            ApiTables::detector('hr.cov', 'hr', [
                ApiTables::insight('tiny', ['severity' => 'high', 'population' => 9, 'coverage' => new \App\Domain\Intelligence\Coverage(3, 40, 'people with attendance', true, null)]),
                ApiTables::insight('thin', ['severity' => 'attention', 'population' => 9, 'coverage' => \App\Domain\Intelligence\Coverage::of(2, 10, 'people with attendance', 5)]),
                ApiTables::insight('big', ['severity' => 'info', 'population' => 9, 'coverage' => new \App\Domain\Intelligence\Coverage(6, 8, 'people with attendance', true, null)]),
            ]),
        ]);
        $generic = 'Not enough people to show without identifying individuals.';
        $endpoints = ['/api/intelligence/insights' => 'data.insights', '/api/intelligence/overview' => 'data.top'];

        foreach ([['mgr', ['id' => 30, 'user_profile_id' => 3]], ['exec', ['id' => 31, 'user_profile_id' => 4]]] as [$plain, $attrs]) {
            $headers = $this->token($plain, $this->user($attrs));

            foreach ($endpoints as $url => $path) {
                $byKey = array_column($this->getJson($url, $headers)->assertOk()->json($path), null, 'key');

                foreach (['tiny', 'thin'] as $key) {
                    $c = $byKey[$key]['coverage'];
                    $this->assertNull($c['observed'], "$plain $url $key");
                    $this->assertNull($c['expected'], "$plain $url $key");
                    $this->assertNull($c['percent'], "$plain $url $key");
                    $this->assertSame($generic, $c['reason'], "$plain $url $key");
                    $this->assertSame(['observed', 'expected', 'percent', 'basis', 'sufficient', 'reason'], array_keys($c));
                }
                $this->assertSame(6, $byKey['big']['coverage']['observed'], "$plain $url");
                $this->assertSame(8, $byKey['big']['coverage']['expected'], "$plain $url");
            }
        }

        $admin = $this->token('adm', $this->user());
        foreach ($endpoints as $url => $path) {
            $byKey = array_column($this->getJson($url, $admin)->assertOk()->json($path), null, 'key');

            $this->assertSame(3, $byKey['tiny']['coverage']['observed'], $url);
            $this->assertSame(40, $byKey['tiny']['coverage']['expected'], $url);
            $this->assertStringContainsString('Only 2', (string) $byKey['thin']['coverage']['reason'], $url);
        }
    }

    public function test_one_request_runs_each_detector_once(): void
    {
        $calls = 0;
        DetectorRegistry::overrideForTests(function () use (&$calls) {
            return [ApiTables::detector("hr.once", "hr", [ApiTables::insight("a")], null, function () use (&$calls) {
                $calls++;
            })];
        });
        $headers = $this->token("adm", $this->user());

        foreach (["overview", "insights", "detectors"] as $endpoint) {
            $calls = 0;
            $this->getJson("/api/intelligence/" . $endpoint, $headers)->assertOk();
            $this->assertSame(1, $calls, $endpoint . " must run the detectors exactly once per request");
        }
    }

    public function test_invalid_token_traffic_is_throttled_before_authentication(): void
    {
        $statuses = [];
        for ($i = 0; $i < 33; $i++) {
            $statuses[] = $this->getJson("/api/intelligence/insights", ["Authorization" => "Bearer garbage-" . $i])->getStatusCode();
        }

        $this->assertSame(401, $statuses[0]);
        $this->assertContains(429, $statuses, "30 requests a minute, counted before the token is checked");
        $this->assertSame(30, count(array_filter($statuses, fn ($s) => $s === 401)));
    }}
