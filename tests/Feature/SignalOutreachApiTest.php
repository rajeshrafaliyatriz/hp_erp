<?php

namespace Tests\Feature;

use App\Domain\Signals\Opportunities\SignalContactStore;
use App\Models\auth\tbluserModel;
use App\Support\RoleKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsFactsWorld;
use Tests\Support\BuildsLeadsWorld;
use Tests\TestCase;

/**
 * The Outreach tab of Market Signals (27 Nov 2026): the companies signals found, the people found or added by hand, what can be done with each, drafts written to them, and the
 * lead a company becomes. In-memory sqlite, the real migration files; nothing here can deliver mail (the drafts are rows, and Mail is faked and asserted empty).
 */
class SignalOutreachApiTest extends TestCase
{
    use BuildsFactsWorld;
    use BuildsLeadsWorld;

    private const OTHER = 2;

    private const BASE = '/api/signals/outreach';

    private array $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildLeadsWorld();
        $this->buildSignalsWorld();
        foreach (['2026_12_28_100000_create_g2g_signal_contacts_table', '2026_12_29_090000_add_outreach_fields_to_signal_contacts_and_companies'] as $m) {
            (require base_path("database/migrations/{$m}.php"))->up();
        }
        Mail::fake();
        $this->admin = $this->signalsAdmin();
    }

    // ── fixtures ─────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $over */
    private function person(int $companyId, array $over = [], int $tenant = self::T): int
    {
        return (int) DB::table('g2g_signal_contacts')->insertGetId($over + [
            'sub_institute_id' => $tenant, 'company_id' => $companyId, 'full_name' => 'Meera Patel', 'designation' => 'Principal', 'email' => 'meera@riverdale-school.org', 'phone' => '+919876543210',
            'verification_status' => 'unverified', 'source' => 'found', 'consent_status' => 'unknown', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A company with a signal (as research leaves it). */
    private function foundCompany(string $name, int $tenant = self::T): int
    {
        return (int) $this->makeSignal($tenant, [], ['name' => $name, 'normalized_name' => strtolower($name), 'domain' => strtolower(str_replace(' ', '', $name)) . '.org'])->company_id;
    }

    private function manualCompany(string $name, array $over = [], int $tenant = self::T): int
    {
        return (int) DB::table('g2g_companies')->insertGetId($over + [
            'sub_institute_id' => $tenant, 'name' => $name, 'normalized_name' => strtolower($name), 'source' => 'manual', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array{id: int, h: array<string, string>} a user whose role is not one the Signals tier allows */
    private function employee(): array
    {
        $profile = DB::table('tbluserprofilemaster')->insertGetId(['name' => 'Employee', 'role_key' => 'employee', 'sub_institute_id' => self::T]);
        RoleKey::flushCache();
        $id = DB::table('tbluser')->insertGetId(['user_name' => 'e' . uniqid(), 'first_name' => 'Ravi', 'last_name' => 'Shah', 'sub_institute_id' => self::T, 'user_profile_id' => $profile, 'status' => 1]);

        return ['id' => $id, 'h' => ['Authorization' => 'Bearer ' . tbluserModel::find($id)->createToken('t')->plainTextToken]];
    }

    private function api(string $path, ?array $as = null)
    {
        return $this->getJson(self::BASE . $path, ($as ?? $this->admin)['h']);
    }

    private function sendTo(string $method, string $path, array $body = [], ?array $as = null)
    {
        return $this->json($method, self::BASE . $path, $body, ($as ?? $this->admin)['h']);
    }

    /** @return list<int> */
    private function ids($response, string $key = 'data'): array
    {
        return array_map('intval', array_column($response->json($key), 'id'));
    }

    // ── who may use it ───────────────────────────────────────────────────────────

    public function test_someone_outside_the_signals_tier_gets_a_403_and_a_stranger_a_401(): void
    {
        $this->getJson(self::BASE . '/companies')->assertStatus(401);
        $this->api('/companies', $this->employee())->assertStatus(403);
        $this->sendTo('POST', '/companies', ['name' => 'Acme'], $this->employee())->assertStatus(403);
    }

    // ── the lists ────────────────────────────────────────────────────────────────

    private function world(): array
    {
        $a = $this->foundCompany('Riverdale Public School');
        $this->person($a);                                                                                  // ready
        $this->person($a, ['full_name' => 'Kiran Desai', 'email' => 'kiran@riverdale-school.org', 'phone' => null, 'is_primary' => 0]);   // email only
        $b = $this->foundCompany('Sunrise Academy');                                                         // a signal, no one found
        $c = $this->manualCompany('Greenfield Traders', ['industry' => 'Retail', 'location' => 'Surat']);
        $this->person($c, ['full_name' => 'Ravi Shah', 'email' => 'ravi@greenfield.example.in', 'phone' => null, 'source' => 'manual']);
        $d = $this->manualCompany('Deleted Co', ['deleted_at' => now()]);
        $this->person($d, ['full_name' => 'Gone Person']);

        return compact('a', 'b', 'c', 'd');
    }

    public function test_the_companies_list_has_the_signal_companies_and_the_manual_ones_and_never_a_deleted_one(): void
    {
        $w = $this->world();

        $r = $this->api('/companies')->assertOk();

        $this->assertEqualsCanonicalizing([$w['a'], $w['b'], $w['c']], $this->ids($r));
        $this->assertSame(['page' => 1, 'per_page' => 20, 'total' => 3, 'last_page' => 1], $r->json('meta'));
        $byId = collect($r->json('data'))->keyBy('id');
        $this->assertSame('ready', $byId[$w['a']]['reach']);
        $this->assertSame(2, $byId[$w['a']]['people_count']);
        $this->assertSame(2, $byId[$w['a']]['reachable_people_count']);
        $this->assertSame(1, $byId[$w['a']]['signals_count']);
        $this->assertSame('needs_details', $byId[$w['b']]['reach']);
        $this->assertNull($byId[$w['b']]['best_contact']);
        $this->assertSame('email_only', $byId[$w['c']]['reach']);
        $this->assertSame('manual', $byId[$w['c']]['source']);
        $this->assertSame(0, $byId[$w['c']]['signals_count']);
        $this->assertNull($byId[$w['c']]['latest_signal']);
        $this->assertSame('Meera Patel', $byId[$w['a']]['best_contact']['name'], 'the best contact is the most complete reachable person');
    }

    public function test_every_chip_asks_for_exactly_its_own_filter_and_the_summary_counts_them_all(): void
    {
        $w = $this->world();

        $this->assertEqualsCanonicalizing([$w['a'], $w['c']], $this->ids($this->api('/companies?reach=reachable')));
        $this->assertSame([$w['b']], $this->ids($this->api('/companies?reach=needs_details')));
        $this->assertSame([$w['c']], $this->ids($this->api('/companies?source=manual')));
        $this->assertEqualsCanonicalizing([$w['a'], $w['b']], $this->ids($this->api('/companies?source=found')));
        $this->assertSame([], $this->ids($this->api('/companies?mine=1')));
        DB::table('g2g_companies')->where('id', $w['b'])->update(['assigned_to' => $this->admin['id']]);
        $this->assertSame([$w['b']], $this->ids($this->api('/companies?mine=1')));

        $summary = $this->api('/companies')->json('summary');
        $this->assertSame(['all' => 3, 'reachable' => 2, 'needs_details' => 1, 'blocked' => 0, 'found' => 2, 'manual' => 1, 'mine' => 1], $summary);
    }

    public function test_a_search_finds_a_company_by_its_name_and_by_a_person_at_it_and_the_counts_follow_the_search(): void
    {
        $w = $this->world();

        $this->assertSame([$w['a']], $this->ids($this->api('/companies?search=riverdale')));
        $this->assertSame([$w['c']], $this->ids($this->api('/companies?search=' . urlencode('ravi@greenfield'))), 'by a person\'s email');
        $this->assertSame([$w['a']], $this->ids($this->api('/companies?search=' . urlencode('Kiran'))), 'by a person\'s name');
        $this->assertSame(1, $this->api('/companies?search=riverdale')->json('summary.all'));
        $this->assertSame([], $this->ids($this->api('/companies?search=nothing-like-this')));
    }

    public function test_sorting_and_paging_never_repeat_or_skip_a_row(): void
    {
        $w = $this->world();

        $this->assertSame([$w['c'], $w['a'], $w['b']], $this->ids($this->api('/companies?sort=name&dir=asc')), 'Greenfield, Riverdale, Sunrise');
        $this->assertSame([$w['b'], $w['a'], $w['c']], $this->ids($this->api('/companies?sort=name&dir=desc')));
        $one = $this->api('/companies?sort=name&per_page=2&page=1');
        $two = $this->api('/companies?sort=name&per_page=2&page=2');
        $this->assertSame(2, $one->json('meta.last_page'));
        $this->assertEqualsCanonicalizing([$w['a'], $w['b'], $w['c']], array_merge($this->ids($one), $this->ids($two)));
        $this->assertCount(1, $two->json('data'));
    }

    public function test_the_people_list_has_every_person_with_their_company_and_reach_and_filters_by_company(): void
    {
        $w = $this->world();
        $blocked = $this->person($w['b'], ['full_name' => 'Anita Rao', 'consent_status' => 'opted_out']);
        $needs = $this->person($w['b'], ['full_name' => 'Kiran Joshi', 'email' => null, 'phone' => null]);

        $all = $this->api('/people?per_page=50')->assertOk();
        $byId = collect($all->json('data'))->keyBy('id');

        $this->assertCount(5, $all->json('data'), 'the deleted company\'s person is not listed');
        $this->assertSame('blocked', $byId[$blocked]['reach']);
        $this->assertSame('needs_details', $byId[$needs]['reach']);
        $this->assertSame('Sunrise Academy', $byId[$blocked]['company_name']);
        $this->assertSame('unverified', $byId[$blocked]['verification_status']);
        $this->assertSame(['all' => 5, 'reachable' => 3, 'needs_details' => 1, 'blocked' => 1, 'found' => 4, 'manual' => 1, 'mine' => 0], $all->json('summary'));
        $this->assertSame([$blocked], $this->ids($this->api('/people?reach=blocked')));
        $this->assertCount(2, $this->api('/people?company_id=' . $w['b'])->json('data'));
        $this->assertSame('Ravi Shah', $this->api('/people?source=manual')->json('data.0.full_name'));
    }

    public function test_another_organisations_rows_are_never_listed_and_are_answered_like_a_missing_one(): void
    {
        $mine = $this->foundCompany('Riverdale Public School');
        $theirs = $this->foundCompany('Their School', self::OTHER);
        $theirPerson = $this->person($theirs, [], self::OTHER);

        $this->assertSame([$mine], $this->ids($this->api('/companies')));
        $this->assertSame([], $this->ids($this->api('/people')));
        $this->api("/companies/{$theirs}")->assertStatus(404);
        $this->sendTo('PATCH', "/people/{$theirPerson}", ['designation' => 'x'])->assertStatus(404);
        $this->sendTo('DELETE', "/people/{$theirPerson}")->assertStatus(404);
        $this->sendTo('POST', "/people/{$theirPerson}/consent", ['consent_status' => 'opted_out'])->assertStatus(404);
        $this->sendTo('POST', "/companies/{$theirs}/convert-to-lead")->assertStatus(404);
        $this->assertSame('unknown', DB::table('g2g_signal_contacts')->where('id', $theirPerson)->value('consent_status'), 'nothing of theirs was touched');
    }

    public function test_one_person_can_be_opened_by_id_with_their_company_and_reach_and_a_strangers_is_a_404(): void
    {
        $c = $this->foundCompany('Riverdale Public School');
        $p = $this->person($c, ['full_name' => 'Meera Patel', 'consent_status' => 'opted_out']);
        $theirs = $this->person($this->foundCompany('Their School', self::OTHER), [], self::OTHER);

        $r = $this->api("/people/{$p}")->assertOk();

        $this->assertSame(['Meera Patel', 'Riverdale Public School', 'blocked', 'unverified'], [$r->json('data.full_name'), $r->json('data.company_name'), $r->json('data.reach'), $r->json('data.verification_status')]);
        $this->api("/people/{$theirs}")->assertStatus(404);
        $this->api('/people/999999')->assertStatus(404);
        $deleted = $this->person($c, ['full_name' => 'Gone', 'email' => 'gone@riverdale-school.org', 'deleted_at' => now()]);
        $this->api("/people/{$deleted}")->assertStatus(404);
    }

    public function test_a_company_page_has_its_signals_people_and_where_the_contact_search_stands(): void
    {
        $w = $this->world();

        $r = $this->api("/companies/{$w['a']}")->assertOk();

        $this->assertSame('Riverdale Public School', $r->json('data.name'));
        $this->assertCount(1, $r->json('data.signals'));
        $this->assertCount(2, $r->json('data.people'));
        $this->assertSame('found', $r->json('data.research.state'));
        $this->assertSame('not_set_up', $this->api("/companies/{$w['b']}")->json('data.research.state'), 'no provider key is configured in tests');
        $this->assertSame([], $this->api("/companies/{$w['c']}")->json('data.signals'), 'a company added by hand has no signals');
    }

    // ── adding by hand ───────────────────────────────────────────────────────────

    public function test_a_company_added_by_hand_is_listed_as_added_by_hand(): void
    {
        $r = $this->sendTo('POST', '/companies', ['name' => 'Acme Traders', 'website' => 'https://www.acme.example.in/home', 'phone' => '98765 43210', 'email' => 'Sales@Acme.example.in', 'industry' => 'Retail'])->assertCreated();

        $this->assertSame('manual', $r->json('data.source'));
        $this->assertSame('acme.example.in', DB::table('g2g_companies')->where('id', $r->json('data.id'))->value('domain'));
        $this->assertSame('+919876543210', $r->json('data.phone'), 'the phone is normalised like a found one');
        $this->assertSame('sales@acme.example.in', $r->json('data.email'));
        $this->assertSame('needs_details', $r->json('data.reach'));
        $this->assertSame(0, $r->json('data.signals_count'));
        $this->assertSame($this->admin['id'], (int) DB::table('g2g_companies')->where('id', $r->json('data.id'))->value('created_by'));
    }

    public function test_adding_a_company_that_is_already_here_is_refused_and_names_the_existing_one(): void
    {
        $existing = $this->foundCompany('Riverdale Public School');

        $r = $this->sendTo('POST', '/companies', ['name' => 'Riverdale Public School'])->assertStatus(422);

        $this->assertSame('exists', $r->json('code'));
        $this->assertSame($existing, $r->json('company_id'));
        $this->assertSame(1, DB::table('g2g_companies')->where('sub_institute_id', self::T)->where('name', 'Riverdale Public School')->count());
    }

    public function test_a_company_needs_a_name_and_a_usable_email_and_phone_and_one_of_your_own_users_as_owner(): void
    {
        $this->sendTo('POST', '/companies', ['name' => ''])->assertStatus(422);
        $this->assertSame('bad_email', $this->sendTo('POST', '/companies', ['name' => 'A', 'email' => 'not an email'])->json('code'));
        $this->assertSame('bad_phone', $this->sendTo('POST', '/companies', ['name' => 'B', 'phone' => '12345'])->json('code'));
        $foreignUser = DB::table('tbluser')->insertGetId(['user_name' => 'x', 'first_name' => 'X', 'last_name' => 'Y', 'sub_institute_id' => self::OTHER, 'user_profile_id' => 1, 'status' => 1]);
        $this->assertSame('bad_owner', $this->sendTo('POST', '/companies', ['name' => 'C', 'assigned_to' => $foreignUser])->json('code'));
        $this->assertSame(0, DB::table('g2g_companies')->where('source', 'manual')->count(), 'every refusal wrote nothing');
    }

    public function test_a_person_added_by_hand_is_listed_as_added_by_hand_and_a_new_primary_replaces_the_old_one(): void
    {
        $c = $this->manualCompany('Greenfield Traders');

        $first = $this->sendTo('POST', '/people', ['company_id' => $c, 'full_name' => 'Ravi Shah', 'designation' => 'Owner', 'email' => 'ravi@greenfield.example.in', 'is_primary' => true])->assertCreated();
        $second = $this->sendTo('POST', '/people', ['company_id' => $c, 'full_name' => 'Asha Mehta', 'mobile' => '9822012345', 'is_primary' => true])->assertCreated();

        $this->assertSame('manual', $first->json('data.source'));
        $this->assertSame('email_only', $first->json('data.reach'));
        $this->assertSame('phone_only', $second->json('data.reach'));
        $this->assertSame('+919822012345', $second->json('data.mobile'));
        $this->assertSame([0, 1], [(int) DB::table('g2g_signal_contacts')->where('id', $first->json('data.id'))->value('is_primary'), (int) DB::table('g2g_signal_contacts')->where('id', $second->json('data.id'))->value('is_primary')]);
        $this->assertSame('unverified', $first->json('data.verification_status'));
    }

    public function test_adding_a_person_who_is_already_listed_is_refused_by_email_phone_or_name_and_names_the_existing_one(): void
    {
        $c = $this->foundCompany('Riverdale Public School');
        $existing = $this->person($c);

        foreach ([['email' => 'MEERA@riverdale-school.org'], ['phone' => '98765 43210'], ['full_name' => 'meera patel']] as $i => $facts) {
            $r = $this->sendTo('POST', '/people', ['company_id' => $c] + $facts + ($i === 0 ? ['full_name' => 'Someone Else'] : ['email' => "other{$i}@riverdale-school.org"]))->assertStatus(422);
            $this->assertSame('duplicate', $r->json('code'), json_encode($facts));
            $this->assertSame($existing, $r->json('person_id'));
        }
        $this->assertSame(1, DB::table('g2g_signal_contacts')->where('company_id', $c)->count());
    }

    public function test_a_person_needs_a_company_of_your_own_a_way_to_find_them_and_usable_details(): void
    {
        $mine = $this->manualCompany('Greenfield Traders');
        $theirs = $this->manualCompany('Their Co', [], self::OTHER);

        $this->assertSame('no_company', $this->sendTo('POST', '/people', ['company_id' => $theirs, 'full_name' => 'A'])->json('code'));
        $this->assertSame('nothing_to_go_on', $this->sendTo('POST', '/people', ['company_id' => $mine])->json('code'));
        $this->assertSame('bad_email', $this->sendTo('POST', '/people', ['company_id' => $mine, 'full_name' => 'A', 'email' => 'noreply@x.org'])->json('code'));
        $this->assertSame('bad_phone', $this->sendTo('POST', '/people', ['company_id' => $mine, 'full_name' => 'A', 'phone' => 'call me'])->json('code'));
        $this->assertSame(0, DB::table('g2g_signal_contacts')->count());
    }

    // ── changing and removing ────────────────────────────────────────────────────

    public function test_a_company_signals_found_keeps_its_name_and_place_but_its_phone_email_note_and_owner_can_be_set(): void
    {
        $found = $this->foundCompany('Riverdale Public School');

        $r = $this->sendTo('PATCH', "/companies/{$found}", ['name' => 'Renamed', 'website' => 'https://x.example', 'phone' => '9876543210', 'description' => 'Met at the fair', 'assigned_to' => $this->admin['id']])->assertOk();

        $this->assertSame('Riverdale Public School', $r->json('data.name'));
        $this->assertNull($r->json('data.website'));
        $this->assertSame('+919876543210', $r->json('data.phone'));
        $this->assertSame('Met at the fair', $r->json('data.description'));
        $this->assertSame($this->admin['id'], $r->json('data.assigned_to'));
    }

    public function test_a_company_added_by_hand_can_be_renamed_unless_the_name_is_taken(): void
    {
        $mine = $this->manualCompany('Greenfield Traders');
        $other = $this->foundCompany('Riverdale Public School');

        $this->sendTo('PATCH', "/companies/{$mine}", ['name' => 'Greenfield Retail', 'industry' => 'Retail'])->assertOk()->assertJsonPath('data.name', 'Greenfield Retail');
        $this->assertSame('exists', $this->sendTo('PATCH', "/companies/{$mine}", ['name' => 'Riverdale Public School'])->json('code'));
        $this->assertSame($other, $this->sendTo('PATCH', "/companies/{$mine}", ['name' => 'Riverdale Public School'])->json('company_id'));
    }

    public function test_only_a_company_added_by_hand_with_no_signal_and_no_lead_can_be_removed_and_its_people_go_with_it(): void
    {
        $found = $this->foundCompany('Riverdale Public School');
        $manual = $this->manualCompany('Greenfield Traders');
        $person = $this->person($manual);
        $withLead = $this->manualCompany('Lead Co', ['lead_id' => 55]);
        $findsSignal = $this->manualCompany('Signal Co');
        $this->makeSignal(self::T, ['company_id' => $findsSignal]);

        $this->assertSame('found_company', $this->sendTo('DELETE', "/companies/{$found}")->json('code'));
        $this->assertSame('has_lead', $this->sendTo('DELETE', "/companies/{$withLead}")->json('code'));
        $this->assertSame('has_signals', $this->sendTo('DELETE', "/companies/{$findsSignal}")->json('code'));
        $this->sendTo('DELETE', "/companies/{$manual}")->assertOk();

        $this->assertNotNull(DB::table('g2g_companies')->where('id', $manual)->value('deleted_at'));
        $this->assertNotNull(DB::table('g2g_signal_contacts')->where('id', $person)->value('deleted_at'));
        $this->assertNull(DB::table('g2g_companies')->where('id', $found)->value('deleted_at'));
        $this->api("/companies/{$manual}")->assertStatus(404);
    }

    public function test_correcting_an_email_clears_the_page_it_was_found_on_and_a_clash_with_someone_else_is_refused(): void
    {
        $c = $this->foundCompany('Riverdale Public School');
        $meera = $this->person($c, ['email_source_url' => 'https://riverdale-school.org/contact']);
        $this->person($c, ['full_name' => 'Kiran Desai', 'email' => 'kiran@riverdale-school.org', 'phone' => null]);

        $this->sendTo('PATCH', "/people/{$meera}", ['email' => 'principal@riverdale-school.org', 'designation' => 'Head'])->assertOk();
        $row = DB::table('g2g_signal_contacts')->where('id', $meera)->first();
        $this->assertSame(['principal@riverdale-school.org', null, 'Head'], [$row->email, $row->email_source_url, $row->designation]);

        $this->assertSame('duplicate', $this->sendTo('PATCH', "/people/{$meera}", ['email' => 'kiran@riverdale-school.org'])->json('code'));
        $this->assertSame('principal@riverdale-school.org', DB::table('g2g_signal_contacts')->where('id', $meera)->value('email'));
    }

    public function test_a_removed_person_leaves_the_lists_and_the_signal_card_and_the_research_does_not_bring_them_back(): void
    {
        $c = $this->foundCompany('Riverdale Public School');
        $person = $this->person($c);
        $this->assertCount(1, SignalContactStore::forCompanies(self::T, [$c])[$c] ?? []);

        $this->sendTo('DELETE', "/people/{$person}")->assertOk();

        $this->assertSame([], $this->ids($this->api('/people')));
        $this->assertSame([], SignalContactStore::forCompanies(self::T, [$c]), 'the signal card shows no one either');
        SignalContactStore::save(self::T, $c, null, [['full_name' => 'Meera Patel', 'email' => 'meera@riverdale-school.org']]);
        $this->assertSame(1, DB::table('g2g_signal_contacts')->where('company_id', $c)->count(), 'a later search finds the same person and does not add a second row');
        $this->assertSame([], $this->ids($this->api('/people')));
    }

    // ── consent and owners ───────────────────────────────────────────────────────

    public function test_opting_out_rules_a_person_out_completely_and_agreeing_lifts_it(): void
    {
        $c = $this->foundCompany('Riverdale Public School');
        $p = $this->person($c);

        $out = $this->sendTo('POST', "/people/{$p}/consent", ['consent_status' => 'opted_out', 'reason' => 'asked by phone'])->assertOk();
        $this->assertSame('blocked', $out->json('data.reach'));
        $this->assertSame('asked by phone', $out->json('data.suppressed_reason'));
        $this->assertNotNull(DB::table('g2g_signal_contacts')->where('id', $p)->value('suppressed_at'));
        $this->assertSame('blocked', $this->api("/companies/{$c}")->json('data.reach'), 'the company has no one left to try');

        $back = $this->sendTo('POST', "/people/{$p}/consent", ['consent_status' => 'opted_in'])->assertOk();
        $this->assertSame('ready', $back->json('data.reach'));
        $this->assertNull(DB::table('g2g_signal_contacts')->where('id', $p)->value('suppressed_at'));
    }

    public function test_no_email_and_do_not_call_each_rule_out_one_channel(): void
    {
        $p = $this->person($this->foundCompany('Riverdale Public School'));

        $this->assertSame('phone_only', $this->sendTo('POST', "/people/{$p}/consent", ['email_opt_out' => true])->json('data.reach'));
        $this->assertSame('blocked', $this->sendTo('POST', "/people/{$p}/consent", ['do_not_call' => true])->json('data.reach'));
        $this->assertSame('bad_consent', $this->sendTo('POST', "/people/{$p}/consent", ['consent_status' => 'maybe'])->json('code'));
    }

    public function test_assigning_owners_changes_only_the_named_rows_and_only_to_one_of_your_own_users(): void
    {
        $a = $this->foundCompany('Riverdale Public School');
        $b = $this->foundCompany('Sunrise Academy');
        $p = $this->person($a);

        $r = $this->sendTo('POST', '/assign', ['company_ids' => [$a], 'person_ids' => [$p], 'assigned_to' => $this->admin['id']])->assertOk();

        $this->assertSame(['companies' => 1, 'people' => 1], $r->json('data'));
        $this->assertSame($this->admin['id'], (int) DB::table('g2g_companies')->where('id', $a)->value('assigned_to'));
        $this->assertNull(DB::table('g2g_companies')->where('id', $b)->value('assigned_to'));
        $this->assertSame('bad_owner', $this->sendTo('POST', '/assign', ['company_ids' => [$a], 'assigned_to' => 999999])->json('code'));
        $this->sendTo('POST', '/assign', ['company_ids' => [$a], 'assigned_to' => null])->assertOk();
        $this->assertNull(DB::table('g2g_companies')->where('id', $a)->value('assigned_to'), 'null means nobody');
    }

    // ── writing messages: drafts only ────────────────────────────────────────────

    public function test_writing_to_a_person_saves_one_draft_in_the_send_engine_and_nothing_is_sent(): void
    {
        $c = $this->foundCompany('Riverdale Public School');
        $p = $this->person($c);
        $crm = [DB::table('crm_organizations')->count(), DB::table('crm_contacts')->count()];

        $r = $this->sendTo('POST', '/message-drafts', ['person_ids' => [$p], 'subject' => 'Hello {{first_name}}', 'body' => 'Hello {{first_name}}, we help {{company}} run admissions without paperwork.'])->assertCreated();

        $this->assertSame([1, 0], [$r->json('data.drafted'), $r->json('data.refused')]);
        $message = DB::table('gtm_outreach_messages')->where('id', $r->json('data.results.0.message_id'))->first();
        $this->assertSame('draft', $message->status);
        $this->assertSame('Hello Meera', $message->subject);
        $this->assertSame('Hello Meera, we help Riverdale Public School run admissions without paperwork.', $message->body);
        $this->assertSame(1, DB::table('gtm_outreach_messages')->count());
        $this->assertSame(0, DB::table('gtm_outreach_attempts')->count(), 'no send was tried');
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        $contact = DB::table('gtm_contacts')->where('id', $message->contact_id)->first();
        $account = DB::table('gtm_accounts')->where('id', $contact->account_id)->first();
        $this->assertSame(['meera@riverdale-school.org', 'active', 'research'], [$contact->email, $contact->status, $contact->source]);
        $this->assertSame(['target', $c], [$account->stage, (int) $account->company_id], 'a prospect: stage target, linked to the signal company');
        $this->assertSame((int) $contact->id, (int) DB::table('g2g_signal_contacts')->where('id', $p)->value('gtm_contact_id'));
        $this->assertSame($crm, [DB::table('crm_organizations')->count(), DB::table('crm_contacts')->count()], 'a prospect never becomes a CRM organization or contact');
    }

    public function test_writing_again_to_the_same_person_reuses_their_contact_and_account(): void
    {
        $p = $this->person($this->foundCompany('Riverdale Public School'));
        $body = ['person_ids' => [$p], 'subject' => 'First note', 'body' => 'A first message that is long enough to be written.'];

        $this->sendTo('POST', '/message-drafts', $body)->assertCreated();
        $this->sendTo('POST', '/message-drafts', ['subject' => 'Second note'] + $body)->assertCreated();

        $this->assertSame([1, 1, 2], [DB::table('gtm_accounts')->count(), DB::table('gtm_contacts')->count(), DB::table('gtm_outreach_messages')->count()]);
    }

    public function test_an_existing_account_with_the_same_domain_is_adopted_not_duplicated(): void
    {
        $c = $this->foundCompany('Riverdale Public School');
        $existing = DB::table('gtm_accounts')->insertGetId(['sub_institute_id' => self::T, 'name' => 'Riverdale (old)', 'domain' => 'riverdalepublicschool.org', 'stage' => 'engaged', 'created_at' => now(), 'updated_at' => now()]);
        $p = $this->person($c);

        $this->sendTo('POST', '/message-drafts', ['person_ids' => [$p], 'subject' => 'Hello', 'body' => 'Hello, a message that is long enough to be written.'])->assertCreated();

        $this->assertSame(1, DB::table('gtm_accounts')->count());
        $this->assertSame($c, (int) DB::table('gtm_accounts')->where('id', $existing)->value('company_id'));
        $this->assertSame('engaged', DB::table('gtm_accounts')->where('id', $existing)->value('stage'), 'a stage someone set is never changed');
    }

    public function test_each_person_is_checked_alone_and_each_refusal_says_why_and_writes_nothing_for_them(): void
    {
        $c = $this->foundCompany('Riverdale Public School');
        $ok = $this->person($c);
        $noEmail = $this->person($c, ['full_name' => 'Anita Rao', 'email' => null, 'phone' => '+919822012345']);
        $optedOut = $this->person($c, ['full_name' => 'Kiran Desai', 'email' => 'kiran@riverdale-school.org', 'consent_status' => 'opted_out']);
        $noMail = $this->person($c, ['full_name' => 'Ravi Shah', 'email' => 'ravi@riverdale-school.org', 'email_opt_out' => 1]);
        $suppressed = $this->person($c, ['full_name' => 'Isha Nair', 'email' => 'isha@riverdale-school.org']);
        DB::table('gtm_suppressions')->insert(['sub_institute_id' => self::T, 'kind' => 'email', 'value' => 'isha@riverdale-school.org', 'reason' => 'manual', 'created_at' => now()]);

        $r = $this->sendTo('POST', '/message-drafts', ['person_ids' => [$ok, $noEmail, $optedOut, $noMail, $suppressed, 999999], 'subject' => 'Hello', 'body' => 'Hello, a message that is long enough to be written.'])->assertCreated();

        $by = collect($r->json('data.results'))->keyBy('person_id');
        $this->assertSame([1, 5], [$r->json('data.drafted'), $r->json('data.refused')]);
        $this->assertTrue($by[$ok]['ok']);
        $this->assertSame('no_email', $by[$noEmail]['code']);
        $this->assertStringContainsString('Anita Rao', $by[$noEmail]['message']);
        $this->assertSame('blocked', $by[$optedOut]['code']);
        $this->assertStringContainsString('opted out', $by[$optedOut]['message']);
        $this->assertSame('email_opt_out', $by[$noMail]['code']);
        $this->assertSame('suppressed', $by[$suppressed]['code']);
        $this->assertSame('not_found', $by[999999]['code']);
        $this->assertSame(1, DB::table('gtm_outreach_messages')->count());
        $this->assertSame(1, DB::table('gtm_contacts')->count(), 'no contact was made for anyone refused');
    }

    public function test_a_gtm_contact_already_marked_do_not_contact_is_respected_and_consent_is_carried_to_the_engine(): void
    {
        $c = $this->foundCompany('Riverdale Public School');
        $p = $this->person($c);
        $body = ['person_ids' => [$p], 'subject' => 'Hello', 'body' => 'Hello, a message that is long enough to be written.'];
        $this->sendTo('POST', '/message-drafts', $body)->assertCreated();
        $gtm = (int) DB::table('g2g_signal_contacts')->where('id', $p)->value('gtm_contact_id');

        $this->sendTo('POST', "/people/{$p}/consent", ['consent_status' => 'opted_out'])->assertOk();
        $this->assertSame('do_not_contact', DB::table('gtm_contacts')->where('id', $gtm)->value('status'), 'the engine refuses them too');

        $this->sendTo('POST', "/people/{$p}/consent", ['consent_status' => 'opted_in'])->assertOk();
        $r = $this->sendTo('POST', '/message-drafts', $body)->assertOk();
        $this->assertSame('contact_blocked', $r->json('data.results.0.code'), 'a block the engine holds is not ours to lift');
        $this->assertSame(1, DB::table('gtm_outreach_messages')->count());
    }

    public function test_a_refused_write_leaves_the_engine_exactly_as_it_was(): void
    {
        $p = $this->person($this->foundCompany('Riverdale Public School'));
        $body = ['person_ids' => [$p], 'subject' => 'Hello', 'body' => 'Hello, a message that is long enough to be written.'];
        $this->sendTo('POST', '/message-drafts', $body)->assertCreated();
        $gtm = (int) DB::table('g2g_signal_contacts')->where('id', $p)->value('gtm_contact_id');
        // The engine marks them do-not-contact (a bounce or an unsubscribe it recorded), then the person's details change here.
        DB::table('gtm_contacts')->where('id', $gtm)->update(['status' => 'unsubscribed']);
        DB::table('g2g_signal_contacts')->where('id', $p)->update(['designation' => 'Head of School']);

        $r = $this->sendTo('POST', '/message-drafts', $body)->assertOk();

        $this->assertSame('contact_blocked', $r->json('data.results.0.code'));
        $this->assertSame('Principal', DB::table('gtm_contacts')->where('id', $gtm)->value('title'), 'the refused write took back its edit of the engine\'s contact');
        $this->assertSame(1, DB::table('gtm_outreach_messages')->count());
    }

    public function test_writing_needs_the_gtm_outreach_right_too_and_a_real_subject_and_body(): void
    {
        $p = $this->person($this->foundCompany('Riverdale Public School'));
        // An HR user has the Signals tier but not the GTM add right (administrators only, until menu rights are switched on).
        $hrProfile = DB::table('tbluserprofilemaster')->insertGetId(['name' => 'HR', 'role_key' => 'hr_manager', 'sub_institute_id' => self::T]);
        RoleKey::flushCache();
        $hrId = DB::table('tbluser')->insertGetId(['user_name' => 'h' . uniqid(), 'first_name' => 'Hema', 'last_name' => 'Joshi', 'sub_institute_id' => self::T, 'user_profile_id' => $hrProfile, 'status' => 1]);
        $hr = ['id' => $hrId, 'h' => ['Authorization' => 'Bearer ' . tbluserModel::find($hrId)->createToken('t')->plainTextToken]];

        $this->api('/companies', $hr)->assertOk();
        $this->sendTo('POST', '/message-drafts', ['person_ids' => [$p], 'subject' => 'Hello', 'body' => 'Hello, a message that is long enough.'], $hr)->assertStatus(403);
        $this->sendTo('POST', '/message-drafts', ['person_ids' => [$p], 'subject' => '', 'body' => 'Hello, a message that is long enough.'])->assertStatus(422);
        $this->sendTo('POST', '/message-drafts', ['person_ids' => [$p], 'subject' => 'Hello', 'body' => 'too short'])->assertStatus(422);
        $this->sendTo('POST', '/message-drafts', ['person_ids' => [], 'subject' => 'Hello', 'body' => 'Hello, a message that is long enough.'])->assertStatus(422);
        $this->assertSame(0, DB::table('gtm_outreach_messages')->count());
    }

    public function test_a_persons_messages_are_their_drafts_and_sent_mail(): void
    {
        $p = $this->person($this->foundCompany('Riverdale Public School'));
        $this->assertSame([], $this->api("/people/{$p}/messages")->json('data'));

        $this->sendTo('POST', '/message-drafts', ['person_ids' => [$p], 'subject' => 'Hello', 'body' => 'Hello, a message that is long enough to be written.'])->assertCreated();

        $messages = $this->api("/people/{$p}/messages")->assertOk()->json('data');
        $this->assertCount(1, $messages);
        $this->assertSame(['Hello', 'draft', null], [$messages[0]['subject'], $messages[0]['status'], $messages[0]['sent_at']]);
    }

    public function test_the_last_time_someone_was_written_to_is_the_last_time_mail_was_sent(): void
    {
        $c = $this->foundCompany('Riverdale Public School');
        $p = $this->person($c);
        $this->sendTo('POST', '/message-drafts', ['person_ids' => [$p], 'subject' => 'Hello', 'body' => 'Hello, a message that is long enough to be written.'])->assertCreated();
        $this->assertNull($this->api('/people')->json('data.0.last_contacted_at'), 'a draft is not contact');

        DB::table('gtm_outreach_messages')->update(['status' => 'sent', 'sent_at' => '2026-11-25 10:00:00']);

        $this->assertStringStartsWith('2026-11-25', (string) $this->api('/people')->json('data.0.last_contacted_at'));
        $this->assertStringStartsWith('2026-11-25', (string) $this->api('/companies')->json('data.0.last_contacted_at'));
    }

    // ── becoming a lead ──────────────────────────────────────────────────────────

    public function test_a_company_becomes_one_lead_about_the_person_and_pressing_again_returns_the_same_lead(): void
    {
        $signal = $this->makeSignal(self::T, [], ['name' => 'Riverdale Public School', 'normalized_name' => 'riverdale public school', 'website' => 'https://riverdale-school.org', 'industry' => 'Education']);
        $p = $this->person($signal->company_id, ['full_name' => 'Meera Patel', 'designation' => 'Principal']);
        $orgs = [DB::table('crm_organizations')->count(), DB::table('crm_contacts')->count(), DB::table('crm_opportunities')->count()];

        $first = $this->sendTo('POST', "/companies/{$signal->company_id}/convert-to-lead", ['person_id' => $p])->assertCreated();
        $again = $this->sendTo('POST', "/companies/{$signal->company_id}/convert-to-lead", ['person_id' => $p])->assertOk();

        $this->assertTrue($first->json('data.created'));
        $this->assertFalse($again->json('data.created'));
        $this->assertSame($first->json('data.lead_id'), $again->json('data.lead_id'));
        $lead = DB::table('crm_leads')->where('id', $first->json('data.lead_id'))->first();
        $this->assertSame(['Meera', 'Patel', 'Riverdale Public School', 'Principal', 'meera@riverdale-school.org', 'Signals'], [$lead->first_name, $lead->last_name, $lead->company, $lead->designation, $lead->email, $lead->lead_source]);
        $this->assertSame((int) $signal->id, (int) $lead->source_company_opportunity_id);
        $this->assertSame((int) $lead->id, (int) DB::table('g2g_signal_contacts')->where('id', $p)->value('lead_id'));
        $this->assertSame(1, DB::table('crm_leads')->count());
        $this->assertSame($orgs, [DB::table('crm_organizations')->count(), DB::table('crm_contacts')->count(), DB::table('crm_opportunities')->count()], 'no organization, contact or opportunity yet: those come when a customer converts');
        $this->assertSame(1, DB::table('crm_lead_comments')->where('lead_id', $lead->id)->count(), 'one note says where the lead came from');
    }

    public function test_when_the_signal_already_became_a_lead_that_lead_is_the_answer_and_no_second_one_is_made(): void
    {
        $signal = $this->makeSignal(self::T, [], ['name' => 'Riverdale Public School', 'normalized_name' => 'riverdale public school']);
        $this->person($signal->company_id);
        $made = $this->postJson("/api/signals/opportunities/{$signal->id}/promote-to-crm", [], $this->admin['h'])->assertCreated()->json('data.lead_id');

        $r = $this->sendTo('POST', "/companies/{$signal->company_id}/convert-to-lead")->assertOk();

        $this->assertFalse($r->json('data.created'));
        $this->assertSame((int) $made, $r->json('data.lead_id'));
        $this->assertSame(1, DB::table('crm_leads')->count());
    }

    public function test_a_company_added_by_hand_becomes_a_lead_too_and_an_opt_out_travels_with_it(): void
    {
        $c = $this->manualCompany('Greenfield Traders', ['industry' => 'Retail']);
        $p = $this->person($c, ['full_name' => 'Ravi Shah', 'email' => 'ravi@greenfield.example.in', 'phone' => null, 'source' => 'manual', 'email_opt_out' => 1]);

        // With nobody chosen, a person who ruled out every channel is not picked: the lead is named after the company.
        $unchosen = $this->sendTo('POST', "/companies/{$c}/convert-to-lead")->assertCreated();
        $this->assertSame('Greenfield Traders', DB::table('crm_leads')->where('id', $unchosen->json('data.lead_id'))->value('last_name'));
        DB::table('crm_leads')->delete();
        DB::table('g2g_companies')->where('id', $c)->update(['lead_id' => null]);

        $r = $this->sendTo('POST', "/companies/{$c}/convert-to-lead", ['person_id' => $p])->assertCreated();

        $lead = DB::table('crm_leads')->where('id', $r->json('data.lead_id'))->first();
        $this->assertSame(['Ravi', 'Shah', 'Greenfield Traders', 'Self Generated'], [$lead->first_name, $lead->last_name, $lead->company, $lead->lead_source]);
        $this->assertNull($lead->source_company_opportunity_id);
        $this->assertSame(1, (int) $lead->email_opt_out, 'what the person said about email goes with the lead');
        $this->assertSame((int) $lead->id, (int) DB::table('g2g_signal_contacts')->where('id', $p)->value('lead_id'));
        $this->sendTo('POST', "/companies/{$c}/convert-to-lead", ['person_id' => $p])->assertOk()->assertJsonPath('data.created', false);
    }

    public function test_a_company_with_nobody_listed_becomes_a_lead_named_after_the_company_and_a_person_of_another_company_is_refused(): void
    {
        $a = $this->manualCompany('Greenfield Traders');
        $b = $this->foundCompany('Riverdale Public School');
        $elsewhere = $this->person($b);

        $r = $this->sendTo('POST', "/companies/{$a}/convert-to-lead")->assertCreated();
        $this->assertSame('Greenfield Traders', DB::table('crm_leads')->where('id', $r->json('data.lead_id'))->value('last_name'));
        $this->assertSame((int) $r->json('data.lead_id'), (int) DB::table('g2g_companies')->where('id', $a)->value('lead_id'));

        $this->assertSame('person_not_found', $this->sendTo('POST', "/companies/{$a}/convert-to-lead", ['person_id' => $elsewhere])->assertStatus(404)->json('code'));
    }

    // ── finding contacts ─────────────────────────────────────────────────────────

    public function test_finding_contacts_needs_a_signal_to_search_from_and_a_search_provider(): void
    {
        $manual = $this->manualCompany('Greenfield Traders');
        $found = $this->foundCompany('Riverdale Public School');

        $this->assertSame('no_signal', $this->sendTo('POST', "/companies/{$manual}/find-contacts")->assertStatus(422)->json('code'));
        $this->assertSame('not_set_up', $this->sendTo('POST', "/companies/{$found}/find-contacts")->assertStatus(422)->json('code'));
        $this->sendTo('POST', '/companies/999999/find-contacts')->assertStatus(404);
    }

    // ── a server that has not run the migration ──────────────────────────────────

    public function test_before_the_database_change_is_applied_the_tab_says_so_plainly_instead_of_failing(): void
    {
        Schema::table('g2g_signal_contacts', fn ($t) => $t->dropColumn('consent_status'));

        $r = $this->api('/companies')->assertStatus(503);

        $this->assertSame('not_set_up', $r->json('code'));
        $this->assertStringContainsString('not set up', $r->json('message'));
    }
}
