<?php

namespace Tests\Feature;

use App\Domain\Crm\Leads\LeadConverter;
use App\Domain\Crm\Leads\LeadFacts;
use App\Domain\Facts\FactWriter;
use App\Domain\Gtm\Workflows\WorkflowActions;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsFactsWorld;
use Tests\Support\BuildsLeadsWorld;
use Tests\TestCase;

/**
 * Converting a lead (slice 6, acceptance 4): a lead that names no person makes no placeholder contact, and what the facts ledger knows about a lead
 * follows it to the contact it becomes. Consent follows too (slice 4's carry-over, tested in FactsConsentRecorderTest; here only that it still runs beside
 * the facts).
 */
class LeadConversionTest extends TestCase
{
    use BuildsFactsWorld;
    use BuildsLeadsWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildLeadsWorld();
    }

    /** @param array<string, mixed> $over */
    private function lead(array $over = []): object
    {
        $id = $this->makeLead(self::T, $over + ['first_name' => 'Rahul', 'last_name' => 'Verma', 'company' => 'Greenfield School', 'email' => 'rahul@greenfield.example', 'assigned_to' => 1]);

        return DB::table('crm_leads')->where('id', $id)->first();
    }

    /** The lead a signal leaves: named after the company, no first name. */
    private function signalLead(array $over = []): object
    {
        return $this->lead($over + ['first_name' => null, 'last_name' => 'Tapovan Vidya Vihar', 'company' => 'Tapovan Vidya Vihar', 'email' => null]);
    }

    private function convert(object $lead, bool $org = true, bool $contact = true, ?int $existing = null, bool $opp = false): array
    {
        return LeadConverter::convert($lead, self::T, 9, 1, $org, $contact, $existing, $opp);
    }

    private function fact(string $type, int $id, string $field, string $value, array $evidence = ['site.own-page'], int $tenant = self::T): array
    {
        return app(FactWriter::class)->propose($tenant, $type, $id, $field, $value, $this->evidence(...$evidence), 'm', 'agent:x');
    }

    // ── no placeholder contact ────────────────────────────────────────────────

    public function test_a_lead_named_after_its_company_makes_the_organisation_and_the_opportunity_but_no_contact(): void
    {
        $lead = $this->signalLead();

        $out = $this->convert($lead, true, true, null, true);

        $this->assertNotNull($out['organization_id']);
        $this->assertNotNull($out['opportunity_id']);
        $this->assertNull($out['contact_id']);
        $this->assertTrue($out['contact_skipped']);
        $this->assertSame(0, DB::table('crm_contacts')->count(), 'no contact whose last name is the company name');
        $this->assertSame(0, DB::table('crm_opportunity_contacts')->count());
        $converted = DB::table('crm_leads')->where('id', $lead->id)->first();
        $this->assertSame([1, null], [(int) $converted->converted, $converted->converted_contact_id]);
        $this->assertSame('Tapovan Vidya Vihar', DB::table('crm_organizations')->where('id', $out['organization_id'])->value('name'));
    }

    public function test_the_inbox_placeholder_and_a_blank_name_are_no_person_either(): void
    {
        $this->assertTrue(LeadConverter::hasNoPerson((object) ['first_name' => null, 'last_name' => 'Signal Lead', 'company' => 'Greenfield']));
        $this->assertTrue(LeadConverter::hasNoPerson((object) ['first_name' => '', 'last_name' => '  Greenfield   SCHOOL ', 'company' => 'greenfield school']), 'case and spacing do not make it a person');
        $this->assertTrue(LeadConverter::hasNoPerson((object) ['first_name' => null, 'last_name' => '', 'company' => 'Greenfield']));
        $this->assertTrue(LeadConverter::hasNoPerson((object) ['last_name' => 'Greenfield', 'company' => null]) === false, 'a last name with no company to compare it to is a name');
    }

    public function test_anything_that_could_be_a_person_still_gets_its_contact(): void
    {
        foreach ([
            'first and last name' => ['first_name' => 'Rahul', 'last_name' => 'Verma', 'company' => 'Greenfield School'],
            'a last name only (the form only requires that)' => ['first_name' => null, 'last_name' => 'Verma', 'company' => 'Greenfield School'],
            'a first name even when the last equals the company' => ['first_name' => 'Rahul', 'last_name' => 'Greenfield School', 'company' => 'Greenfield School'],
            'no company at all' => ['first_name' => null, 'last_name' => 'Verma', 'company' => null],
        ] as $label => $over) {
            $out = $this->convert($this->lead($over), false, true);

            $this->assertNotNull($out['contact_id'], $label);
            $this->assertFalse($out['contact_skipped'], $label);
        }
        $this->assertSame(4, DB::table('crm_contacts')->count());
    }

    public function test_a_lead_with_no_person_can_still_be_attached_to_an_existing_contact(): void
    {
        $contact = $this->makeContact(self::T, ['first_name' => 'Priya', 'last_name' => 'Sharma']);
        $lead = $this->signalLead();

        $out = $this->convert($lead, true, true, $contact);

        $this->assertSame($contact, $out['contact_id']);
        $this->assertFalse($out['contact_skipped']);
        $this->assertSame(1, DB::table('crm_contacts')->count(), 'nothing was made for the person nobody knows');
    }

    public function test_the_response_says_no_contact_was_made_and_a_contact_only_request_is_refused(): void
    {
        $user = $this->leadsUser();
        $lead = (int) $this->signalLead()->id;

        $refused = $this->postJson("/api/crm/leads/{$lead}/convert", ['createOrganization' => false, 'createContact' => true, 'assignedTo' => $user['id']], $user['h'])->assertStatus(422);
        $this->assertStringContainsString('names no person', $refused->json('message'));
        $this->assertSame(0, (int) DB::table('crm_leads')->where('id', $lead)->value('converted'), 'a lead is not marked converted for nothing');
        $this->assertSame(0, DB::table('crm_organizations')->count());

        $ok = $this->postJson("/api/crm/leads/{$lead}/convert", ['createOrganization' => true, 'createContact' => true, 'createOpportunity' => true, 'assignedTo' => $user['id']], $user['h'])->assertOk();
        $this->assertSame('Lead converted.', $ok->json('message'), 'the message the screen already shows is unchanged');
        $this->assertTrue($ok->json('data.contactSkipped'));
        $this->assertStringContainsString('No contact was created', $ok->json('data.notice'));
        $this->assertNull($ok->json('data.contactId'));
        $this->assertNotNull($ok->json('data.organizationId'));
        $this->assertNotNull($ok->json('data.opportunityId'));
    }

    public function test_a_normal_conversion_answers_exactly_as_before(): void
    {
        $user = $this->leadsUser();
        $lead = (int) $this->lead()->id;

        $ok = $this->postJson("/api/crm/leads/{$lead}/convert", ['createOrganization' => true, 'createContact' => true, 'assignedTo' => $user['id']], $user['h'])->assertOk();

        $this->assertSame(['organizationId', 'contactId', 'opportunityId'], array_keys($ok->json('data')), 'no new key appears unless a contact was skipped');
        $this->assertNotNull($ok->json('data.contactId'));
    }

    public function test_the_automations_note_does_not_name_a_contact_that_was_not_made(): void
    {
        $lead = $this->signalLead(['lead_status' => 'Qualified', 'phone' => '+91 98765 43210']);
        $ctx = ['values' => [], 'ids' => ['lead' => (int) $lead->id]];

        $result = (new WorkflowActions)->run('convert_lead', ['create_opportunity' => false], $ctx, (object) ['id' => 1, 'sub_institute_id' => self::T], (object) ['id' => 1, 'created_by' => 9]);

        $this->assertNull($result['contact_id']);
        $note = (string) DB::table('crm_lead_comments')->where('lead_id', $lead->id)->value('comment');
        $this->assertStringContainsString('no Contact (the lead names no person, so none was made)', $note);
        $this->assertStringNotContainsString('Contact #,', $note);
    }

    // ── facts follow the lead ─────────────────────────────────────────────────

    public function test_what_the_ledger_knows_about_the_lead_follows_it_to_its_new_contact_with_its_evidence(): void
    {
        $lead = $this->lead();
        $phone = $this->fact('lead', $lead->id, 'phone', '+91 98765 43210', ['site.own-page', 'directory.listing']);
        $title = $this->fact('lead', $lead->id, 'title', 'Principal');
        $website = $this->fact('lead', $lead->id, 'website', 'greenfield.example');

        $out = $this->convert($lead, false, true);

        $contact = $out['contact_id'];
        $this->assertSame(2, $out['facts_moved'], 'the phone and the job title apply to a person');
        $moved = DB::table('g2g_entity_facts')->whereIn('id', [$phone['fact']->id, $title['fact']->id])->get();
        $this->assertSame(['contact', 'contact'], $moved->pluck('entity_type')->all());
        $this->assertSame([$contact, $contact], $moved->pluck('entity_id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(2, DB::table('g2g_entity_fact_evidence')->where('fact_id', $phone['fact']->id)->count(), 'its evidence went with it');
        $stays = DB::table('g2g_entity_facts')->where('id', $website['fact']->id)->first();
        $this->assertSame(['lead', (int) $lead->id], [$stays->entity_type, (int) $stays->entity_id], 'a website is an organisation\'s, so it stays on the lead, the archive of what was known');
        $this->assertSame(0, DB::table('g2g_entity_facts')->where('entity_id', '>=', 4_000_000_000_000)->count(), 'no stand-in id is left behind');
    }

    public function test_facts_follow_the_lead_to_an_existing_contact_by_the_ledgers_own_rules(): void
    {
        $lead = $this->lead();
        $contact = $this->makeContact(self::T);
        $leadPhone = $this->fact('lead', $lead->id, 'phone', '+91 98765 43210');
        $contactPhone = $this->fact('contact', $contact, 'phone', '+91 98765 43210');
        // A person turned the lead's suggestion down; the contact's identical suggestion is still waiting.
        app(FactWriter::class)->dismiss(self::T, (int) $leadPhone['fact']->id, 9, 'not theirs');
        $leadMail = $this->fact('lead', $lead->id, 'secondary_email', 'second@greenfield.example');

        $out = $this->convert($lead, false, true, $contact);

        $this->assertSame($contact, $out['contact_id']);
        $this->assertSame('dismissed', DB::table('g2g_entity_facts')->where('id', $contactPhone['fact']->id)->value('status'), 'a value a person refused is not offered again just because the lead became the contact');
        $this->assertSame(0, DB::table('g2g_entity_facts')->where('id', $leadPhone['fact']->id)->count(), 'the identical fact was not duplicated');
        $this->assertSame(['contact', $contact], [DB::table('g2g_entity_facts')->where('id', $leadMail['fact']->id)->value('entity_type'), (int) DB::table('g2g_entity_facts')->where('id', $leadMail['fact']->id)->value('entity_id')]);
    }

    public function test_the_move_never_touches_another_organisations_facts(): void
    {
        $lead = $this->lead();
        // Another organisation's fact about a record that happens to carry the same id as this lead (ids are not shared in real data, but the move must not rely on it).
        $theirLead = $this->makeLead(self::T2);
        $theirs = $this->fact('lead', $theirLead, 'phone', '+91 11111 11111', ['site.own-page'], self::T2);
        DB::table('g2g_entity_facts')->where('id', $theirs['fact']->id)->update(['entity_id' => $lead->id]);   // a stray pointer, as corrupted data could hold
        $mine = $this->fact('lead', $lead->id, 'phone', '+91 22222 22222');

        $this->convert($lead, false, true);

        $stillTheirs = DB::table('g2g_entity_facts')->where('id', $theirs['fact']->id)->first();
        $this->assertSame(['lead', (int) $lead->id, self::T2], [$stillTheirs->entity_type, (int) $stillTheirs->entity_id, (int) $stillTheirs->sub_institute_id]);
        $this->assertSame('contact', DB::table('g2g_entity_facts')->where('id', $mine['fact']->id)->value('entity_type'));
    }

    public function test_a_conversion_with_no_contact_moves_no_facts(): void
    {
        $lead = $this->signalLead();
        $fact = $this->fact('lead', $lead->id, 'phone', '+91 98765 43210');

        $out = $this->convert($lead, true, true);

        $this->assertSame(0, $out['facts_moved']);
        $this->assertSame('lead', DB::table('g2g_entity_facts')->where('id', $fact['fact']->id)->value('entity_type'));
    }

    public function test_a_conversion_still_works_when_the_ledger_cannot_be_moved_and_leaves_the_facts_where_they_were(): void
    {
        $lead = $this->lead();
        $fact = $this->fact('lead', $lead->id, 'phone', '+91 98765 43210');
        // The first update re-labels the lead's facts, the second (inside FactRepoint) moves them: break the second, so the move is half done.
        $updates = 0;
        DB::beforeExecuting(function (string $query) use (&$updates) {
            if (str_contains($query, 'update "g2g_entity_facts"') && ++$updates === 2) {
                throw new \RuntimeException('the ledger is broken');
            }
        });

        $out = $this->convert($lead, false, true);

        $this->assertNotNull($out['contact_id'], 'the contact is made whatever happened to the ledger');
        $this->assertSame(0, $out['facts_moved']);
        $this->assertSame(1, (int) DB::table('crm_leads')->where('id', $lead->id)->value('converted'));
        $this->assertSame('lead', DB::table('g2g_entity_facts')->where('id', $fact['fact']->id)->value('entity_type'), 'the half-done move was undone');
    }

    public function test_a_host_without_the_ledger_converts_as_before(): void
    {
        $lead = $this->lead();
        \Illuminate\Support\Facades\Schema::drop('g2g_entity_fact_evidence');
        \Illuminate\Support\Facades\Schema::drop('g2g_entity_facts');

        $out = $this->convert($lead, false, true);

        $this->assertNotNull($out['contact_id']);
        $this->assertSame(0, $out['facts_moved']);
        $this->assertSame(['moved' => 0, 'dropped' => 0, 'dismissals_kept' => 0, 'superseded' => 0], LeadFacts::followToContact(self::T, (int) $lead->id, $out['contact_id']));
    }

    // ── a lead merged into another one ────────────────────────────────────────

    public function test_merging_a_duplicate_lead_keeps_its_facts_and_its_opt_out_on_the_survivor(): void
    {
        $user = $this->leadsUser();
        $survivor = (int) $this->lead(['email' => 'a@greenfield.example'])->id;
        $duplicate = (int) $this->lead(['email' => 'a@greenfield.example', 'email_opt_out' => 1, 'consent_status' => 'opted_out'])->id;
        $fact = $this->fact('lead', $duplicate, 'phone', '+91 98765 43210');
        DB::table('crm_consent_preferences')->insert(['entity_type' => 'lead', 'entity_id' => $duplicate, 'channel' => 'email', 'status' => 'opted_out', 'source' => 'unsubscribe_link', 'sub_institute_id' => self::T]);

        $this->postJson('/api/crm/leads/merge', ['survivorId' => $survivor, 'duplicateIds' => [$duplicate]], $user['h'])->assertOk();

        $kept = DB::table('crm_leads')->where('id', $survivor)->first();
        $this->assertSame([1, 'opted_out'], [(int) $kept->email_opt_out, $kept->consent_status], 'a duplicate\'s opt-out is never lost in a merge');
        $this->assertSame($survivor, (int) DB::table('g2g_entity_facts')->where('id', $fact['fact']->id)->value('entity_id'), 'its facts follow it');
        $this->assertSame('opted_out', DB::table('crm_consent_preferences')->where('entity_type', 'lead')->where('entity_id', $survivor)->where('channel', 'email')->value('status'));
        $this->assertNotNull(DB::table('crm_leads')->where('id', $duplicate)->value('deleted_at'), 'the duplicate is archived as before');
    }

    public function test_a_lead_merge_still_works_on_a_host_without_the_ledger(): void
    {
        $user = $this->leadsUser();
        $survivor = (int) $this->lead(['email' => 'a@greenfield.example'])->id;
        $duplicate = (int) $this->lead(['email' => 'a@greenfield.example'])->id;
        \Illuminate\Support\Facades\Schema::drop('g2g_entity_fact_evidence');
        \Illuminate\Support\Facades\Schema::drop('g2g_entity_facts');

        $this->postJson('/api/crm/leads/merge', ['survivorId' => $survivor, 'duplicateIds' => [$duplicate]], $user['h'])->assertOk();

        $this->assertNotNull(DB::table('crm_leads')->where('id', $duplicate)->value('deleted_at'));
    }

    public function test_the_lead_keeps_the_opt_out_it_carried_to_the_contact_beside_its_facts(): void
    {
        $lead = $this->lead(['email_opt_out' => 1, 'consent_status' => 'opted_out']);
        $this->fact('lead', $lead->id, 'phone', '+91 98765 43210');

        $out = $this->convert($lead, false, true);

        $contact = DB::table('crm_contacts')->where('id', $out['contact_id'])->first();
        $this->assertSame([1, 'opted_out'], [(int) $contact->email_opt_out, $contact->consent_status]);
        $this->assertSame(1, $out['facts_moved']);
    }
}
