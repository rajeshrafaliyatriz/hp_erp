<?php

namespace Tests\Feature\Intelligence;

use App\Domain\Intelligence\Access\IntelligenceCaller;
use App\Domain\Intelligence\Access\RightsChecker;
use App\Domain\Intelligence\DetectorRegistry;
use App\Http\Middleware\IntelligenceTokenAuthenticator;
use App\Http\Middleware\SanctumIntelligenceTokenAuthenticator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Intelligence\Fixtures\ApiTables;
use Tests\Intelligence\IntelligenceTestCase;

/**
 * M7. The REAL authenticator (Laravel\Sanctum's lookup, no fake bound) against a real `personal_access_tokens` table and a minimal `tbluser`, both in the
 * in-memory SQLite fixture. The point: an unrecognised token is refused and NOTHING is written (the existing resolver would have provisioned an administrator
 * token), and a valid token yields a caller built from the token owner's own row only.
 */
class IntelligenceRealAuthenticatorTest extends IntelligenceTestCase
{
    private const USER_MODEL = \App\Models\auth\tbluserModel::class;

    /** @var list<IntelligenceCaller> */
    private array $seen = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPeopleTables();
        ApiTables::createProfileTable();
        \App\Support\RoleKey::flushCache();
        Schema::create('personal_access_tokens', function (Blueprint $t) {
            $t->id();
            $t->morphs('tokenable');
            $t->string('name');
            $t->string('token', 64)->unique();
            $t->text('abilities')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
        });

        $this->db()->table('tbluserprofilemaster')->insert([
            ['id' => 1, 'sub_institute_id' => 1, 'name' => 'Admin', 'role_key' => 'administrator'],
            ['id' => 2, 'sub_institute_id' => 1, 'name' => 'Employee', 'role_key' => 'employee'],
        ]);
        $this->insertUser(10, 1, ['user_profile_id' => 2, 'department_id' => 5]);    // the employee who owns the valid token
        $this->insertUser(11, 1, ['user_profile_id' => 1]);                          // an administrator a provisioning resolver would bind to
        $this->insertUser(12, null, ['user_profile_id' => 1]);                       // tenant-less
        $this->insertUser(13, 0, ['user_profile_id' => 1]);                          // tenant 0

        // The REAL authenticator: assert nothing replaced it.
        $this->assertFalse($this->app->bound(IntelligenceTokenAuthenticator::class));
        $this->app->instance(RightsChecker::class, ApiTables::rights());

        $seen = &$this->seen;
        DetectorRegistry::overrideForTests(function () use (&$seen) {
            return [ApiTables::detector('hr.spy', 'hr', [ApiTables::insight('a')], null, function (IntelligenceCaller $c) use (&$seen) {
                $seen[] = $c;
            })];
        });
        config()->set('intelligence.enabled', true);
    }

    protected function tearDown(): void
    {
        DetectorRegistry::overrideForTests(null);
        parent::tearDown();
    }

    private function issue(string $plain, int $userId, array $override = []): array
    {
        $this->db()->table('personal_access_tokens')->insert(array_merge([
            'tokenable_type' => self::USER_MODEL,
            'tokenable_id' => $userId,
            'name' => 'login',
            'token' => hash('sha256', $plain),
            'abilities' => '["*"]',
            'expires_at' => null,
            'created_at' => '2026-11-01 00:00:00',
            'updated_at' => '2026-11-01 00:00:00',
        ], $override));

        return ['Authorization' => 'Bearer ' . $plain];
    }

    private function tokenRows(): int
    {
        return $this->db()->table('personal_access_tokens')->count();
    }

    private function usersSnapshot(): string
    {
        return json_encode($this->db()->table('tbluser')->orderBy('id')->get()->all());
    }

    public function test_the_authenticator_in_use_is_the_real_sanctum_one(): void
    {
        $middleware = $this->app->make(\App\Http\Middleware\IntelligenceIdentity::class);
        $property = (new \ReflectionClass($middleware))->getProperty('tokens');

        $this->assertInstanceOf(SanctumIntelligenceTokenAuthenticator::class, $property->getValue($middleware));
    }

    public function test_an_unknown_bearer_string_is_401_and_nothing_is_written(): void
    {
        $usersBefore = $this->usersSnapshot();

        // Even with request parameters that the provisioning resolver would have used to pick (and bind to) a user or a tenant.
        $this->getJson('/api/intelligence/insights?user_id=11&sub_institute_id=1&token=also-garbage', ['Authorization' => 'Bearer totally-unknown-token-value', 'sub_institute_id' => '1'])
            ->assertStatus(401)->assertJson(['status' => 0]);
        $this->getJson('/api/intelligence/overview', ['Authorization' => 'Bearer 7|another-made-up-token'])->assertStatus(401);

        $this->assertSame(0, $this->tokenRows(), 'no token may be provisioned for an unrecognised string');
        $this->assertSame($usersBefore, $this->usersSnapshot(), 'tbluser must be untouched');
        $this->assertSame([], $this->seen, 'no detector ran');
    }

    public function test_a_valid_token_resolves_the_caller_from_the_owners_own_row_only(): void
    {
        $headers = $this->issue('valid-employee-token', 10);

        $this->getJson('/api/intelligence/insights?sub_institute_id=2&user_id=11&role=administrator&scope=organization', $headers + ['sub_institute_id' => '2', 'user_id' => '11'])
            ->assertOk()->assertJsonPath('status', 1)->assertJsonPath('data.meta.scope', 'self');

        $this->assertCount(1, $this->seen);
        $caller = $this->seen[0];
        $this->assertSame(1, $caller->tenantId, 'the tenant of the token owner, not the requested one');
        $this->assertSame(10, $caller->userId, 'the token owner, not the requested user');
        $this->assertSame('employee', $caller->roleKey);
        $this->assertSame(5, $caller->departmentId);
        $this->assertSame(1, $this->tokenRows(), 'a valid lookup writes nothing either');
    }

    public function test_a_valid_token_with_the_id_prefix_form_also_works(): void
    {
        $this->issue('abc123', 10);
        $id = (int) $this->db()->table('personal_access_tokens')->value('id');

        $this->getJson('/api/intelligence/overview', ['Authorization' => "Bearer {$id}|abc123"])->assertOk();
        $this->getJson('/api/intelligence/overview', ['Authorization' => "Bearer {$id}|wrong-secret"])->assertStatus(401);
    }

    public function test_an_expired_token_is_401(): void
    {
        $headers = $this->issue('old-token', 10, ['expires_at' => '2020-01-01 00:00:00']);

        $this->getJson('/api/intelligence/insights', $headers)->assertStatus(401)->assertJson(['message' => 'Token expired']);
        $this->assertSame([], $this->seen);
    }

    public function test_an_unexpired_token_with_a_future_expiry_is_accepted(): void
    {
        $headers = $this->issue('future-token', 10, ['expires_at' => '2099-01-01 00:00:00']);

        $this->getJson('/api/intelligence/insights', $headers)->assertOk();
    }

    public function test_a_token_whose_owner_has_no_tenant_is_403_even_when_a_tenant_is_supplied(): void
    {
        foreach ([12 => 'tenantless-null', 13 => 'tenantless-zero'] as $userId => $plain) {
            $headers = $this->issue($plain, $userId);

            $this->getJson('/api/intelligence/insights', $headers)->assertStatus(403);
            $this->getJson('/api/intelligence/insights?sub_institute_id=1&tenant=1&user_id=10', $headers + ['sub_institute_id' => '1'])->assertStatus(403);
            $this->getJson('/api/intelligence/overview?sub_institute_id=1', $headers + ['sub_institute_id' => '1', 'tenant' => '1'])->assertStatus(403);
        }
        $this->assertSame([], $this->seen, 'a tenant-less owner is never rescued by the request');
    }

    public function test_a_token_whose_tokenable_is_not_the_user_model_is_401(): void
    {
        // Another model that does exist, with an id that resolves to a real row (school_setup id 10 below).
        $this->db()->table('school_setup')->insert(['id' => 10, 'SchoolName' => 'x']);
        $headers = $this->issue('wrong-model', 10, ['tokenable_type' => \App\Models\auth\schoolSetupModel::class]);
        $this->getJson('/api/intelligence/insights', $headers)->assertStatus(401);

        // A class that does not exist.
        $headers = $this->issue('no-such-class', 10, ['tokenable_type' => 'App\\Models\\Nope\\Missing']);
        $this->getJson('/api/intelligence/insights', $headers)->assertStatus(401);

        // An owner row that does not exist.
        $headers = $this->issue('no-owner', 9999);
        $this->getJson('/api/intelligence/insights', $headers)->assertStatus(401);

        $this->assertSame([], $this->seen);
    }

    public function test_inactive_owners_are_refused_with_a_real_token(): void
    {
        $this->insertUser(20, 1, ['user_profile_id' => 2, 'status' => 0]);
        $this->insertUser(21, 1, ['user_profile_id' => 2, 'terminated_date' => '2026-01-01']);

        foreach ([20 => 'inactive', 21 => 'terminated'] as $userId => $plain) {
            $this->getJson('/api/intelligence/insights', $this->issue($plain, $userId))->assertStatus(403);
        }
    }
}
