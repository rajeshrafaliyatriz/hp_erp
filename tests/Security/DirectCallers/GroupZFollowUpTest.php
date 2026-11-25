<?php

namespace Tests\Security\DirectCallers;

use App\Domain\Intelligence\DetectorRegistry;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\front_desk\TaskUpdateController;
use App\Http\Middleware\authMiddleware;
use App\Http\Middleware\RequireApiToken;
use App\Http\Middleware\RequireProfile;
use App\Models\auth\tbluserModel;
use App\Support\PersonalAccessTokenResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A-1c Group Z follow-up. Three gaps found by the independent security review after the first Group Z change:
 *
 *  (1) talent_interviewpanelController store (storeinterviewer, ~l.141) and destroy (~l.257): behind `profile:admin,hr,recruiter`, but that gate reads the BEARER
 *      first while the action reads `input('token')`. "Two tokens in one request": Authorization: Bearer <a legitimate HR token> (the role gate passes) plus
 *      token=<a surviving admin-bound 'Session Token' row> made the action's plain lookup accept the fabricated row and take `user_id = tokenable_id` of the admin.
 *      The other three lookups in that controller (l.61, 95, 196) are deliberately NOT part of this change.
 *  (2) front_desk/TaskUpdateController::updateStatusAndDescription (~l.31). WHAT IS REACHABLE: the real route (lms.php, PUT/POST /lms/task/update-status/{id})
 *      calls `$request->Token()`, a method that does not exist on Illuminate\Http\Request and has no macro in this application, so today the real route throws
 *      before it reaches the lookup. The lookup is therefore exercised by calling the action with a Request subclass that defines Token(), exactly as it would
 *      behave if Token() ever existed. The first test pins the "dead route" fact so the day it is fixed this file says so.
 *  (3) SanctumIntelligenceTokenAuthenticator (routes/intelligence.php): refuses a row named 'Session Token' in any case/padding.
 *
 * Finally one generic test: with TWO different tokens in one request, authMiddleware (reads input('token') first) and RequireApiToken / the identity trait (read the
 * bearer first) disagree about WHICH token to read; the property proved is that every layer, whichever token it reads, yields only a strict identity and the
 * administrator behind a fabricated row never appears.
 */
class GroupZFollowUpTest extends GroupZTestCase
{
    private tbluserModel $hr;
    private string $hrToken;

    protected function setUp(): void
    {
        parent::setUp();

        // An HR user: the `profile:admin,hr,recruiter` gate needs a role key in the 'hr' group.
        $this->profile(3, 1, 'hr_manager');
        \App\Support\RoleKey::flushCache();
        $this->hr = $this->makeUser(4, 1, 3);
        $this->hrToken = $this->issueToken($this->hr);

        Schema::create('talent_interview_panel', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->string('panel_name')->nullable();
            $t->string('target_positions')->nullable();
            $t->text('description')->nullable();
            $t->text('available_interviewers')->nullable();
            $t->string('status')->nullable();
            $t->unsignedBigInteger('deleted_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        DB::table('talent_interview_panel')->insert(['id' => 5, 'sub_institute_id' => 1, 'panel_name' => 'Existing', 'status' => 'available']);
    }

    protected function tearDown(): void
    {
        DetectorRegistry::overrideForTests(null);
        parent::tearDown();
    }

    /**
     * Everything a refused request must leave alone. The genuine HR bearer token is NOT frozen: TouchTokenActivity (in the `api` group, existing and intended)
     * records its `last_used_at` and slides its expiry, so those columns of the token table are excluded; every other column of every row is compared, and so
     * are the user, profile and panel tables.
     */
    private function durableSnapshot(): string
    {
        return md5(json_encode([
            'tokens' => DB::table('personal_access_tokens')->orderBy('id')->get(['id', 'tokenable_type', 'tokenable_id', 'name', 'token', 'abilities'])->toArray(),
            'users' => DB::table('tbluser')->orderBy('id')->get()->toArray(),
            'profiles' => DB::table('tbluserprofilemaster')->orderBy('id')->get()->toArray(),
            'panels' => DB::table('talent_interview_panel')->orderBy('id')->get()->toArray(),
        ]));
    }

    /** @var array<string, string> kind => token, so one kind is created once per test */
    private array $pairCache = [];

    private function panelRows(): int
    {
        return (int) DB::table('talent_interview_panel')->count();
    }

    private function panelAlive(): bool
    {
        $row = DB::table('talent_interview_panel')->where('id', 5)->first();

        return $row !== null && $row->deleted_at === null && $row->deleted_by === null;
    }

    /** A real request to the panel routes: the BEARER header and the `token` input are separate arguments (that is the whole point). */
    private function panelRequest(string $method, string $uri, ?string $bearer, ?string $inputToken, array $extra = []): \Illuminate\Testing\TestResponse
    {
        $payload = ['type' => 'API', 'sub_institute_id' => 1, 'panel_name' => 'Interviewers A'] + $extra;
        if ($inputToken !== null) {
            $payload['token'] = $inputToken;
        }
        $headers = $bearer === null ? [] : ['Authorization' => 'Bearer ' . $bearer];

        return $this->json($method, $uri, $payload, $headers);
    }

    /* ── (1) interview panel: store + destroy ─────────────────────────── */

    /** @return array<string, array{0: string, 1: string}> action => [method, uri] */
    public static function panelActionProvider(): array
    {
        return ['store' => ['POST', '/api/interview-panel/store'], 'destroy' => ['DELETE', '/api/interview-panel/delete/5']];
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function panelScenarioProvider(): array
    {
        $rows = [];
        foreach (self::panelActionProvider() as $action => [$method, $uri]) {
            foreach (self::SCENARIOS as $scenario) {
                $rows["$action / $scenario"] = [$method, $uri, $scenario];
            }
        }

        return $rows;
    }

    /**
     * THE TWO-TOKEN CASE. The role gate reads the legitimate HR bearer and passes; the action reads input('token'), which is the unrecognised / fabricated token.
     * Before this change a fabricated admin-bound 'Session Token' row passed the action's plain lookup and `user_id` became the administrator's id.
     */
    #[DataProvider('panelScenarioProvider')]
    public function test_panel_store_and_destroy_refuse_a_bad_input_token_even_when_the_bearer_passes_the_role_gate(string $method, string $uri, string $scenario): void
    {
        $token = $this->tokenFor($scenario);
        $panelsBefore = $this->panelRows();

        $before = $this->durableSnapshot();
        $this->assertRefusedAtTheSite($this->panelRequest($method, $uri, $this->hrToken, $token), "$scenario as the input token, genuine HR bearer");
        $this->assertSame($before, $this->durableSnapshot(), $scenario . ': a refused request changes no row (no token provisioned, no user, no panel changed)');

        $this->assertSame($panelsBefore, $this->panelRows(), 'no panel was created');
        $this->assertTrue($this->panelAlive(), 'the existing panel was not deleted and not attributed to anybody');
    }

    /** The same, with identity parameters supplied: they cannot rescue an unrecognised token. */
    #[DataProvider('panelScenarioProvider')]
    public function test_panel_actions_request_parameters_cannot_rescue_a_bad_input_token(string $method, string $uri, string $scenario): void
    {
        $token = $this->tokenFor($scenario);

        $this->assertRefusedAtTheSite($this->panelRequest($method, $uri, $this->hrToken, $token, self::SPOOF), $scenario . ' with identity parameters');
        $this->assertTrue($this->panelAlive());
    }

    public function test_panel_the_fabricated_admin_row_was_the_exact_old_exploit(): void
    {
        // The scenario by name, readable on its own: genuine HR bearer + the attacker's surviving admin-bound 'Session Token' row as the input token.
        $fabricated = $this->tokenFor('session_token_admin');
        $this->assertSame('Session Token', DB::table('personal_access_tokens')->where('tokenable_id', 1)->where('name', 'Session Token')->value('name'));

        $created = $this->panelRequest('POST', '/api/interview-panel/store', $this->hrToken, $fabricated);
        $deleted = $this->panelRequest('DELETE', '/api/interview-panel/delete/5', $this->hrToken, $fabricated);

        $created->assertStatus(401)->assertJsonPath('message', 'Invalid token');
        $deleted->assertStatus(401)->assertJsonPath('message', 'Invalid token');
        $this->assertSame(1, $this->panelRows());
        $this->assertTrue($this->panelAlive());
    }

    public function test_panel_a_bad_bearer_is_refused_by_the_role_gate_before_the_action_runs(): void
    {
        // Control (not the changed site): the gate itself is strict.
        foreach (['unknown', 'session_token_admin', 'expired'] as $scenario) {
            $this->panelRequest('POST', '/api/interview-panel/store', $this->tokenFor($scenario), $this->hrToken)->assertStatus(401);
        }
        $this->assertSame(1, $this->panelRows());
    }

    public function test_panel_the_legitimate_flow_still_passes_the_auth_layer_and_does_its_work(): void
    {
        // Bearer and input are the same genuine HR token (what the frontend sends).
        $created = $this->panelRequest('POST', '/api/interview-panel/store', $this->hrToken, $this->hrToken);
        $created->assertStatus(201)->assertJsonPath('message', 'Interview Panel Created Successfully');
        $this->assertSame(2, $this->panelRows());
        $this->assertSame(1, (int) DB::table('talent_interview_panel')->orderByDesc('id')->value('sub_institute_id'), 'created in the token owner\'s own tenant');

        // The token only as the input value (no bearer): the role gate reads input('token') when there is no bearer.
        $this->panelRequest('POST', '/api/interview-panel/store', null, $this->hrToken)->assertStatus(201);

        $deleted = $this->panelRequest('DELETE', '/api/interview-panel/delete/5', $this->hrToken, $this->hrToken);
        $deleted->assertStatus(200)->assertJsonPath('message', 'Interview Panel Deleted Successfully');
        $this->assertNotNull(DB::table('talent_interview_panel')->where('id', 5)->value('deleted_at'), 'soft-deleted');
    }

    public function test_panel_an_employee_still_cannot_use_the_routes_at_all(): void
    {
        // Authorization is unchanged: an employee's genuine token is refused by the role gate (403), not turned into anything by the action.
        $this->panelRequest('POST', '/api/interview-panel/store', $this->employeeToken, $this->employeeToken)->assertStatus(403);
        $this->assertSame(1, $this->panelRows());
    }

    public function test_panel_the_three_lookups_left_alone_are_the_ones_the_directive_names(): void
    {
        // Pins scope: store (~141) and destroy (~257) use the strict resolver; l.61, 95, 196 (getInterviewPanel, getInterviewers, update) are outside this change.
        $code = $this->codeOf('app/Http/Controllers/talent/interview_panel/talent_interviewpanelController.php');

        $this->assertSame(2, substr_count($code, '\App\Support\PersonalAccessTokenResolver::findToken('));
        $this->assertGreaterThanOrEqual(3, substr_count($code, 'PersonalAccessToken::findToken(') - 0, 'the other lookups are untouched here (a later change may swap them)');
    }

    /* ── (2) TaskUpdateController ─────────────────────────────────────── */

    public function test_task_update_the_real_route_is_dead_today_because_request_token_does_not_exist(): void
    {
        // CHARACTERISATION of what is reachable. If this assertion starts failing, Request::Token() has been added: the real route now reaches the lookup, and the
        // tests below describe its behaviour through the route as well.
        $this->assertFalse(Request::hasMacro('Token'), 'no Token() macro is registered');
        $this->assertFalse(method_exists(Request::class, 'Token'), 'Illuminate\Http\Request has no Token()');

        $this->assertStringContainsString('$request->Token()', $this->codeOf('app/Http/Controllers/front_desk/TaskUpdateController.php'));
    }

    /** The action called directly with a Request that DOES define Token() (returns null, so the code falls back to input('token')): this is the lookup under test. */
    private function taskUpdate(array $params): \Symfony\Component\HttpFoundation\Response
    {
        $request = (new class extends Request {
            public function Token()
            {
                return null;
            }
        })::create('/lms/task/update-status/5', 'POST', $params);

        return app(TaskUpdateController::class)->updateStatusAndDescription($request, 5);
    }

    #[DataProvider('scenarioProvider')]
    public function test_task_update_refuses_every_unrecognised_token(string $scenario): void
    {
        $token = $this->tokenFor($scenario);

        $response = $this->taskUpdate(['token' => $token] + self::SPOOF);

        $this->assertSame(401, $response->getStatusCode(), $scenario);
        $this->assertSame('Invalid token', json_decode($response->getContent(), true)['message'], $scenario);
    }

    public function test_task_update_a_genuine_token_passes_the_lookup_and_reaches_its_own_validation(): void
    {
        $response = $this->taskUpdate(['token' => $this->employeeToken]);

        // Past the lookup the action validates `row.data.status`: a 400 from THAT validator proves the genuine token was accepted. (What it would do next -
        // load the task by id with no tenant check - reads a table this isolated fixture does not create, so it is not exercised.)
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(0, json_decode($response->getContent(), true)['status_code']);
        $this->assertSame(401, $this->taskUpdate([])->getStatusCode(), 'no token at all: refused');
    }

    /* ── (3) the Intelligence authenticator ───────────────────────────── */

    private function intelligence(string $token): \Illuminate\Testing\TestResponse
    {
        DetectorRegistry::overrideForTests(static fn () => []);
        config()->set('intelligence.enabled', true);

        return $this->getJson('/api/intelligence/overview', ['Authorization' => 'Bearer ' . $token]);
    }

    public function test_intelligence_refuses_a_surviving_admin_bound_session_token_row_with_the_correct_secret(): void
    {
        $plain = 'attacker-chosen-secret-for-intelligence';
        $this->insertTokenRow(null, 'Session Token', 1, hash('sha256', $plain), now()->addDays(30));
        $before = $this->tokenRows();

        $this->intelligence($plain)->assertStatus(401);
        $this->assertSame($before, $this->tokenRows(), 'nothing is provisioned or touched');

        // The id|secret form too.
        $this->insertTokenRow(600, 'Session Token', 1, hash('sha256', 'sec600'), now()->addDays(30));
        $this->intelligence('600|sec600')->assertStatus(401);
    }

    /** @return array<string, array{0: string}> */
    public static function provisionedNameVariantProvider(): array
    {
        return ['lower case' => ['session token'], 'upper case' => ['SESSION TOKEN'], 'mixed case' => ['sEsSiOn ToKeN'], 'padded' => ['  Session Token  '], 'padded lower' => ["\tsession token "]];
    }

    #[DataProvider('provisionedNameVariantProvider')]
    public function test_intelligence_refuses_the_provisioned_name_in_any_case_or_padding(string $name): void
    {
        $this->insertTokenRow(null, $name, 1, hash('sha256', 'variant-secret'), now()->addDays(30));

        $this->intelligence('variant-secret')->assertStatus(401);
    }

    public function test_intelligence_a_legitimately_named_token_for_the_same_user_still_authenticates(): void
    {
        $this->intelligence($this->adminToken)->assertOk()->assertJsonPath('status', 1);

        // A different legitimate name for the same user, with a future expiry.
        $this->insertTokenRow(null, 'API or script', 1, hash('sha256', 'script-secret'), now()->addDays(10));
        $this->intelligence('script-secret')->assertOk();
    }

    public function test_intelligence_an_expired_token_is_refused_whatever_its_name(): void
    {
        $this->insertTokenRow(null, 'Chrome on Windows', 1, hash('sha256', 'old-secret'), now()->subDay());
        $this->insertTokenRow(null, 'Session Token', 1, hash('sha256', 'old-session-secret'), now()->subDay());

        $this->intelligence('old-secret')->assertStatus(401);
        $this->intelligence('old-session-secret')->assertStatus(401);
        $this->intelligence($this->tokenFor('expired'))->assertStatus(401);
    }

    public function test_intelligence_orphan_foreign_and_unknown_tokens_are_refused_and_request_parameters_do_not_help(): void
    {
        foreach (['unknown', 'unknown_id_guess', 'orphan', 'foreign_owner'] as $scenario) {
            DetectorRegistry::overrideForTests(static fn () => []);
            $this->getJson('/api/intelligence/overview?token=' . urlencode($this->tokenFor($scenario)) . '&user_id=1&sub_institute_id=1', ['sub_institute_id' => '1', 'user_id' => '1'])
                ->assertStatus(401);
        }
    }

    /* ── generic: two different tokens in one request ─────────────────── */

    /** @return array<string, array{0: ?string, 1: ?string}> bearer kind, input-token kind */
    public static function tokenPairProvider(): array
    {
        $kinds = ['employee', 'fabricated', 'expired', 'unknown', null];
        $rows = [];
        foreach ($kinds as $bearer) {
            foreach ($kinds as $input) {
                $rows[sprintf('bearer=%s input=%s', $bearer ?? 'none', $input ?? 'none')] = [$bearer, $input];
            }
        }

        return $rows;
    }

    private function pairToken(?string $kind): ?string
    {
        if ($kind === null) {
            return null;
        }

        // Created once per test: the same kind on both sides of a pair is the SAME token (inserting the same fabricated row twice would also violate the unique hash).
        return $this->pairCache[$kind] ??= match ($kind) {
            'employee' => $this->employeeToken,
            'fabricated' => $this->tokenFor('session_token_admin'),
            'expired' => $this->tokenFor('expired'),
            'unknown' => $this->tokenFor('unknown'),
        };
    }

    private function registerTwoTokenRoutes(): void
    {
        $identityReader = new class {
            use ResolvesApiIdentity;

            public function userId(Request $request): ?int
            {
                $identity = $this->resolveApiIdentity($request);

                return is_array($identity) ? (int) $identity['user_id'] : null;
            }
        };
        // What every swapped site does: look up input('token') with the resolver and take the owner. Plus what the trait does (bearer first).
        $report = static fn (Request $request) => response()->json([
            'site_user' => PersonalAccessTokenResolver::findToken((string) $request->input('token'))?->tokenable_id,
            'trait_user' => $identityReader->userId($request),
        ]);

        Route::match(['GET', 'POST'], '/__z/two/auth', $report)->middleware(authMiddleware::class);               // reads input('token') FIRST
        Route::match(['GET', 'POST'], '/__z/two/api_token', $report)->middleware(RequireApiToken::class);         // reads the BEARER first
        Route::match(['GET', 'POST'], '/__z/two/profile', $report)->middleware(RequireProfile::class . ':admin,hr,employee'); // reads the BEARER first
    }

    /**
     * Two different tokens in one request. The layers disagree about which one to read, so a request can pass one layer on token A and be handled by a site that
     * reads token B. The property that matters, and is asserted for every pair of {genuine employee, fabricated admin row, expired, unknown, none}:
     *   - a layer that answers 200 reports an identity ONLY from a strict token: the genuine employee (3), or nobody (null);
     *   - the administrator behind the fabricated row (1) is never reported by any layer;
     *   - `site_user` (resolver on input('token')) is the employee exactly when input('token') IS the genuine employee token;
     *   - `trait_user` (bearer first, then input) is the employee exactly when the token that layer reads is the genuine one.
     */
    #[DataProvider('tokenPairProvider')]
    public function test_with_two_different_tokens_every_layer_yields_only_a_strict_identity(?string $bearerKind, ?string $inputKind): void
    {
        $this->registerTwoTokenRoutes();
        $bearer = $this->pairToken($bearerKind);
        $input = $this->pairToken($inputKind);

        foreach (['auth', 'api_token', 'profile'] as $layer) {
            $headers = $bearer === null ? [] : ['Authorization' => 'Bearer ' . $bearer];
            $response = $this->json('POST', '/__z/two/' . $layer, $input === null ? [] : ['token' => $input], $headers);

            $readFirst = $layer === 'auth' ? ($input ?? $bearer) : ($bearer ?? $input);
            $readFirstKind = $layer === 'auth' ? ($inputKind ?? $bearerKind) : ($bearerKind ?? $inputKind);
            $context = "layer=$layer bearer=" . ($bearerKind ?? 'none') . ' input=' . ($inputKind ?? 'none');

            if ($readFirstKind !== 'employee') {
                // The token that layer reads is not a strict credential: the layer itself refuses (401), so no identity is ever produced.
                $response->assertStatus(401);

                continue;
            }

            $response->assertOk();
            $body = $response->json();
            $this->assertNotSame(1, $body['site_user'], "$context: the administrator behind the fabricated row must never be reported");
            $this->assertNotSame(1, $body['trait_user'], $context);
            $this->assertSame($inputKind === 'employee' ? 3 : null, $body['site_user'], "$context: site_user (resolver on input('token'))");
            $traitReads = $bearerKind ?? $inputKind;
            $this->assertSame($traitReads === 'employee' ? 3 : null, $body['trait_user'], "$context: trait_user (bearer first, else input)");
            $this->assertNotNull($readFirst);
        }
    }

    public function test_two_tokens_the_documented_layer_preference_is_what_the_tests_assume(): void
    {
        $this->registerTwoTokenRoutes();
        $good = $this->employeeToken;
        $fabricated = $this->tokenFor('session_token_admin');

        // authMiddleware (input first) sees the fabricated input token and refuses, although the bearer is genuine.
        $this->json('POST', '/__z/two/auth', ['token' => $fabricated], ['Authorization' => 'Bearer ' . $good])->assertStatus(401);
        // RequireApiToken (bearer first) accepts the genuine bearer; the site that reads input('token') then gets NO identity from the fabricated row.
        $mixed = $this->json('POST', '/__z/two/api_token', ['token' => $fabricated], ['Authorization' => 'Bearer ' . $good]);
        $mixed->assertOk();
        $this->assertNull($mixed->json('site_user'), 'the swapped site refuses the fabricated input token');
        $this->assertSame(3, $mixed->json('trait_user'));
        // And the reverse: authMiddleware passes on the genuine input token, the bearer-first trait refuses the fabricated bearer.
        $this->json('POST', '/__z/two/auth', ['token' => $good], ['Authorization' => 'Bearer ' . $fabricated])->assertOk()->assertJsonPath('site_user', 3)->assertJsonPath('trait_user', null);
    }

    /** PHP source with comments removed. */
    private function codeOf(string $relative): string
    {
        $path = dirname(__DIR__, 3) . '/' . $relative;
        $this->assertFileExists($path);
        $out = '';
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }

        return $out;
    }
}
