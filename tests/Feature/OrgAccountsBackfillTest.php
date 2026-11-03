<?php

namespace Tests\Feature;

use App\Domain\OrgAccounts\OrgAccount;
use App\Domain\OrgAccounts\OrgBackfill;
use App\Domain\OrgAccounts\OrgBackfillBlocked;
use App\Domain\OrgAccounts\OrgLinker;
use App\Domain\OrgAccounts\OrgResolver;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsOrgAccountsWorld;
use Tests\TestCase;

/**
 * `org-accounts:backfill` and the engine behind it (slice 3, Docs/agentic-crm/slices/S03-organisation-360.md, D2 and D9). The source tables and the
 * identity tables come from the real migration files on the in-memory sqlite database.
 */
class OrgAccountsBackfillTest extends TestCase
{
    use BuildsOrgAccountsWorld;

    private const A = 1;

    private const B = 2;

    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildOrgAccountsWorld();
        $this->scratch = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'org-backfill-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->scratch)) {
            foreach (glob($this->scratch . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->scratch);
        }
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function apply(int $tenant = self::A): array
    {
        return (new OrgBackfill)->run($tenant, true);
    }

    /** @return array<string, mixed> */
    private function dry(int $tenant = self::A): array
    {
        return (new OrgBackfill)->run($tenant, false);
    }

    /** @return array<string, mixed>|null the live identity holding this source row */
    private function holder(string $type, int $id, int $tenant = self::A): ?array
    {
        return $this->identityByRow($tenant)[$type . ':' . $id] ?? null;
    }

    private function identityCount(int $tenant = self::A): int
    {
        return OrgAccount::query()->where('sub_institute_id', $tenant)->count();
    }

    private function snapshot(string $table = 'g2g_org_accounts'): array
    {
        return DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    }

    // ================= the three kinds of evidence =================

    public function test_an_exact_name_finds_the_pair_that_has_no_domain(): void
    {
        $crm = $this->crm('Ryan International School');
        $company = $this->company('The Ryan International (Pvt.) Ltd.');

        $r = $this->apply();

        $this->assertSame(1, $this->identityCount());
        $identity = $this->holder('crm_organization', $crm);
        $this->assertSame($identity['id'], $this->holder('g2g_company', $company)['id'], 'one identity holds both');
        $this->assertSame('exact_name', $identity['crm_link_basis']);
        $this->assertSame('exact_name', $identity['company_link_basis']);
        $this->assertSame(['existing_link' => 0, 'exact_name' => 2, 'exact_domain' => 0], $r['linked']);
    }

    public function test_an_exact_domain_finds_the_pair_with_a_different_spelling(): void
    {
        $gtm = $this->gtm('Podar World School Maneja', self::A, ['domain' => 'podar.org']);
        $company = $this->company('Podar Group of Schools', self::A, ['website' => 'HTTPS://www.Podar.org/about/us?x=1']);

        $r = $this->apply();

        $identity = $this->holder('gtm_account', $gtm);
        $this->assertSame($identity['id'], $this->holder('g2g_company', $company)['id']);
        $this->assertSame('exact_domain', $identity['gtm_link_basis']);
        $this->assertSame('exact_domain', $identity['company_link_basis']);
        $this->assertSame('podar.org', $identity['domain']);
        $this->assertSame(['existing_link' => 0, 'exact_name' => 0, 'exact_domain' => 2], $r['linked']);
    }

    public function test_the_links_the_system_already_holds_win_and_are_recorded_as_existing_links(): void
    {
        $company = $this->company('Totally Different Legal Name');
        $crm = $this->crm('Another Name Again');
        $gtm = $this->gtm('Alpha Academy', self::A, ['company_id' => $company, 'crm_organization_id' => $crm]);

        $r = $this->apply();

        $identity = $this->holder('gtm_account', $gtm);
        $this->assertSame($identity['id'], $this->holder('g2g_company', $company)['id']);
        $this->assertSame($identity['id'], $this->holder('crm_organization', $crm)['id']);
        $this->assertSame(['existing_link', 'existing_link', 'existing_link'], [$identity['crm_link_basis'], $identity['gtm_link_basis'], $identity['company_link_basis']]);
        $this->assertSame(['existing_link' => 3, 'exact_name' => 0, 'exact_domain' => 0], $r['linked']);
        $this->assertSame('Another Name Again', $identity['name'], 'the CRM name is the one shown');
        $this->assertSame('engaged', $identity['lifecycle']);
    }

    public function test_an_existing_link_to_another_organisations_row_or_a_missing_row_is_ignored(): void
    {
        $foreignCompany = $this->company('Foreign Co', self::B);
        $foreignCrm = $this->crm('Foreign Org', self::B);
        $this->gtm('Alpha Academy', self::A, ['company_id' => $foreignCompany, 'crm_organization_id' => $foreignCrm]);
        $this->gtm('Beta Academy', self::A, ['company_id' => 99999]);

        $r = $this->apply();

        $this->assertSame(2, $this->identityCount(), 'only the two GTM accounts, each alone');
        $this->assertSame([null, null], OrgAccount::query()->where('sub_institute_id', self::A)->orderBy('id')->pluck('g2g_company_id')->all());
        $this->assertSame([null, null], OrgAccount::query()->where('sub_institute_id', self::A)->orderBy('id')->pluck('crm_organization_id')->all());
        $this->assertSame(['existing_link' => 0, 'exact_name' => 0, 'exact_domain' => 0], $r['linked']);
        $this->assertSame(0, $this->identityCount(self::B), 'nothing was made for the other organisation either');
    }

    public function test_an_exact_match_never_undoes_a_link_the_system_holds(): void
    {
        $other = $this->company('Unrelated Legal Name');
        $namesake = $this->company('Beta School');
        $gtm = $this->gtm('Beta School', self::A, ['company_id' => $other]);

        $r = $this->apply();

        $this->assertSame($this->holder('gtm_account', $gtm)['id'], $this->holder('g2g_company', $other)['id'], 'the existing link stands');
        $this->assertNotSame($this->holder('gtm_account', $gtm)['id'], $this->holder('g2g_company', $namesake)['id']);
        $this->assertSame(1, $r['same_source_conflict'], 'the namesake would have given the organisation a second company');
        $this->assertSame(2, $this->identityCount());
    }

    // ================= the guards =================

    public function test_two_companies_with_one_normalised_name_are_never_linked_and_become_suggestions(): void
    {
        $c1 = $this->company('Vibrant International Academy');
        $c2 = $this->company('VIBRANT INTERNATIONAL ACADEMY Pvt Ltd');
        $gtm = $this->gtm('Vibrant International Academy');

        $r = $this->apply();

        $this->assertSame(3, $this->identityCount(), 'nothing linked');
        $this->assertSame(2, $r['ambiguous'], 'the GTM account has two candidates: two pairs refused');
        $this->assertSame(['existing_link' => 0, 'exact_name' => 0, 'exact_domain' => 0], $r['linked']);
        $this->assertNotSame($this->holder('gtm_account', $gtm)['id'], $this->holder('g2g_company', $c1)['id']);

        $keys = array_column((new OrgResolver)->suggestions(self::A), 'score', 'key');
        $this->assertSame(1.0, $keys["g2g_company:{$c1}|g2g_company:{$c2}"], 'the duplicate inside the Signals companies is offered, score 1.0');
        $this->assertSame(1.0, $keys["g2g_company:{$c1}|gtm_account:{$gtm}"]);
        $this->assertSame(1.0, $keys["g2g_company:{$c2}|gtm_account:{$gtm}"]);
        $this->assertSame($r['suggestions'], count($keys));
    }

    public function test_a_near_match_is_never_linked_only_offered(): void
    {
        $gtm = $this->gtm('Delhi Public Academy Noida Extension');   // four of the five words: 0.8
        $company = $this->company('Delhi Public Academy Noida');
        $crm = $this->crm('Delhi Public Academy Noida Campus');       // 0.8 again

        $r = $this->apply();

        $this->assertSame(3, $this->identityCount(), 'alike is not the same: nothing is linked on a fuzzy score');
        $this->assertSame(['existing_link' => 0, 'exact_name' => 0, 'exact_domain' => 0], $r['linked']);
        $this->assertSame(0, $r['ambiguous']);
        $this->assertContains("g2g_company:{$company}|gtm_account:{$gtm}", array_column((new OrgResolver)->suggestions(self::A), 'key'));
        $this->assertContains("crm_organization:{$crm}|g2g_company:{$company}", array_column((new OrgResolver)->suggestions(self::A), 'key'));
    }

    public function test_the_same_name_in_a_different_place_is_not_linked_but_is_offered(): void
    {
        $gtm = $this->gtm('Kendriya Vidyalaya', self::A, ['location' => 'Surat, Gujarat']);
        $company = $this->company('Kendriya Vidyalaya', self::A, ['location' => 'Pune', 'state' => 'Maharashtra']);

        $r = $this->apply();

        $this->assertSame(2, $this->identityCount());
        $this->assertNotSame($this->holder('gtm_account', $gtm)['id'], $this->holder('g2g_company', $company)['id']);
        $this->assertSame(1, $r['place_conflict']);
        $this->assertSame(0, $r['ambiguous']);
        $suggestion = (new OrgResolver)->suggestions(self::A)[0];
        $this->assertSame(1.0, $suggestion['score']);
        $this->assertSame("g2g_company:{$company}|gtm_account:{$gtm}", $suggestion['key']);
    }

    public function test_the_same_name_with_one_side_having_no_place_is_linked(): void
    {
        $gtm = $this->gtm('Kendriya Vidyalaya', self::A, ['location' => null]);
        $company = $this->company('Kendriya Vidyalaya', self::A, ['location' => 'Pune', 'state' => 'Maharashtra']);

        $r = $this->apply();

        $this->assertSame($this->holder('gtm_account', $gtm)['id'], $this->holder('g2g_company', $company)['id']);
        $this->assertSame(0, $r['place_conflict']);
    }

    public function test_a_state_or_a_country_is_not_evidence_of_the_same_place(): void
    {
        // Two different cities in ONE state are two different schools that happen to share a name: no link, and it is counted.
        $g1 = $this->gtm('Alpha Vidya', self::A, ['location' => 'Surat, Gujarat']);
        $c1 = $this->company('Alpha Vidya', self::A, ['location' => 'Anand', 'state' => 'Gujarat']);
        // "India" on both sides says nothing about where.
        $g2 = $this->gtm('Beta Vidya', self::A, ['location' => 'Surat, India']);
        $c2 = $this->company('Beta Vidya', self::A, ['location' => 'Pune, India']);
        // The same city, one side with the state and one without: they agree.
        $g3 = $this->gtm('Gamma Vidya', self::A, ['location' => 'Surat, Gujarat']);
        $c3 = $this->company('Gamma Vidya', self::A, ['location' => 'Surat']);
        // A place that is only a state tells nothing about the city: it contradicts nothing.
        $g4 = $this->gtm('Delta Vidya', self::A, ['location' => 'Gujarat']);
        $c4 = $this->company('Delta Vidya', self::A, ['location' => 'Anand', 'state' => 'Gujarat']);
        // A city that is also a union territory still counts as a place.
        $g5 = $this->gtm('Epsilon Vidya', self::A, ['location' => 'Delhi']);
        $c5 = $this->company('Epsilon Vidya', self::A, ['location' => 'Chandigarh']);

        $r = $this->apply();

        $this->assertNotSame($this->holder('gtm_account', $g1)['id'], $this->holder('g2g_company', $c1)['id'], 'two cities in one state');
        $this->assertNotSame($this->holder('gtm_account', $g2)['id'], $this->holder('g2g_company', $c2)['id']);
        $this->assertSame($this->holder('gtm_account', $g3)['id'], $this->holder('g2g_company', $c3)['id'], 'same city');
        $this->assertSame($this->holder('gtm_account', $g4)['id'], $this->holder('g2g_company', $c4)['id'], 'a state alone contradicts nothing');
        $this->assertNotSame($this->holder('gtm_account', $g5)['id'], $this->holder('g2g_company', $c5)['id'], 'Delhi is not Chandigarh');
        $this->assertSame(3, $r['place_conflict']);
    }

    public function test_rows_that_link_to_each_other_in_a_circle_of_evidence_stay_apart(): void
    {
        // c1~g by name, g~k by domain, k~c2 by name: following it would give one organisation two Signals companies.
        $c1 = $this->company('Alpha School');
        $g = $this->gtm('Alpha School', self::A, ['domain' => 'gamma.org']);
        $k = $this->crm('Gamma Academy', self::A, ['website' => 'https://gamma.org']);
        $c2 = $this->company('Gamma Academy');

        $r = $this->apply();

        $this->assertSame(1, $r['same_source_conflict']);
        $this->assertSame(4, $this->identityCount(), 'four identities of their own');
        $this->assertSame(['existing_link' => 0, 'exact_name' => 0, 'exact_domain' => 0], $r['linked']);
        $holders = [$this->holder('g2g_company', $c1)['id'], $this->holder('gtm_account', $g)['id'], $this->holder('crm_organization', $k)['id'], $this->holder('g2g_company', $c2)['id']];
        $this->assertCount(4, array_unique($holders));
    }

    public function test_a_shared_host_or_an_empty_name_is_never_evidence(): void
    {
        $this->gtm('Alpha Academy', self::A, ['website' => 'https://www.facebook.com/alpha.academy']);
        $this->company('Zeta College', self::A, ['website' => 'facebook.com/zeta']);
        $this->crm('The School');
        $this->gtm('School Pvt Ltd');

        $r = $this->apply();

        $this->assertSame(4, $this->identityCount());
        $this->assertSame(['existing_link' => 0, 'exact_name' => 0, 'exact_domain' => 0], $r['linked']);
        $this->assertSame(0, $r['ambiguous']);
        $names = OrgAccount::query()->where('sub_institute_id', self::A)->pluck('normalized_name')->all();
        $this->assertContains('', $names, 'a name made only of stop words still gets an identity, with an empty comparable name');
        $this->assertNull(OrgAccount::query()->where('name', 'Alpha Academy')->first()->domain, 'a Facebook page is not the organisation\'s domain');
    }

    public function test_a_rejected_pair_is_not_linked_by_the_backfill(): void
    {
        $gtm = $this->gtm('Alpha Academy');
        $company = $this->company('Alpha Academy');
        (new OrgLinker)->reject(self::A, 5, 'gtm_account', $gtm, 'g2g_company', $company);

        $r = $this->apply();

        $this->assertSame(2, $this->identityCount());
        $this->assertSame(['existing_link' => 0, 'exact_name' => 0, 'exact_domain' => 0], $r['linked']);
        $this->assertSame(0, $r['ambiguous'], 'a pair a person turned down is not a candidate');
        $this->assertSame([], (new OrgResolver)->suggestions(self::A), 'and is not offered again');
    }

    public function test_a_non_latin_name_matches_itself_and_nothing_else(): void
    {
        $crm = $this->crm('ગુજરાત પબ્લિક સ્કૂલ');
        $company = $this->company('સ્કૂલ ગુજરાત પબ્લિક');
        $hindi = $this->company('दिल्ली पब्लिक स्कूल');

        $this->apply();

        $this->assertSame($this->holder('crm_organization', $crm)['id'], $this->holder('g2g_company', $company)['id']);
        $this->assertNotSame($this->holder('crm_organization', $crm)['id'], $this->holder('g2g_company', $hindi)['id']);
        $this->assertSame(2, $this->identityCount());
        $this->assertSame('ગુજરાત પબ્લિક સ્કૂલ', $this->holder('crm_organization', $crm)['name']);
    }

    // ================= scope: deleted rows and other organisations =================

    public function test_soft_deleted_source_rows_are_ignored(): void
    {
        $this->crm('Alpha Academy', self::A, ['deleted_at' => now()]);
        $gtm = $this->gtm('Alpha Academy');
        $this->gtm('Gone Academy', self::A, ['deleted_at' => now()]);

        $r = $this->apply();

        $this->assertSame(1, $this->identityCount());
        $this->assertNull($this->holder('gtm_account', $gtm)['crm_organization_id'], 'not linked to a deleted CRM organisation');
        $this->assertSame(['crm_organization' => 0, 'gtm_account' => 1, 'g2g_company' => 0], $r['rows']);
        $this->assertSame('target', $this->holder('gtm_account', $gtm)['lifecycle']);
    }

    public function test_a_companies_table_that_has_a_deleted_at_column_is_honoured(): void
    {
        // The real g2g_companies has no deleted_at; another host might. The read adapts to the columns that are there.
        Schema::table('g2g_companies', fn ($t) => $t->timestamp('deleted_at')->nullable());
        $this->company('Alpha Academy', self::A, ['deleted_at' => now()]);
        $gtm = $this->gtm('Alpha Academy');

        $r = $this->apply();

        $this->assertSame(1, $this->identityCount());
        $this->assertNull($this->holder('gtm_account', $gtm)['g2g_company_id']);
        $this->assertSame(0, $r['rows']['g2g_company']);
    }

    public function test_another_organisations_rows_are_never_linked_to_this_ones(): void
    {
        $crm = $this->crm('Acme Academy', self::A);
        $gtmB = $this->gtm('Acme Academy', self::B);
        $companyB = $this->company('Acme Academy', self::B);

        $this->apply(self::A);

        $this->assertSame(1, $this->identityCount(self::A));
        $this->assertSame(0, $this->identityCount(self::B), 'organisation 2 was not touched by organisation 1\'s run');
        $this->assertNull($this->holder('crm_organization', $crm)['gtm_account_id']);

        $this->apply(self::B);

        $this->assertSame($this->holder('gtm_account', $gtmB, self::B)['id'], $this->holder('g2g_company', $companyB, self::B)['id']);
        $this->assertSame(1, $this->identityCount(self::A), 'and organisation 1 was not touched by organisation 2\'s');
        $this->assertSame(1, OrgAccount::query()->where('sub_institute_id', self::B)->count());
        $this->assertSame([self::B], OrgAccount::query()->where('crm_organization_id', null)->pluck('sub_institute_id')->unique()->values()->all());
    }

    // ================= what an identity says =================

    public function test_name_domain_and_lifecycle_come_from_the_rows_in_priority_order(): void
    {
        $company = $this->company('Delta Learning Pvt Ltd', self::A, ['website' => 'delta.example.com']);
        $gtm = $this->gtm('Delta Learning', self::A, ['company_id' => $company]);
        $crmOnly = $this->crm('Solo CRM');
        $companyOnly = $this->company('Solo Company');

        $this->apply();

        $pair = $this->holder('gtm_account', $gtm);
        $this->assertSame('Delta Learning', $pair['name'], 'GTM before company');
        $this->assertSame('delta learning', $pair['normalized_name']);
        $this->assertSame('delta.example.com', $pair['domain'], 'the first domain any of its rows has');
        $this->assertSame('target', $pair['lifecycle']);
        $this->assertSame('engaged', $this->holder('crm_organization', $crmOnly)['lifecycle']);
        $this->assertSame('discovered', $this->holder('g2g_company', $companyOnly)['lifecycle']);
        $this->assertNull($this->holder('g2g_company', $companyOnly)['company_link_basis'], 'a row linked to nothing has no basis');
    }

    // ================= the invariants =================

    /** One of everything, linked and unlinked, in two organisations. */
    private function seedWorld(): void
    {
        $this->crm('Ryan International School', self::A);
        $this->company('The Ryan International', self::A);
        $this->gtm('Podar World', self::A, ['domain' => 'podar.org']);
        $this->company('Podar Group', self::A, ['website' => 'www.podar.org']);
        $c = $this->company('Legacy Name Ltd', self::A);
        $this->gtm('Modern Name', self::A, ['company_id' => $c]);
        $this->company('Vibrant Academy', self::A);
        $this->company('Vibrant Academy Pvt', self::A);
        $this->gtm('Vibrant Academy', self::A);
        $this->gtm('Kendriya Vidyalaya', self::A, ['location' => 'Surat']);
        $this->company('Kendriya Vidyalaya', self::A, ['location' => 'Pune', 'state' => 'Maharashtra']);
        $this->crm('Lonely Org', self::A);
        $this->crm('Gone Org', self::A, ['deleted_at' => now()]);
        $this->crm('Other Tenant Org', self::B);
        $this->company('Other Tenant Org', self::B);
    }

    public function test_every_live_source_row_ends_up_in_exactly_one_identity_and_no_identity_is_empty(): void
    {
        $this->seedWorld();
        $this->apply();

        $holders = [];
        foreach (OrgAccount::query()->where('sub_institute_id', self::A)->get() as $identity) {
            $pointers = array_filter(array_intersect_key($identity->getAttributes(), array_flip(OrgAccount::POINTERS)), fn ($v) => $v !== null);
            $this->assertNotEmpty($pointers, "identity {$identity->id} has no pointer");
            foreach (OrgAccount::POINTERS as $type => $column) {
                if ($identity->{$column} !== null) {
                    $key = $type . ':' . $identity->{$column};
                    $this->assertArrayNotHasKey($key, $holders, "{$key} is in two identities");
                    $holders[$key] = $identity->id;
                }
            }
        }
        $live = [];
        foreach (DB::table('crm_organizations')->where('sub_institute_id', self::A)->whereNull('deleted_at')->pluck('id') as $id) {
            $live[] = 'crm_organization:' . $id;
        }
        foreach (DB::table('gtm_accounts')->where('sub_institute_id', self::A)->whereNull('deleted_at')->pluck('id') as $id) {
            $live[] = 'gtm_account:' . $id;
        }
        foreach (DB::table('g2g_companies')->where('sub_institute_id', self::A)->pluck('id') as $id) {   // this table has no deleted_at
            $live[] = 'g2g_company:' . $id;
        }
        $this->assertCount(12, $live, 'the fixture has twelve live rows in organisation 1');
        sort($live);
        $held = array_keys($holders);
        sort($held);
        $this->assertSame($live, $held, 'every live row of the organisation is in an identity, and nothing else is');
    }

    public function test_a_second_run_changes_nothing(): void
    {
        $this->seedWorld();
        $first = $this->apply();
        $after = $this->snapshot();

        $second = $this->apply();

        $this->assertSame($after, $this->snapshot(), 'no new row and no changed row, updated_at included');
        $this->assertSame(0, $second['identities']);
        $this->assertSame(0, $second['extended']);
        $this->assertSame(0, $second['written']);
        $this->assertSame(['existing_link' => 0, 'exact_name' => 0, 'exact_domain' => 0], $second['linked']);
        $this->assertSame($first['rows'][OrgAccount::SOURCE_CRM] + $first['rows'][OrgAccount::SOURCE_GTM] + $first['rows'][OrgAccount::SOURCE_COMPANY], $second['left_alone'], 'every row was already in an identity');
        $this->assertSame($first['suggestions'], $second['suggestions']);
    }

    public function test_a_dry_run_writes_nothing_and_reports_what_apply_then_does(): void
    {
        $this->seedWorld();
        $sources = $this->sourceChecksums();

        $dry = $this->dry();

        $this->assertSame(0, DB::table('g2g_org_accounts')->count(), 'a dry run writes nothing');
        $this->assertSame($sources, $this->sourceChecksums());
        $this->assertFalse($dry['apply']);
        $this->assertSame(0, $dry['written']);

        $applied = $this->apply();

        $this->assertSame($dry['identities'], $applied['identities']);
        $this->assertSame($dry['identities'], $this->identityCount(), 'the dry run predicted the number of identities');
        foreach (['rows', 'linked', 'ambiguous', 'place_conflict', 'same_source_conflict', 'identity_conflict', 'left_alone', 'suggestions'] as $figure) {
            $this->assertSame($dry[$figure], $applied[$figure], "{$figure}: dry run and apply agree");
        }
    }

    public function test_apply_never_writes_to_a_source_table(): void
    {
        $this->seedWorld();
        $linked = $this->company('Legacy Two');
        $this->gtm('Modern Two', self::A, ['company_id' => $linked]);
        $before = $this->sourceChecksums();

        $this->apply(self::A);
        $this->apply(self::B);
        $this->apply(self::A);

        $this->assertSame($before, $this->sourceChecksums(), 'crm_organizations, gtm_accounts and g2g_companies are byte for byte as they were');
        $this->assertGreaterThan(0, DB::table('g2g_org_accounts')->count());
    }

    // ================= a re-run only adds =================

    public function test_a_later_run_adds_a_missing_record_to_an_existing_identity_and_changes_nothing_else(): void
    {
        $company = $this->company('Echo Academy');
        $this->apply();
        $identity = $this->holder('g2g_company', $company);
        $this->assertSame('discovered', $identity['lifecycle']);
        DB::table('g2g_org_accounts')->where('id', $identity['id'])->update(['name' => 'Echo (renamed by a person)', 'owner_user_id' => 7]);

        $crm = $this->crm('Echo Academy', self::A, ['website' => 'https://echo.example.org']);
        $r = $this->apply();

        $after = $this->holder('crm_organization', $crm);
        $this->assertSame($identity['id'], $after['id'], 'the new CRM row joined the existing identity');
        $this->assertSame($company, $after['g2g_company_id'], 'the pointer that was there is untouched');
        $this->assertSame('exact_name', $after['crm_link_basis']);
        $this->assertSame('Echo (renamed by a person)', $after['name'], 'a name that exists is never overwritten');
        $this->assertSame('discovered', $after['lifecycle'], 'neither is a lifecycle');
        $this->assertSame(7, (int) $after['owner_user_id']);
        $this->assertSame('echo.example.org', $after['domain'], 'an empty domain may be filled');
        $this->assertSame(1, $this->identityCount());
        $this->assertSame(0, $r['identities']);
        $this->assertSame(1, $r['extended']);
    }

    public function test_an_existing_pointer_is_never_replaced_even_by_a_better_looking_row(): void
    {
        $company = $this->company('Zeta Academy');
        $first = $this->gtm('A Different GTM Name');
        $identity = OrgAccount::query()->create([
            'sub_institute_id' => self::A, 'name' => 'Zeta (by hand)', 'normalized_name' => 'zeta', 'lifecycle' => 'customer',
            'g2g_company_id' => $company, 'gtm_account_id' => $first, 'company_link_basis' => 'manual', 'gtm_link_basis' => 'manual',
        ]);
        $namesake = $this->gtm('Zeta Academy');

        $r = $this->apply();

        $identity->refresh();
        $this->assertSame($first, $identity->gtm_account_id, 'the GTM pointer a person set stays');
        $this->assertSame('manual', $identity->gtm_link_basis);
        $this->assertSame('customer', $identity->lifecycle);
        $this->assertSame('Zeta (by hand)', $identity->name);
        $this->assertSame(1, $r['same_source_conflict']);
        $this->assertNotSame($identity->id, $this->holder('gtm_account', $namesake)['id'], 'the namesake got an identity of its own');
    }

    public function test_a_pointer_whose_row_is_gone_still_holds_its_slot(): void
    {
        $company = $this->company('Eta Academy');
        $identity = OrgAccount::query()->create([
            'sub_institute_id' => self::A, 'name' => 'Eta', 'normalized_name' => 'academy eta', 'lifecycle' => 'target',
            'g2g_company_id' => $company, 'gtm_account_id' => 99999, 'company_link_basis' => 'manual', 'gtm_link_basis' => 'manual',
        ]);
        $this->gtm('Eta Academy');

        $r = $this->apply();

        $this->assertSame(99999, $identity->fresh()->gtm_account_id);
        $this->assertSame(1, $r['same_source_conflict']);
    }

    public function test_two_existing_identities_are_never_merged_by_the_backfill_but_the_pair_is_offered(): void
    {
        $gtm = $this->gtm('Theta Academy');
        $company = $this->company('Theta Academy');
        $x = OrgAccount::query()->create(['sub_institute_id' => self::A, 'name' => 'Theta', 'normalized_name' => 'academy theta', 'lifecycle' => 'target', 'gtm_account_id' => $gtm]);
        $y = OrgAccount::query()->create(['sub_institute_id' => self::A, 'name' => 'Theta', 'normalized_name' => 'academy theta', 'lifecycle' => 'discovered', 'g2g_company_id' => $company]);

        $r = $this->apply();

        $this->assertSame(1, $r['identity_conflict']);
        $this->assertSame(2, $this->identityCount());
        $this->assertSame([$x->id, $y->id], OrgAccount::query()->orderBy('id')->pluck('id')->all());
        $this->assertSame(["g2g_company:{$company}|gtm_account:{$gtm}"], array_column((new OrgResolver)->suggestions(self::A), 'key'));
    }

    // ================= the command =================

    public function test_the_command_is_a_dry_run_by_default_and_prints_the_counts(): void
    {
        $this->seedWorld();
        $before = $this->snapshot();

        $this->artisan('org-accounts:backfill')
            ->expectsOutputToContain('DRY RUN')->expectsOutputToContain('Organisation 1')->expectsOutputToContain('Organisation 2')
            ->expectsOutputToContain('identities to create')->expectsOutputToContain('exact_name')->expectsOutputToContain('suggestions pending')
            ->expectsOutputToContain('Dry run: nothing was written')->assertExitCode(0);

        $this->assertSame($before, $this->snapshot());
    }

    public function test_the_command_refuses_bad_input_and_writes_nothing(): void
    {
        $this->seedWorld();

        $this->artisan('org-accounts:backfill', ['--apply' => true])->assertExitCode(2);
        $this->artisan('org-accounts:backfill', ['--tenant' => 'x', '--apply' => true])->assertExitCode(2);
        $this->artisan('org-accounts:backfill', ['--tenant' => '0', '--apply' => true])->assertExitCode(2);
        $this->artisan('org-accounts:backfill', ['--tenant' => self::A, '--database' => 'no_such_connection', '--apply' => true])->assertExitCode(2);

        $this->assertSame(0, DB::table('g2g_org_accounts')->count());
    }

    public function test_the_command_applies_for_one_organisation_and_a_second_run_changes_nothing(): void
    {
        $this->seedWorld();

        $this->artisan('org-accounts:backfill', ['--tenant' => self::A, '--apply' => true, '--database' => 'sqlite'])
            ->expectsOutputToContain('APPLY')->expectsOutputToContain('identities created')->expectsOutputToContain('Done. Running it again changes nothing.')->assertExitCode(0);
        $count = $this->identityCount();
        $this->assertGreaterThan(0, $count);
        $this->assertSame(0, $this->identityCount(self::B), 'only the organisation asked for');
        $after = $this->snapshot();

        $this->artisan('org-accounts:backfill', ['--tenant' => self::A, '--apply' => true])->assertExitCode(0);

        $this->assertSame($after, $this->snapshot());
    }

    public function test_the_command_saves_the_printed_summary(): void
    {
        $this->seedWorld();
        $path = $this->scratch . DIRECTORY_SEPARATOR . 'summary.txt';

        $this->artisan('org-accounts:backfill', ['--tenant' => self::A, '--summary' => $path])->assertExitCode(0);

        $text = (string) file_get_contents($path);
        $this->assertStringContainsString('DRY RUN', $text);
        $this->assertStringContainsString('Organisation 1', $text);
        $this->assertStringContainsString('suggestions pending', $text);
    }

    public function test_apply_before_the_tables_exist_fails_and_a_dry_run_still_counts_and_warns(): void
    {
        $this->crm('Alpha Academy');
        $this->company('Alpha Academy');
        Schema::drop('g2g_org_link_rejections');
        Schema::drop('g2g_org_accounts');

        $this->artisan('org-accounts:backfill', ['--tenant' => self::A, '--apply' => true])
            ->expectsOutputToContain('run its migration first')->assertExitCode(1);

        $this->artisan('org-accounts:backfill', ['--tenant' => self::A])
            ->expectsOutputToContain('run its migration first')->expectsOutputToContain('identities to create')->expectsOutputToContain('Dry run: nothing was written')->assertExitCode(0);

        $this->expectException(OrgBackfillBlocked::class);
        (new OrgBackfill)->run(self::A, true);
    }

    public function test_apply_with_a_missing_source_table_is_blocked(): void
    {
        $this->crm('Alpha Academy');
        Schema::drop('gtm_accounts');

        $this->artisan('org-accounts:backfill', ['--tenant' => self::A, '--apply' => true])->expectsOutputToContain('gtm_accounts does not exist')->assertExitCode(1);
        $this->assertSame(0, DB::table('g2g_org_accounts')->count());
    }

    public function test_the_command_is_registered(): void
    {
        $this->assertContains('org-accounts:backfill', array_keys(Artisan::all()));
        $this->assertContains('org-accounts:grant', array_keys(Artisan::all()));
    }
}
