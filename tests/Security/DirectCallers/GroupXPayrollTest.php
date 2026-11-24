<?php

namespace Tests\Security\DirectCallers;

use App\Http\Controllers\Payroll\PayrollController;
use App\Http\Middleware\RequireHritRole;
use App\Http\Middleware\authMiddleware;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A-1c, group X: PayrollController's six direct lookups (payrollType, payrollStore, payrollDestroy, employeeSalaryStructure, employeeSalaryStructureStore,
 * payrollTypeReport). Each runs only when `type=API` and answers 401 {"message":"Invalid token"} for a token it does not recognise. Before the fix a
 * fabricated ('Session Token') or expired token was "recognised" by plain Sanctum, so the controller carried on and wrote/read payroll for whatever tenant
 * the caller's session (or the token) pointed to.
 *
 * Payroll data is not read or written here: the fixture has no payroll tables, and every refusal happens before the first query.
 */
class GroupXPayrollTest extends GroupXTestCase
{
    /** Real controller method => [throwaway URI, HTTP method]. */
    public const ENTRY_POINTS = [
        'payrollType' => ['/__x/payroll/type', 'GET'],
        'payrollStore' => ['/__x/payroll/store', 'POST'],
        'payrollDestroy' => ['/__x/payroll/destroy/5', 'POST'],
        'employeeSalaryStructure' => ['/__x/payroll/structure', 'GET'],
        'employeeSalaryStructureStore' => ['/__x/payroll/structure_store', 'POST'],
        'payrollTypeReport' => ['/__x/payroll/report', 'GET'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // The real production stack of the payroll routes in routes/hrms.php: session gate, then the role gate, then the controller.
        Route::match(['GET', 'POST'], '/__x/payroll_stack/type', [PayrollController::class, 'payrollType'])
            ->middleware([StartSession::class, authMiddleware::class, RequireHritRole::class . ':admin,hr']);
        Route::match(['GET', 'POST'], '/__x/payroll_stack/structure', [PayrollController::class, 'employeeSalaryStructure'])
            ->middleware([StartSession::class, authMiddleware::class, RequireHritRole::class . ':admin,hr']);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function entryByKind(): array
    {
        $rows = [];
        foreach (array_keys(self::ENTRY_POINTS) as $method) {
            foreach (self::REFUSED_KINDS as $kind) {
                $rows["{$method} / {$kind}"] = [$method, $kind];
            }
        }

        return $rows;
    }

    /** @return array<string, array{0: string}> */
    public static function entryPoints(): array
    {
        $rows = [];
        foreach (array_keys(self::ENTRY_POINTS) as $method) {
            $rows[$method] = [$method];
        }

        return $rows;
    }

    private function hit(string $method, ?string $token, array $params = [], ?array $session = null)
    {
        [$uri, $verb] = self::ENTRY_POINTS[$method];
        $headers = ['Accept' => 'application/json'] + ($token === null ? [] : ['Authorization' => 'Bearer ' . $token]);
        $payload = ['type' => 'API'] + $params;
        $caller = $session === null ? $this : $this->withSession($session);

        return $verb === 'GET'
            ? $caller->getJson($uri . '?' . http_build_query($payload), $headers)
            : $caller->postJson($uri, $payload, $headers);
    }

    #[DataProvider('entryByKind')]
    public function test_every_payroll_entry_point_refuses_an_unrecognised_fabricated_expired_orphaned_or_foreign_token(string $method, string $kind): void
    {
        $this->scenario();
        $token = $this->makeToken($kind);
        $rows = $this->tokenRows();
        $before = $this->snapshot();
        $this->startQueryLog();

        $response = $this->hit($method, $token, ['sub_institute_id' => 1, 'user_id' => 1, 'syear' => '2026', 'payroll_name' => 'x']);

        $this->assertSame(401, $response->getStatusCode(), "{$method} / {$kind}");
        $this->assertSame('Invalid token', $response->json('message'));
        $this->assertSame($rows, $this->tokenRows());
        $this->assertSame($before, $this->snapshot());
        $this->assertSame([], $this->writeStatements(), 'a refused payroll call issued no write');
    }

    #[DataProvider('entryByKind')]
    public function test_request_supplied_ids_cannot_make_a_refused_token_acceptable_to_a_payroll_entry_point(string $method, string $kind): void
    {
        $this->scenario();
        $token = $this->makeToken($kind);

        $response = $this->hit($method, $token, ['sub_institute_id' => 1, 'user_id' => 1, 'syear' => '2026'], ['user_id' => 1, 'sub_institute_id' => 1]);

        $this->assertSame(401, $response->getStatusCode(), "{$method} / {$kind} even with a logged-in session and spoofed ids");
    }

    #[DataProvider('entryPoints')]
    public function test_a_legitimate_administrator_token_still_gets_past_authentication_at_every_payroll_entry_point(string $method): void
    {
        [, , $adminToken] = $this->scenario();

        if ($method === 'payrollTypeReport') {
            // No validator in this one: past the token gate it goes straight to the payroll_types read, which the fixture does not have. Pinned exactly.
            try {
                $this->withoutExceptionHandling();
                $this->hit($method, $adminToken);
                $this->fail('expected the payroll_types read to fail in this fixture');
            } catch (\Illuminate\Database\QueryException $e) {
                $this->assertStringContainsString('no such table: payroll_types', $e->getMessage());
            }

            return;
        }

        // No sub_institute_id/user_id in the request: the entry point's OWN validation answers - an exact 400 and message that exist only after the token check.
        $response = $this->hit($method, $adminToken);

        $this->assertSame(400, $response->getStatusCode(), $method);
        $this->assertSame('The sub institute id field is required.', $response->json('message'), $method);
        $this->assertSame(0, $response->json('status'));
    }

    #[DataProvider('entryPoints')]
    public function test_no_token_is_still_refused_as_before(string $method): void
    {
        $this->scenario();

        $response = $this->hit($method, null);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('Token not provided', $response->json('message'));
    }

    /**
     * The audit's HIGH finding: `auth` accepts any valid web session without looking at a bearer token, hrit.role then took the ROLE from the bearer
     * (plain Sanctum accepted a fabricated administrator-bound row), and the controller's own plain check passed - payroll for the caller's session tenant.
     */
    public function test_a_valid_session_plus_a_fabricated_administrator_bearer_never_reaches_payroll(): void
    {
        $this->scenario();
        foreach (['fabricated', 'fabricated_id', 'expired', 'id_guess', 'orphan', 'foreign_owner'] as $kind) {
            $token = $this->makeToken($kind);
            foreach (['payroll_stack/type', 'payroll_stack/structure'] as $path) {
                $this->flushSession();
                $rowsBefore = $this->tokenRows();

                $response = $this->withSession(['user_id' => 2, 'sub_institute_id' => 2])
                    ->getJson('/__x/' . $path . '?type=API&syear=2026', ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json']);

                $this->assertSame(401, $response->getStatusCode(), "{$path} / {$kind}");
                $this->assertSame($rowsBefore, $this->tokenRows(), "{$path} / {$kind}: the request provisioned no token");
            }
        }
    }

    public function test_a_legitimate_administrator_token_and_a_session_only_blade_user_still_pass_the_payroll_stack(): void
    {
        [, , $adminToken] = $this->scenario();

        // legitimate token: through auth (token) and hrit.role (token role), then into the controller (its validation answers, not a refusal)
        $withToken = $this->getJson('/__x/payroll_stack/type?type=API', ['Authorization' => 'Bearer ' . $adminToken, 'Accept' => 'application/json']);
        // exact controller outcome past BOTH gates: its own validation
        $this->assertSame(400, $withToken->getStatusCode());
        $this->assertSame('The sub institute id field is required.', $withToken->json('message'));

        $this->flushSession();
        // session-only Blade user (no token): hrit.role takes the role from the session user; the controller's non-API path goes to the payroll_types read
        try {
            $this->withoutExceptionHandling();
            $this->withSession(['user_id' => 1, 'sub_institute_id' => 1])->getJson('/__x/payroll_stack/type', ['Accept' => 'application/json']);
            $this->fail('expected the payroll_types read to fail in this fixture');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('no such table: payroll_types', $e->getMessage());
        }
    }

    public function test_a_non_api_request_does_not_consult_the_token_check_at_all_as_before(): void
    {
        $this->scenario();
        $fabricated = $this->makeToken('fabricated');

        // Documents unchanged behaviour: the in-controller check is inside `if (type == "API")`; without it the session path runs (the route middleware is the gate).
        $response = $this->withSession(['user_id' => 1, 'sub_institute_id' => 1])
            ->getJson('/__x/payroll/type', ['Authorization' => 'Bearer ' . $fabricated, 'Accept' => 'application/json']);

        $this->assertNotSame('Invalid token', $response->json('message'));
    }
}
