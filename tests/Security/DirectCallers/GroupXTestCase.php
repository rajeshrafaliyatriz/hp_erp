<?php

namespace Tests\Security\DirectCallers;

use App\Http\Controllers\Api\TaskManagement\Concerns\ResolvesTaskContext;
use App\Http\Controllers\Payroll\PayrollController;
use App\Http\Middleware\RequireHritRole;
use App\Http\Middleware\RequireMenuRight;
use App\Http\Middleware\RequirePlatformOwner;
use App\Http\Middleware\RequirePlatformRight;
use App\Http\Middleware\RequireSubjectAuthority;
use App\Http\Middleware\TaskPermissionMiddleware;
use App\Http\Middleware\authMiddleware;
use App\Models\auth\tbluserModel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Security\TokenTestCase;

/**
 * Base for the A-1c "group X" regression tests: the authorization middlewares, the task-management token context and the payroll controller, all of which
 * used to call Sanctum's plain `PersonalAccessToken::findToken` directly (hash check only - no expiry, no owner, no fabricated-row refusal) and now go through
 * App\Support\PersonalAccessTokenResolver.
 *
 * Everything here is the REAL production class mounted on a throwaway `/__x/...` route (middleware: the real alias target with its real parameters; payroll:
 * the real controller methods), on top of the in-memory fixtures of TokenTestCase. No database, no env file.
 *
 * TOKEN KINDS (the strings a caller can present), all built by makeToken():
 *   unknown            a string no row matches
 *   id_guess           "1|garbage": a real token id with a wrong secret (the removed `id = X OR token = hash` matched this as token 1's owner)
 *   fabricated         a row named 'Session Token' (what the removed provisioning code inserted), abilities ["*"], 30 days left, bound to the ADMINISTRATOR,
 *                      presented with its correct secret in the bare form: Sanctum accepts it, the resolver must not
 *   fabricated_id      the same row presented as "id|secret"
 *   expired            a genuine login token of the administrator whose expiry is in the past
 *   orphan             a genuine token whose owner row has been deleted
 *   foreign_owner      a token whose owner is not tbluserModel
 */
abstract class GroupXTestCase extends TokenTestCase
{
    /** Token kinds a gate must refuse. */
    public const REFUSED_KINDS = ['unknown', 'id_guess', 'fabricated', 'fabricated_id', 'expired', 'orphan', 'foreign_owner'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createGroupXTables();
        $this->registerGroupXRoutes();
    }

    /* ── fixtures ─────────────────────────────────────────────────────── */

    protected function createGroupXTables(): void
    {
        Schema::create('platform_owners', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('user_id');
            $t->timestamp('revoked_at')->nullable();
        });
        Schema::create('tblmenumaster_g2g', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('access_link')->nullable();
        });
        Schema::create('tblgroupwise_rights_g2g', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('sub_institute_id')->nullable(); // TEXT in the real table
            $t->unsignedBigInteger('menu_id');
            $t->unsignedBigInteger('profile_id');
            $t->boolean('can_view')->default(false);
            $t->boolean('can_add')->default(false);
            $t->boolean('can_edit')->default(false);
            $t->boolean('can_delete')->default(false);
            $t->string('right_view')->nullable();
            $t->string('right_add')->nullable();
            $t->string('right_edit')->nullable();
            $t->string('right_delete')->nullable();
        });
    }

    /** Admin (user 1, tenant 1, profile 1 administrator) + employee (user 2, tenant 2, profile 2): the TokenTestCase pair, plus rights/ownership for the admin. */
    protected function scenario(): array
    {
        [$admin, $employee, $adminToken, $employeeToken] = $this->twoTenants();

        DB::table('platform_owners')->insert(['user_id' => $admin->id]);
        DB::table('tblmenumaster_g2g')->insert([['id' => 201, 'access_link' => '/module/crm/marketing/leads'], ['id' => 77, 'access_link' => '/gtm/deals']]);
        foreach ([201, 77] as $menu) {
            DB::table('tblgroupwise_rights_g2g')->insert(['sub_institute_id' => '1', 'menu_id' => $menu, 'profile_id' => 1, 'can_view' => 1, 'can_add' => 1, 'can_edit' => 1, 'can_delete' => 1]);
        }

        return [$admin, $employee, $adminToken, $employeeToken];
    }

    /** Build a token string of the given kind (call scenario() first: kinds are bound to its administrator, user 1). */
    protected function makeToken(string $kind): string
    {
        $admin = tbluserModel::query()->findOrFail(1);

        switch ($kind) {
            case 'unknown':
                return 'definitely-not-a-token-' . str_repeat('x', 12);
            case 'id_guess':
                return '1|garbage';
            case 'fabricated':
                return $this->insertFabricated($admin, 'attacker-chosen-secret-0001', false);
            case 'fabricated_id':
                return $this->insertFabricated($admin, 'attacker-chosen-secret-0002', true);
            case 'expired':
                return $this->issueToken($admin, 'Chrome on Windows', now()->subMinute());
            case 'orphan':
                $ghost = $this->makeUser(9, 1, 1);
                $token = $this->issueToken($ghost);
                DB::table('tbluser')->where('id', 9)->delete();

                return $token;
            case 'foreign_owner':
                $secret = 'foreign-owner-secret-0003';
                DB::table('personal_access_tokens')->insert([
                    'tokenable_type' => GroupXForeignOwner::class, 'tokenable_id' => 1, 'name' => 'Chrome on Windows', 'token' => hash('sha256', $secret),
                    'abilities' => '["*"]', 'expires_at' => now()->addDays(30), 'created_at' => now(), 'updated_at' => now(),
                ]);

                return $secret;
        }

        throw new \InvalidArgumentException("unknown token kind {$kind}");
    }

    /** The row the removed provisioning code inserted: name 'Session Token', abilities ["*"], 30 days, owner = the chosen user. */
    protected function insertFabricated(tbluserModel $owner, string $secret, bool $withId): string
    {
        $id = DB::table('personal_access_tokens')->insertGetId([
            'tokenable_type' => tbluserModel::class, 'tokenable_id' => $owner->id, 'name' => 'Session Token', 'token' => hash('sha256', $secret),
            'abilities' => '["*"]', 'expires_at' => now()->addDays(30), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $withId ? $id . '|' . $secret : $secret;
    }

    /** @return array<string, array{0: string}> */
    public static function refusedKinds(): array
    {
        $rows = [];
        foreach (self::REFUSED_KINDS as $kind) {
            $rows[$kind] = [$kind];
        }

        return $rows;
    }

    /* ── routes: the REAL middleware and controllers on throwaway paths ── */

    /** Gate key => [middleware (with its real parameters), status the gate itself uses to refuse an unauthenticated caller]. */
    public const GATES = [
        'hrit_role' => [RequireHritRole::class . ':admin', 401],
        'menu_right' => [RequireMenuRight::class . ':201,view', 401],
        'platform_owner' => [RequirePlatformOwner::class, 404],
        'platform_right' => [RequirePlatformRight::class . ':/gtm/deals,view', 401],
        'subject' => [RequireSubjectAuthority::class . ':hr_elevated', 401],
        'task_permission' => [TaskPermissionMiddleware::class . ':task.delete', 401],
    ];

    protected function registerGroupXRoutes(): void
    {
        $ok = static fn () => response()->json(['ok' => true]);

        foreach (self::GATES as $key => [$middleware]) {
            Route::match(['GET', 'POST'], '/__x/' . $key, $ok)->middleware([StartSession::class, $middleware]);
        }
        // The stack the audit named: the session gate (`auth`) in front of the role gate.
        Route::match(['GET', 'POST'], '/__x/auth_then_hrit', $ok)->middleware([StartSession::class, authMiddleware::class, RequireHritRole::class . ':admin']);
        // Task permission without a privileged ability (the 85 routes that only need an identity).
        Route::match(['GET', 'POST'], '/__x/task_permission_plain', $ok)->middleware([StartSession::class, TaskPermissionMiddleware::class]);

        // The task-management trait (about 26 controllers use it), through a tiny controller.
        $controller = new class {
            use ResolvesTaskContext;

            public function who(Request $request)
            {
                $context = $this->taskContext($request);

                return is_array($context) ? response()->json(['ok' => true, 'context' => $context]) : $context;
            }
        };
        Route::match(['GET', 'POST'], '/__x/task_context', static fn (Request $request) => $controller->who($request));

        // The six payroll entry points that hold a direct lookup, as the real controller methods behind a real session.
        Route::match(['GET', 'POST'], '/__x/payroll/type', [PayrollController::class, 'payrollType'])->middleware(StartSession::class);
        Route::match(['GET', 'POST'], '/__x/payroll/store', [PayrollController::class, 'payrollStore'])->middleware(StartSession::class);
        Route::match(['GET', 'POST'], '/__x/payroll/destroy/{id}', [PayrollController::class, 'payrollDestroy'])->middleware(StartSession::class);
        Route::match(['GET', 'POST'], '/__x/payroll/structure', [PayrollController::class, 'employeeSalaryStructure'])->middleware(StartSession::class);
        Route::match(['GET', 'POST'], '/__x/payroll/structure_store', [PayrollController::class, 'employeeSalaryStructureStore'])->middleware(StartSession::class);
        Route::match(['GET', 'POST'], '/__x/payroll/report', [PayrollController::class, 'payrollTypeReport'])->middleware(StartSession::class);
    }

    /** One request: token in the chosen form, optional spoofed parameters, optional session. */
    protected function xCall(string $uri, ?string $token, string $form = 'bearer', array $spoof = [], string $spoofIn = 'query', ?array $session = null, array $extraQuery = []): \Illuminate\Testing\TestResponse
    {
        $headers = ['Accept' => 'application/json'];
        $query = $extraQuery;
        $body = [];

        if ($token !== null) {
            match ($form) {
                'bearer' => $headers['Authorization'] = 'Bearer ' . $token,
                'query' => $query['token'] = $token,
                'body' => $body['token'] = $token,
            };
        }
        match ($spoofIn) {
            'query' => $query = array_merge($query, $spoof),
            'body' => $body = array_merge($body, $spoof),
            'header' => $headers = array_merge($headers, $spoof),
        };

        $url = $uri . ($query === [] ? '' : '?' . http_build_query($query));
        $caller = $session === null ? $this : $this->withSession($session);

        return ($form === 'body' || $spoofIn === 'body') ? $caller->postJson($url, $body, $headers) : $caller->getJson($url, $headers);
    }

    /** Build a controller without running its constructor and call one of its private/protected methods (the audit's "private context()" sites). */
    protected function callPrivate(string $class, string $method, Request $request): mixed
    {
        $reflection = new \ReflectionClass($class);
        $instance = $reflection->newInstanceWithoutConstructor();
        $m = $reflection->getMethod($method);
        $m->setAccessible(true);

        return $m->invoke($instance, $request);
    }

    protected function apiRequest(?string $token, array $params = []): Request
    {
        $server = $token === null ? [] : ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];

        return Request::create('/__x/direct', 'GET', $params, [], [], $server);
    }
}
