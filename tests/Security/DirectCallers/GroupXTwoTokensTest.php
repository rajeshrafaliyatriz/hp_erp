<?php

namespace Tests\Security\DirectCallers;

use App\Http\Controllers\Api\TaskManagement\Concerns\ResolvesTaskContext;
use App\Http\Middleware\RequireApiToken;
use App\Http\Middleware\RequireHritRole;
use App\Http\Middleware\RequireProfile;
use App\Http\Middleware\TaskPermissionMiddleware;
use App\Http\Middleware\TouchTokenActivity;
use App\Http\Middleware\authMiddleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A-1c, group X: TWO DIFFERENT TOKENS IN ONE REQUEST.
 *
 * In production the middlewares do not read the same place first:
 *   - `authMiddleware` (alias `auth`) reads `input('token')` first and the Bearer header second;
 *   - RequireApiToken, RequireProfile, RequireHritRole, TaskPermissionMiddleware, TouchTokenActivity and the ResolvesApiIdentity / ResolvesTaskContext
 *     traits read the Bearer header first and `input('token')` second.
 * So one request can carry a LEGITIMATE token in one place and a fabricated or expired one in the other, and different gates then see different tokens.
 * The guarantees tested here, on stacks that mirror the production order:
 *   (1) whichever token a gate reads, a bad token (fabricated, expired) is refused BY THAT GATE;
 *   (2) when the gates see good tokens, the identity each reports is the owner of ITS OWN token - never an identity escalated by the other one: a
 *       fabricated administrator-bound row is never the role source, the actor or the tenant of anything;
 *   (3) nothing is written on behalf of the fabricated (or expired) owner: the fabricated and expired rows are untouched, no row is created, and the only
 *       write that can happen is the ordinary sliding-expiry UPDATE of a token that was itself valid.
 *
 * Tokens: A = the administrator's own valid token (user 1, tenant 1); E = the employee's valid token (user 2, tenant 2);
 *         F = a fabricated 'Session Token' row bound to the ADMINISTRATOR, correct secret, 30 days left; X = the administrator's EXPIRED token.
 */
class GroupXTwoTokensTest extends GroupXTestCase
{
    /** Stack key => URI. Order is the column order of every expectation row below. */
    public const STACKS = [
        'auth_hrit' => '/__x2/auth_hrit',            // auth (input first) -> hrit.role:admin (bearer first) -> identity (bearer first)
        'apitoken_profile' => '/__x2/apitoken_profile', // api.token (bearer first) -> profile:admin (bearer first) -> identity
        'taskperm_context' => '/__x2/taskperm_context', // task.permission (bearer first) -> taskContext (bearer first)
        'taskperm_priv' => '/__x2/taskperm_priv',    // task.permission:task.delete (bearer first); privileged ability
        'touch_stack' => '/__x2/touch_stack',        // TouchTokenActivity -> RequireApiToken -> identity (the `api` group order)
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $identity = $this->identityController;
        $context = new class {
            use ResolvesTaskContext;

            public function who(Request $request)
            {
                $c = $this->taskContext($request);

                return is_array($c) ? response()->json(['ok' => true, 'context' => $c]) : $c;
            }
        };

        Route::get('/__x2/auth_hrit', static fn (Request $r) => $identity->who($r))
            ->middleware([StartSession::class, authMiddleware::class, RequireHritRole::class . ':admin']);
        Route::get('/__x2/apitoken_profile', static fn (Request $r) => $identity->who($r))
            ->middleware([RequireApiToken::class, RequireProfile::class . ':admin']);
        Route::get('/__x2/taskperm_context', static fn (Request $r) => $context->who($r))
            ->middleware([StartSession::class, TaskPermissionMiddleware::class]);
        Route::get('/__x2/taskperm_priv', static fn () => response()->json(['ok' => true]))
            ->middleware([StartSession::class, TaskPermissionMiddleware::class . ':task.delete']);
        Route::get('/__x2/touch_stack', static fn (Request $r) => $identity->who($r))
            ->middleware([TouchTokenActivity::class, RequireApiToken::class]);
    }

    /**
     * label => [input token, bearer token, expectations per stack in STACKS order]; a cell is [status] or [status, user id the stack must report].
     *
     * @return array<string, array{0: string, 1: string, 2: list<array<int, int>>}>
     */
    public static function combinations(): array
    {
        return [
            'input=E valid employee, bearer=F fabricated admin row' => ['E', 'F', [[401], [401], [401], [401], [401]]],
            'input=F fabricated admin row, bearer=A valid admin' => ['F', 'A', [[401], [200, 1], [200, 1], [200], [200, 1]]],
            'input=X expired, bearer=A valid admin' => ['X', 'A', [[401], [200, 1], [200, 1], [200], [200, 1]]],
            'input=A valid admin, bearer=X expired' => ['A', 'X', [[401], [401], [401], [401], [401]]],
            'input=A valid admin, bearer=F fabricated admin row' => ['A', 'F', [[401], [401], [401], [401], [401]]],
            'input=A valid admin, bearer=E valid employee' => ['A', 'E', [[403], [403], [200, 2], [403], [200, 2]]],
            'input=E valid employee, bearer=A valid admin' => ['E', 'A', [[200, 1], [200, 1], [200, 1], [200], [200, 1]]],
            'input=A, bearer=A (same admin twice)' => ['A', 'A', [[200, 1], [200, 1], [200, 1], [200], [200, 1]]],
            'input=E, bearer=E (same employee twice)' => ['E', 'E', [[403], [403], [200, 2], [403], [200, 2]]],
            'input=F fabricated, bearer=E valid employee' => ['F', 'E', [[401], [403], [200, 2], [403], [200, 2]]],
            'input=X expired, bearer=E valid employee' => ['X', 'E', [[401], [403], [200, 2], [403], [200, 2]]],
            'input=F fabricated, bearer=F fabricated' => ['F', 'F', [[401], [401], [401], [401], [401]]],
            'input=X expired, bearer=X expired' => ['X', 'X', [[401], [401], [401], [401], [401]]],
        ];
    }

    #[DataProvider('combinations')]
    public function test_each_gate_judges_the_token_it_reads_and_reports_only_its_own_owner(string $inputKind, string $bearerKind, array $expected): void
    {
        [$admin, $employee, $A, $E] = $this->scenario();
        $tokens = ['A' => $A, 'E' => $E, 'F' => $this->makeToken('fabricated'), 'X' => $this->makeToken('expired')];

        foreach (array_keys(self::STACKS) as $i => $stack) {
            $response = $this->two(self::STACKS[$stack], $tokens[$inputKind], $tokens[$bearerKind]);

            $this->assertSame($expected[$i][0], $response->getStatusCode(), "{$stack}: input={$inputKind} bearer={$bearerKind}");

            if (isset($expected[$i][1])) {
                $reported = $response->json('user_id') ?? $response->json('context.user_id');
                $this->assertSame($expected[$i][1], $reported, "{$stack}: the identity must be the owner of the token that gate reads (bearer={$bearerKind})");
                if ($bearerKind === 'E') {
                    // The other token (input) is an administrator's, valid or fabricated: it must never become the actor or the tenant.
                    $this->assertNotSame($admin->id, $reported, "{$stack}: no escalation to the administrator through the other token");
                    $this->assertNotSame(1, $response->json('sub_institute_id') ?? $response->json('context.sub_institute_id'), "{$stack}: nor to the administrator's tenant");
                }
            }
        }
    }

    #[DataProvider('combinations')]
    public function test_nothing_is_written_for_the_fabricated_or_expired_owner_whichever_place_carries_it(string $inputKind, string $bearerKind, array $expected): void
    {
        [, , $A, $E] = $this->scenario();
        $tokens = ['A' => $A, 'E' => $E, 'F' => $this->makeToken('fabricated'), 'X' => $this->makeToken('expired')];
        $fabricatedBefore = DB::table('personal_access_tokens')->where('name', 'Session Token')->first();
        $expiredBefore = DB::table('personal_access_tokens')->whereNotNull('expires_at')->where('expires_at', '<', now())->first();
        $rows = $this->tokenRows();
        $people = md5(json_encode([DB::table('tbluser')->get(), DB::table('tbluserprofilemaster')->get(), DB::table('school_setup')->get()]));
        $this->startQueryLog();

        foreach (self::STACKS as $uri) {
            $this->two($uri, $tokens[$inputKind], $tokens[$bearerKind]);
        }

        $this->assertEquals($fabricatedBefore, DB::table('personal_access_tokens')->where('name', 'Session Token')->first(), 'the fabricated row was not touched, slid or revived');
        $this->assertEquals($expiredBefore, DB::table('personal_access_tokens')->where('id', $expiredBefore->id)->first(), 'the expired row was not revived');
        $this->assertSame($rows, $this->tokenRows(), 'no token row was created');
        $this->assertSame($people, md5(json_encode([DB::table('tbluser')->get(), DB::table('tbluserprofilemaster')->get(), DB::table('school_setup')->get()])), 'no user, profile or tenant row changed');
        foreach ($this->writeStatements() as $statement) {
            $this->assertMatchesRegularExpression('/^\s*update\s+"personal_access_tokens"/i', $statement, 'the only write possible is the sliding-expiry UPDATE of a valid token');
        }
    }

    /**
     * The audit's exact scenario, stated plainly: a logged-in low-privilege caller puts a fabricated administrator secret where the role gate reads it while
     * the session gate is satisfied by their own valid token in the other place. The role is never taken from the fabricated row.
     */
    public function test_a_valid_employee_token_in_the_input_cannot_carry_a_fabricated_administrator_bearer_past_the_role_gate(): void
    {
        [, , , $E] = $this->scenario();
        $F = $this->makeToken('fabricated');

        $response = $this->two(self::STACKS['auth_hrit'], $E, $F);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertNotTrue($response->json('ok'));
    }

    /** Same, with the web session in place of the input token: the session satisfies `auth`, the bearer is the only thing hrit.role reads. */
    public function test_a_valid_session_cannot_carry_a_fabricated_or_expired_bearer_past_the_role_gate_in_either_order(): void
    {
        $this->scenario();
        $F = $this->makeToken('fabricated');
        $X = $this->makeToken('expired');

        foreach ([$F, $X] as $bearer) {
            $this->flushSession();
            $this->assertSame(401, $this->withSession(['user_id' => 2])->getJson(self::STACKS['auth_hrit'], ['Authorization' => 'Bearer ' . $bearer, 'Accept' => 'application/json'])->getStatusCode());
        }
    }

    private function two(string $uri, string $inputToken, string $bearerToken): \Illuminate\Testing\TestResponse
    {
        return $this->getJson($uri . '?' . http_build_query(['token' => $inputToken, 'syear' => '2026']), ['Authorization' => 'Bearer ' . $bearerToken, 'Accept' => 'application/json']);
    }
}
