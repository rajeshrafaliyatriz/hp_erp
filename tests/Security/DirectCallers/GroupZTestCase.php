<?php

namespace Tests\Security\DirectCallers;

use App\Http\Controllers\lms\chapterController;
use App\Http\Controllers\talent\TalentOfferController;
use App\Models\auth\tbluserModel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\Security\TokenTestCase;

/**
 * Shared fixtures for A-1c "Group Z": five controllers whose own plain-Sanctum token check was replaced by the strict resolver
 * (SkillDashboardController, dashboardController, IdmsDocumentController::actor, chapterController store/update/destroy, TalentOfferController decideOffer/index).
 *
 * The people (tenant 1 unless noted):
 *   user 1  administrator (is_admin)         token row 1 is ITS legitimate token, so '1|garbage' is a guess of an existing token id
 *   user 3  employee (the "any low-privilege login" used for web-session tests)
 *   user 2  employee of tenant 2
 *
 * Every refusal scenario (see SCENARIOS) is a way of presenting a token that the old plain lookup ACCEPTED (or, for 'unknown', a control that both versions refuse):
 *   unknown / unknown_id_guess        a made-up string; '1|garbage' is the guess of token 1's id (the removed code matched `id = N OR token = hash`)
 *   session_token_admin(+_id_form)    a row the removed provisioning code fabricated: name 'Session Token', abilities ['*'], 30-day expiry, owner = the administrator,
 *                                     and the attacker KNOWS the secret (its stored hash is sha256 of the string the attacker chose)
 *   expired                           a genuine token whose expiry has passed
 *   orphan                            a genuine token whose owner row was deleted
 *   foreign_owner                     a token owned by another model class (App\Models\User)
 */
abstract class GroupZTestCase extends TokenTestCase
{
    /** @var list<string> */
    public const SCENARIOS = ['unknown', 'unknown_id_guess', 'session_token_admin', 'session_token_admin_id_form', 'expired', 'orphan', 'foreign_owner'];

    /** The parameters an attacker adds to try to pick an identity/tenant when the token itself is not recognised. */
    public const SPOOF = ['user_id' => 1, 'sub_institute_id' => 1, 'user_profile_name' => 'ADMIN', 'user_profile_id' => 1];

    protected tbluserModel $admin;
    protected tbluserModel $employee;
    protected string $adminToken;
    protected string $employeeToken;

    public static function scenarioProvider(): array
    {
        $rows = [];
        foreach (self::SCENARIOS as $scenario) {
            $rows[$scenario] = [$scenario];
        }

        return $rows;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Columns the controllers' own queries read that the shared fixture does not carry.
        Schema::table('tbluser', function (Blueprint $t) {
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->string('email')->nullable();
            $t->string('password')->nullable();
            $t->rememberToken();
            $t->timestamps();
        });

        $this->profile(1, 1, 'administrator');
        $this->profile(2, 1, 'employee');
        $this->admin = $this->makeUser(1, 1, 1, ['is_admin' => 1]);
        $this->adminToken = $this->issueToken($this->admin);          // token row id 1
        $this->employee = $this->makeUser(3, 1, 2);
        $this->employeeToken = $this->issueToken($this->employee);
        $this->makeUser(2, 2, 2);

        $this->registerGroupZRoutes();
    }

    /**
     * Throwaway routes that put the changed controller actions BEHIND NO MIDDLEWARE, so the action's own token check is the thing under test (the real routes
     * sit behind `auth`/`profile`, which would refuse a bad token before the action runs and hide whether the site itself is safe).
     */
    protected function registerGroupZRoutes(): void
    {
        $session = \Illuminate\Session\Middleware\StartSession::class; // update()/destroy() call $request->session(), which needs a started session
        Route::match(['GET', 'POST'], '/__z/chapter_store', [chapterController::class, 'store'])->middleware($session);
        Route::match(['PUT', 'POST'], '/__z/chapter_update/{id}', [chapterController::class, 'update'])->middleware($session);
        Route::match(['DELETE', 'POST'], '/__z/chapter_destroy/{id}', [chapterController::class, 'destroy'])->middleware($session);
        Route::get('/__z/offers', [TalentOfferController::class, 'index']);
        Route::post('/__z/offer_decision/{id}', [TalentOfferController::class, 'decideOffer']);
    }

    /** The plain token string for a scenario (creating whatever rows it needs). */
    protected function tokenFor(string $scenario): string
    {
        switch ($scenario) {
            case 'unknown':
                return 'zz-totally-unknown-token-string';
            case 'unknown_id_guess':
                return '1|garbage';
            case 'session_token_admin':
                $plain = 'attacker-chosen-string-for-the-admin';
                $this->insertTokenRow(null, 'Session Token', 1, hash('sha256', $plain), now()->addDays(30));

                return $plain;
            case 'session_token_admin_id_form':
                $this->insertTokenRow(500, 'Session Token', 1, hash('sha256', 'attackersecret'), now()->addDays(30));

                return '500|attackersecret';
            case 'expired':
                return $this->issueToken($this->employee, 'Chrome on Windows', now()->subDay());
            case 'orphan':
                $ghost = $this->makeUser(9, 1, 2);
                $token = $this->issueToken($ghost);
                DB::table('tbluser')->where('id', 9)->delete();

                return $token;
            case 'foreign_owner':
                DB::table('users')->insert(['id' => 1, 'name' => 'x', 'email' => 'x@example.invalid', 'password' => 'x']);
                $secret = str_repeat('f', 40);
                $this->insertTokenRow(800, 'Chrome on Windows', 1, hash('sha256', $secret), null, \App\Models\User::class);

                return '800|' . $secret;
        }

        throw new \InvalidArgumentException("unknown scenario {$scenario}");
    }

    protected function insertTokenRow(?int $id, string $name, int $ownerId, string $hash, $expiresAt, string $type = tbluserModel::class): void
    {
        $row = [
            'tokenable_type' => $type, 'tokenable_id' => $ownerId, 'name' => $name, 'token' => $hash, 'abilities' => '["*"]',
            'expires_at' => $expiresAt, 'created_at' => now(), 'updated_at' => now(),
        ];
        if ($id !== null) {
            $row['id'] = $id;
        }
        DB::table('personal_access_tokens')->insert($row);
    }

    /** Send a request the way the screens do: `type=API` and the token as an input value (the sites read `input('token')`). */
    protected function send(string $method, string $uri, array $params, array $session = []): TestResponse
    {
        $method = strtoupper($method);
        if ($session !== []) {
            $this->withSession($session);
        }

        return $method === 'GET'
            ? $this->getJson($uri . '?' . http_build_query($params))
            : $this->json($method, $uri, $params);
    }

    /** A request refused at the token gate: 401 and the site's own "Invalid token" body. */
    protected function assertRefusedAtTheSite(TestResponse $response, string $context): void
    {
        $response->assertStatus(401);
        $this->assertSame('Invalid token', $response->json('message'), $context . ' (the site\'s own refusal, not some other gate)');
    }

    /** The row count of every fixture table: a refused request must not create a token, a session identity or any row. */
    protected function assertNothingWritten(callable $act, string $context): void
    {
        $before = $this->snapshot();
        $act();
        $this->assertSame($before, $this->snapshot(), $context . ': a refused request changes no row');
    }
}
