<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsCampaignsWorld;
use Tests\Support\BuildsFactsWorld;
use Tests\Support\BuildsLeadsWorld;
use Tests\TestCase;

/**
 * S20: a signal is converted straight into an Organization, an optional Person and an Opportunity (POST /signals/opportunities/{id}/convert-to-opportunity), with the
 * choices of the Convert Lead pop-up, one opportunity per signal, the company's Prospecting account moving to the opportunity stage.
 */
class SignalOpportunityConversionTest extends TestCase
{
    use BuildsCampaignsWorld;
    use BuildsFactsWorld;
    use BuildsLeadsWorld;

    private const OPPS_LINK = '/module/crm/sales/opportunities';

    private array $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCampaignsWorld();
        $this->buildSignalsWorld();
        foreach (['2026_12_13_100000_add_product_scope_to_gtm_accounts', '2026_12_20_100000_add_region_to_gtm_accounts', '2026_12_27_090000_add_prospecting_links_to_crm_leads', '2026_12_27_090100_add_source_signal_to_crm_opportunities'] as $m) {
            (require base_path("database/migrations/{$m}.php"))->up();
        }
        if (! Schema::hasColumn('gtm_accounts', 'crm_organization_id')) {
            (require base_path('database/migrations/2026_11_24_081100_add_crm_organization_id_to_gtm_accounts_table.php'))->up();
        }
        // What the conversion writes beyond the leads world: the opportunity's product pointer and money columns, and the S18 star / role of a person.
        Schema::table('crm_opportunities', function ($t) {
            $t->unsignedBigInteger('product_master_id')->nullable();
            $t->decimal('amount', 15, 2)->nullable();
            $t->string('currency', 10)->nullable();
            $t->date('closing_date')->nullable();
        });
        (require base_path('database/migrations/2026_12_26_100000_add_primary_and_role_to_crm_contacts.php'))->up();
        DB::table('tblmenumaster_g2g')->insert(['id' => 331, 'menu_name' => 'Opportunities', 'access_link' => self::OPPS_LINK]);
        $this->admin = $this->converter([self::OPPS_LINK => ['view', 'add'], self::ORGS_LINK => ['view', 'add']]);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** An administrator (the Signals tier) holding exactly these rights. @param array<string, list<string>> $rights @return array{id: int, h: array<string, string>} */
    private function converter(array $rights): array
    {
        $user = $this->adminWithLeads(self::T, []);
        $profile = (int) DB::table('tbluser')->where('id', $user['id'])->value('user_profile_id');
        foreach ($rights as $link => $actions) {
            DB::table('tblgroupwise_rights_g2g')->insert(['sub_institute_id' => self::T, 'menu_id' => (int) DB::table('tblmenumaster_g2g')->where('access_link', $link)->value('id'), 'profile_id' => $profile]
                + array_combine(array_map(fn ($a) => "can_{$a}", $actions), array_fill(0, count($actions), 1)));
        }

        return $user;
    }

    private function convert(int $signalId, array $body = [], ?array $as = null)
    {
        return $this->postJson("/api/signals/opportunities/{$signalId}/convert-to-opportunity", $body, ($as ?? $this->admin)['h']);
    }

    private function signal(array $over = []): \App\Domain\Signals\Opportunities\CompanyOpportunity
    {
        return $this->makeSignal(self::T, $over + ['title' => 'Opens a new campus'], ['name' => 'Greenfield School', 'normalized_name' => 'greenfield', 'website' => 'https://greenfield.example', 'industry' => 'Education']);
    }

    private function account(int $company, string $stage = 'target'): int
    {
        return (int) DB::table('gtm_accounts')->insertGetId(['sub_institute_id' => self::T, 'company_id' => $company, 'name' => 'Greenfield School', 'stage' => $stage, 'created_at' => now(), 'updated_at' => now()]);
    }

    // ── what it makes ────────────────────────────────────────────────────────

    public function test_a_signal_becomes_an_organization_and_an_opportunity_with_no_person_when_none_is_given(): void
    {
        $signal = $this->signal(['business_fit' => 'scholar']);
        $product = (int) DB::table('g2g_product_master')->insertGetId(['sub_institute_id' => self::T, 'kind' => 'product', 'product_key' => 'scholarclone', 'signal_key' => 'scholar', 'portfolio_name' => 'ScholarClone', 'name' => 'ScholarClone', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        $res = $this->convert($signal->id)->assertCreated();

        $org = DB::table('crm_organizations')->sole();
        $opp = DB::table('crm_opportunities')->sole();
        $this->assertSame(['Greenfield School', 'Education', 'https://greenfield.example'], [$org->name, $org->industry, $org->website]);
        $this->assertSame(['Greenfield School - Opens a new campus', (int) $org->id, 'Signals', (int) $signal->id, $product, $this->admin['id']], [
            $opp->name, (int) $opp->organization_id, $opp->lead_source, (int) $opp->source_company_opportunity_id, (int) $opp->product_master_id, (int) $opp->assigned_to,
        ]);
        $this->assertSame(0, DB::table('crm_contacts')->count(), 'the person is optional');
        $this->assertSame(0, DB::table('crm_opportunity_contacts')->count());
        $this->assertEquals(['opportunity_id' => (int) $opp->id, 'opportunity_name' => $opp->name, 'organization_id' => (int) $org->id, 'organization_created' => true, 'contact_id' => null, 'primary_set' => false,
            'account_id' => (int) DB::table('gtm_accounts')->value('id'), 'created' => true], $res->json('data'));
        $history = DB::table('g2g_signal_reviews')->where('opportunity_id', $signal->id)->sole();
        $this->assertSame(['opportunity', 'convert', "Converted to opportunity #{$opp->id}"], [$history->decision, $history->via, $history->note]);
        $this->assertSame(1, DB::table('g2g_event')->where('type', 'signals.opportunity.converted')->count());
        $this->assertSame(1, DB::table('g2g_event')->where('type', 'crm.opportunity.created')->count());
    }

    public function test_a_second_press_answers_the_same_opportunity_and_makes_nothing(): void
    {
        $signal = $this->signal();
        $first = $this->convert($signal->id)->assertCreated();
        $before = [DB::table('crm_organizations')->count(), DB::table('crm_opportunities')->count(), DB::table('g2g_signal_reviews')->count(), DB::table('g2g_event')->count(), DB::table('gtm_activities')->count()];

        $again = $this->convert($signal->id, ['opportunity' => ['name' => 'Another name']])->assertOk();

        $this->assertSame([$first->json('data.opportunity_id'), false], [$again->json('data.opportunity_id'), $again->json('data.created')]);
        $this->assertSame($before, [DB::table('crm_organizations')->count(), DB::table('crm_opportunities')->count(), DB::table('g2g_signal_reviews')->count(), DB::table('g2g_event')->count(), DB::table('gtm_activities')->count()]);
    }

    public function test_the_choices_of_the_pop_up_are_honoured_a_new_person_the_star_the_role_and_the_opportunitys_details(): void
    {
        $signal = $this->signal();

        $this->convert($signal->id, [
            'organizationName' => 'Greenfield Group', 'industry' => 'Schools',
            'person' => ['firstName' => 'Asha', 'lastName' => 'Rao', 'email' => 'asha@greenfield.example', 'phone' => '+91 98250 11111', 'title' => 'Principal'], 'makePrimary' => true, 'contactRole' => 'Decision maker',
            'opportunity' => ['name' => 'Campus software', 'amount' => 250000, 'currency' => 'INR', 'closeDate' => '2027-03-31', 'stage' => 'Qualification'],
        ])->assertCreated()->assertJsonPath('data.primary_set', true);

        $org = DB::table('crm_organizations')->sole();
        $person = DB::table('crm_contacts')->sole();
        $opp = DB::table('crm_opportunities')->sole();
        $this->assertSame(['Greenfield Group', 'Schools'], [$org->name, $org->industry]);
        $this->assertSame(['Asha', 'Rao', 'Principal', 'asha@greenfield.example', (int) $org->id], [$person->first_name, $person->last_name, $person->title, $person->email, (int) $person->organization_id]);
        $this->assertSame(['Campus software', 250000.0, 'INR', '2027-03-31', 'Qualification'], [$opp->name, (float) $opp->amount, $opp->currency, substr((string) $opp->closing_date, 0, 10), $opp->sales_stage]);
        $this->assertSame([[(int) $opp->id, (int) $person->id]], DB::table('crm_opportunity_contacts')->get()->map(fn ($r) => [(int) $r->opportunity_id, (int) $r->contact_id])->all(), 'the person is on the opportunity');
        $this->assertSame(1, (int) DB::table('crm_contacts')->where('id', $person->id)->value('is_primary'), 'the star');
        $this->assertSame('Decision maker', DB::table('crm_contacts')->where('id', $person->id)->value('contact_role'));
    }

    public function test_an_existing_organization_and_an_existing_person_are_used_not_duplicated(): void
    {
        $signal = $this->signal();
        $org = $this->makeOrganization(self::T, ['name' => 'Greenfield Parent Trust']);
        $contact = $this->makeContact(self::T, ['organization_id' => $org]);

        $this->convert($signal->id, ['existingOrganizationId' => $org, 'existingContactId' => $contact])->assertCreated()->assertJsonPath('data.organization_created', false);

        $this->assertSame(1, DB::table('crm_organizations')->count());
        $this->assertSame(1, DB::table('crm_contacts')->count());
        $this->assertSame([$org, $contact], [(int) DB::table('crm_opportunities')->value('organization_id'), (int) DB::table('crm_opportunity_contacts')->value('contact_id')]);
    }

    public function test_an_organization_with_exactly_the_companys_name_is_reused_as_convert_lead_does(): void
    {
        $existing = $this->makeOrganization(self::T, ['name' => 'Greenfield School']);

        $this->convert($this->signal()->id)->assertCreated()->assertJsonPath('data.organization_created', false);

        $this->assertSame([1, $existing], [DB::table('crm_organizations')->count(), (int) DB::table('crm_opportunities')->value('organization_id')]);
    }

    // ── what it refuses ──────────────────────────────────────────────────────

    public function test_an_organization_that_is_not_this_organisations_own_live_company_is_refused_and_nothing_is_written(): void
    {
        $signal = $this->signal();
        $theirs = $this->makeOrganization(self::T2, ['name' => 'Their Company']);
        $removed = $this->makeOrganization(self::T, ['name' => 'Removed Company']);
        DB::table('crm_organizations')->where('id', $removed)->update(['deleted_at' => now()]);

        $this->convert($signal->id, ['existingOrganizationId' => $theirs])->assertStatus(422)->assertJsonPath('message', 'Choose a company from the list.');
        $this->convert($signal->id, ['existingOrganizationId' => $removed])->assertStatus(422);
        $this->convert($signal->id, ['existingOrganizationId' => 987654])->assertStatus(422);

        $this->assertSame([0, 0, 0], [DB::table('crm_opportunities')->count(), DB::table('g2g_signal_reviews')->count(), DB::table('gtm_accounts')->count()]);
    }

    public function test_a_person_that_is_not_this_organisations_or_is_asked_for_twice_is_refused(): void
    {
        $signal = $this->signal();
        $theirs = $this->makeContact(self::T2);
        $mine = $this->makeContact(self::T);

        $this->convert($signal->id, ['existingContactId' => $theirs])->assertStatus(422)->assertJsonPath('message', 'That existing Contact was not found.');
        $this->convert($signal->id, ['existingContactId' => $mine, 'person' => ['lastName' => 'Rao']])->assertStatus(422);
        $this->convert($signal->id, ['person' => ['firstName' => 'Asha']])->assertStatus(422)->assertJsonPath('message', 'A new person needs a last name.');
        $this->convert($signal->id, ['opportunity' => ['currency' => 'ZZZZ']])->assertStatus(422);
        $this->convert($signal->id, ['contactRole' => 'Not A Role'])->assertStatus(422);
        $this->convert($signal->id, ['assignedTo' => $this->user([], self::T2)['id']])->assertStatus(422);

        $this->assertSame(0, DB::table('crm_opportunities')->count());
        $this->assertSame(0, DB::table('crm_organizations')->count());
    }

    public function test_another_organisations_signal_is_a_404_and_writes_nothing(): void
    {
        $theirs = $this->makeSignal(self::T2);

        $this->convert($theirs->id)->assertNotFound();
        $this->convert(987654)->assertNotFound();

        $this->assertSame([0, 0], [DB::table('crm_opportunities')->count(), DB::table('crm_organizations')->count()]);
    }

    public function test_the_account_moves_to_the_opportunity_stage_is_linked_to_the_organization_and_never_goes_down(): void
    {
        foreach (['target' => 'opportunity', 'engaged' => 'opportunity', 'opportunity' => 'opportunity', 'customer' => 'customer', 'churned' => 'churned', 'disqualified' => 'disqualified'] as $from => $to) {
            $signal = $this->signal();
            $account = $this->account($signal->company_id, $from);

            $res = $this->convert($signal->id)->assertCreated();

            $this->assertSame($to, DB::table('gtm_accounts')->where('id', $account)->value('stage'), "{$from} -> {$to}");
            $this->assertSame((int) $res->json('data.organization_id'), (int) DB::table('gtm_accounts')->where('id', $account)->value('crm_organization_id'), 'the account knows its organization');
            $this->assertSame($account, $res->json('data.account_id'));
        }
        // A company with no account gets one (the automatic Prospecting step, were it missing) and it is an opportunity.
        $signal = $this->signal();
        $this->convert($signal->id)->assertCreated();
        $this->assertSame('opportunity', DB::table('gtm_accounts')->where('company_id', $signal->company_id)->value('stage'));
    }

    // ── rights ───────────────────────────────────────────────────────────────

    public function test_it_needs_crm_opportunities_add_and_for_a_new_organization_organizations_add(): void
    {
        $signal = $this->signal();
        $noOpportunities = $this->converter([self::ORGS_LINK => ['view', 'add']]);
        $noOrganizations = $this->converter([self::OPPS_LINK => ['view', 'add']]);
        $employee = $this->user([self::OPPS_LINK => ['view', 'add'], self::ORGS_LINK => ['view', 'add']], self::T);

        $this->convert($signal->id, [], $noOpportunities)->assertForbidden();                       // the Signals tier, no CRM Opportunities add
        $this->convert($signal->id, [], $employee)->assertForbidden();                              // CRM rights, but not the Signals tier
        $this->convert($signal->id, [], $noOrganizations)->assertForbidden();                       // would make a NEW organization without Organizations add
        $this->postJson("/api/signals/opportunities/{$signal->id}/convert-to-opportunity")->assertUnauthorized();
        $this->assertSame([0, 0], [DB::table('crm_opportunities')->count(), DB::table('crm_organizations')->count()]);

        $org = $this->makeOrganization(self::T, ['name' => 'Existing Co']);
        $this->convert($signal->id, ['existingOrganizationId' => $org], $noOrganizations)->assertCreated();   // an existing one needs no Organizations add

        $route = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => in_array('POST', $r->methods(), true) && $r->uri() === 'api/signals/opportunities/{id}/convert-to-opportunity');
        $this->assertNotNull($route);
        $this->assertContains('profile:admin,hr,manager', $route->gatherMiddleware());
        $this->assertContains('platformright:/module/crm/sales/opportunities,add', $route->gatherMiddleware());
    }

    public function test_a_server_without_the_link_column_refuses_plainly(): void
    {
        (require base_path('database/migrations/2026_12_27_090100_add_source_signal_to_crm_opportunities.php'))->down();
        $signal = $this->signal();

        $this->convert($signal->id)->assertStatus(503)->assertJsonPath('code', 'not_ready');

        $this->assertSame([0, 0], [DB::table('crm_opportunities')->count(), DB::table('crm_organizations')->count()]);
    }

    public function test_the_stand_together_rule_nothing_is_kept_when_the_history_line_cannot_be_written(): void
    {
        $signal = $this->signal();
        Schema::drop('g2g_event');

        $this->convert($signal->id)->assertStatus(500);

        $this->assertSame([0, 0, 0, 0], [DB::table('crm_opportunities')->count(), DB::table('crm_organizations')->count(), DB::table('g2g_signal_reviews')->count(), DB::table('gtm_accounts')->count()]);
    }

    // ── what the signal list says ────────────────────────────────────────────

    public function test_the_signal_list_says_where_each_signal_is_in_the_funnel(): void
    {
        $inProspecting = $this->signal();
        $account = $this->account($inProspecting->company_id, 'engaged');
        $this->contact($account);
        $converted = $this->signal();
        $opportunityId = (int) $this->convert($converted->id)->assertCreated()->json('data.opportunity_id');
        $plain = $this->signal();

        $items = collect($this->getJson('/api/signals/opportunities?product=all&per_page=50', $this->admin['h'])->assertOk()->json('data'))->keyBy('id');

        $this->assertSame(['account_id' => $account, 'stage' => 'engaged', 'contacts_count' => 1], $items[$inProspecting->id]['prospecting']);
        $this->assertNull($items[$inProspecting->id]['converted_opportunity']);
        $this->assertSame(['id' => $opportunityId, 'name' => 'Greenfield School - Opens a new campus'], $items[$converted->id]['converted_opportunity']);
        $this->assertSame('opportunity', $items[$converted->id]['prospecting']['stage']);
        $this->assertNull($items[$plain->id]['prospecting']);
    }

    private function contact(int $account): int
    {
        return (int) DB::table('gtm_contacts')->insertGetId(['sub_institute_id' => self::T, 'account_id' => $account, 'full_name' => 'Rahul Verma', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }
}
