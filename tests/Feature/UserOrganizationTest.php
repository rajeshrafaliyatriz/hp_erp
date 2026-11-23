<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Models\auth\tbluserModel;
use App\Support\UserOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * An account belongs to the organisation written on its own row, and to no other. No organisation: HTTP 400 and a fixed sentence. Never the first organisation in
 * the database, never the one the request names. (Requirement of 23 Nov 2026; the code is App\Support\UserOrganization and the five gates that call it.)
 */
class UserOrganizationTest extends TestCase
{
    private const MESSAGE = 'You do not have any organization assigned. Please contact your administrator.';

    protected function setUp(): void
    {
        parent::setUp();

        // a key for the encrypter (the session and cookies need one; a checkout without a .env has none)
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');

        Schema::create('school_setup', function ($t) {
            $t->increments('id');
            $t->string('SchoolName')->nullable();
            $t->string('ShortCode')->nullable();
            $t->string('Logo')->nullable();
            $t->string('institute_type')->nullable();
            $t->string('syear')->nullable();
            $t->unsignedBigInteger('client_id')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('tblclient', function ($t) {
            $t->increments('id');
            $t->string('multischool')->nullable();
        });
        Schema::create('tbluserprofilemaster', function ($t) {
            $t->increments('id');
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->string('name')->nullable();
            $t->string('role_key')->nullable();
        });
        Schema::create('academic_year', function ($t) {
            $t->increments('id');
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->string('title')->nullable();
            $t->string('syear')->nullable();
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
        });
        Schema::create('tbluser', function ($t) {
            $t->increments('id');
            $t->string('user_name')->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('email')->nullable();
            $t->string('password')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->unsignedBigInteger('client_id')->nullable();
            $t->unsignedBigInteger('user_profile_id')->nullable();
            $t->unsignedInteger('is_admin')->default(0);
            $t->unsignedInteger('status')->default(1);
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('user_two_factor', function ($t) {
            $t->increments('id');
            $t->unsignedBigInteger('user_id');
            $t->timestamp('confirmed_at')->nullable();
        });
        Schema::create('personal_access_tokens', function ($t) {
            $t->id();
            $t->string('tokenable_type');
            $t->unsignedBigInteger('tokenable_id');
            $t->string('name');
            $t->string('token', 64)->unique();
            $t->text('abilities')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
        });

        // organisation 1 comes first in the table (the one the old fallback would have picked); organisation 2 is another tenant
        DB::table('school_setup')->insert([
            ['id' => 1, 'SchoolName' => 'First Organisation', 'ShortCode' => 'ONE', 'syear' => '2026', 'deleted_at' => null],
            ['id' => 2, 'SchoolName' => 'Second Organisation', 'ShortCode' => 'TWO', 'syear' => '2026', 'deleted_at' => null],
            ['id' => 3, 'SchoolName' => 'Deleted Organisation', 'ShortCode' => 'DEL', 'syear' => '2026', 'deleted_at' => '2026-01-01 00:00:00'],
        ]);
        DB::table('academic_year')->insert([
            ['sub_institute_id' => 1, 'title' => '2026', 'syear' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'],
            ['sub_institute_id' => 2, 'title' => '2026', 'syear' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'],
        ]);
        DB::table('tbluserprofilemaster')->insert([['id' => 1, 'sub_institute_id' => 2, 'name' => 'Employee', 'role_key' => 'employee']]);
        DB::table('tbluser')->insert([
            // 10: has organisation 2; 11: no organisation (null); 12: organisation 0; 13: organisation that does not exist; 14: organisation soft-deleted;
            // 15: has organisation 2 but is disabled (status 0); 16: has organisation 1; 17: organisation 2, soft-deleted account
            $this->user(10, 2), $this->user(11, null), $this->user(12, 0), $this->user(13, 99), $this->user(14, 3),
            $this->user(15, 2, ['status' => 0]), $this->user(16, 1), $this->user(17, 2, ['deleted_at' => '2026-02-01 00:00:00']),
        ]);

        // A route behind the real gate and a controller that uses the real trait: what any protected API does.
        $probe = function (Request $request) {
            $probe = new class {
                use ResolvesApiIdentity;

                public function identity(Request $r)
                {
                    return $this->resolveApiIdentity($r);
                }
            };
            $identity = $probe->identity($request);

            return $identity instanceof JsonResponse ? $identity : response()->json(['status' => 1, 'user_id' => $identity['user_id'], 'sub_institute_id' => $identity['sub_institute_id']]);
        };
        Route::middleware('api.token')->get('/api/zz-org-gate', $probe);
        Route::get('/api/zz-org-trait-only', $probe);   // no middleware: the trait alone decides
        Route::middleware('profile:employee')->get('/api/zz-org-profile', fn () => response()->json(['status' => 1]));
        Route::middleware('anyaccess:employee')->get('/api/zz-org-any', fn () => response()->json(['status' => 1]));
        Route::middleware('api.token')->get('/api/zz-org-token-only', fn () => response()->json(['status' => 1]));
    }

    private function user(int $id, ?int $org, array $over = []): array
    {
        return array_merge([
            'id' => $id, 'user_name' => "user{$id}", 'first_name' => "First{$id}", 'last_name' => "Last{$id}", 'email' => "user{$id}@example.test",
            'password' => Hash::make('secret-pass'), 'sub_institute_id' => $org, 'user_profile_id' => 1, 'is_admin' => 0, 'status' => 1, 'deleted_at' => null,
        ], $over);
    }

    private function token(int $userId): string
    {
        return tbluserModel::findOrFail($userId)->createToken('test')->plainTextToken;
    }

    private function bearer(int $userId): array
    {
        return ['Authorization' => 'Bearer ' . $this->token($userId)];
    }

    private function snapshotUsers(): array
    {
        return DB::table('tbluser')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    }

    private function signIn(int $userId)
    {
        return $this->getJson('/login?' . http_build_query(['email' => "user{$userId}@example.test", 'password' => 'secret-pass', 'type' => 'API']));
    }

    // ---- 1: a user with a valid organisation signs in ----

    public function test_a_user_with_a_valid_organisation_signs_in_and_gets_a_token(): void
    {
        $res = $this->signIn(10);

        $res->assertOk();
        $this->assertSame(1, $res->json('status'));
        $this->assertSame('User Successfully Login', $res->json('message'));
        $this->assertSame(2, $res->json('data.sub_institute_id'), 'with the account\'s own organisation');
        $this->assertStringNotContainsString(self::MESSAGE, $res->getContent());
        $this->assertSame(1, DB::table('personal_access_tokens')->where('tokenable_id', 10)->count(), 'and a token was issued');
    }

    // ---- 2, 3, 4: a user with no organisation is refused and nothing is assigned ----

    public function test_a_user_without_an_organisation_gets_400_and_the_exact_sentence_and_no_token(): void
    {
        foreach ([11 => 'null', 12 => 'zero', 13 => 'an id that is not an organisation', 14 => 'a deleted organisation'] as $id => $why) {
            $res = $this->signIn($id);

            $res->assertStatus(400);
            $this->assertSame(self::MESSAGE, $res->json('message'), "user {$id} ({$why})");
            $this->assertSame(0, $res->json('status'));
            $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $id)->count(), "no token for user {$id}");
        }
    }

    public function test_no_organisation_is_assigned_and_the_account_is_neither_deleted_nor_disabled(): void
    {
        $before = $this->snapshotUsers();

        foreach ([11, 12, 13, 14] as $id) {
            $this->signIn($id)->assertStatus(400);
        }
        // the per-request gates read the organisation id on the account (null or 0 is none); whether that organisation still exists is checked when signing in
        foreach ([11, 12] as $id) {
            $this->getJson('/api/zz-org-gate', $this->bearer($id))->assertStatus(400);
        }

        $this->assertSame($before, $this->snapshotUsers(), 'not one column of one account changed: nobody was given the first organisation, nobody was deleted or disabled');
        $this->assertNull(DB::table('tbluser')->where('id', 11)->value('sub_institute_id'), 'the first organisation was not written to the account');
    }

    // ---- 5: never the first organisation, whatever the request says ----

    public function test_an_account_with_no_organisation_is_refused_even_when_the_request_names_one(): void
    {
        $headers = $this->bearer(11);

        foreach ([
            '/api/zz-org-gate?sub_institute_id=1',
            '/api/zz-org-gate?sub_institute_id=2',
            '/api/zz-org-gate?organization_id=2',
            '/api/zz-org-gate?organisation_id=2&sub_institute_id=2&user_id=10',
        ] as $url) {
            $res = $this->getJson($url, $headers);
            $res->assertStatus(400);
            $this->assertSame(self::MESSAGE, $res->json('message'), $url);
        }
        $this->getJson('/api/zz-org-gate', $headers + ['sub_institute_id' => '2'])->assertStatus(400);
        // the trait on its own, with no middleware in front of it
        foreach (['', '?sub_institute_id=1', '?sub_institute_id=2', '?organization_id=2'] as $query) {
            $res = $this->getJson('/api/zz-org-trait-only' . $query, $headers);
            $res->assertStatus(400);
            $this->assertSame(self::MESSAGE, $res->json('message'), 'trait alone ' . $query);
        }
        $this->getJson('/api/zz-org-trait-only', $headers + ['sub_institute_id' => '1'])->assertStatus(400);
        $this->getJson('/api/zz-org-token-only?sub_institute_id=2', $headers)->assertStatus(400);
    }

    // ---- 7: an account with an organisation cannot reach another by naming it ----

    public function test_an_account_cannot_reach_another_organisation_by_naming_it(): void
    {
        $headers = $this->bearer(16);   // organisation 1

        foreach (['sub_institute_id=2', 'organization_id=2', 'organisation_id=2', 'sub_institute_id=2&user_id=10'] as $query) {
            $res = $this->getJson('/api/zz-org-gate?' . $query, $headers)->assertOk();
            $this->assertSame(1, $res->json('sub_institute_id'), $query . ': the organisation is the account\'s own');
            $this->assertSame(16, $res->json('user_id'), $query . ': and so is the user');
        }
        $res = $this->getJson('/api/zz-org-trait-only?sub_institute_id=2&organization_id=2', $headers)->assertOk();
        $this->assertSame(1, $res->json('sub_institute_id'), 'the trait on its own: the account\'s organisation, not the one named');
        $res = $this->getJson('/api/zz-org-gate', $headers + ['sub_institute_id' => '2'])->assertOk();
        $this->assertSame(1, $res->json('sub_institute_id'), 'the sub_institute_id header changes nothing');
        $this->assertSame(2, $this->getJson('/api/zz-org-gate', $this->bearer(10))->json('sub_institute_id'), 'and organisation 2\'s own user gets organisation 2');
    }

    // ---- the other gates ----

    public function test_the_token_gate_and_the_profile_gate_refuse_an_account_with_no_organisation_too(): void
    {
        $this->getJson('/api/zz-org-token-only', $this->bearer(11))->assertStatus(400)->assertJsonPath('message', self::MESSAGE);
        $this->getJson('/api/zz-org-token-only', $this->bearer(10))->assertOk();
        $this->getJson('/api/zz-org-profile', $this->bearer(11))->assertStatus(400)->assertJsonPath('message', self::MESSAGE);
        $this->getJson('/api/zz-org-profile', $this->bearer(10))->assertOk();
        $this->getJson('/api/zz-org-any', $this->bearer(11))->assertStatus(400)->assertJsonPath('message', self::MESSAGE);
        $this->getJson('/api/zz-org-any', $this->bearer(10))->assertOk();
    }

    public function test_every_sign_in_path_checks_the_organisation_before_it_makes_a_token(): void
    {
        // The Google sign-in verifies a Google token with a client built inside the method, so it cannot be driven here: its source is read instead.
        foreach (['app/Http/Controllers/auth/authController.php' => 'return $this->twoFactorChallenge', 'app/Http/Controllers/Api/GoogleAuthController.php' => 'return $this->buildLoginResponse'] as $file => $after) {
            $source = file_get_contents(base_path($file));
            $gate = strpos($source, 'UserOrganization::isValidFor(');
            $this->assertNotFalse($gate, "{$file} asks UserOrganization::isValidFor");
            $this->assertNotFalse(strpos($source, 'UserOrganization::refusal()'), "{$file} answers with UserOrganization::refusal()");
            $firstToken = strpos($source, 'createToken(');
            $this->assertTrue($gate < $firstToken, "{$file}: the organisation is checked before the first createToken");
        }
    }

    // ---- 6: an inactive account cannot use a protected API ----

    public function test_a_disabled_or_deleted_account_cannot_use_a_protected_api_with_a_token_it_still_holds(): void
    {
        // tokens made while the accounts were active, then the accounts are disabled / deleted straight in the table
        $disabled = $this->token(10);
        $deleted = $this->token(16);
        DB::table('tbluser')->where('id', 10)->update(['status' => 0]);
        DB::table('tbluser')->where('id', 16)->update(['deleted_at' => '2026-03-01 00:00:00']);

        foreach ([$disabled, $deleted] as $plain) {
            $this->getJson('/api/zz-org-gate', ['Authorization' => 'Bearer ' . $plain])->assertStatus(401);
            $this->getJson('/api/zz-org-token-only', ['Authorization' => 'Bearer ' . $plain])->assertStatus(401);
        }
        // accounts that were disabled or deleted before they ever had a token
        $this->getJson('/api/zz-org-gate', $this->bearer(15))->assertStatus(401);
        $this->getJson('/api/zz-org-gate', $this->bearer(17))->assertStatus(401);
        // and sign-in refuses them as before
        $this->signIn(15)->assertStatus(403);
    }

    // ---- the unit ----

    public function test_the_helper_says_what_a_valid_organisation_is(): void
    {
        $user = fn (int $id) => tbluserModel::findOrFail($id);

        $this->assertSame(2, UserOrganization::idOf($user(10)));
        $this->assertNull(UserOrganization::idOf($user(11)));
        $this->assertNull(UserOrganization::idOf($user(12)));
        $this->assertNull(UserOrganization::idOf(null));
        $this->assertTrue(UserOrganization::isValidFor($user(10)));
        $this->assertFalse(UserOrganization::isValidFor($user(11)));
        $this->assertFalse(UserOrganization::isValidFor($user(13)), 'an id that is not an organisation');
        $this->assertFalse(UserOrganization::isValidFor($user(14)), 'a deleted organisation');
        $this->assertSame(400, UserOrganization::refusal()->getStatusCode());
        $this->assertSame(self::MESSAGE, UserOrganization::MESSAGE);
    }
}
