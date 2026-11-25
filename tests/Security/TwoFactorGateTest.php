<?php

namespace Tests\Security;

use App\Http\Middleware\RequireApiToken;
use App\Http\Middleware\RequireTwoFactorEnrolment;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * The organisation's "require two-step verification" gate (RequireTwoFactorEnrolment) after the A-1 fix.
 *
 * That gate calls PersonalAccessTokenResolver::findToken on EVERY api/web request, before route middleware. With the strict resolver it must still:
 *   - hold back a VALID token whose owner has not enrolled, when the tenant's policy is on (403 + `two_factor_setup_required`);
 *   - let an enrolled owner through, and everybody through when the policy is off;
 *   - never turn an UNKNOWN token into an identity (it simply sees nobody and leaves the refusal to the route's own gate).
 *
 * Fixtures (read from the middleware and its services): `tenant_setting` (sub_institute_id, setting_key, setting_value; key `security.require_two_factor`,
 * values off | administrators | everyone) and `user_two_factor` (user_id, confirmed_at; "enrolled" = a row with confirmed_at set). The middleware keeps a
 * per-process memo (`private static $decided`, keyed tenant:user); it is cleared before each test through reflection.
 */
class TwoFactorGateTest extends TokenTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('tenant_setting', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('sub_institute_id');
            $t->string('setting_key', 96);
            $t->text('setting_value')->nullable();
            $t->timestamps();
        });
        Schema::create('user_two_factor', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('user_id');
            $t->text('secret')->nullable();
            $t->timestamp('confirmed_at')->nullable();
            $t->timestamps();
        });
        $this->clearDecisionMemo();

        $ok = static fn () => response()->json(['ok' => true]);
        // The gate alone (it never refuses an unauthenticated caller), and the gate in front of the token gate (the real api-group order).
        Route::get('/__a1/tfa_alone', $ok)->middleware(RequireTwoFactorEnrolment::class);
        Route::get('/__a1/tfa_then_token', $ok)->middleware([RequireTwoFactorEnrolment::class, RequireApiToken::class]);
        // An allow-listed path the gate must never block (the enrolment screens themselves).
        Route::get('/api/account/2fa/start', $ok)->middleware(RequireTwoFactorEnrolment::class);
    }

    protected function tearDown(): void
    {
        $this->clearDecisionMemo();
        parent::tearDown();
    }

    private function clearDecisionMemo(): void
    {
        $property = new \ReflectionProperty(RequireTwoFactorEnrolment::class, 'decided');
        $property->setAccessible(true);
        $property->setValue(null, []);
    }

    private function policy(int $tenant, string $value): void
    {
        DB::table('tenant_setting')->updateOrInsert(['sub_institute_id' => $tenant, 'setting_key' => 'security.require_two_factor'], ['setting_value' => $value]);
        $this->clearDecisionMemo();
    }

    private function enrol(int $userId, bool $confirmed = true): void
    {
        DB::table('user_two_factor')->insert(['user_id' => $userId, 'secret' => 'x', 'confirmed_at' => $confirmed ? now() : null]);
        $this->clearDecisionMemo();
    }

    public function test_with_the_policy_on_a_valid_unenrolled_token_is_held_back_with_the_setup_required_body(): void
    {
        [, , $adminToken, $employeeToken] = $this->twoTenants();
        $this->policy(1, 'everyone');

        $response = $this->callWithToken('tfa_then_token', $adminToken);

        $response->assertStatus(403)->assertJsonPath('two_factor_setup_required', true)->assertJsonPath('status', 0);
        $this->callWithToken('tfa_alone', $adminToken)->assertStatus(403)->assertJsonPath('two_factor_setup_required', true);
        // Tenant 2 has no policy: its user is unaffected by tenant 1's policy.
        $this->callWithToken('tfa_then_token', $employeeToken)->assertOk();
    }

    public function test_an_enrolled_user_passes_and_an_unconfirmed_enrolment_does_not(): void
    {
        [, , $adminToken] = $this->twoTenants();
        $this->policy(1, 'everyone');

        $this->enrol(1, false);                                                       // secret stored, never confirmed
        $this->callWithToken('tfa_then_token', $adminToken)->assertStatus(403);

        DB::table('user_two_factor')->where('user_id', 1)->update(['confirmed_at' => now()]);
        $this->clearDecisionMemo();
        $this->callWithToken('tfa_then_token', $adminToken)->assertOk();
    }

    public function test_with_the_policy_off_or_unparseable_everyone_passes(): void
    {
        [, , $adminToken] = $this->twoTenants();

        $this->callWithToken('tfa_then_token', $adminToken)->assertOk();              // no row at all: default off
        $this->policy(1, 'off');
        $this->callWithToken('tfa_then_token', $adminToken)->assertOk();
        $this->policy(1, 'something-unrecognised');
        $this->callWithToken('tfa_then_token', $adminToken)->assertOk();             // an unparseable policy never locks an organisation out
    }

    public function test_the_administrators_policy_binds_administrators_only(): void
    {
        [, , $adminToken] = $this->twoTenants();
        $this->profile(5, 1, 'employee');
        $employee = $this->makeUser(5, 1, 5);
        $employeeToken = $this->issueToken($employee);
        $this->policy(1, 'administrators');

        $this->callWithToken('tfa_then_token', $adminToken)->assertStatus(403)->assertJsonPath('two_factor_setup_required', true);
        $this->callWithToken('tfa_then_token', $employeeToken)->assertOk();
    }

    /** The unknown-token half: the gate must not conjure an identity (and so a tenant policy) out of a string it cannot verify. */
    public function test_an_unknown_token_is_not_turned_into_an_identity_by_the_gate(): void
    {
        $this->twoTenants();
        $this->policy(1, 'everyone');                                                 // tenant 1's administrator is unenrolled and would be refused
        $before = $this->snapshot();
        $this->startQueryLog();

        foreach (['definitely-not-a-token', '1|garbage'] as $token) {
            // The gate alone: it sees nobody, so it neither refuses (no 403 setup-required) nor identifies anybody; the route's own gate handles it.
            $this->callWithToken('tfa_alone', $token, 'bearer', ['user_id' => '1', 'sub_institute_id' => '1'])->assertOk();
            $this->callWithToken('tfa_alone', $token, 'bearer', ['user_id' => '1', 'sub_institute_id' => '1'], 'header')->assertOk();
            // In front of the token gate the answer is the token gate's 401, never the gate's 403.
            $this->callWithToken('tfa_then_token', $token, 'bearer', ['user_id' => '1', 'sub_institute_id' => '1'])->assertStatus(401);
        }
        $this->assertSame($before, $this->snapshot(), 'nothing was created for the unknown token');
        $this->assertSame([], $this->writeStatements());
    }

    public function test_an_expired_token_is_not_an_identity_for_the_gate_either(): void
    {
        $this->twoTenants();
        $this->policy(1, 'everyone');
        $expired = $this->issueToken(\App\Models\auth\tbluserModel::query()->find(1), 'Chrome on Windows', now()->subDay());

        $this->callWithToken('tfa_alone', $expired)->assertOk();                       // sees nobody, so no 403
        $this->callWithToken('tfa_then_token', $expired)->assertStatus(401);
    }

    public function test_the_enrolment_endpoints_stay_reachable_for_an_unenrolled_valid_token(): void
    {
        [, , $adminToken] = $this->twoTenants();
        $this->policy(1, 'everyone');

        $this->callWithToken('tfa_then_token', $adminToken)->assertStatus(403);
        $this->getJson('/api/account/2fa/start', ['Authorization' => 'Bearer ' . $adminToken])->assertOk();
    }
}
