<?php

namespace Tests\Feature;

use App\Models\auth\tbluserModel;
use App\Support\PersonalAccessTokenResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The rule of PersonalAccessTokenResolver: a bearer string is a signed-in caller only when a token ISSUED for it exists and its owner still exists.
 *
 * Written when the first version of the class (commit 23f7da09 on main, 19 Nov 2026) was found to treat ANY string as a caller: it wrote a 30-day token row for an
 * unknown string and bound it to an administrator, to the `user_id` named in the request, or to the first user in tbluser, and it matched an existing row on its id alone.
 * Each test below fails on that version (the mutations of the track that fixed it are in docs/agentic-crm/_evidence/sync/token-resolver-fix-2026-11-23.md).
 */
class PersonalAccessTokenResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('tbluser', function ($t) {
            $t->increments('id');
            $t->string('user_name')->nullable();
            $t->string('first_name')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->unsignedInteger('is_admin')->default(0);
            $t->unsignedInteger('status')->default(1);
            $t->unsignedBigInteger('user_profile_id')->nullable();
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
        Schema::create('school_setup', function ($t) {
            $t->increments('id');
        });
        DB::table('school_setup')->insert(['id' => 1]);
        // user 1: an administrator of organisation 1; user 2: an ordinary employee of organisation 1; user 3: an administrator of organisation 2
        DB::table('tbluser')->insert([
            ['id' => 1, 'user_name' => 'admin_one', 'first_name' => 'AdminOne', 'sub_institute_id' => 1, 'is_admin' => 1],
            ['id' => 2, 'user_name' => 'emp_one', 'first_name' => 'EmployeeOne', 'sub_institute_id' => 1, 'is_admin' => 0],
            ['id' => 3, 'user_name' => 'admin_two', 'first_name' => 'AdminTwo', 'sub_institute_id' => 2, 'is_admin' => 1],
        ]);
    }

    private function request(array $input = []): void
    {
        $this->app->instance('request', Request::create('/api/anything', 'GET', $input));
    }

    private function tokens(): int
    {
        return DB::table('personal_access_tokens')->count();
    }

    private function issueFor(int $userId): string
    {
        return tbluserModel::findOrFail($userId)->createToken('test')->plainTextToken;
    }

    // ---- a string nobody issued is nobody ----

    public function test_a_string_nobody_issued_is_not_a_caller_and_writes_nothing(): void
    {
        $this->request();

        $this->assertNull(PersonalAccessTokenResolver::findToken('this-token-was-never-issued'));
        $this->assertNull(PersonalAccessTokenResolver::findToken('777|this-one-neither'));
        $this->assertNull(PersonalAccessTokenResolver::findToken('|'));
        $this->assertNull(PersonalAccessTokenResolver::findToken(''));
        $this->assertNull(PersonalAccessTokenResolver::findToken(null));
        $this->assertSame(0, $this->tokens(), 'no unknown string leaves a token row behind');
        $this->assertSame(0, DB::table('personal_access_tokens')->where('id', 777)->count(), 'and the caller cannot choose a row id');
    }

    public function test_nothing_in_the_request_can_make_an_unknown_string_somebody(): void
    {
        $this->request(['user_id' => 2]);
        $this->assertNull(PersonalAccessTokenResolver::findToken('made-up-string-one'), 'user_id in the request does not pick the owner');

        $this->request(['sub_institute_id' => 2]);
        $this->assertNull(PersonalAccessTokenResolver::findToken('made-up-string-two'), 'sub_institute_id in the request does not pick an organisation');

        $this->app->instance('request', Request::create('/api/anything', 'GET', [], [], [], ['HTTP_SUB_INSTITUTE_ID' => '2']));
        $this->assertNull(PersonalAccessTokenResolver::findToken('made-up-string-three'), 'neither does the sub_institute_id header');

        $this->assertSame(0, $this->tokens());
    }

    // ---- an issued token is its owner, and only its owner ----

    public function test_an_issued_token_resolves_to_its_own_user_whatever_the_request_says(): void
    {
        $plain = $this->issueFor(2);
        $this->assertSame(1, $this->tokens());

        $this->request(['user_id' => 1, 'sub_institute_id' => 2]);   // a request that tries to be somebody else
        $found = PersonalAccessTokenResolver::findToken($plain);

        $this->assertNotNull($found);
        $this->assertSame(2, (int) $found->tokenable->id, 'the owner of the token, not the user the request names');
        $this->assertSame(1, $this->tokens(), 'a lookup writes nothing');
    }

    public function test_a_real_id_with_a_wrong_secret_is_not_that_users_token(): void
    {
        $plain = $this->issueFor(1);
        [$id, $secret] = explode('|', $plain, 2);

        $this->request();
        $this->assertNull(PersonalAccessTokenResolver::findToken($id . '|not-the-secret'), 'the id alone is not enough');
        $this->assertNull(PersonalAccessTokenResolver::findToken($id . '|'), 'nor an empty secret');
        $this->assertNotNull(PersonalAccessTokenResolver::findToken($plain), 'the right secret behind the id works');
        $this->assertNotNull(PersonalAccessTokenResolver::findToken($secret), 'and so does the secret alone, as Sanctum has always allowed');
    }

    public function test_the_own_lookup_of_a_stored_row_needs_the_hash_and_the_id_to_agree(): void
    {
        // The lookup the class makes itself when Sanctum does not find a token. It is private, so it is called through reflection.
        $plain = $this->issueFor(1);
        [$id, $secret] = explode('|', $plain, 2);
        $lookup = new ReflectionMethod(PersonalAccessTokenResolver::class, 'storedRow');
        $lookup->setAccessible(true);

        $this->assertNotNull($lookup->invoke(null, $plain), 'id and secret agree');
        $this->assertNotNull($lookup->invoke(null, $secret), 'the secret alone');
        $this->assertNull($lookup->invoke(null, $id . '|wrong-secret'), 'a real id with a wrong secret matches nothing');
        $this->assertNull($lookup->invoke(null, '999|' . $secret), 'a right secret behind another id matches nothing');
        $this->assertNull($lookup->invoke(null, 'abc|' . $secret), 'a prefix that is not a row number is no token, as with Sanctum');
        $this->assertNull($lookup->invoke(null, $id . '|'), 'an empty secret matches nothing');
    }

    public function test_a_token_whose_user_is_gone_is_not_a_caller_and_is_not_handed_to_another_user(): void
    {
        $plain = $this->issueFor(2);
        DB::table('tbluser')->where('id', 2)->delete();

        $this->request();
        $this->assertNull(PersonalAccessTokenResolver::findToken($plain), 'no fallback to an administrator or to the first user');
    }

    // ---- the table check ----

    public function test_the_table_check_is_quiet_on_a_database_that_has_no_information_schema(): void
    {
        Log::spy();

        PersonalAccessTokenResolver::findToken('anything');

        Log::shouldNotHaveReceived('warning');
        $this->assertFalse(method_exists(PersonalAccessTokenResolver::class, 'ensureTable'), 'no runtime DDL helper exists');
    }
}
