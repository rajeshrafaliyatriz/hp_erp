<?php

namespace Tests\Security\DirectCallers;

use App\Http\Controllers\Idms\IdmsDocumentController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A-1c, Group Z: each changed token site refuses everything the plain Sanctum lookup used to accept, and still lets a genuine token through.
 *
 *   SkillDashboardController::index     GET  /skill_dashboard                      route is OUTSIDE every auth middleware: this check is the only gate
 *   dashboardController::index          GET  /dashboard                            builds the whole answer AS the token's owner
 *   IdmsDocumentController::actor()     private; reached through reflection        the actor of every document call
 *   chapterController store/update/destroy   /chapter_master...                    the token's owner is the recorded actor
 *   TalentOfferController decideOffer/index  /api/talent-offers/{id}/decision, /api/offers   the token owner's tenant was a fallback
 *
 * Where a real route sits behind `auth`/`profile` (which already use the strict resolver), the isolated `/__z/...` routes put the action behind NO middleware so
 * the action's own check is what is exercised. The "valid web session + fabricated token" cases use the REAL `/dashboard` and `/chapter_master` routes: `auth`
 * passes on the session alone and never looks at the token, so before this change the plain lookup was the only check on the token.
 */
class GroupZSitesTest extends GroupZTestCase
{
    /* ── SkillDashboardController (the only gate) ─────────────────────── */

    #[DataProvider('scenarioProvider')]
    public function test_skill_dashboard_refuses_every_unrecognised_token(string $scenario): void
    {
        $token = $this->tokenFor($scenario);

        $this->assertNothingWritten(function () use ($token, $scenario) {
            $this->assertRefusedAtTheSite($this->send('GET', '/skill_dashboard', ['type' => 'API', 'token' => $token]), $scenario);
        }, $scenario);
    }

    #[DataProvider('scenarioProvider')]
    public function test_skill_dashboard_request_parameters_cannot_rescue_an_unrecognised_token(string $scenario): void
    {
        $token = $this->tokenFor($scenario);

        $this->assertRefusedAtTheSite(
            $this->send('GET', '/skill_dashboard', ['type' => 'API', 'token' => $token] + self::SPOOF),
            $scenario . ' with user_id / sub_institute_id / user_profile_* supplied',
        );
    }

    public function test_skill_dashboard_a_genuine_token_still_passes_the_token_gate(): void
    {
        // Past the gate the action validates its own required fields; a 400 from THAT validator (not the 401) proves the genuine token was accepted.
        $response = $this->send('GET', '/skill_dashboard', ['type' => 'API', 'token' => $this->employeeToken]);

        $response->assertStatus(400);
        $this->assertSame(0, $response->json('status_code'));
        $this->assertNotSame('Invalid token', $response->json('message'));
        $this->assertNotSame('Token not provided', $response->json('message'));

        // The same, as the bare-secret form and with the administrator's token.
        $this->send('GET', '/skill_dashboard', ['type' => 'API', 'token' => $this->adminToken])->assertStatus(400);
    }

    public function test_skill_dashboard_no_token_at_all_is_refused(): void
    {
        $this->send('GET', '/skill_dashboard', ['type' => 'API'])->assertStatus(401)->assertJsonPath('message', 'Token not provided');
    }

    /* ── dashboardController (identity = the token's owner) ───────────── */

    /**
     * THE escalation: any web login (here user 3, an employee) is a valid session, so `auth` lets the request through WITHOUT reading the token. With
     * `type=API&token=<a fabricated Session Token bound to the administrator>` the old plain lookup accepted the row and the whole response was built as that
     * administrator (profile, rights, menus, employee list).
     */
    #[DataProvider('scenarioProvider')]
    public function test_dashboard_with_a_valid_session_still_refuses_an_unrecognised_token(string $scenario): void
    {
        $token = $this->tokenFor($scenario);

        $this->assertNothingWritten(function () use ($token, $scenario) {
            $this->assertRefusedAtTheSite(
                $this->send('GET', '/dashboard', ['type' => 'API', 'token' => $token], ['user_id' => 3, 'sub_institute_id' => 1]),
                $scenario . ' presented with a valid web session',
            );
        }, $scenario);
    }

    #[DataProvider('scenarioProvider')]
    public function test_dashboard_request_parameters_cannot_rescue_an_unrecognised_token(string $scenario): void
    {
        $token = $this->tokenFor($scenario);

        $this->assertRefusedAtTheSite(
            $this->send('GET', '/dashboard', ['type' => 'API', 'token' => $token] + self::SPOOF, ['user_id' => 3, 'sub_institute_id' => 1]),
            $scenario . ' with identity parameters supplied',
        );
    }

    public function test_dashboard_without_a_session_the_outer_gate_refuses_too(): void
    {
        // Control (not the changed site): with no session `auth` itself is strict.
        $token = $this->tokenFor('session_token_admin');

        $this->send('GET', '/dashboard', ['type' => 'API', 'token' => $token])->assertStatus(401);
    }

    public function test_dashboard_a_genuine_token_is_still_accepted_with_a_session_and_without(): void
    {
        foreach ([['user_id' => 3, 'sub_institute_id' => 1], []] as $session) {
            $response = $this->send('GET', '/dashboard', ['type' => 'API', 'token' => $this->employeeToken], $session);

            // Past the token gate the action reads many tables this isolated fixture does not create, so its later outcome is not the point: it must not be the
            // token gate's refusal.
            $this->assertNotSame(401, $response->status(), 'a genuine token must not be refused (session: ' . json_encode($session) . ')');
            $this->assertNotSame('Invalid token', $response->json('message'));
            $this->assertNotSame('Token not provided', $response->json('message'));
        }
    }

    /* ── IdmsDocumentController::actor() ──────────────────────────────── */

    /** actor() is private and needs no constructor services: call it through reflection with a built request. */
    private function idmsActor(?string $token, string $in = 'bearer'): ?array
    {
        $reflection = new \ReflectionClass(IdmsDocumentController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('actor');
        $method->setAccessible(true);

        $server = $in === 'bearer' && $token !== null ? ['HTTP_AUTHORIZATION' => 'Bearer ' . $token] : [];
        $parameters = $in === 'input' && $token !== null ? ['token' => $token] : [];

        return $method->invoke($controller, Request::create('/__z/idms', 'GET', $parameters + self::SPOOF, [], [], $server));
    }

    #[DataProvider('scenarioProvider')]
    public function test_idms_actor_is_null_for_every_unrecognised_token_and_parameters_cannot_supply_one(string $scenario): void
    {
        $token = $this->tokenFor($scenario);

        $this->assertNull($this->idmsActor($token, 'bearer'), $scenario . ' (Bearer)');
        $this->assertNull($this->idmsActor($token, 'input'), $scenario . ' (token input)');
    }

    public function test_idms_actor_is_the_genuine_owner_with_their_own_tenant(): void
    {
        $actor = $this->idmsActor($this->employeeToken);

        $this->assertIsArray($actor);
        $this->assertSame(3, (int) $actor['user']->id);
        $this->assertSame(1, $actor['tenant']);

        // The other tenant's genuine token resolves to that tenant's user, never to tenant 1.
        $tenant2 = $this->issueToken(\App\Models\auth\tbluserModel::query()->findOrFail(2));
        $other = $this->idmsActor($tenant2);
        $this->assertSame(2, $other['tenant']);
        $this->assertSame(2, (int) $other['user']->id);

        $this->assertNull($this->idmsActor(null), 'no token, no actor');
        $this->assertNull($this->idmsActor('   ', 'input'));
    }

    /* ── chapterController store / update / destroy (the token's owner is the actor) ── */

    /** @return array<string, array{0: string, 1: string, 2: string}> [label => [method, real uri, isolated uri]] */
    public static function chapterActionProvider(): array
    {
        return [
            'store' => ['POST', '/lms/chapter_master', '/__z/chapter_store'],
            'update' => ['PUT', '/lms/chapter_master/5', '/__z/chapter_update/5'],
            'destroy' => ['DELETE', '/lms/chapter_master/5', '/__z/chapter_destroy/5'],
        ];
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string}> every action x every scenario */
    public static function chapterScenarioProvider(): array
    {
        $rows = [];
        foreach (self::chapterActionProvider() as $action => [$method, $real, $isolated]) {
            foreach (self::SCENARIOS as $scenario) {
                $rows["$action / $scenario"] = [$method, $real, $isolated, $scenario];
            }
        }

        return $rows;
    }

    #[DataProvider('chapterScenarioProvider')]
    public function test_chapter_actions_refuse_every_unrecognised_token_behind_no_middleware(string $method, string $real, string $isolated, string $scenario): void
    {
        $token = $this->tokenFor($scenario);

        $this->assertNothingWritten(function () use ($method, $isolated, $token, $scenario) {
            $this->assertRefusedAtTheSite($this->send($method, $isolated, ['type' => 'API', 'token' => $token] + self::SPOOF), $scenario);
        }, $scenario);
    }

    /**
     * THE attribution bug: a person logged in on the web (session user 3) who presents a Session Token bound to the administrator used to have the chapter
     * written or deleted AS the administrator (`user_id = $accessToken->tokenable->id`).
     */
    #[DataProvider('chapterScenarioProvider')]
    public function test_chapter_actions_on_the_real_routes_refuse_a_valid_session_plus_an_unrecognised_token(string $method, string $real, string $isolated, string $scenario): void
    {
        $token = $this->tokenFor($scenario);

        $this->assertNothingWritten(function () use ($method, $real, $token, $scenario) {
            $this->assertRefusedAtTheSite(
                $this->send($method, $real, ['type' => 'API', 'token' => $token] + self::SPOOF, ['user_id' => 3, 'sub_institute_id' => 1]),
                $scenario,
            );
        }, $scenario);
    }

    #[DataProvider('chapterActionProvider')]
    public function test_chapter_actions_a_genuine_token_still_passes_the_token_gate(string $method, string $real, string $isolated): void
    {
        // Past the gate each action validates `sub_institute_id`: the 400 from that validator proves the genuine token was accepted.
        foreach ([$isolated => [], $real => ['user_id' => 3, 'sub_institute_id' => 1]] as $uri => $session) {
            $response = $this->send($method, $uri, ['type' => 'API', 'token' => $this->employeeToken], $session);

            $response->assertStatus(400);
            $this->assertSame(0, $response->json('status_code'), $uri);
        }
    }

    /* ── TalentOfferController decideOffer / index (owner's tenant was a fallback) ── */

    #[DataProvider('scenarioProvider')]
    public function test_offer_decision_refuses_every_unrecognised_token(string $scenario): void
    {
        $token = $this->tokenFor($scenario);

        $this->assertNothingWritten(function () use ($token, $scenario) {
            $this->assertRefusedAtTheSite(
                $this->send('POST', '/__z/offer_decision/5', ['type' => 'API', 'token' => $token, 'decision' => 'approve'] + self::SPOOF),
                $scenario,
            );
        }, $scenario);
    }

    #[DataProvider('scenarioProvider')]
    public function test_offer_list_refuses_every_unrecognised_token_and_does_not_adopt_the_owners_tenant(string $scenario): void
    {
        $token = $this->tokenFor($scenario);

        // `$sub_institute_id = apiTenantId() ?? $user->sub_institute_id`: with the old lookup an expired / fabricated token still reached the second operand
        // (the owner's own tenant) and read that tenant's offers.
        $this->assertRefusedAtTheSite($this->send('GET', '/__z/offers', ['type' => 'API', 'token' => $token] + self::SPOOF), $scenario);
    }

    public function test_offer_actions_a_genuine_token_still_passes_the_token_gate(): void
    {
        $decision = $this->send('POST', '/__z/offer_decision/5', ['type' => 'API', 'token' => $this->employeeToken]);
        $decision->assertStatus(422); // past the gate, the action's own validator asks for `decision`
        $this->assertSame(0, $decision->json('status'));

        $list = $this->send('GET', '/__z/offers', ['type' => 'API', 'token' => $this->employeeToken]);
        $this->assertNotSame(401, $list->status(), 'a genuine token must reach the action (its later table read is outside this fixture)');
        $this->assertNotSame('Invalid token', $list->json('message'));
    }

    public function test_offer_actions_keep_their_type_gate(): void
    {
        // Unchanged behaviour: without type=API the actions refuse before any token is read.
        $this->send('POST', '/__z/offer_decision/5', ['token' => $this->employeeToken])->assertStatus(400);
        $this->send('GET', '/__z/offers', ['token' => $this->employeeToken])->assertStatus(400);
    }

    /* ── tenant isolation of the swapped sites ────────────────────────── */

    public function test_a_genuine_token_of_tenant_2_never_becomes_a_tenant_1_identity_at_any_site(): void
    {
        $tenant2Token = $this->issueToken(\App\Models\auth\tbluserModel::query()->findOrFail(2));

        // The token resolves to ITS owner: the Idms actor says tenant 2, whatever the request claims.
        $actor = $this->idmsActor($tenant2Token);
        $this->assertSame(2, $actor['tenant']);

        // A guess of tenant 1's token id with tenant 2's data is still refused.
        $this->assertRefusedAtTheSite($this->send('GET', '/skill_dashboard', ['type' => 'API', 'token' => '1|' . substr($tenant2Token, strpos($tenant2Token, '|') + 1)]), 'a secret of tenant 2 under token id 1');
    }

    public function test_no_site_issues_a_write_when_it_refuses(): void
    {
        $token = $this->tokenFor('unknown');
        $this->startQueryLog();

        $this->send('GET', '/skill_dashboard', ['type' => 'API', 'token' => $token]);
        $this->send('GET', '/dashboard', ['type' => 'API', 'token' => $token], ['user_id' => 3, 'sub_institute_id' => 1]);
        $this->send('POST', '/__z/chapter_store', ['type' => 'API', 'token' => $token]);
        $this->send('POST', '/__z/offer_decision/5', ['type' => 'API', 'token' => $token]);

        $this->assertSame([], $this->writeStatements(), 'no insert/update/delete/DDL is issued for a refused token');
        $this->assertSame([], $this->ddlStatements());
        $this->assertSame(0, DB::table('personal_access_tokens')->where('name', 'Session Token')->count(), 'nothing is provisioned');
    }
}
