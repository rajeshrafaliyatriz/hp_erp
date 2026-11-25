<?php

namespace Tests\Security;

use App\Support\PersonalAccessTokenResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A-1 at the resolver itself: App\Support\PersonalAccessTokenResolver::findToken.
 *
 * Before the fix an unknown bearer string was PROVISIONED into a stored 30-day token owned by an administrator (or by a user/tenant the request named), a
 * string like "5|anything" authenticated as the owner of token row 5, and a missing table was CREATED from inside the request. Every test here runs the real
 * resolver against the in-memory fixtures of TokenTestCase and asserts the opposite, and none of them writes anything.
 */
class PersonalAccessTokenResolverTest extends TokenTestCase
{
    /** @return array<string, array{0: string}> */
    public static function unrecognisedStrings(): array
    {
        return [
            'arbitrary string' => ['definitely-not-a-token'],
            'a 64-hex string that is no stored hash' => [str_repeat('a', 64)],
            'id that exists, secret that does not (the removed `id = X OR token = hash` match)' => ['1|garbage'],
            'id that does not exist' => ['999|garbage'],
            'pipe only' => ['|'],
            'empty secret' => ['5|'],
            'empty id' => ['|x'],
            'non-numeric id' => ['abc|def'],
            'numeric prefix id (MySQL would cast it to 5)' => ['5abc|def'],
            'whitespace only' => ['     '],
            'empty string' => [''],
            'very long string' => [str_repeat('x', 100000)],
            'sql-shaped string' => ["1' OR '1'='1|x"],
        ];
    }

    #[DataProvider('unrecognisedStrings')]
    public function test_an_unrecognised_string_is_rejected_and_nothing_is_created(string $token): void
    {
        [$admin] = $this->twoTenants();
        $before = $this->snapshot();
        $this->startQueryLog();

        $this->assertNull(PersonalAccessTokenResolver::findToken($token));

        $this->assertSame($before, $this->snapshot(), 'no row was created or changed anywhere');
        $this->assertSame([], $this->writeStatements(), 'the lookup issued no write or DDL statement');
        $this->assertSame(2, $this->tokenRows(), 'still only the two real tokens');
        $this->assertSame(1, (int) $admin->id);
    }

    public function test_null_is_rejected(): void
    {
        $this->assertNull(PersonalAccessTokenResolver::findToken(null));
    }

    /** Test 6: with an active administrator available, an unknown string still does not fall back to them. */
    public function test_an_unknown_token_never_falls_back_to_an_administrator(): void
    {
        $this->profile(1, 1, 'administrator');
        $this->makeUser(1, 1, 1, ['is_admin' => 1, 'user_name' => 'adminuser']);
        // Even the request names that administrator's id and tenant, by every channel the removed code looked at.
        request()->merge(['user_id' => 1, 'sub_institute_id' => 1]);
        request()->headers->set('sub_institute_id', '1');

        $this->assertNull(PersonalAccessTokenResolver::findToken('unknown-string'));
        $this->assertNull(PersonalAccessTokenResolver::findToken('1|x'));
        $this->assertSame(0, $this->tokenRows(), 'no administrator session was minted');
    }

    /** Test 2/10: the right id with the WRONG secret (the removed code matched by id alone). */
    public function test_right_id_wrong_secret_is_rejected_and_wrong_id_right_secret_is_rejected(): void
    {
        [, , $adminToken, $otherToken] = $this->twoTenants();
        [$adminId, $adminSecret] = explode('|', $adminToken, 2);
        [$otherId, $otherSecret] = explode('|', $otherToken, 2);

        $this->assertNull(PersonalAccessTokenResolver::findToken($adminId . '|' . $otherSecret), 'token 1 with token 2\'s secret');
        $this->assertNull(PersonalAccessTokenResolver::findToken($otherId . '|' . $adminSecret), 'token 2 with token 1\'s secret');
        $this->assertNull(PersonalAccessTokenResolver::findToken($adminId . '|' . substr($adminSecret, 0, -1) . 'X'), 'one character off');
        $this->assertNull(PersonalAccessTokenResolver::findToken($adminId . '|garbage'));
    }

    public function test_an_expired_token_is_rejected_even_with_the_correct_secret(): void
    {
        $this->profile(1, 1, 'administrator');
        $user = $this->makeUser(1, 1, 1);
        $expired = $this->issueToken($user, 'Chrome on Windows', now()->subMinute());
        $valid = $this->issueToken($user, 'Chrome on Windows', now()->addDay());
        $noExpiry = $this->issueToken($user);

        $this->assertNull(PersonalAccessTokenResolver::findToken($expired));
        $this->assertNotNull(PersonalAccessTokenResolver::findToken($valid));
        $this->assertNotNull(PersonalAccessTokenResolver::findToken($noExpiry), 'a token without an expiry is still valid');
    }

    public function test_a_token_whose_owner_is_gone_is_rejected_and_is_not_healed_to_an_administrator(): void
    {
        [$admin, $other, $adminToken, $otherToken] = $this->twoTenants();
        DB::table('tbluser')->where('id', $other->id)->delete();   // the owner row disappears

        $this->assertNull(PersonalAccessTokenResolver::findToken($otherToken), 'an orphaned token is not an identity');
        $this->assertNotNull(PersonalAccessTokenResolver::findToken($adminToken), 'the administrator\'s own token is unaffected');
        $this->assertSame(2, $this->tokenRows(), 'the orphan was neither deleted nor re-bound');
        $row = DB::table('personal_access_tokens')->where('id', 2)->first();
        $this->assertSame(2, (int) $row->tokenable_id, 'still bound to its original (missing) owner, not to the administrator');
    }

    /** Test 9: the name the removed provisioning code gave every fabricated token. */
    public function test_a_token_named_session_token_is_refused_even_with_a_correct_secret(): void
    {
        $this->profile(1, 1, 'administrator');
        $user = $this->makeUser(1, 1, 1);
        $fabricated = $this->issueToken($user, PersonalAccessTokenResolver::PROVISIONED_TOKEN_NAME);

        $this->assertSame('Session Token', PersonalAccessTokenResolver::PROVISIONED_TOKEN_NAME);
        $this->assertNull(PersonalAccessTokenResolver::findToken($fabricated), 'rows the old code created stay in the database but cannot authenticate');
    }

    /** The refusal of the provisioning name is not defeated by case or padding. */
    #[DataProvider('provisionedNameVariants')]
    public function test_the_provisioning_name_is_refused_in_any_case_or_padding(string $name): void
    {
        $this->profile(1, 1, 'administrator');
        $user = $this->makeUser(1, 1, 1);

        $this->assertNull(PersonalAccessTokenResolver::findToken($this->issueToken($user, $name)), "[$name]");
    }

    /** @return array<string, array{0: string}> */
    public static function provisionedNameVariants(): array
    {
        return ['lower' => ['session token'], 'upper' => ['SESSION TOKEN'], 'padded' => ['  Session Token  '], 'mixed' => ['sEsSiOn ToKeN']];
    }

    /** A token whose owner is some OTHER model class is not an application identity here (every login issues its token on tbluserModel). */
    public function test_a_token_owned_by_a_different_model_class_is_refused(): void
    {
        $this->profile(1, 1, 'administrator');
        $this->makeUser(1, 1, 1, ['is_admin' => 1]);
        $foreign = ForeignOwnerModel::query()->findOrFail(1);
        $token = $foreign->createToken('Chrome on Windows', ['*'])->plainTextToken;

        $this->assertSame(ForeignOwnerModel::class, DB::table('personal_access_tokens')->value('tokenable_type'));
        $this->assertNull(PersonalAccessTokenResolver::findToken($token), 'owned by another class, even though it points at an existing tbluser row');
    }

    /** Test 8/9: legitimate, recognised tokens still resolve to their own owner, whatever the login named them. */
    #[DataProvider('legitimateNames')]
    public function test_a_recognised_token_authenticates_as_its_owner(string $name): void
    {
        [$admin, $other] = $this->twoTenants();
        $token = $this->issueToken($other, $name);

        $found = PersonalAccessTokenResolver::findToken($token);

        $this->assertNotNull($found);
        $this->assertSame($other->id, $found->tokenable->id);
        $this->assertSame(2, (int) $found->tokenable->sub_institute_id);
        $this->assertNotSame($admin->id, $found->tokenable->id);
    }

    /** @return array<string, array{0: string}> */
    public static function legitimateNames(): array
    {
        return [
            'browser login' => ['Chrome on Windows'],
            'script' => ['API or script'],
            'unrecognised browser' => ['Unknown browser'],
            'seed command' => ['seed-ai-demo'],
            'a name that merely contains the removed name' => ['My Session Token laptop'],
        ];
    }

    /** Sanctum also accepts the bare secret (no id prefix); that existing behaviour is preserved. */
    public function test_the_bare_secret_form_still_resolves_to_its_owner(): void
    {
        [, $other, , $otherToken] = $this->twoTenants();
        [, $secret] = explode('|', $otherToken, 2);

        $found = PersonalAccessTokenResolver::findToken($secret);

        $this->assertNotNull($found);
        $this->assertSame($other->id, $found->tokenable->id);
    }

    /** Test 10: tenant isolation — each token resolves only its own owner and tenant. */
    public function test_each_token_resolves_its_own_owner_and_tenant_only(): void
    {
        [$admin, $other, $adminToken, $otherToken] = $this->twoTenants();

        $a = PersonalAccessTokenResolver::findToken($adminToken);
        $b = PersonalAccessTokenResolver::findToken($otherToken);

        $this->assertSame([1, 1], [(int) $a->tokenable->id, (int) $a->tokenable->sub_institute_id]);
        $this->assertSame([2, 2], [(int) $b->tokenable->id, (int) $b->tokenable->sub_institute_id]);
    }

    /** Test 7 (resolver level): a missing table fails closed. Nothing is created, altered or written. */
    public function test_a_missing_tokens_table_fails_closed_and_is_never_created(): void
    {
        Schema::drop('personal_access_tokens');
        $this->startQueryLog();

        $this->assertNull(PersonalAccessTokenResolver::findToken('anything'));
        $this->assertNull(PersonalAccessTokenResolver::findToken('1|anything'));

        $this->assertSame([], $this->ddlStatements());
        $this->assertSame([], $this->writeStatements());
        $this->assertFalse(Schema::hasTable('personal_access_tokens'), 'the lookup did not create the table');
    }

    /** SECONDARY GUARD (source shape, not behaviour): the provisioning and DDL helpers no longer exist. */
    public function test_the_provisioning_and_ddl_methods_are_gone(): void
    {
        foreach (['ensureTable', 'resolveAdminUser', 'resolveOrProvision'] as $method) {
            $this->assertFalse(method_exists(PersonalAccessTokenResolver::class, $method), "$method must not exist");
        }
        $this->assertSame(['findToken'], array_values(array_map(
            static fn (\ReflectionMethod $m) => $m->getName(),
            (new \ReflectionClass(PersonalAccessTokenResolver::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
        )), 'the only public entry point is findToken');
    }

    public function test_a_lookup_failure_is_logged_without_leaking_anything_into_the_result(): void
    {
        Schema::drop('personal_access_tokens');   // the lookup throws inside Sanctum
        \Illuminate\Support\Facades\Log::shouldReceive('warning')->once()->withArgs(
            static fn ($message) => is_string($message)
                && ! str_contains($message, 'secret-looking-token-value')
                && ! str_contains($message, 'personal_access_tokens')
                && ! str_contains(strtolower($message), 'select'),
        );

        $this->assertNull(PersonalAccessTokenResolver::findToken('secret-looking-token-value'));
    }
}

/** A model class that is NOT App\Models\auth\tbluserModel but points at the same table: a token issued on it is "owned by some other model". */
class ForeignOwnerModel extends \Illuminate\Database\Eloquent\Model
{
    use \Laravel\Sanctum\HasApiTokens;

    protected $table = 'tbluser';
    public $timestamps = false;
}
