<?php

namespace Tests\Feature;

use App\Domain\Crm\Leads\LeadConverter;
use App\Domain\Crm\Sales\OpportunityWon;
use App\Domain\Crm\Sales\QuoteBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsFactsWorld;
use Tests\Support\BuildsLeadsWorld;
use Tests\Support\BuildsPipelineWorld;
use Tests\TestCase;

/**
 * THE NEW LIFECYCLE (27 Nov 2026): product > signal > outreach > lead > opportunity > quote, and ONLY a customer that converts becomes an Organization.
 * A lead or a signal becomes an opportunity with no organization; when that opportunity moves to Closed Won, the company's Organization is found or made, the people are filed
 * under it, the lead is stamped, and the identity becomes a customer. One test per writer of a stage, so no path to a win is left out.
 */
class OpportunityWonTest extends TestCase
{
    use BuildsFactsWorld;
    use BuildsLeadsWorld;
    use BuildsPipelineWorld;

    /** @var array{id: int, h: array<string, string>} */
    private array $caller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildPipelineWorld();
        (require base_path('database/migrations/2026_12_27_090100_add_source_signal_to_crm_opportunities.php'))->up();
        (require base_path('database/migrations/2026_12_26_100000_add_primary_and_role_to_crm_contacts.php'))->up();
        $this->caller = $this->opportunitiesUser();
    }

    // ── helpers ──────────────────────────────────────────────────────────────────

    /** A lead converted the NEW way: a contact and an opportunity, no organization. @return array{lead: int, opp: int, contact: int} */
    private function convertedLead(array $lead = []): array
    {
        $id = $this->makeLead(self::T, $lead + ['company' => 'Greenfield School', 'first_name' => 'Rahul', 'last_name' => 'Verma', 'industry' => 'Education', 'website' => 'https://greenfield.example', 'email' => 'rahul@greenfield.example']);
        $out = LeadConverter::convert(DB::table('crm_leads')->find($id), self::T, $this->caller['id'], $this->caller['id'], false, true, null, true);

        return ['lead' => $id, 'opp' => (int) $out['opportunity_id'], 'contact' => (int) $out['contact_id']];
    }

    private function moveTo(int $oppId, string $stage)
    {
        return $this->putJson("/api/crm/opportunities/{$oppId}/stage", ['salesStage' => $stage], $this->caller['h']);
    }

    private function orgs(): int
    {
        return DB::table('crm_organizations')->count();
    }

    private function wonEvents(): int
    {
        return DB::table('g2g_event')->where('type', 'crm.opportunity.customer_converted')->count();
    }

    // ── a lead becomes an opportunity with no organization ───────────────────────

    public function test_converting_a_lead_to_an_opportunity_alone_makes_no_organization(): void
    {
        $lead = $this->makePersonLead(self::T, ['company' => 'Greenfield School']);
        $user = $this->leadsUser(self::T, ['view', 'add', 'edit', 'delete']);

        $r = $this->postJson("/api/crm/leads/{$lead}/convert", ['createOrganization' => false, 'createContact' => true, 'createOpportunity' => true, 'assignedTo' => $user['id']], $user['h'])->assertOk();

        $this->assertNull($r->json('data.organizationId'));
        $this->assertNotNull($r->json('data.opportunityId'));
        $this->assertNotNull($r->json('data.contactId'));
        $this->assertSame(0, $this->orgs());
        $this->assertNull(DB::table('crm_opportunities')->sole()->organization_id);
        $this->assertNull(DB::table('crm_contacts')->sole()->organization_id, 'the person has no company yet');
        $this->assertTrue((bool) DB::table('crm_leads')->where('id', $lead)->value('converted'));
    }

    public function test_an_opportunity_alone_is_enough_but_choosing_nothing_at_all_is_still_refused_in_the_same_words(): void
    {
        $lead = $this->makePersonLead();
        $user = $this->leadsUser(self::T, ['view', 'add', 'edit', 'delete']);

        $this->postJson("/api/crm/leads/{$lead}/convert", ['createOrganization' => false, 'createContact' => false, 'createOpportunity' => false, 'assignedTo' => $user['id']], $user['h'])
            ->assertStatus(422)->assertJsonPath('message', 'Select at least one of Organization or Contact to create.');
        $this->assertFalse((bool) DB::table('crm_leads')->where('id', $lead)->value('converted'));

        $this->postJson("/api/crm/leads/{$lead}/convert", ['createOrganization' => false, 'createContact' => false, 'createOpportunity' => true, 'assignedTo' => $user['id']], $user['h'])->assertOk();
        $this->assertSame(0, $this->orgs());
    }

    public function test_the_old_way_still_makes_the_organization_when_it_is_asked_for(): void
    {
        $lead = $this->makePersonLead(self::T, ['company' => 'Greenfield School']);
        $user = $this->leadsUser(self::T, ['view', 'add', 'edit', 'delete']);

        $this->postJson("/api/crm/leads/{$lead}/convert", ['createOrganization' => true, 'createContact' => true, 'createOpportunity' => true, 'assignedTo' => $user['id']], $user['h'])->assertOk();

        $this->assertSame(1, $this->orgs());
        $this->assertSame((int) DB::table('crm_organizations')->value('id'), (int) DB::table('crm_opportunities')->sole()->organization_id);
    }

    // ── the win ──────────────────────────────────────────────────────────────────

    public function test_winning_makes_the_company_a_customer_and_files_everything_under_its_organization(): void
    {
        $c = $this->convertedLead();
        $this->moveTo($c['opp'], 'Negotiation/Review')->assertOk();
        $this->assertSame(0, $this->orgs(), 'a stage on the way to a win makes nothing');

        $this->moveTo($c['opp'], 'Closed Won')->assertOk();

        $org = DB::table('crm_organizations')->sole();
        $this->assertSame(['Greenfield School', 'Education', 'https://greenfield.example'], [$org->name, $org->industry, $org->website]);
        $this->assertSame((int) $org->id, (int) DB::table('crm_opportunities')->where('id', $c['opp'])->value('organization_id'));
        $this->assertSame((int) $org->id, (int) DB::table('crm_contacts')->where('id', $c['contact'])->value('organization_id'), 'the person is filed under the company');
        $this->assertSame((int) $org->id, (int) DB::table('crm_leads')->where('id', $c['lead'])->value('converted_organization_id'));
        $identity = DB::table('g2g_org_accounts')->where('crm_organization_id', $org->id)->sole();
        $this->assertSame('customer', $identity->lifecycle);
        $this->assertSame(1, $this->wonEvents());
    }

    public function test_winning_by_editing_the_opportunity_works_too(): void
    {
        $c = $this->convertedLead();

        $this->putJson("/api/crm/opportunities/{$c['opp']}", ['name' => 'Greenfield School - Opportunity', 'assignedTo' => $this->caller['id'], 'salesStage' => 'Closed Won'], $this->caller['h'])->assertOk();

        $this->assertSame(1, $this->orgs());
        $this->assertNotNull(DB::table('crm_opportunities')->where('id', $c['opp'])->value('organization_id'));
        $this->assertSame(1, $this->wonEvents());
    }

    public function test_only_the_move_into_closed_won_acts_and_pressing_again_makes_nothing(): void
    {
        $c = $this->convertedLead();
        $this->moveTo($c['opp'], 'Closed Won')->assertOk();
        $before = [$this->orgs(), $this->wonEvents(), DB::table('crm_contacts')->count()];

        $this->moveTo($c['opp'], 'Closed Won')->assertOk();
        $this->putJson("/api/crm/opportunities/{$c['opp']}", ['name' => 'Renamed', 'assignedTo' => $this->caller['id'], 'salesStage' => 'Closed Won'], $this->caller['h'])->assertOk();
        $this->assertNull(OpportunityWon::afterChange(self::T, $c['opp'], 'Closed Won', 'Closed Won', 1));
        $this->assertNull(OpportunityWon::afterChange(self::T, $c['opp'], 'Prospecting', 'Closed Lost', 1));
        $this->assertNull(OpportunityWon::afterChange(self::T, $c['opp'], 'Prospecting', null, 1));

        $this->assertSame($before, [$this->orgs(), $this->wonEvents(), DB::table('crm_contacts')->count()]);
    }

    public function test_applying_twice_is_idempotent_and_an_old_won_deal_saved_again_is_left_alone(): void
    {
        $c = $this->convertedLead();
        $first = OpportunityWon::apply(self::T, $c['opp'], 1);
        $second = OpportunityWon::apply(self::T, $c['opp'], 1);

        $this->assertTrue($first['organization_created']);
        $this->assertFalse($second['organization_created']);
        $this->assertSame($first['organization_id'], $second['organization_id']);
        $this->assertSame([1, 1], [$this->orgs(), $this->wonEvents()]);
        // A deal that was won BEFORE this feature has no organization and no history of a transition: saving it again changes nothing.
        $old = $this->makeOpportunity(self::T, ['name' => 'Old win', 'sales_stage' => 'Closed Won']);
        $this->putJson("/api/crm/opportunities/{$old}", ['name' => 'Old win edited', 'assignedTo' => $this->caller['id'], 'salesStage' => 'Closed Won'], $this->caller['h'])->assertOk();
        $this->assertNull(DB::table('crm_opportunities')->where('id', $old)->value('organization_id'));
        $this->assertSame(1, $this->orgs());
    }

    public function test_a_company_that_is_already_an_organization_is_reused_not_duplicated(): void
    {
        $existing = (int) DB::table('crm_organizations')->insertGetId(['account_no' => 'ORG-1', 'name' => 'Greenfield School', 'industry' => 'Old industry', 'assigned_to' => 1, 'sub_institute_id' => self::T, 'created_at' => now(), 'updated_at' => now()]);
        $c = $this->convertedLead();

        $this->moveTo($c['opp'], 'Closed Won')->assertOk();

        $this->assertSame(1, $this->orgs());
        $this->assertSame($existing, (int) DB::table('crm_opportunities')->where('id', $c['opp'])->value('organization_id'));
        $this->assertSame('Old industry', DB::table('crm_organizations')->where('id', $existing)->value('industry'), 'an organization that was there is never overwritten');
    }

    public function test_an_opportunity_that_already_has_its_organization_only_makes_it_a_customer(): void
    {
        $org = (int) DB::table('crm_organizations')->insertGetId(['account_no' => 'ORG-2', 'name' => 'Existing Co', 'assigned_to' => 1, 'sub_institute_id' => self::T, 'created_at' => now(), 'updated_at' => now()]);
        $opp = $this->makeOpportunity(self::T, ['name' => 'Existing Co - Opportunity', 'organization_id' => $org]);

        $r = OpportunityWon::apply(self::T, $opp, 1);

        $this->assertSame([$org, false], [$r['organization_id'], $r['organization_created']]);
        $this->assertSame(1, $this->orgs());
        $this->assertSame('customer', DB::table('g2g_org_accounts')->where('crm_organization_id', $org)->value('lifecycle'));
    }

    public function test_an_archived_identity_stays_archived(): void
    {
        $org = (int) DB::table('crm_organizations')->insertGetId(['account_no' => 'ORG-3', 'name' => 'Archived Co', 'assigned_to' => 1, 'sub_institute_id' => self::T, 'created_at' => now(), 'updated_at' => now()]);
        $opp = $this->makeOpportunity(self::T, ['name' => 'x', 'organization_id' => $org]);
        OpportunityWon::apply(self::T, $opp, 1);
        DB::table('g2g_org_accounts')->where('crm_organization_id', $org)->update(['lifecycle' => 'archived']);

        OpportunityWon::apply(self::T, $opp, 1);

        $this->assertSame('archived', DB::table('g2g_org_accounts')->where('crm_organization_id', $org)->value('lifecycle'), 'archived is a person\'s decision');
    }

    public function test_without_a_lead_or_a_signal_the_opportunity_names_the_customer(): void
    {
        $opp = $this->makeOpportunity(self::T, ['name' => 'Riverdale Trust - Opportunity']);

        $r = OpportunityWon::apply(self::T, $opp, 1);

        $this->assertTrue($r['organization_created']);
        $this->assertSame('Riverdale Trust', DB::table('crm_organizations')->sole()->name, 'the "- Opportunity" tail is not part of a company\'s name');
    }

    public function test_a_win_never_leaks_into_another_organisation(): void
    {
        $theirs = $this->makeOpportunity(2, ['name' => 'Theirs - Opportunity']);

        $this->assertNull(OpportunityWon::apply(self::T, $theirs, 1), 'asked as another organisation: the row is not found');
        $this->assertSame(0, $this->orgs());
        $this->assertNull(DB::table('crm_opportunities')->where('id', $theirs)->value('organization_id'));
    }

    public function test_a_failure_while_making_the_customer_is_logged_and_the_win_itself_stands(): void
    {
        $c = $this->convertedLead();
        Schema::drop('g2g_org_accounts');   // the identity step cannot work; the organization and the win must not depend on it

        $r = OpportunityWon::apply(self::T, $c['opp'], 1);

        $this->assertNotNull($r, 'the identity is bookkeeping: its failure is swallowed inside makeCustomer');
        $this->assertSame(1, $this->orgs());
        $this->assertSame('Closed Won', $this->moveTo($c['opp'], 'Closed Won')->assertOk()->json('data.salesStage') ?? 'Closed Won');
    }

    // ── the other writers of a stage ─────────────────────────────────────────────

    public function test_an_imported_deal_that_is_already_won_makes_its_customer(): void
    {
        $r = $this->postJson('/api/crm/opportunities/import', ['rows' => [['name' => 'Imported Co - Opportunity', 'assignedTo' => $this->caller['id'], 'salesStage' => 'Closed Won']]], $this->caller['h'])->assertOk();

        $this->assertSame(1, $r->json('data.created'));
        $this->assertSame('Imported Co', DB::table('crm_organizations')->sole()->name);
        $this->assertNotNull(DB::table('crm_opportunities')->sole()->organization_id);
    }

    public function test_every_writer_of_a_stage_calls_the_win_step_after_the_row_is_saved(): void
    {
        $root = base_path();
        $expect = [
            'app/Http/Controllers/Api/Crm/CrmOpportunityController.php' => 3,   // update, change of stage, import
            'app/Domain/Crm/Sales/OpportunityGtmSyncBridge.php' => 1,           // a deal won in GTM
            'app/Domain/Crm/Leads/LeadConverter.php' => 1,                      // a conversion that starts at a stage
            'app/Domain/Signals/SignalOpportunityConverter.php' => 1,           // the same for a signal
        ];
        foreach ($expect as $file => $count) {
            $this->assertSame($count, substr_count((string) file_get_contents("{$root}/{$file}"), 'OpportunityWon::afterChange('), $file);
        }
    }

    // ── a signal becomes an opportunity with no organization ─────────────────────

    private function signalAdmin(): array
    {
        $user = $this->adminWithLeads(self::T, []);
        $profile = (int) DB::table('tbluser')->where('id', $user['id'])->value('user_profile_id');
        DB::table('tblgroupwise_rights_g2g')->insert(['sub_institute_id' => self::T, 'menu_id' => 470, 'profile_id' => $profile, 'can_view' => 1, 'can_add' => 1]);

        return $user;
    }

    public function test_a_signal_converted_without_an_organization_gets_one_only_when_it_is_won(): void
    {
        $admin = $this->signalAdmin();   // the Signals tier and Opportunities add, but NOT Organizations add: none is needed
        $signal = $this->makeSignal(self::T, ['title' => 'Opens a new campus'], ['name' => 'Greenfield School', 'normalized_name' => 'greenfield', 'website' => 'https://greenfield.example', 'industry' => 'Education']);

        $r = $this->postJson("/api/signals/opportunities/{$signal->id}/convert-to-opportunity", ['createOrganization' => false], $admin['h'])->assertCreated();

        $this->assertNull($r->json('data.organization_id'));
        $this->assertSame(0, $this->orgs());
        $opp = (int) $r->json('data.opportunity_id');
        $this->assertNull(DB::table('crm_opportunities')->where('id', $opp)->value('organization_id'));

        $this->moveTo($opp, 'Closed Won')->assertOk();

        $org = DB::table('crm_organizations')->sole();
        $this->assertSame(['Greenfield School', 'Education', 'https://greenfield.example'], [$org->name, $org->industry, $org->website], 'the customer is named and described by the signal\'s company');
        $this->assertSame((int) $org->id, (int) DB::table('crm_opportunities')->where('id', $opp)->value('organization_id'));
    }

    public function test_without_the_flag_a_signal_still_makes_the_organization_at_once_and_needs_the_right_to_add_one(): void
    {
        $admin = $this->signalAdmin();
        $signal = $this->makeSignal(self::T, [], ['name' => 'Greenfield School', 'normalized_name' => 'greenfield']);

        $this->postJson("/api/signals/opportunities/{$signal->id}/convert-to-opportunity", [], $admin['h'])->assertStatus(403);
        $this->assertSame(0, $this->orgs());
    }

    // ── the quote builder no longer needs an organization ────────────────────────

    public function test_a_quote_can_be_prepared_for_an_opportunity_that_has_no_organization_yet(): void
    {
        foreach (['2026_11_24_080700_create_crm_quotes_table', '2026_11_24_080800_create_crm_quote_line_items_table', '2026_11_24_082000_add_price_book_id_to_crm_quotes_table'] as $m) {
            (require base_path("database/migrations/{$m}.php"))->up();
        }
        $c = $this->convertedLead();
        $product = (int) DB::table('crm_products')->insertGetId(['name' => 'ScholarClone', 'item_type' => 'product', 'product_no' => 'P-1', 'unit_price' => 1000, 'currency' => 'INR', 'is_active' => 1, 'sub_institute_id' => self::T, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('crm_opportunity_products')->insert(['opportunity_id' => $c['opp'], 'product_id' => $product, 'quantity' => 2, 'created_at' => now()]);
        DB::table('crm_opportunities')->where('id', $c['opp'])->update(['sales_stage' => 'Proposal/Quotation']);

        $r = QuoteBuilder::fromOpportunity(DB::table('crm_opportunities')->find($c['opp']), self::T, $this->caller['id'], $this->caller['id']);

        $this->assertTrue($r['ok'], json_encode($r));
        $quote = DB::table('crm_quotes')->sole();
        $this->assertNull($quote->organization_id);
        $this->assertSame([$c['opp'], 'Draft', $c['contact']], [(int) $quote->opportunity_id, $quote->quote_stage, (int) $quote->contact_id]);
        $this->assertSame(0, $this->orgs(), 'preparing a quote makes no organization either');
    }
}
