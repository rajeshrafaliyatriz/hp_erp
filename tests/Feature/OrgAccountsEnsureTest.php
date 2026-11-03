<?php

namespace Tests\Feature;

use App\Domain\OrgAccounts\OrgAccount;
use App\Domain\OrgAccounts\OrgBackfill;
use App\Domain\OrgAccounts\OrgEnsure;
use App\Domain\OrgAccounts\OrgLinker;
use App\Domain\OrgAccounts\OrgResolver;
use App\Models\auth\tbluserModel;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsOrgAccountsWorld;
use Tests\TestCase;

/**
 * A CRM organisation always has an Organisation 360 identity (slice 3, folded into the Organizations screen):
 *   - OrgEnsure decides exactly what the backfill decides (an exact name or domain, one-to-one, places not contradicting, nothing rejected) and NEVER a fuzzy link;
 *   - POST /api/org-accounts/for-crm/{id} ensures and answers with the 360, idempotently, and is a 404 for another organisation's or a deleted CRM organisation;
 *   - POST /api/crm/organizations (the normal create path) ensures the new organisation's identity, and nothing that goes wrong there can fail the create.
 * Real migration files on in-memory sqlite.
 */
class OrgAccountsEnsureTest extends TestCase
{
    use BuildsOrgAccountsWorld;

    private const T = 1;

    private const T2 = 2;

    private const MARKETING = '/module/crm/marketing/organizations';

    private const P = '/api/org-accounts';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildOrgAccountsWorld();
        Schema::table('crm_organizations', function ($t) {
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->unsignedBigInteger('deleted_by')->nullable();
        });
        Schema::create('personal_access_tokens', function ($t) {
            $t->id();
            $t->morphs('tokenable');
            $t->text('name');
            $t->string('token', 64)->unique();
            $t->text('abilities')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
        });
        Schema::create('tbluserprofilemaster', function ($t) {
            $t->id();
            $t->string('name');
            $t->string('role_key')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->softDeletes();
        });
        Schema::create('tenant_setting', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->string('setting_key');
            $t->text('setting_value')->nullable();
            $t->timestamps();
        });
        Schema::create('tbluser', function ($t) {
            $t->id();
            $t->string('user_name')->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->unsignedBigInteger('user_profile_id')->nullable();
            $t->integer('status')->default(1);
        });
        Schema::create('tblmenumaster_g2g', function ($t) {
            $t->id();
            $t->string('menu_name');
            $t->unsignedBigInteger('parent_id')->default(0);
            $t->integer('level')->default(1);
            $t->string('access_link')->nullable();
            $t->integer('status')->default(1);
        });
        Schema::create('tblgroupwise_rights_g2g', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->unsignedBigInteger('menu_id');
            $t->unsignedBigInteger('profile_id');
            $t->tinyInteger('can_view')->default(0);
            $t->tinyInteger('can_add')->default(0);
            $t->tinyInteger('can_edit')->default(0);
            $t->tinyInteger('can_delete')->default(0);
            $t->string('right_view')->nullable();
            $t->string('right_add')->nullable();
            $t->string('right_edit')->nullable();
            $t->string('right_delete')->nullable();
        });
        Cache::flush();
        DB::table('tblmenumaster_g2g')->insert(['id' => 302, 'menu_name' => 'Organizations', 'access_link' => self::MARKETING]);
    }

    // ---- helpers ----

    private function ensure(int $crm, int $tenant = self::T): ?array
    {
        return (new OrgEnsure)->forCrmOrganization($tenant, $crm);
    }

    /** @return array{id: int, h: array<string, string>} a user of a tenant who may only VIEW and ADD organizations */
    private function user(int $tenant = self::T, array $actions = ['view', 'add']): array
    {
        $profile = DB::table('tbluserprofilemaster')->insertGetId(['name' => 'P' . uniqid(), 'role_key' => 'employee', 'sub_institute_id' => $tenant]);
        DB::table('tblgroupwise_rights_g2g')->insert(['sub_institute_id' => $tenant, 'menu_id' => 302, 'profile_id' => $profile]
            + array_combine(array_map(fn ($a) => "can_{$a}", $actions), array_fill(0, count($actions), 1)));
        $id = DB::table('tbluser')->insertGetId(['user_name' => 'u' . uniqid(), 'first_name' => 'Asha', 'last_name' => 'Rao', 'sub_institute_id' => $tenant, 'user_profile_id' => $profile, 'status' => 1]);

        return ['id' => $id, 'h' => ['Authorization' => 'Bearer ' . tbluserModel::find($id)->createToken('t')->plainTextToken]];
    }

    private function identityOfCrm(int $crm): ?OrgAccount
    {
        return OrgAccount::query()->where('crm_organization_id', $crm)->first();
    }

    // ---- the decision: exactly the backfill's, never fuzzier ----

    public function test_a_crm_organization_nobody_matches_gets_an_identity_of_its_own(): void
    {
        $crm = $this->crm('Lone Star School', self::T, ['website' => 'https://www.lonestar.example/about']);

        $out = $this->ensure($crm);

        $this->assertTrue($out['created']);
        $a = $out['account'];
        $this->assertSame(['Lone Star School', 'engaged', $crm, null, null, 'lonestar.example'], [$a->name, $a->lifecycle, $a->crm_organization_id, $a->gtm_account_id, $a->g2g_company_id, $a->domain]);
        $this->assertSame(1, OrgAccount::query()->count());
    }

    public function test_it_is_idempotent_the_second_call_returns_the_same_identity_and_changes_nothing(): void
    {
        $crm = $this->crm('Repeat School');
        $first = $this->ensure($crm);
        $before = DB::table('g2g_org_accounts')->get()->map(fn ($r) => (array) $r)->all();

        $second = $this->ensure($crm);

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame($first['account']->id, $second['account']->id);
        $this->assertSame($before, DB::table('g2g_org_accounts')->get()->map(fn ($r) => (array) $r)->all());
    }

    public function test_an_exact_name_joins_the_identity_of_the_gtm_account_and_raises_its_lifecycle_to_engaged(): void
    {
        $gtm = $this->gtm('Riverdale Public School');
        $company = $this->company('Riverdale Public School');
        (new OrgBackfill)->run(self::T, true);
        $identity = $this->identityByRow()["gtm_account:{$gtm}"];
        $this->assertSame('target', $identity['lifecycle']);

        $crm = $this->crm('Riverdale Public School');
        $out = $this->ensure($crm);

        $this->assertSame((int) $identity['id'], (int) $out['account']->id, 'it joined the existing identity, it did not make a second one');
        $a = $out['account']->fresh();
        $this->assertSame([$crm, $gtm, $company, 'exact_name', 'engaged'], [$a->crm_organization_id, $a->gtm_account_id, $a->g2g_company_id, $a->crm_link_basis, $a->lifecycle]);
        $this->assertSame(1, OrgAccount::query()->count());
    }

    public function test_an_exact_website_joins_even_when_the_names_differ(): void
    {
        $gtm = $this->gtm('Totally Different Name', self::T, ['domain' => 'acme-school.example']);
        (new OrgBackfill)->run(self::T, true);

        $crm = $this->crm('Acme Public School', self::T, ['website' => 'https://www.acme-school.example/']);
        $a = $this->ensure($crm)['account']->fresh();

        $this->assertSame([$gtm, 'exact_domain'], [$a->gtm_account_id, $a->crm_link_basis]);
        $this->assertSame(1, OrgAccount::query()->count());
    }

    public function test_a_name_that_is_only_alike_is_never_linked_it_stays_a_suggestion_for_a_person(): void
    {
        $company = $this->company('Delhi Public Academy Noida');
        (new OrgBackfill)->run(self::T, true);

        $crm = $this->crm('Delhi Public Academy Noida Extension');   // 4 of 5 words: a suggestion, not evidence
        $out = $this->ensure($crm);

        $this->assertNull($out['account']->g2g_company_id, 'no fuzzy link');
        $this->assertSame(2, OrgAccount::query()->count(), 'its own identity, beside the company\'s');
        $keys = array_column((new OrgResolver)->suggestions(self::T), 'key');
        $this->assertContains("crm_organization:{$crm}|g2g_company:{$company}", $keys, 'the pair is offered to a person');
    }

    public function test_an_ambiguous_name_two_candidates_is_not_linked(): void
    {
        $this->company('Twin School');
        $this->company('Twin School Pvt Ltd');   // normalises to the same name: two candidates for one CRM row
        (new OrgBackfill)->run(self::T, true);

        $crm = $this->crm('Twin School');
        $a = $this->ensure($crm)['account'];

        $this->assertNull($a->g2g_company_id);
        $this->assertSame(3, OrgAccount::query()->count());
    }

    public function test_the_same_name_in_two_different_places_is_not_linked(): void
    {
        $this->company('Omega Vidyalaya', self::T, ['location' => 'Mumbai', 'state' => 'Maharashtra']);
        (new OrgBackfill)->run(self::T, true);

        $crm = $this->crm('Omega Vidyalaya', self::T, ['billing_city' => 'Surat', 'billing_state' => 'Gujarat']);
        $a = $this->ensure($crm)['account'];

        $this->assertNull($a->g2g_company_id);
        $pair = (new OrgResolver)->suggestions(self::T)[0];
        $this->assertTrue($pair['place_conflict'], 'a person is shown the conflict');
    }

    public function test_a_pair_a_person_turned_down_is_not_linked(): void
    {
        $company = $this->company('Sigma School');
        (new OrgBackfill)->run(self::T, true);
        $crm = $this->crm('Sigma School');
        (new OrgLinker)->reject(self::T, 5, 'crm_organization', $crm, 'g2g_company', $company);

        $a = $this->ensure($crm)['account'];

        $this->assertNull($a->g2g_company_id);
    }

    public function test_a_customer_or_archived_identity_is_not_lowered_or_changed_when_the_crm_row_joins(): void
    {
        $gtm = $this->gtm('Kappa School');
        (new OrgBackfill)->run(self::T, true);
        OrgAccount::query()->where('gtm_account_id', $gtm)->update(['lifecycle' => 'archived']);

        $a = $this->ensure($this->crm('Kappa School'))['account']->fresh();

        $this->assertSame($gtm, $a->gtm_account_id);
        $this->assertSame('archived', $a->lifecycle, 'archived is a person\'s decision and nothing raises it');
    }

    public function test_it_writes_to_the_identity_table_only_never_to_a_source_table(): void
    {
        $this->gtm('Pi School');
        $this->company('Pi School');
        (new OrgBackfill)->run(self::T, true);
        $crm = $this->crm('Pi School');
        $before = $this->sourceChecksums();

        $this->ensure($crm);

        $this->assertSame($before, $this->sourceChecksums());
    }

    // ---- one organisation only ----

    public function test_another_organisations_or_a_deleted_or_a_missing_crm_organization_is_null_and_writes_nothing(): void
    {
        $theirs = $this->crm('Their School', self::T2);
        $deleted = $this->crm('Deleted School', self::T, ['deleted_at' => now()]);

        foreach ([$theirs, $deleted, 999999] as $id) {
            $this->assertNull($this->ensure($id), "crm organization {$id}");
        }
        $this->assertSame(0, OrgAccount::query()->count());
    }

    public function test_rows_of_another_organisation_are_never_used_to_match(): void
    {
        $this->gtm('Shared Name School', self::T2);
        $this->company('Shared Name School', self::T2);
        (new OrgBackfill)->run(self::T2, true);

        $a = $this->ensure($this->crm('Shared Name School', self::T))['account'];

        $this->assertNull($a->gtm_account_id);
        $this->assertNull($a->g2g_company_id);
        $this->assertSame(self::T, (int) $a->sub_institute_id);
    }

    public function test_without_the_identity_tables_it_answers_null_instead_of_failing(): void
    {
        $crm = $this->crm('No Tables School');
        Schema::drop('g2g_org_link_rejections');
        Schema::drop('g2g_org_accounts');

        $this->assertNull($this->ensure($crm));
    }

    public function test_a_lost_race_is_tried_once_more_and_a_second_loss_is_reported(): void
    {
        // The write of the identity fails the way a lost race does (the unique index of another request's identity), once or twice.
        $loseTimes = function (int $times) {
            $left = $times;
            DB::listen(function ($query) use (&$left) {
                if ($left > 0 && str_starts_with($query->sql, 'insert into "g2g_org_accounts"')) {
                    $left--;

                    throw new UniqueConstraintViolationException('sqlite', $query->sql, [], new \Exception('duplicate'));
                }
            });
        };

        $loseTimes(1);
        $out = $this->ensure($this->crm('Race School'));
        $this->assertTrue($out['created'], 'the first attempt lost, the second one made it');
        $this->assertSame(1, OrgAccount::query()->count());

        $loseTimes(2);
        $crm2 = $this->crm('Race School Two');
        $this->expectException(UniqueConstraintViolationException::class);
        $this->ensure($crm2);
    }

    // ---- the endpoint ----

    public function test_the_endpoint_ensures_and_answers_with_the_360_and_a_second_call_makes_nothing_new(): void
    {
        $this->company('Greenfield Academy', self::T, ['industry' => 'K-12']);
        (new OrgBackfill)->run(self::T, true);
        $crm = $this->crm('Greenfield Academy');
        $viewer = $this->user(self::T, ['view']);   // view alone is enough: it only records derived data for a record the caller may open

        $first = $this->postJson(self::P . "/for-crm/{$crm}", [], $viewer['h'])->assertOk();
        $second = $this->postJson(self::P . "/for-crm/{$crm}", [], $viewer['h'])->assertOk();

        $this->assertTrue($first->json('created'));
        $this->assertFalse($second->json('created'));
        $this->assertSame($first->json('data.identity.id'), $second->json('data.identity.id'));
        $this->assertSame(['id' => $crm, 'basis' => 'exact_name'], $first->json('data.identity.links.crm'));
        $this->assertSame('K-12', $first->json('data.company.industry'), 'the 360 of the joined identity');
        $this->assertSame(1, OrgAccount::query()->count());
    }

    public function test_the_endpoint_answers_404_alike_for_missing_deleted_and_another_organisations_crm_organization(): void
    {
        $viewer = $this->user();
        $theirs = $this->crm('Their School', self::T2);
        $deleted = $this->crm('Deleted School', self::T, ['deleted_at' => now()]);

        $missing = $this->postJson(self::P . '/for-crm/999999', [], $viewer['h'])->assertStatus(404);
        foreach ([$theirs, $deleted] as $id) {
            $res = $this->postJson(self::P . "/for-crm/{$id}", [], $viewer['h']);
            $this->assertSame(404, $res->getStatusCode(), "crm organization {$id}");
            $this->assertSame($missing->json(), $res->json());
        }
        $this->assertSame(0, OrgAccount::query()->count());
    }

    public function test_the_endpoint_is_a_503_not_a_crash_on_a_host_without_the_identity_tables(): void
    {
        $viewer = $this->user();
        $crm = $this->crm('Old Host School');
        Schema::drop('g2g_org_link_rejections');
        Schema::drop('g2g_org_accounts');

        $this->postJson(self::P . "/for-crm/{$crm}", [], $viewer['h'])->assertStatus(503);
    }

    public function test_every_route_answers_503_not_a_sql_error_on_a_host_without_the_identity_tables(): void
    {
        // The Organizations screen opens this API on every visit, so a host that has not run the schema migration must say "not set up" and never fail with SQL.
        $person = $this->user(self::T, ['view', 'add', 'edit']);
        $crm = $this->crm('Old Host School');
        Schema::drop('g2g_org_link_rejections');
        Schema::drop('g2g_org_accounts');

        $routes = [
            ['GET', self::P], ['GET', self::P . '?has_crm=0&per_page=1'], ['GET', self::P . '/suggestions'], ['GET', self::P . '/suggestions?identity_id=1'],
            ['GET', self::P . '/1'], ['PATCH', self::P . '/1', ['name' => 'X']],
            ['POST', self::P . '/links', ['left_type' => 'crm_organization', 'left_id' => $crm, 'right_type' => 'gtm_account', 'right_id' => 1, 'action' => 'reject']],
            ['POST', self::P . '/1/create-crm-organisation', []], ['POST', self::P . "/for-crm/{$crm}", []],
        ];
        foreach ($routes as $route) {
            [$method, $uri] = $route;
            $res = $this->json($method, $uri, $route[2] ?? [], $person['h']);
            $this->assertSame(503, $res->getStatusCode(), "{$method} {$uri}");
            $this->assertSame('Organisation 360 is not set up on this server yet.', $res->json('message'), "{$method} {$uri}");
        }
        $this->getJson(self::P . '/meta', $person['h'])->assertOk();   // the rights it reports do not need the tables
    }

    public function test_the_endpoint_answers_409_when_another_request_keeps_winning_the_race(): void
    {
        $viewer = $this->user();
        $crm = $this->crm('Busy School');
        $this->app->instance(OrgEnsure::class, new class
        {
            public function forCrmOrganization(int $tenantId, int $crmOrganizationId): ?array
            {
                throw new UniqueConstraintViolationException('sqlite', 'insert', [], new \Exception('duplicate'));
            }
        });

        $this->postJson(self::P . "/for-crm/{$crm}", [], $viewer['h'])->assertStatus(409)->assertJsonPath('code', 'identity_conflict');
    }

    // ---- the suggestions of one organisation ----

    public function test_suggestions_can_be_narrowed_to_the_pairs_of_one_organisation(): void
    {
        $viewer = $this->user();
        $crm = $this->crm('Alpha Prime Academy One Two');
        $co = $this->company('Alpha Prime Academy One Two Three');       // 5 of 6 words: a pair with the CRM row
        $this->gtm('Zeta Beta Gamma Delta Epsilon');
        $this->company('Zeta Beta Gamma Delta Epsilon Eta');             // a pair that has nothing to do with the CRM row
        (new OrgBackfill)->run(self::T, true);
        $identity = $this->identityOfCrm($crm)->id;

        $all = $this->getJson(self::P . '/suggestions', $viewer['h'])->assertOk()->json('data');
        $mine = $this->getJson(self::P . "/suggestions?identity_id={$identity}", $viewer['h'])->assertOk()->json('data');

        $this->assertCount(2, $all);
        $this->assertSame(["crm_organization:{$crm}|g2g_company:{$co}"], array_column($mine, 'key'));
    }

    // ---- the normal create path ----

    public function test_creating_a_crm_organization_through_the_normal_path_gives_it_an_identity(): void
    {
        $gtm = $this->gtm('Hilltop Public School');
        (new OrgBackfill)->run(self::T, true);
        $user = $this->user(self::T, ['view', 'add']);

        $res = $this->postJson('/api/crm/organizations', ['name' => 'Hilltop Public School', 'assignedTo' => $user['id']], $user['h'])->assertStatus(201);

        $crm = (int) $res->json('data.id');
        $a = $this->identityOfCrm($crm);
        $this->assertNotNull($a, 'the new organization has an identity at once');
        $this->assertSame([$gtm, 'exact_name', 'engaged'], [$a->gtm_account_id, $a->crm_link_basis, $a->lifecycle]);
    }

    public function test_nothing_that_goes_wrong_in_the_identity_step_can_fail_the_create(): void
    {
        $user = $this->user(self::T, ['view', 'add']);
        Log::spy();
        $this->app->instance(OrgEnsure::class, new class
        {
            public function forCrmOrganization(int $tenantId, int $crmOrganizationId): ?array
            {
                throw new \RuntimeException('the identity table is on fire');
            }
        });

        $res = $this->postJson('/api/crm/organizations', ['name' => 'Fragile School', 'assignedTo' => $user['id']], $user['h']);

        $res->assertStatus(201);
        $this->assertSame(1, DB::table('crm_organizations')->where('name', 'Fragile School')->count(), 'the organization exists');
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => str_contains($message, 'Organisation 360') && ($context['error'] ?? '') === 'the identity table is on fire')->once();
    }

    public function test_creating_on_a_host_without_the_identity_tables_still_works(): void
    {
        $user = $this->user(self::T, ['view', 'add']);
        Schema::drop('g2g_org_link_rejections');
        Schema::drop('g2g_org_accounts');

        $this->postJson('/api/crm/organizations', ['name' => 'Old Host School', 'assignedTo' => $user['id']], $user['h'])->assertStatus(201);
    }

    public function test_a_refused_create_makes_no_identity(): void
    {
        $user = $this->user(self::T, ['view', 'add']);
        $this->crm('Taken School');

        $this->postJson('/api/crm/organizations', ['name' => 'Taken School', 'assignedTo' => $user['id']], $user['h'])->assertStatus(422);

        $this->assertSame(0, OrgAccount::query()->count());
    }
}
