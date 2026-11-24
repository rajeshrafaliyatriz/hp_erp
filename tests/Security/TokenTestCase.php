<?php

namespace Tests\Security;

use App\Http\Middleware\AiAuth;
use App\Http\Middleware\authMiddleware;
use App\Http\Middleware\RequireAnyAccess;
use App\Http\Middleware\RequireApiToken;
use App\Http\Middleware\RequireProfile;
use App\Http\Middleware\RequireTwoFactorEnrolment;
use App\Http\Middleware\TouchTokenActivity;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Models\auth\tbluserModel;
use Carbon\CarbonInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Intelligence\IntelligenceTestCase;

/**
 * Base for the A-1 regression tests (PersonalAccessTokenResolver).
 *
 * ISOLATION (inherited, then re-checked): IntelligenceTestCase boots the application WITHOUT reading any env file and aborts unless the default connection
 * is in-memory SQLite. Run these tests only with tests/Intelligence/bootstrap.php (it forces the in-memory environment). Logging is pointed at the `null`
 * channel so a test cannot write into storage/logs.
 *
 * The fixtures are minimal in-memory shapes of the tables the real callers read: `personal_access_tokens` (the standard Sanctum schema), `tbluser`,
 * `tbluserprofilemaster` and `school_setup`. Tokens are issued the way the application issues them: `$user->createToken(...)` on App\Models\auth\tbluserModel.
 *
 * Every caller under test is the REAL class, mounted on a throwaway `/__a1/...` route (see registerCallerRoutes()).
 */
abstract class TokenTestCase extends IntelligenceTestCase
{
    /** Route key => [URI, middleware list]. Every route answers 200 {ok:true,...} when its middleware lets the request through. */
    public const CALLERS = [
        'api_token' => 'RequireApiToken',
        'profile' => 'RequireProfile:admin',
        'any_access' => 'RequireAnyAccess:admin',
        'ai_auth' => 'AiAuth',
        'auth' => 'authMiddleware',
        'identity_trait' => 'ResolvesApiIdentity trait',
    ];

    /** @var list<string> every SQL statement executed since startQueryLog() */
    protected array $sql = [];

    /** @var list<string> every SQL statement ATTEMPTED (before the driver runs it, so also the ones that then fail) since startQueryLog() */
    protected array $attempted = [];

    /** The trait-using controller behind the `identity_trait` routes. */
    protected object $identityController;

    /** Caller key => the middleware that gates it (the `identity_trait` caller has no middleware: the controller itself refuses). */
    public const CALLER_MIDDLEWARE = [
        'api_token' => [RequireApiToken::class],
        'profile' => [RequireProfile::class . ':admin'],
        'any_access' => [RequireAnyAccess::class . ':admin'],
        'ai_auth' => [AiAuth::class],
        'auth' => [authMiddleware::class],
        'identity_trait' => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // A failed lookup logs a warning; keep it out of storage/logs.
        config(['logging.default' => 'null']);

        $this->createTokenTables();
        $this->registerCallerRoutes();
    }

    /* ── fixtures ─────────────────────────────────────────────────────── */

    protected function createTokenTables(): void
    {
        $this->createPeopleTables();
        Schema::table('tbluser', function (Blueprint $t) {
            $t->string('user_name')->nullable();
            $t->unsignedBigInteger('client_id')->nullable();
        });
        Schema::create('tbluserprofilemaster', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->string('name')->nullable();
            $t->string('role_key', 64)->nullable();
            $t->softDeletes();
        });
        $this->createTokensTable();
    }

    /** The standard Sanctum schema. */
    protected function createTokensTable(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('tokenable_type');
            $t->unsignedBigInteger('tokenable_id');
            $t->string('name');
            $t->string('token', 64)->unique();
            $t->text('abilities')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
        });
    }

    protected function profile(int $id, ?int $tenant, string $roleKey): void
    {
        DB::table('tbluserprofilemaster')->insert(['id' => $id, 'sub_institute_id' => $tenant, 'name' => ucfirst($roleKey), 'role_key' => $roleKey]);
    }

    /** An active user; returns the Eloquent model (it has HasApiTokens). */
    protected function makeUser(int $id, ?int $tenant, ?int $profileId = null, array $extra = []): tbluserModel
    {
        $this->insertUser($id, $tenant, array_merge(['user_profile_id' => $profileId, 'user_name' => 'user' . $id], $extra));

        return tbluserModel::query()->findOrFail($id);
    }

    /** Issue a token exactly as the login code does and return the plain `id|secret` string. */
    protected function issueToken(tbluserModel $user, string $name = 'Chrome on Windows', ?CarbonInterface $expiresAt = null): string
    {
        return $user->createToken($name, ['*'], $expiresAt)->plainTextToken;
    }

    /** A tenant-1 administrator (user 1, profile 1) and a tenant-2 employee (user 2, profile 2). Token ids are 1 and 2 respectively. */
    protected function twoTenants(): array
    {
        $this->profile(1, 1, 'administrator');
        $this->profile(2, 2, 'employee');
        $admin = $this->makeUser(1, 1, 1, ['is_admin' => 1]);
        $other = $this->makeUser(2, 2, 2);

        return [$admin, $other, $this->issueToken($admin), $this->issueToken($other)];
    }

    /* ── routes through the REAL callers ──────────────────────────────── */

    protected function registerCallerRoutes(): void
    {
        $ok = static fn () => response()->json(['ok' => true]);

        Route::match(['GET', 'POST'], '/__a1/api_token', $ok)->middleware(RequireApiToken::class);
        Route::match(['GET', 'POST'], '/__a1/profile', $ok)->middleware(RequireProfile::class . ':admin');
        Route::match(['GET', 'POST'], '/__a1/any_access', $ok)->middleware(RequireAnyAccess::class . ':admin');
        Route::match(['GET', 'POST'], '/__a1/ai_auth', static fn (Request $r) => response()->json(['ok' => true, 'identity' => $r->attributes->get('ai_auth')]))
            ->middleware(AiAuth::class);
        Route::match(['GET', 'POST'], '/__a1/auth', $ok)->middleware(authMiddleware::class);

        // The trait, through a tiny controller: it answers an array (identity) or the refusal response.
        $controller = new class {
            use ResolvesApiIdentity;

            public function who(Request $request)
            {
                $identity = $this->resolveApiIdentity($request);

                return is_array($identity)
                    ? response()->json(['ok' => true, 'user_id' => $identity['user_id'], 'sub_institute_id' => $identity['sub_institute_id']])
                    : $identity;
            }
        };
        $this->identityController = $controller;
        Route::match(['GET', 'POST'], '/__a1/identity_trait', static fn (Request $request) => $controller->who($request));

        // The same callers behind the REAL StartSession middleware (so a test can look at the session of THAT request), under /__a1s/.
        foreach (self::CALLER_MIDDLEWARE as $key => $middleware) {
            $handler = match ($key) {
                'ai_auth' => static fn (Request $r) => response()->json(['ok' => true, 'identity' => $r->attributes->get('ai_auth')]),
                'identity_trait' => static fn (Request $request) => $controller->who($request),
                default => $ok,
            };
            Route::match(['GET', 'POST'], '/__a1s/' . $key, $handler)->middleware(array_merge([\Illuminate\Session\Middleware\StartSession::class], $middleware));
        }
        // A control that DOES write the session, to prove the probe can see a session write at all.
        Route::get('/__a1s/control_writes_session', static function () {
            session()->put('user_id', 5);

            return response()->json(['ok' => true]);
        })->middleware(\Illuminate\Session\Middleware\StartSession::class);

        // The global-group middlewares the audit named, stacked as the `api` group stacks them, in front of the token gate.
        Route::match(['GET', 'POST'], '/__a1/api_group', $ok)->middleware([TouchTokenActivity::class, RequireTwoFactorEnrolment::class, RequireApiToken::class]);
        Route::match(['GET', 'POST'], '/__a1/touch', $ok)->middleware(TouchTokenActivity::class);
    }

    /** @return list<string> the caller keys that gate (all of CALLERS) */
    public static function callerKeys(): array
    {
        return array_keys(self::CALLERS);
    }

    /** @return array<string, array{0: string}> data-provider shape */
    public static function callerProvider(): array
    {
        $rows = [];
        foreach (self::CALLERS as $key => $label) {
            $rows[$label] = [$key];
        }

        return $rows;
    }

    /** Send a request through one caller with the token in the given form: bearer | query | body. */
    protected function callWithToken(string $caller, ?string $token, string $form = 'bearer', array $spoof = [], string $spoofIn = 'query'): \Illuminate\Testing\TestResponse
    {
        $uri = '/__a1/' . $caller;
        $headers = ['Accept' => 'application/json'];
        $query = [];
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

        if ($query !== []) {
            $uri .= '?' . http_build_query($query);
        }

        return $form === 'body' || $spoofIn === 'body'
            ? $this->postJson($uri, $body, $headers)
            : $this->getJson($uri, $headers);
    }

    /* ── observation helpers ──────────────────────────────────────────── */

    /**
     * Records every statement twice: `$sql` (QueryExecuted: only statements the driver accepted) and `$attempted` (beforeExecuting: every statement handed to
     * the driver, including ones that then fail). The second list is what shows a DDL that was ATTEMPTED but rejected by SQLite.
     */
    protected function startQueryLog(): void
    {
        $this->sql = [];
        $this->attempted = [];
        DB::listen(function ($query) {
            $this->sql[] = $query->sql;
        });
        $connection = $this->db();
        if (method_exists($connection, 'beforeExecuting')) {
            $connection->beforeExecuting(function ($query) {
                $this->attempted[] = (string) $query;
            });
        }
    }

    /** Send an unknown/known token through a caller mounted behind the real StartSession middleware. */
    protected function callSessionRoute(string $caller, ?string $token, array $query = []): \Illuminate\Testing\TestResponse
    {
        $uri = '/__a1s/' . $caller . ($query === [] ? '' : '?' . http_build_query($query));

        return $this->getJson($uri, $token === null ? [] : ['Authorization' => 'Bearer ' . $token]);
    }

    /** The session keys the request left in the application's session store (the store StartSession used for that request). */
    protected function sessionKeys(): array
    {
        return array_keys(app('session')->driver()->all());
    }

    /** @return list<string> executed statements that are not plain reads */
    protected function writeStatements(): array
    {
        return array_values(array_filter($this->sql, static fn (string $s) => preg_match('/^\s*(insert|update|delete|replace|create|alter|drop|truncate|rename)\b/i', $s) === 1));
    }

    /** @return list<string> */
    protected function ddlStatements(): array
    {
        return array_values(array_filter($this->sql, static fn (string $s) => preg_match('/create\s+table|alter\s+table|drop\s+table|truncate|rename\s+table/i', $s) === 1));
    }

    /** A fingerprint of every fixture table: any row created, changed or deleted anywhere changes it. */
    protected function snapshot(): string
    {
        $out = [];
        foreach (['personal_access_tokens', 'tbluser', 'tbluserprofilemaster', 'school_setup'] as $table) {
            $out[$table] = Schema::hasTable($table) ? DB::table($table)->orderBy('id')->get()->toArray() : 'missing';
        }

        return md5(json_encode($out));
    }

    protected function tokenRows(): int
    {
        return Schema::hasTable('personal_access_tokens') ? (int) DB::table('personal_access_tokens')->count() : 0;
    }
}
