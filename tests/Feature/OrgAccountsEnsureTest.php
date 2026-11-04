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

    // ---- a GTM account made AFTER the identity (E2E finding B2, 4 Nov 2026) ----

    public function test_a_gtm_account_made_after_the_identity_is_attached_with_the_existing_link_basis(): void
    {
        $crm = $this->crm('Delta Public School');
        $first = $this->ensure($crm);   // Account 360 opened first: the identity is made, there is no GTM account yet
        $this->assertNull($first['account']->gtm_account_id);

        // What the opportunity sync does later: it makes the GTM account of the organisation, pointing at it.
        $gtm = $this->gtm('Delta Public School', self::T, ['crm_organization_id' => $crm]);
        $second = $this->ensure($crm);

        $this->assertFalse($second['created'], 'it is still the identity that was there');
        $this->assertSame((int) $first['account']->id, (int) $second['account']->id);
        $this->assertSame($gtm, $second['account']->gtm_account_id, 'the account it answers with already has the GTM pointer');
        $stored = OrgAccount::query()->find($first['account']->id);
        $this->assertSame([$crm, $gtm, 'existing_link'], [$stored->crm_organization_id, $stored->gtm_account_id, $stored->gtm_link_basis]);
        $this->assertSame(1, OrgAccount::query()->count(), 'no second identity');
    }

    public function test_once_attached_a_third_call_changes_nothing_and_no_source_table_is_written(): void
    {
        $crm = $this->crm('Echo Public School');
        $this->ensure($crm);
        $this->gtm('Echo Public School', self::T, ['crm_organization_id' => $crm]);
        $this->ensure($crm);
        $identities = $this->checksum('g2g_org_accounts');
        $sources = $this->sourceChecksums();

        $third = $this->ensure($crm);

        $this->assertFalse($third['created']);
        $this->assertSame($identities, $this->checksum('g2g_org_accounts'));
        $this->assertSame($sources, $this->sourceChecksums());
    }

    public function test_the_endpoint_shows_the_gtm_account_that_was_made_after_the_identity(): void
    {
        $crm = $this->crm('Foxtrot Public School');
        $viewer = $this->user(self::T, ['view']);
        $before = $this->postJson(self::P . "/for-crm/{$crm}", [], $viewer['h'])->assertOk();
        $this->assertNull($before->json('data.gtm'), 'no GTM account yet: "No GTM account found" is true at this moment');

        $gtm = $this->gtm('Foxtrot Public School', self::T, ['crm_organization_id' => $crm, 'stage' => 'target']);
        $after = $this->postJson(self::P . "/for-crm/{$crm}", [], $viewer['h'])->assertOk();

        $this->assertFalse($after->json('created'));
        $this->assertSame(['id' => $gtm, 'basis' => 'existing_link'], $after->json('data.identity.links.gtm'));
        $this->assertSame([$gtm, 'Foxtrot Public School'], [$after->json('data.gtm.id'), $after->json('data.gtm.name')], 'the 360 now has its GTM section');
        $this->assertSame(1, OrgAccount::query()->count());
    }

    public function test_a_gtm_account_that_is_not_this_organisations_link_is_never_attached(): void
    {
        $other = $this->crm('Other School');
        $cases = [];
        // another organisation's GTM account that happens to point at the same CRM id
        $cases['another organisation'] = [$this->crm('Golf School'), fn (int $crm) => $this->gtm('Golf School', self::T2, ['crm_organization_id' => $crm])];
        // a deleted GTM account
        $cases['deleted'] = [$this->crm('Hotel School'), fn (int $crm) => $this->gtm('Hotel School', self::T, ['crm_organization_id' => $crm, 'deleted_at' => now()])];
        // a GTM account that points at some other CRM organisation
        $cases['other crm organisation'] = [$this->crm('India School'), fn (int $crm) => $this->gtm('India School', self::T, ['crm_organization_id' => $other])];
        // the same name but no link: only an exact name at the FIRST view links, a later one stays a suggestion for a person
        $cases['same name, no link'] = [$this->crm('Juliet School'), fn (int $crm) => $this->gtm('Juliet School', self::T)];

        foreach ($cases as $label => [$crm, $makeGtm]) {
            $this->ensure($crm);
            $makeGtm($crm);
        }
        $before = $this->checksum('g2g_org_accounts');
        $sql = [];
        DB::listen(function ($query) use (&$sql) {
            $sql[] = $query->sql;
        });
        foreach ($cases as $label => [$crm]) {
            $out = $this->ensure($crm);
            $this->assertNull($out['account']->gtm_account_id, $label);
            $this->assertNull(OrgAccount::query()->where('crm_organization_id', $crm)->value('gtm_account_id'), $label . ' (stored)');
        }
        $this->assertSame($before, $this->checksum('g2g_org_accounts'), 'nothing was written');
        $this->assertSame([], $this->organisationReads($sql), 'none of these has a candidate, so the quick check ends it: the tenant-wide plan is not run');
    }

    /** @param list<string> $sql @return list<string> the statements that read the CRM organisations or the Signals companies (what the tenant-wide plan reads first) */
    private function organisationReads(array $sql): array
    {
        return array_values(array_filter($sql, fn (string $s) => str_contains($s, 'from "crm_organizations"') || str_contains($s, 'from "g2g_companies"')));
    }

    public function test_an_identity_that_simply_has_no_gtm_account_costs_one_quick_check_and_not_a_read_of_every_organisation(): void
    {
        $crm = $this->crm('Papa School');
        $this->ensure($crm);   // made; there is no GTM account and there never will be: most CRM organisations are like this
        $sql = [];
        DB::listen(function ($query) use (&$sql) {
            $sql[] = $query->sql;
        });

        $out = $this->ensure($crm);

        $this->assertFalse($out['created']);
        $this->assertNull($out['account']->gtm_account_id);
        $this->assertSame([], $this->organisationReads($sql));
        $this->assertNotSame([], array_filter($sql, fn (string $s) => str_contains($s, 'gtm_accounts')), 'the quick check itself did run');
    }

    public function test_a_gtm_account_another_identity_already_holds_is_not_taken_from_it(): void
    {
        $crm = $this->crm('Kilo School');
        $mine = $this->ensure($crm)['account'];
        $gtm = $this->gtm('Kilo Academy', self::T, ['crm_organization_id' => $crm]);
        $holder = OrgAccount::query()->create(['sub_institute_id' => self::T, 'name' => 'Kilo Academy', 'normalized_name' => 'kilo academy', 'lifecycle' => 'target', 'gtm_account_id' => $gtm, 'gtm_link_basis' => 'manual']);

        $sql = [];
        DB::listen(function ($query) use (&$sql) {
            $sql[] = $query->sql;
        });
        $out = $this->ensure($crm);

        $this->assertSame([], $this->organisationReads($sql), 'the account is held, so there is nothing to adopt and no plan is run');
        $this->assertNull($out['account']->gtm_account_id);
        $this->assertSame($gtm, OrgAccount::query()->find($holder->id)->gtm_account_id, 'the other identity keeps its account');
        $this->assertNull(OrgAccount::query()->find($mine->id)->gtm_account_id);
    }

    public function test_two_gtm_accounts_pointing_at_one_crm_organization_attach_neither(): void
    {
        $crm = $this->crm('Lima School');
        $this->ensure($crm);
        // Neither name equals the CRM name, so only the two links speak, and they contradict each other.
        $this->gtm('Lima Academy', self::T, ['crm_organization_id' => $crm]);
        $this->gtm('Lima Vidyalaya', self::T, ['crm_organization_id' => $crm]);

        $a = $this->ensure($crm)['account'];

        $this->assertNull($a->gtm_account_id, 'an organisation holds one GTM account: with two it is a question for a person, not a guess');
        $this->assertNull(OrgAccount::query()->find($a->id)->gtm_account_id);
    }

    public function test_an_identity_that_has_a_gtm_account_never_has_it_replaced(): void
    {
        $crm = $this->crm('Mike School');
        $first = $this->gtm('Mike School', self::T, ['crm_organization_id' => $crm]);
        $identity = $this->ensure($crm)['account'];
        $this->assertSame($first, $identity->gtm_account_id);

        $this->gtm('Mike School Newer', self::T, ['crm_organization_id' => $crm]);
        $sql = [];
        DB::listen(function ($query) use (&$sql) {
            $sql[] = $query->sql;
        });
        $out = $this->ensure($crm);

        $this->assertSame($first, $out['account']->gtm_account_id);
        $this->assertSame($first, OrgAccount::query()->find($identity->id)->gtm_account_id);
        $this->assertSame([], $this->organisationReads($sql), 'an identity that has its account is not even looked into');
    }

    public function test_a_gtm_account_held_by_a_deleted_identity_is_not_adopted_because_its_pointer_is_still_unique(): void
    {
        $crm = $this->crm('Oscar School');
        $this->ensure($crm);
        $gtm = $this->gtm('Oscar Academy', self::T, ['crm_organization_id' => $crm]);
        $gone = OrgAccount::query()->create(['sub_institute_id' => self::T, 'name' => 'Oscar Academy', 'normalized_name' => 'oscar academy', 'lifecycle' => 'target', 'gtm_account_id' => $gtm, 'gtm_link_basis' => 'manual']);
        $gone->delete();   // soft-deleted: the unique index (tenant, gtm_account_id) still holds the account
        Log::spy();
        $sql = [];
        DB::listen(function ($query) use (&$sql) {
            $sql[] = $query->sql;
        });

        $out = $this->ensure($crm);

        $this->assertNull($out['account']->gtm_account_id);
        $this->assertSame([], $this->organisationReads($sql), 'the quick check already knows the account is taken');
        Log::shouldNotHaveReceived('warning');
    }

    public function test_a_failure_while_attaching_is_logged_and_the_identity_is_answered_as_it_was(): void
    {
        $crm = $this->crm('Papa Academy');
        $this->ensure($crm);
        $this->gtm('Papa Academy', self::T, ['crm_organization_id' => $crm]);
        Log::spy();
        DB::listen(function ($query) {
            if (str_starts_with($query->sql, 'update "g2g_org_accounts"')) {
                throw new \RuntimeException('the identity table is on fire');
            }
        });

        $out = $this->ensure($crm);   // the tab still opens

        $this->assertFalse($out['created']);
        $this->assertNull($out['account']->gtm_account_id);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => str_contains($message, 'Organisation 360') && ($context['error'] ?? '') === 'the identity table is on fire' && ($context['crm_organization_id'] ?? null) === $crm)->once();
    }

    public function test_a_host_without_the_gtm_table_or_its_crm_link_column_still_answers_with_the_identity(): void
    {
        $crm = $this->crm('November School');
        $this->ensure($crm);

        // A host that has not run the migration that adds gtm_accounts.crm_organization_id.
        Schema::drop('gtm_accounts');
        Schema::create('gtm_accounts', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id');
            $t->string('name');
            $t->string('stage')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });
        DB::table('gtm_accounts')->insert(['sub_institute_id' => self::T, 'name' => 'November School', 'stage' => 'target']);
        $sql = [];
        DB::listen(function ($query) use (&$sql) {
            $sql[] = $query->sql;
        });
        $this->assertFalse($this->ensure($crm)['created'], 'no column: the identity as it was');
        // sqlite reads an unknown double-quoted column as a string and would not fail, MySQL would: so the proof is that the question is never asked.
        $this->assertSame([], array_values(array_filter($sql, fn (string $s) => str_contains($s, 'from "gtm_accounts"') && str_contains($s, 'crm_organization_id'))), 'the link column is not asked about where it does not exist');

        // A host without the table at all (MySQL would fail on SHOW COLUMNS of a table that is not there).
        Schema::drop('gtm_accounts');
        $sql = [];
        $out = $this->ensure($crm);

        $this->assertFalse($out['created']);
        $this->assertNull($out['account']->gtm_account_id);
        $this->assertSame([], array_values(array_filter($sql, fn (string $s) => str_contains($s, 'pragma_table') || str_contains($s, 'from "gtm_accounts"'))), 'once it is known to be missing, the table is not read and its columns are not listed');
    }

    public function test_an_identity_whose_crm_organization_was_deleted_since_is_answered_without_a_plan_or_a_warning(): void
    {
        $crm = $this->crm('Quebec Academy');
        $this->ensure($crm);
        $this->gtm('Quebec Academy', self::T, ['crm_organization_id' => $crm]);
        DB::table('crm_organizations')->where('id', $crm)->update(['deleted_at' => now()]);
        Log::spy();

        $out = $this->ensure($crm);   // the identity is found first, as it always was

        $this->assertFalse($out['created']);
        $this->assertNull($out['account']->gtm_account_id, 'a deleted organisation does not adopt anything');
        Log::shouldNotHaveReceived('warning');
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
