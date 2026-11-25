<?php

namespace Tests\Security;

use App\Http\Middleware\VerifyCsrfTokenExceptApi;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A-1 through every REAL caller of PersonalAccessTokenResolver::findToken:
 *   RequireApiToken (api.token), RequireProfile (profile), RequireAnyAccess (anyaccess), AiAuth, authMiddleware (auth), the ResolvesApiIdentity trait,
 *   and the two global-group middlewares TouchTokenActivity / RequireTwoFactorEnrolment (they used to run BEFORE any route middleware and so provisioned a token
 *   on every API request), plus VerifyCsrfTokenExceptApi (an unknown token must not buy a CSRF bypass).
 *
 * Each caller is mounted on a throwaway /__a1/<caller> route (TokenTestCase::registerCallerRoutes) and driven over HTTP-shaped requests.
 */
class TokenCallersTest extends TokenTestCase
{
    /* ── 1/2: unknown, malformed and expired tokens are refused by every caller ── */

    #[DataProvider('callerProvider')]
    public function test_an_unknown_bearer_token_is_refused_with_401(string $caller): void
    {
        $this->twoTenants();

        $this->callWithToken($caller, 'definitely-not-a-token')->assertStatus(401);
        $this->callWithToken($caller, 'definitely-not-a-token', 'query')->assertStatus(401);
        $this->callWithToken($caller, 'definitely-not-a-token', 'body')->assertStatus(401);
        $this->callWithToken($caller, null)->assertStatus(401);
    }

    /** @return array<string, array{0: string, 1: string}> caller x malformed token */
    public static function malformedByCaller(): array
    {
        $rows = [];
        $strings = ['1|garbage' => '1|garbage', 'pipe' => '|', 'id only' => '5|', 'empty id' => '|x', 'non-numeric id' => 'abc|def', 'very long' => str_repeat('x', 5000), 'wrong secret, right id (token 2)' => '2|' . str_repeat('0', 40)];
        foreach (self::CALLERS as $caller => $label) {
            foreach ($strings as $name => $string) {
                $rows["$label / $name"] = [$caller, $string];
            }
        }

        return $rows;
    }

    #[DataProvider('malformedByCaller')]
    public function test_malformed_and_wrong_secret_tokens_are_refused(string $caller, string $token): void
    {
        $this->twoTenants();

        $this->callWithToken($caller, $token)->assertStatus(401);
        $this->callWithToken($caller, $token, 'query')->assertStatus(401);
    }

    #[DataProvider('callerProvider')]
    public function test_an_expired_token_is_refused_by_every_caller_including_the_session_gate(string $caller): void
    {
        $this->profile(1, 1, 'administrator');
        $admin = $this->makeUser(1, 1, 1, ['is_admin' => 1]);
        $expired = $this->issueToken($admin, 'Chrome on Windows', now()->subSecond());

        $this->callWithToken($caller, $expired)->assertStatus(401);
        $this->callWithToken($caller, $expired, 'query')->assertStatus(401);
    }

    #[DataProvider('callerProvider')]
    public function test_a_token_whose_owner_row_is_gone_is_refused_not_healed_to_an_administrator(string $caller): void
    {
        [, $other, , $otherToken] = $this->twoTenants();
        \Illuminate\Support\Facades\DB::table('tbluser')->where('id', $other->id)->delete();

        $this->callWithToken($caller, $otherToken)->assertStatus(401);
    }

    /* ── 3: an unknown token creates no session, token row or any other row ─── */

    #[DataProvider('callerProvider')]
    public function test_an_unknown_token_creates_no_session_no_token_and_changes_no_row(string $caller): void
    {
        $this->twoTenants();
        $before = $this->snapshot();
        $tokensBefore = $this->tokenRows();
        $this->startQueryLog();

        $response = $this->callWithToken($caller, 'definitely-not-a-token', 'bearer', ['user_id' => 1, 'sub_institute_id' => 1]);

        $response->assertStatus(401);
        $this->assertSame($tokensBefore, $this->tokenRows(), 'no personal_access_tokens row was created');
        $this->assertSame($before, $this->snapshot(), 'no row anywhere changed');
        $this->assertSame([], $this->writeStatements(), 'no INSERT/UPDATE/DELETE/DDL was issued');
        $this->assertArrayNotHasKey('ai_auth', $response->json() ?? []);
        // (The session half of this guarantee is NOT asserted here: these routes carry no StartSession middleware, so a "no session" check would be
        // vacuous. It is asserted for real in test_an_unknown_token_starts_no_authenticated_session_behind_the_real_session_middleware below.)
    }

    /**
     * An unknown token must not authenticate a SESSION. Mounted behind the real Illuminate StartSession middleware, so there IS a session for the request
     * and a cookie is issued as for any visitor; what must be absent is any sign of a login in THAT request's session.
     */
    #[DataProvider('callerProvider')]
    public function test_an_unknown_token_starts_no_authenticated_session_behind_the_real_session_middleware(string $caller): void
    {
        $this->twoTenants();

        $response = $this->callSessionRoute($caller, 'definitely-not-a-token', ['user_id' => 1, 'sub_institute_id' => 1]);

        $response->assertStatus(401);
        $keys = $this->sessionKeys();
        $this->assertNotContains('user_id', $keys, 'no user id was put into the session');
        $this->assertSame([], array_values(array_filter($keys, static fn (string $k) => str_starts_with($k, 'login_'))), 'no guard login key either');
        $this->assertNull(app('session')->driver()->get('user_id'));
        // A session cookie may exist (StartSession issues one to every visitor); it must belong to an anonymous session, i.e. the store above.
        foreach ($response->baseResponse->headers->getCookies() as $cookie) {
            $this->assertNotSame('', (string) $cookie->getValue());
        }
    }

    /** The probe above would fail if a request did write the session: this control route writes `user_id` and the same probe sees it. */
    public function test_the_session_probe_can_see_a_session_write(): void
    {
        $this->getJson('/__a1s/control_writes_session')->assertOk();

        $this->assertContains('user_id', $this->sessionKeys());
    }

    /** A VALID token authenticates the request but does not turn it into a login session either. */
    public function test_a_valid_token_does_not_create_a_login_session_either(): void
    {
        [, , $adminToken] = $this->twoTenants();

        $this->callSessionRoute('api_token', $adminToken)->assertOk();

        $this->assertNotContains('user_id', $this->sessionKeys());
    }

    /** The group-level middlewares, in the order the `api` group runs them, ahead of the token gate. */
    public function test_the_global_group_middlewares_no_longer_provision_a_token_for_an_unknown_string(): void
    {
        $this->twoTenants();
        $before = $this->snapshot();
        $this->startQueryLog();

        $this->callWithToken('api_group', 'definitely-not-a-token', 'bearer', ['user_id' => 1, 'sub_institute_id' => 1])->assertStatus(401);
        $this->callWithToken('touch', 'definitely-not-a-token', 'query', ['user_id' => 1])->assertOk();   // Touch alone never refuses; it must also never write

        $this->assertSame($before, $this->snapshot());
        $this->assertSame([], $this->writeStatements());
    }

    /* ── 4/5/6: request-supplied identity or tenant cannot select who is authenticated ── */

    /** @return array<string, array{0: string, 1: string}> caller x channel the spoof travels in */
    public static function spoofChannels(): array
    {
        $rows = [];
        foreach (self::CALLERS as $caller => $label) {
            foreach (['query', 'body', 'header'] as $channel) {
                $rows["$label via $channel"] = [$caller, $channel];
            }
        }

        return $rows;
    }

    #[DataProvider('spoofChannels')]
    public function test_an_unknown_token_cannot_borrow_a_user_or_tenant_from_the_request(string $caller, string $channel): void
    {
        [$admin] = $this->twoTenants();
        $spoof = ['user_id' => (string) $admin->id, 'sub_institute_id' => '1'];

        $this->callWithToken($caller, 'definitely-not-a-token', 'bearer', $spoof, $channel)->assertStatus(401);
        $this->callWithToken($caller, 'definitely-not-a-token', 'query', ['user_id' => (string) $admin->id], $channel === 'body' ? 'body' : 'query')->assertStatus(401);
    }

    /** Test 6: the only user in the table is an active administrator named by the request; an unknown token still gets nothing. */
    public function test_with_only_an_administrator_present_an_unknown_token_is_still_refused_by_every_caller(): void
    {
        $this->profile(1, 1, 'administrator');
        $this->makeUser(1, 1, 1, ['is_admin' => 1, 'user_name' => 'adminuser']);

        foreach (self::callerKeys() as $caller) {
            $this->callWithToken($caller, 'x', 'bearer', ['user_id' => '1', 'sub_institute_id' => '1'], 'query')->assertStatus(401);
            $this->callWithToken($caller, 'x', 'bearer', ['user_id' => '1', 'sub_institute_id' => '1'], 'header')->assertStatus(401);
        }
        $this->assertSame(0, $this->tokenRows());
    }

    /** Test 4/5: a VALID token of user B plus a request naming the administrator and tenant 1 still identifies B in tenant 2. */
    #[DataProvider('spoofChannels')]
    public function test_a_valid_token_keeps_its_owner_and_tenant_whatever_the_request_names(string $caller, string $channel): void
    {
        [$admin, $other, , $otherToken] = $this->twoTenants();
        $spoof = ['user_id' => (string) $admin->id, 'sub_institute_id' => '1'];

        $response = $this->callWithToken($caller, $otherToken, 'bearer', $spoof, $channel);

        if ($caller === 'profile' || $caller === 'any_access') {
            // user B is an employee: authenticated (not 401) but not an administrator, whatever the request claims.
            $response->assertStatus(403);

            return;
        }
        $response->assertOk();
        if ($caller === 'identity_trait') {
            $response->assertJsonPath('user_id', $other->id)->assertJsonPath('sub_institute_id', 2);
        }
        if ($caller === 'ai_auth') {
            $response->assertJsonPath('identity.user_id', $other->id)->assertJsonPath('identity.sub_institute_id', 2);
        }
    }

    /* ── 8: a legitimate recognised token still authenticates, in every supported form ── */

    /** @return array<string, array{0: string, 1: string}> */
    public static function legitimateForms(): array
    {
        $rows = [];
        foreach (self::CALLERS as $caller => $label) {
            foreach (['bearer', 'query', 'body'] as $form) {
                $rows["$label / $form"] = [$caller, $form];
            }
        }

        return $rows;
    }

    #[DataProvider('legitimateForms')]
    public function test_a_legitimate_token_authenticates_in_every_supported_form(string $caller, string $form): void
    {
        [$admin, , $adminToken] = $this->twoTenants();

        $response = $this->callWithToken($caller, $adminToken, $form);

        $response->assertOk();
        if ($caller === 'identity_trait') {
            $response->assertJsonPath('user_id', $admin->id)->assertJsonPath('sub_institute_id', 1);
        }
        if ($caller === 'ai_auth') {
            $response->assertJsonPath('identity.user_id', $admin->id)->assertJsonPath('identity.sub_institute_id', 1);
        }
    }

    #[DataProvider('callerProvider')]
    public function test_the_bare_secret_form_sanctum_supports_still_authenticates(string $caller): void
    {
        [, , $adminToken] = $this->twoTenants();
        [, $secret] = explode('|', $adminToken, 2);

        $this->callWithToken($caller, $secret)->assertOk();
    }

    #[DataProvider('callerProvider')]
    public function test_tokens_named_like_real_logins_authenticate_but_the_removed_provisioning_name_does_not(string $caller): void
    {
        $this->profile(1, 1, 'administrator');
        $admin = $this->makeUser(1, 1, 1, ['is_admin' => 1]);

        foreach (['Chrome on Windows', 'API or script', 'Unknown browser', 'seed-ai-demo'] as $name) {
            $this->callWithToken($caller, $this->issueToken($admin, $name))->assertOk();
        }
        $this->callWithToken($caller, $this->issueToken($admin, 'Session Token'))->assertStatus(401);
    }

    /* ── 10: tenant isolation: the removed `id = X OR token = hash(secret)` match ───────── */

    /**
     * The removed code looked a token up by `id = <prefix> OR token = sha256(<rest>)`. So with token 1 owned by the tenant-1 administrator and token 2 by a
     * tenant-2 employee, a caller who sent "1|garbage" was authenticated as the tenant-1 ADMINISTRATOR without knowing the secret. It must be refused.
     */
    #[DataProvider('callerProvider')]
    public function test_guessing_another_users_token_id_does_not_authenticate_as_that_user(string $caller): void
    {
        [, , $adminToken, $otherToken] = $this->twoTenants();
        $this->assertStringStartsWith('1|', $adminToken, 'fixture: the administrator\'s token has id 1');
        $this->assertStringStartsWith('2|', $otherToken);

        $this->callWithToken($caller, '1|garbage')->assertStatus(401);
        $this->callWithToken($caller, '1|' . substr($otherToken, 2))->assertStatus(401);   // right secret, wrong id
        $this->callWithToken($caller, '2|' . substr($adminToken, 2))->assertStatus(401);
    }

    public function test_each_tenants_token_resolves_only_its_own_identity_through_the_trait(): void
    {
        [$admin, $other, $adminToken, $otherToken] = $this->twoTenants();

        $this->callWithToken('identity_trait', $adminToken)->assertOk()->assertJsonPath('sub_institute_id', 1)->assertJsonPath('user_id', $admin->id);
        $this->callWithToken('identity_trait', $otherToken)->assertOk()->assertJsonPath('sub_institute_id', 2)->assertJsonPath('user_id', $other->id);
    }

    /* ── CSRF gate: an unknown token must not be treated as a token-authenticated API call ── */

    /**
     * Laravel skips CSRF verification while unit tests run, so the verdict is read from the gate's own decision method (`isTokenAuthenticatedApiCall`), which
     * `handle()` uses to skip CSRF. True would mean a CSRF bypass.
     *
     * COUPLING NOTE: this test calls a PRIVATE method of VerifyCsrfTokenExceptApi by reflection. It will fail (loudly: ReflectionException) if that method is
     * renamed or its decision moves elsewhere; update the name here when that happens rather than deleting the test. It is the only way to observe the
     * decision, because CSRF verification itself is switched off in the test environment.
     */
    public function test_an_unknown_token_does_not_buy_a_csrf_bypass_but_a_valid_one_still_does(): void
    {
        [, , $adminToken] = $this->twoTenants();
        $gate = new \ReflectionMethod(VerifyCsrfTokenExceptApi::class, 'isTokenAuthenticatedApiCall');
        $gate->setAccessible(true);
        $middleware = $this->app->make(VerifyCsrfTokenExceptApi::class);

        $make = fn (string $token, string $form) => $form === 'bearer'
            ? Request::create('/x', 'POST', ['type' => 'API'], [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token])
            : Request::create('/x', 'POST', ['type' => 'API', 'token' => $token]);

        foreach (['bearer', 'body'] as $form) {
            $this->assertFalse($gate->invoke($middleware, $make('definitely-not-a-token', $form)), "unknown token ($form)");
            $this->assertFalse($gate->invoke($middleware, $make('1|garbage', $form)), "guessed id ($form)");
            $this->assertTrue($gate->invoke($middleware, $make($adminToken, $form)), "valid token ($form)");
        }
        // Not an API call at all: never a bypass, whatever the token.
        $this->assertFalse($gate->invoke($middleware, Request::create('/x', 'POST', ['token' => $adminToken])));

        $expired = $this->issueToken(\App\Models\auth\tbluserModel::query()->find(1), 'Chrome on Windows', now()->subDay());
        $this->assertFalse($gate->invoke($middleware, $make($expired, 'bearer')), 'an expired token does not buy a bypass');
    }

    /* ── sliding expiry (TouchTokenActivity) is preserved for valid tokens and never revives an expired one ── */

    public function test_touch_token_activity_still_slides_a_valid_token_and_never_revives_an_expired_one(): void
    {
        $this->profile(1, 1, 'administrator');
        $user = $this->makeUser(1, 1, 1);
        $valid = $this->issueToken($user, 'Chrome on Windows', now()->addDay());
        $expired = $this->issueToken($user, 'Chrome on Windows', now()->subDay());
        $expiredBefore = \Illuminate\Support\Facades\DB::table('personal_access_tokens')->where('id', 2)->value('expires_at');

        $this->callWithToken('touch', $valid)->assertOk();
        $this->callWithToken('touch', $expired)->assertOk();

        $validRow = \Illuminate\Support\Facades\DB::table('personal_access_tokens')->where('id', 1)->first();
        $this->assertNotNull($validRow->last_used_at, 'a valid token is recorded as used');
        $this->assertTrue(\Carbon\Carbon::parse($validRow->expires_at)->greaterThan(now()->addDays(25)), 'its expiry slid out roughly thirty days');
        $this->assertSame($expiredBefore, \Illuminate\Support\Facades\DB::table('personal_access_tokens')->where('id', 2)->value('expires_at'), 'an expired token is NOT revived');
        $this->assertNull(\Illuminate\Support\Facades\DB::table('personal_access_tokens')->where('id', 2)->value('last_used_at'));
    }

    /* ── 9: existing session-authenticated flow is unchanged ── */

    public function test_session_authenticated_web_requests_still_pass_without_any_token(): void
    {
        // Unauthenticated first: a session set with withSession() would otherwise persist for the later request in this same application instance.
        $this->getJson('/__a1/auth')->assertStatus(401);
        $this->withSession(['user_id' => 7])->getJson('/__a1/auth')->assertOk();
    }

    /** The upload limiter keyed by token owner (valid token) or by address (unknown token) — it also calls the resolver. */
    public function test_the_simulation_upload_limiter_keys_a_valid_token_by_owner_and_an_unknown_one_by_address(): void
    {
        [, $other, , $otherToken] = $this->twoTenants();
        $tokensBefore = $this->tokenRows();
        $limiter = \Illuminate\Support\Facades\RateLimiter::limiter('simulation-assets');
        $this->assertNotNull($limiter, 'the limiter is registered by AppServiceProvider');

        $valid = $limiter(Request::create('/x', 'POST', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $otherToken, 'REMOTE_ADDR' => '203.0.113.9']));
        $unknown = $limiter(Request::create('/x', 'POST', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer made-up', 'REMOTE_ADDR' => '203.0.113.9']));

        $this->assertSame('user:' . \App\Models\auth\tbluserModel::class . ':' . $other->id, $valid->key);
        $this->assertSame('ip:203.0.113.9', $unknown->key, 'a made-up token is keyed by address, never as somebody');
        $this->assertSame($tokensBefore, $this->tokenRows(), 'and building the keys created no token');
    }

    /* ── a caller that is authenticated but not authorised stays 403, not 401 (compatibility of the refusal codes) ── */

    public function test_an_authenticated_employee_is_forbidden_not_unauthenticated_where_a_role_is_required(): void
    {
        [, , , $otherToken] = $this->twoTenants();

        $this->callWithToken('profile', $otherToken)->assertStatus(403);
        $this->callWithToken('any_access', $otherToken)->assertStatus(403);
        $this->callWithToken('api_token', $otherToken)->assertOk();
    }
}
