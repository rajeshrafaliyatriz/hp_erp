<?php

namespace Tests\Security;

use App\Models\auth\tbluserModel;
use App\Support\PersonalAccessTokenResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The shapes of token rows that ALREADY EXIST in production, which the strict resolver must still handle correctly.
 *
 *   - About 4,960 live tokens carry `expires_at = NULL` and `last_used_at = NULL` (from the comment in bootstrap/app.php: every live token had a NULL
 *     last_used_at, and migration 2026_09_21_100000_expire_immortal_access_tokens exists because tokens used to live forever). A NULL expiry means
 *     "does not expire"; the fix must keep authenticating them and must not crash on them.
 *   - Tokens whose `tokenable_type` is not tbluserModel (see the owner-type test: this is a deliberate hardening that a data check must precede).
 */
class LegacyTokenShapesTest extends TokenTestCase
{
    /* ── item 1: NULL expiry / NULL last_used_at ── */

    private function nullExpiryAdminToken(): string
    {
        $this->profile(1, 1, 'administrator');
        $admin = $this->makeUser(1, 1, 1, ['is_admin' => 1]);
        $token = $this->issueToken($admin, 'Chrome on Windows', null);

        $row = DB::table('personal_access_tokens')->where('id', 1)->first();
        $this->assertNull($row->expires_at, 'fixture: the legacy shape has a NULL expiry');
        $this->assertNull($row->last_used_at, 'fixture: ... and a NULL last_used_at');

        return $token;
    }

    #[DataProvider('callerProvider')]
    public function test_a_token_with_a_null_expiry_authenticates_through_every_caller(string $caller): void
    {
        $token = $this->nullExpiryAdminToken();

        foreach (['bearer', 'query', 'body'] as $form) {
            $this->callWithToken($caller, $token, $form)->assertOk();
        }
        // Authenticating did not, by itself, add an expiry (the callers only read it).
        $this->assertNull(DB::table('personal_access_tokens')->where('id', 1)->value('expires_at'));
    }

    public function test_the_resolver_returns_a_null_expiry_token_unchanged(): void
    {
        $token = $this->nullExpiryAdminToken();

        $found = PersonalAccessTokenResolver::findToken($token);

        $this->assertNotNull($found);
        $this->assertNull($found->expires_at);
        $this->assertSame(1, (int) $found->tokenable->id);
    }

    /**
     * CHARACTERISATION of what TouchTokenActivity really does to such a token (it is existing behaviour, not something the fix changed): the request is not
     * broken, `last_used_at` is set, and the expiry is converted from "never" into a sliding window of IDLE_DAYS (30) from now.
     */
    public function test_touch_token_activity_does_not_break_a_null_expiry_token_and_converts_it_to_a_thirty_day_sliding_expiry(): void
    {
        $token = $this->nullExpiryAdminToken();

        $this->callWithToken('touch', $token)->assertOk();

        $row = DB::table('personal_access_tokens')->where('id', 1)->first();
        $this->assertNotNull($row->last_used_at, 'a first use is recorded');
        $this->assertNotNull($row->expires_at, 'the NULL expiry became a real one');
        $days = now()->diffInHours(\Carbon\Carbon::parse($row->expires_at), false) / 24;
        $this->assertEqualsWithDelta(30.0, $days, 0.1, 'about thirty days (TouchTokenActivity::IDLE_DAYS) from now');

        // ... and the token still authenticates afterwards, through the full api-group-shaped stack.
        $this->callWithToken('api_group', $token)->assertOk();
        $this->callWithToken('identity_trait', $token)->assertOk()->assertJsonPath('user_id', 1);
    }

    public function test_a_second_touch_within_the_throttle_window_does_not_rewrite_the_row(): void
    {
        $token = $this->nullExpiryAdminToken();
        $this->callWithToken('touch', $token)->assertOk();
        $first = DB::table('personal_access_tokens')->where('id', 1)->first();

        $this->startQueryLog();
        $this->callWithToken('touch', $token)->assertOk();

        $this->assertSame([], array_values(array_filter($this->writeStatements(), static fn (string $s) => stripos($s, 'personal_access_tokens') !== false)), 'throttled: no second write');
        $this->assertEquals($first->last_used_at, DB::table('personal_access_tokens')->where('id', 1)->value('last_used_at'));
    }

    /* ── item 6: owner-type hardening ── */

    /**
     * DATA-SHAPE / HARDENING TEST. The resolver now accepts a token only when its owner is App\Models\auth\tbluserModel (every login in this repository
     * issues its token on that class: authController and GoogleAuthController both call `$user->createToken` on a tbluserModel). A token whose
     * `tokenable_type` is some OTHER model, here `App\Models\User` (a real class in this repository that also uses HasApiTokens), is refused even though its
     * owner row exists and its secret is right.
     *
     * DEPLOYMENT NOTE: before this is deployed, the live `personal_access_tokens` table must be checked for tokenable_type values other than
     * `App\Models\auth\tbluserModel` (read-only: SELECT tokenable_type, COUNT(*) ... GROUP BY tokenable_type). Any such rows will stop authenticating through
     * this resolver. This test documents the behaviour; it does not prove that no live row has another type.
     */
    public function test_a_token_owned_by_app_models_user_is_refused_by_the_owner_type_hardening(): void
    {
        $this->assertTrue(class_exists(\App\Models\User::class), 'the class exists in this repository');
        $this->assertContains(\Laravel\Sanctum\HasApiTokens::class, class_uses_recursive(\App\Models\User::class));

        // The owner row EXISTS in the table that class maps to, so the morph relation resolves to a real model: refusal is due to the owner TYPE alone.
        Schema::create('users', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->string('email')->nullable();
            $t->string('password')->nullable();
            $t->rememberToken();
            $t->timestamps();
        });
        DB::table('users')->insert(['id' => 1, 'name' => 'x', 'email' => 'x@example.invalid', 'password' => 'x']);
        $secret = str_repeat('s', 40);
        DB::table('personal_access_tokens')->insert([
            'id' => 1, 'tokenable_type' => \App\Models\User::class, 'tokenable_id' => 1, 'name' => 'Chrome on Windows',
            'token' => hash('sha256', $secret), 'abilities' => '["*"]', 'expires_at' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertNotNull(\Laravel\Sanctum\PersonalAccessToken::findToken('1|' . $secret)?->tokenable, 'control: Sanctum itself resolves this token and its owner');
        $this->assertNull(PersonalAccessTokenResolver::findToken('1|' . $secret), 'the resolver refuses a non-tbluserModel owner');
        foreach (self::callerKeys() as $caller) {
            $this->callWithToken($caller, '1|' . $secret)->assertStatus(401);
        }

        // Control: the same row re-typed as the application's user model is accepted (when a tbluser row with that id exists).
        $this->profile(1, 1, 'administrator');
        $this->makeUser(1, 1, 1, ['is_admin' => 1]);
        DB::table('personal_access_tokens')->where('id', 1)->update(['tokenable_type' => tbluserModel::class]);
        $this->assertNotNull(PersonalAccessTokenResolver::findToken('1|' . $secret), 'control: owner type is the only difference');
    }
}
