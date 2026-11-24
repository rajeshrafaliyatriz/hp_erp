<?php

namespace Tests\Security\DirectCallers;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A-1c, group X: the six AUTHORIZATION middlewares (hrit.role, menuright, platform.owner, platformright, subject, task.permission).
 *
 * Each used to decide "who is this?" with Sanctum's plain `findToken` (hash check only), so a row the removed provisioning code had fabricated
 * ('Session Token', abilities ["*"], bound to an administrator) satisfied every role gate, and - for menuright / platformright / task.permission, which never
 * checked `expires_at` - an EXPIRED token kept working. They now use App\Support\PersonalAccessTokenResolver. The real middleware classes run here, on
 * throwaway routes, with their real parameters.
 */
class GroupXMiddlewareTest extends GroupXTestCase
{
    /** The body each gate answers with for ANY token it does not accept (read from the middleware classes; an expired token reads the same as an unknown one). */
    public const REFUSAL_MESSAGES = [
        'hrit_role' => 'Invalid token',
        'menu_right' => 'Invalid token',
        'platform_owner' => 'Not found.',
        'platform_right' => 'Invalid token',
        'subject' => 'Invalid token',
        'task_permission' => 'Unauthenticated.',
    ];

    /** @return array<string, array{0: string, 1: string}> gate x kind */
    public static function gateByKind(): array
    {
        $rows = [];
        foreach (array_keys(self::GATES) as $gate) {
            foreach (self::REFUSED_KINDS as $kind) {
                $rows["{$gate} / {$kind}"] = [$gate, $kind];
            }
        }

        return $rows;
    }

    /** @return array<string, array{0: string}> */
    public static function gates(): array
    {
        $rows = [];
        foreach (array_keys(self::GATES) as $gate) {
            $rows[$gate] = [$gate];
        }

        return $rows;
    }

    #[DataProvider('gateByKind')]
    public function test_every_unrecognised_fabricated_expired_orphaned_or_foreign_owned_token_is_refused(string $gate, string $kind): void
    {
        $this->scenario();
        $token = $this->makeToken($kind);
        $before = $this->snapshot();
        $rows = $this->tokenRows();
        $this->startQueryLog();

        $response = $this->xCall('/__x/' . $gate, $token);

        $this->assertSame(self::GATES[$gate][1], $response->getStatusCode(), "{$gate} must refuse a '{$kind}' token with the status it uses for an unauthenticated caller");
        // The refusal BODY too: every kind of bad token is answered exactly like an unrecognised one (no "expired" / "owner missing" oracle), and
        // platform.owner answers its deliberate 404 "Not found." so the endpoint does not reveal itself to non-owners.
        $this->assertSame(self::REFUSAL_MESSAGES[$gate], $response->json('message'), "{$gate} / {$kind}: refusal body");
        $this->assertEmpty($response->json('status'), "{$gate} / {$kind}: the refusal body carries a falsy status");
        $this->assertNotTrue($response->json('ok'), 'the route body must not have run');
        $this->assertSame($rows, $this->tokenRows(), 'refusing created no token row');
        $this->assertSame($before, $this->snapshot(), 'and changed no row anywhere');
        $this->assertSame([], $this->writeStatements(), 'and issued no write statement');
    }

    #[DataProvider('gateByKind')]
    public function test_the_refusal_holds_whichever_form_carries_the_token(string $gate, string $kind): void
    {
        $this->scenario();
        $token = $this->makeToken($kind);

        foreach (['bearer', 'query', 'body'] as $form) {
            $this->assertSame(self::GATES[$gate][1], $this->xCall('/__x/' . $gate, $token, $form)->getStatusCode(), "{$gate} / {$kind} / {$form}");
        }
    }

    #[DataProvider('gates')]
    public function test_a_legitimate_administrator_token_still_passes_every_gate(string $gate): void
    {
        [, , $adminToken] = $this->scenario();

        foreach (['bearer', 'query', 'body'] as $form) {
            $this->xCall('/__x/' . $gate, $adminToken, $form)->assertOk()->assertJson(['ok' => true]);
        }
    }

    #[DataProvider('gates')]
    public function test_a_legitimate_but_unprivileged_token_is_still_refused_by_authorization_not_by_authentication(string $gate): void
    {
        [, , , $employeeToken] = $this->scenario();

        $response = $this->xCall('/__x/' . $gate, $employeeToken);

        // Authorization is preserved: a recognised employee is refused as before (403; 404 for platform.owner, which hides the endpoint from non-owners) ...
        $expected = $gate === 'platform_owner' ? 404 : 403;
        $this->assertSame($expected, $response->getStatusCode());
        // ... and it is a DIFFERENT outcome from "I do not know who you are" wherever the gate distinguishes them (401).
        if ($gate !== 'platform_owner') {
            $this->assertNotSame(401, $response->getStatusCode());
        }
    }

    #[DataProvider('gateByKind')]
    public function test_request_supplied_identity_cannot_rescue_a_refused_token(string $gate, string $kind): void
    {
        $this->scenario();
        $token = $this->makeToken($kind);
        $rows = $this->tokenRows();
        $spoof = ['user_id' => 1, 'sub_institute_id' => 1, 'role' => 'administrator'];

        foreach (['query', 'body', 'header'] as $where) {
            $response = $this->xCall('/__x/' . $gate, $token, 'bearer', $where === 'header' ? ['user_id' => '1', 'sub_institute_id' => '1'] : $spoof, $where);
            $this->assertSame(self::GATES[$gate][1], $response->getStatusCode(), "{$gate} / {$kind} / spoof in {$where}");
        }
        $this->assertSame($rows, $this->tokenRows(), 'no token was provisioned for the spoofed identity');
    }

    #[DataProvider('gates')]
    public function test_no_token_at_all_is_refused_as_before(string $gate): void
    {
        $this->scenario();

        $this->assertSame(self::GATES[$gate][1], $this->xCall('/__x/' . $gate, null)->getStatusCode());
    }

    /**
     * The expired-token status did not change: the middlewares that used to answer "Token expired" (hrit.role, subject) answered 401, and 401 is still the
     * answer (the body now says "Invalid token", as for any unrecognised token).
     */
    public function test_an_expired_token_keeps_the_gates_unauthenticated_status(): void
    {
        $this->scenario();
        $expired = $this->makeToken('expired');

        $this->assertSame(401, $this->xCall('/__x/hrit_role', $expired)->getStatusCode());
        $this->assertSame(401, $this->xCall('/__x/subject', $expired)->getStatusCode());
        $this->assertSame(404, $this->xCall('/__x/platform_owner', $expired)->getStatusCode());
        $this->assertSame(401, $this->xCall('/__x/menu_right', $expired)->getStatusCode(), 'menuright never checked expiry before: an expired token was a working credential');
        $this->assertSame(401, $this->xCall('/__x/platform_right', $expired)->getStatusCode(), 'platformright never checked expiry before');
        $this->assertSame(401, $this->xCall('/__x/task_permission', $expired)->getStatusCode(), 'task.permission never checked expiry before');
    }

    /** task.permission with no privileged ability (85 routes only need an identity): a refused token never reaches the route, a legitimate one does. */
    public function test_task_permission_without_a_privileged_ability_still_authenticates_by_identity(): void
    {
        [, , , $employeeToken] = $this->scenario();

        $this->xCall('/__x/task_permission_plain', $employeeToken)->assertOk();
        foreach (self::REFUSED_KINDS as $kind) {
            $this->assertSame(401, $this->xCall('/__x/task_permission_plain', $this->makeToken($kind))->getStatusCode(), $kind);
        }
    }

    /* ── the combination the audit found: a valid web session plus a fabricated bearer ── */

    /**
     * `auth` (authMiddleware) lets ANY valid session through without looking at the bearer token, so before the fix a logged-in low-privilege user could send
     * `Authorization: Bearer <fabricated administrator-bound secret>` and have hrit.role take the ROLE from that token. Now the role gate refuses it.
     */
    public function test_a_valid_session_plus_a_fabricated_administrator_bearer_is_refused_by_the_role_gate(): void
    {
        $this->scenario();
        $fabricated = $this->makeToken('fabricated');

        $response = $this->xCall('/__x/auth_then_hrit', $fabricated, 'bearer', [], 'query', ['user_id' => 2, 'sub_institute_id' => 2]);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertNotTrue($response->json('ok'));
    }

    public function test_the_same_session_with_an_expired_or_orphaned_bearer_is_refused_too(): void
    {
        $this->scenario();

        foreach (['expired', 'orphan', 'foreign_owner', 'fabricated_id', 'id_guess', 'unknown'] as $kind) {
            $token = $this->makeToken($kind);
            $this->flushSession();
            $this->assertSame(401, $this->xCall('/__x/auth_then_hrit', $token, 'bearer', [], 'query', ['user_id' => 2])->getStatusCode(), $kind);
        }
    }

    /** Compatibility: the Blade surface (session only, no token) and a legitimate token both still pass the same stack. */
    public function test_the_session_only_blade_flow_and_a_legitimate_token_still_pass_the_same_stack(): void
    {
        [, , $adminToken] = $this->scenario();

        // session of the administrator (user 1), no token: authMiddleware passes on the session, hrit.role takes the role from the session user
        $this->xCall('/__x/auth_then_hrit', null, 'bearer', [], 'query', ['user_id' => 1])->assertOk();
        $this->flushSession();
        // a legitimate administrator token and no session
        $this->xCall('/__x/auth_then_hrit', $adminToken)->assertOk();
    }
}
