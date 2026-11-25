<?php

namespace Tests\Feature;

use App\Domain\Crm\Leads\LeadConverter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsFactsWorld;
use Tests\Support\BuildsLeadsWorld;
use Tests\TestCase;

/**
 * The one Convert Lead pop-up (S19, 25 Nov 2026), through the real route, rights and controller on in-memory sqlite: the new optional fields of POST /api/crm/leads/{id}/convert.
 * The default behaviour (no new field) is pinned by LeadConversionTest, which is untouched; here only what the pop-up adds: the organization's name and industry, "use an existing
 * organization", the person's Primary star and role (S18), the opportunity's amount / currency / close date / stage, and "Transfer related records to" (comments, e-mails, documents
 * and events of the lead move to the organization, the person or the opportunity, in the same transaction).
 */
class LeadConversionPopupTest extends TestCase
{
    use BuildsFactsWorld;
    use BuildsLeadsWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildLeadsWorld();

        // What the pop-up writes beyond the leads world: the opportunity's money columns, the three comment tables of the targets, the link tables and the S18 star / role.
        Schema::table('crm_opportunities', function ($t) {
            $t->decimal('amount', 15, 2)->nullable();
            $t->string('currency', 10)->nullable();
            $t->date('closing_date')->nullable();
        });
        foreach (['organization' => 'crm_organization_comments', 'contact' => 'crm_contact_comments', 'opportunity' => 'crm_opportunity_comments'] as $key => $table) {
            Schema::create($table, function ($t) use ($key) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger("{$key}_id")->index();
                $t->unsignedBigInteger('sub_institute_id')->index();
                $t->text('comment');
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
            });
        }
        Schema::create('document_library', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->string('title', 191)->nullable();
            $t->timestamp('deleted_at')->nullable();
        });
        foreach ([
            '2026_11_24_084100_create_document_library_links_table', '2026_11_24_084200_create_crm_email_templates_table', '2026_11_24_084300_create_crm_emails_table',
            '2026_11_24_084400_create_crm_email_links_table', '2026_10_10_090000_create_task_management_calendar_events_table', '2026_12_02_090000_create_crm_lead_followups_table',
            '2026_12_26_100000_add_primary_and_role_to_crm_contacts',
        ] as $migration) {
            (require base_path("database/migrations/{$migration}.php"))->up();
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $over */
    private function lead(array $over = [], int $tenant = self::T): int
    {
        return $this->makeLead($tenant, $over + ['first_name' => 'Rahul', 'last_name' => 'Verma', 'company' => 'Greenfield School', 'industry' => 'Retail', 'email' => 'rahul@greenfield.example']);
    }

    /** @param array<string, mixed> $over @return \Illuminate\Testing\TestResponse */
    private function convert(int $lead, array $over = [], ?array $user = null)
    {
        $user ??= $this->leadsUser();

        return $this->postJson("/api/crm/leads/{$lead}/convert", $over + ['createOrganization' => true, 'createContact' => true, 'assignedTo' => $user['id']], $user['h']);
    }

    private function isConverted(int $lead): bool
    {
        return (int) DB::table('crm_leads')->where('id', $lead)->value('converted') === 1;
    }

    /** One of everything a lead can carry, for the transfer tests. @return array{email: int, document: int, event: int} */
    private function seedRelated(int $lead, int $tenant = self::T): array
    {
        DB::table('crm_lead_comments')->insert([
            ['lead_id' => $lead, 'sub_institute_id' => $tenant, 'comment' => 'Called, wants a demo', 'created_by' => 7, 'created_at' => '2026-11-01 09:00:00', 'updated_at' => '2026-11-01 09:00:00'],
            ['lead_id' => $lead, 'sub_institute_id' => $tenant, 'comment' => 'Sent the brochure', 'created_by' => 8, 'created_at' => '2026-11-02 10:30:00', 'updated_at' => '2026-11-03 08:00:00'],
        ]);
        $email = (int) DB::table('crm_emails')->insertGetId(['subject' => 'Hello', 'body' => 'b', 'to_address' => 'a@b.example', 'sub_institute_id' => $tenant, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('crm_email_links')->insert(['email_id' => $email, 'linked_type' => 'LEAD', 'linked_id' => $lead, 'sub_institute_id' => $tenant, 'created_at' => now()]);
        $document = (int) DB::table('document_library')->insertGetId(['sub_institute_id' => $tenant, 'title' => 'Brochure']);
        DB::table('document_library_links')->insert(['document_id' => $document, 'linked_type' => 'LEAD', 'linked_id' => $lead, 'sub_institute_id' => $tenant, 'created_at' => now()]);
        $event = (int) DB::table('task_management_calendar_events')->insertGetId([
            'sub_institute_id' => $tenant, 'syear' => '2026', 'title' => 'Demo', 'start_at' => '2026-12-01 10:00:00', 'end_at' => '2026-12-01 11:00:00',
            'owner_id' => 1, 'created_by' => 1, 'linked_type' => 'LEAD', 'linked_id' => $lead, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['email' => $email, 'document' => $document, 'event' => $event];
    }

    private function followup(int $lead, int $tenant = self::T): int
    {
        return (int) DB::table('crm_lead_followups')->insertGetId([
            'sub_institute_id' => $tenant, 'lead_id' => $lead, 'max_attempts' => 3, 'intervals' => '2,4', 'timezone' => 'Asia/Kolkata', 'subject_template' => 's', 'body_template' => 'b',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── the organization: name, industry, an existing one ────────────────────

    public function test_the_organization_takes_the_name_and_industry_the_pop_up_sends_not_the_leads(): void
    {
        $lead = $this->lead();

        $r = $this->convert($lead, ['organizationName' => 'Greenfield Education Trust', 'industry' => 'Education'])->assertOk();

        $org = DB::table('crm_organizations')->where('id', $r->json('data.organizationId'))->first();
        $this->assertSame(['Greenfield Education Trust', 'Education'], [$org->name, $org->industry]);
        $this->assertSame('Greenfield School', DB::table('crm_leads')->where('id', $lead)->value('company'), 'the lead keeps its own company name');
        $this->assertNull($r->json('data.transferred'), 'nothing moves unless asked');
    }

    public function test_an_existing_organization_is_attached_and_not_duplicated(): void
    {
        $acme = $this->makeOrganization(self::T, ['name' => 'Acme Schools']);
        $lead = $this->lead();

        $r = $this->convert($lead, ['createOrganization' => false, 'existingOrganizationId' => $acme, 'createOpportunity' => true])->assertOk();

        $this->assertSame((string) $acme, $r->json('data.organizationId'));
        $this->assertSame(1, DB::table('crm_organizations')->count(), 'no second company was made');
        $this->assertSame($acme, (int) DB::table('crm_contacts')->where('id', $r->json('data.contactId'))->value('organization_id'));
        $this->assertSame($acme, (int) DB::table('crm_opportunities')->where('id', $r->json('data.opportunityId'))->value('organization_id'));
        $this->assertSame($acme, (int) DB::table('crm_leads')->where('id', $lead)->value('converted_organization_id'));
    }

    public function test_a_company_that_is_not_this_organisations_own_live_company_is_refused_and_nothing_is_written(): void
    {
        $theirs = $this->makeOrganization(self::T2, ['name' => 'Their Co']);
        $gone = $this->makeOrganization(self::T, ['name' => 'Gone Co', 'deleted_at' => now()]);
        $mine = $this->makeOrganization(self::T, ['name' => 'My Co']);
        $lead = $this->lead();

        foreach ([$theirs, $gone, 999999] as $id) {
            $r = $this->convert($lead, ['createOrganization' => false, 'existingOrganizationId' => $id])->assertStatus(422);
            $this->assertSame('Choose a company from the list.', $r->json('message'));
        }
        $both = $this->convert($lead, ['createOrganization' => true, 'existingOrganizationId' => $mine])->assertStatus(422);
        $this->assertStringContainsString('not both', $both->json('message'));

        $this->assertFalse($this->isConverted($lead));
        $this->assertSame(0, DB::table('crm_contacts')->count());
        $this->assertSame(2, DB::table('crm_organizations')->where('sub_institute_id', self::T)->count(), 'mine and the deleted one; nothing new');
    }

    // ── the person: primary star and role ────────────────────────────────────

    public function test_the_new_person_becomes_the_primary_contact_with_the_role_and_the_old_primary_loses_the_star(): void
    {
        $acme = $this->makeOrganization(self::T, ['name' => 'Acme Schools']);
        $boss = $this->makeContact(self::T, ['organization_id' => $acme, 'is_primary' => 1]);
        $lead = $this->lead();

        $r = $this->convert($lead, ['createOrganization' => false, 'existingOrganizationId' => $acme, 'makePrimary' => true, 'contactRole' => 'Decision maker'])->assertOk();

        $person = DB::table('crm_contacts')->where('id', $r->json('data.contactId'))->first();
        $this->assertSame([1, 'Decision maker'], [(int) $person->is_primary, $person->contact_role]);
        $this->assertSame(0, (int) DB::table('crm_contacts')->where('id', $boss)->value('is_primary'));
        $this->assertTrue($r->json('data.primarySet'));
    }

    public function test_a_chosen_person_of_that_company_gets_the_star_and_one_of_another_company_is_left_alone(): void
    {
        $acme = $this->makeOrganization(self::T, ['name' => 'Acme Schools']);
        $other = $this->makeOrganization(self::T, ['name' => 'Other Co']);
        $mine = $this->makeContact(self::T, ['organization_id' => $acme]);
        $foreign = $this->makeContact(self::T, ['organization_id' => $other]);

        $ok = $this->convert($this->lead(), ['createOrganization' => false, 'existingOrganizationId' => $acme, 'existingContactId' => $mine, 'makePrimary' => true, 'contactRole' => 'Billing'])->assertOk();
        $this->assertSame([1, 'Billing', true], [(int) DB::table('crm_contacts')->where('id', $mine)->value('is_primary'), DB::table('crm_contacts')->where('id', $mine)->value('contact_role'), $ok->json('data.primarySet')]);

        $ignored = $this->convert($this->lead(), ['createOrganization' => false, 'existingOrganizationId' => $acme, 'existingContactId' => $foreign, 'makePrimary' => true, 'contactRole' => 'Billing'])->assertOk();
        $this->assertSame([0, null, false], [(int) DB::table('crm_contacts')->where('id', $foreign)->value('is_primary'), DB::table('crm_contacts')->where('id', $foreign)->value('contact_role'), $ignored->json('data.primarySet')]);
    }

    public function test_the_star_is_ignored_without_a_company_and_a_role_outside_the_picklist_is_refused(): void
    {
        $lead = $this->lead();

        $bad = $this->convert($lead, ['contactRole' => 'Wizard'])->assertStatus(422);
        $this->assertSame('Choose a role from the list.', $bad->json('message'));
        $this->assertFalse($this->isConverted($lead));

        $r = $this->convert($lead, ['createOrganization' => false, 'makePrimary' => true])->assertOk();
        $this->assertSame(0, (int) DB::table('crm_contacts')->where('id', $r->json('data.contactId'))->value('is_primary'), 'no company, no star, no error');
        $this->assertFalse($r->json('data.primarySet'));
    }

    // ── the opportunity ──────────────────────────────────────────────────────

    public function test_the_opportunity_keeps_the_name_amount_currency_close_date_and_stage_it_is_given(): void
    {
        $r = $this->convert($this->lead(), ['createOpportunity' => true, 'opportunity' => [
            'name' => 'Greenfield School - Rahul Verma', 'amount' => 125000.5, 'currency' => 'usd', 'closeDate' => '2026-12-31', 'stage' => 'Proposal/Quotation',
        ]])->assertOk();

        $opp = DB::table('crm_opportunities')->where('id', $r->json('data.opportunityId'))->first();
        $this->assertSame(['Greenfield School - Rahul Verma', 125000.5, 'USD', '2026-12-31', 'Proposal/Quotation'], [$opp->name, (float) $opp->amount, $opp->currency, $opp->closing_date, $opp->sales_stage]);
        $history = DB::table('crm_opportunity_stage_history')->where('opportunity_id', $opp->id)->first();
        $this->assertSame('Proposal/Quotation', $history->to_stage, 'its first stage history row is written like any new opportunity');
    }

    public function test_an_opportunity_without_a_currency_gets_the_organisations_default_and_without_details_it_is_as_before(): void
    {
        $withAmount = $this->convert($this->lead(), ['createOpportunity' => true, 'opportunity' => ['amount' => 5000]])->assertOk();
        $opp = DB::table('crm_opportunities')->where('id', $withAmount->json('data.opportunityId'))->first();
        $this->assertSame(['INR', 5000.0, 'Greenfield School - Opportunity'], [$opp->currency, (float) $opp->amount, $opp->name], 'the default name is today\'s name');

        $plain = $this->convert($this->lead(), ['createOpportunity' => true])->assertOk();
        $plainRow = DB::table('crm_opportunities')->where('id', $plain->json('data.opportunityId'))->first();
        $this->assertSame([null, null, null, null], [$plainRow->amount, $plainRow->currency, $plainRow->closing_date, $plainRow->sales_stage]);
    }

    public function test_opportunity_details_are_ignored_when_no_opportunity_is_made_and_checked_when_one_is(): void
    {
        $this->convert($this->lead(), ['createOpportunity' => false, 'opportunity' => ['amount' => 5000, 'currency' => 'XXX']])->assertOk();
        $this->assertSame(0, DB::table('crm_opportunities')->count());

        $lead = $this->lead();
        foreach ([
            'a currency outside the list' => [['currency' => 'XXX'], 'Choose a currency from the list.'],
            'a negative amount' => [['amount' => -1], 'amount'],
            'a date that is no date' => [['closeDate' => 'tomorrow-ish'], 'close'],
            'an opportunity born Closed Lost' => [['stage' => 'Closed Lost'], 'lost'],
        ] as $label => [$details, $needle]) {
            $r = $this->convert($lead, ['createOpportunity' => true, 'opportunity' => $details])->assertStatus(422);
            $this->assertStringContainsStringIgnoringCase($needle, $r->json('message'), $label);
        }
        $this->assertFalse($this->isConverted($lead));
        $this->assertSame(0, DB::table('crm_opportunities')->count());
    }

    // ── transfer related records ─────────────────────────────────────────────

    /** @return array<string, array{0: string}> */
    public static function transferTargets(): array
    {
        return ['to the organization' => ['organization'], 'to the person' => ['contact'], 'to the opportunity' => ['opportunity']];
    }

    #[DataProvider('transferTargets')]
    public function test_each_kind_of_related_record_moves_to_each_target_and_leaves_the_lead(string $target): void
    {
        $lead = $this->lead();
        $seed = $this->seedRelated($lead);
        $followup = $this->followup($lead);
        $type = strtoupper($target);

        $r = $this->convert($lead, ['createOpportunity' => true, 'transferRelatedTo' => $target])->assertOk();

        $ids = ['organization' => (int) $r->json('data.organizationId'), 'contact' => (int) $r->json('data.contactId'), 'opportunity' => (int) $r->json('data.opportunityId')];
        $id = $ids[$target];
        $this->assertSame(['comments' => 2, 'emails' => 1, 'documents' => 1, 'events' => 1], $r->json('data.transferred'));

        $table = "crm_{$target}_comments";
        $comments = DB::table($table)->where("{$target}_id", $id)->orderBy('id')->get();
        $this->assertSame(['Called, wants a demo', 'Sent the brochure'], $comments->pluck('comment')->all());
        $this->assertSame([7, 8], $comments->pluck('created_by')->map(fn ($v) => (int) $v)->all(), 'same authors');
        $this->assertSame(['2026-11-01 09:00:00', '2026-11-02 10:30:00'], $comments->pluck('created_at')->all(), 'same timestamps');
        $this->assertSame('2026-11-03 08:00:00', $comments[1]->updated_at);
        $this->assertSame(self::T, (int) $comments[0]->sub_institute_id);
        $this->assertSame(0, DB::table('crm_lead_comments')->where('lead_id', $lead)->count(), 'the lead\'s own copy is deleted');
        foreach (array_diff(['organization', 'contact', 'opportunity'], [$target]) as $other) {
            $this->assertSame(0, DB::table("crm_{$other}_comments")->count(), "nothing went to the {$other}");
        }

        $this->assertSame([$type, $id], [DB::table('crm_email_links')->where('email_id', $seed['email'])->value('linked_type'), (int) DB::table('crm_email_links')->where('email_id', $seed['email'])->value('linked_id')]);
        $this->assertSame([$type, $id], [DB::table('document_library_links')->where('document_id', $seed['document'])->value('linked_type'), (int) DB::table('document_library_links')->where('document_id', $seed['document'])->value('linked_id')]);
        $event = DB::table('task_management_calendar_events')->where('id', $seed['event'])->first();
        $this->assertSame([$type, $id], [$event->linked_type, (int) $event->linked_id]);
        $this->assertSame($lead, (int) DB::table('crm_lead_followups')->where('id', $followup)->value('lead_id'), 'the follow-up stays on the lead');
        $this->assertSame(0, DB::table('crm_email_links')->where('linked_type', 'LEAD')->count());
    }

    public function test_the_move_is_announced_in_one_audit_line_with_the_counts(): void
    {
        $lead = $this->lead();
        $this->seedRelated($lead);

        $r = $this->convert($lead, ['transferRelatedTo' => 'organization'])->assertOk();

        $rows = DB::table('g2g_event')->where('type', 'crm.lead.related_transferred')->where('entity_type', 'crm_leads')->where('entity_id', $lead)->get();
        $this->assertCount(1, $rows);
        $payload = json_decode((string) $rows[0]->payload, true);
        $this->assertSame(['organization', (int) $r->json('data.organizationId'), 2, 1, 1, 1], [$payload['to'], $payload['id'], $payload['comments'], $payload['emails'], $payload['documents'], $payload['events']]);

        $this->convert($this->lead())->assertOk();
        $this->assertSame(1, DB::table('g2g_event')->where('type', 'crm.lead.related_transferred')->count(), 'a conversion that moves nothing says nothing');
    }

    public function test_nothing_moves_when_no_target_is_chosen_and_another_organisations_rows_never_move(): void
    {
        $lead = $this->lead();
        $this->seedRelated($lead);
        $this->seedRelated($lead, self::T2);   // another organisation's rows that carry the same lead id (ids are not shared in real data, but the move must not rely on it)

        $this->convert($lead)->assertOk();
        $this->assertSame(2, DB::table('crm_lead_comments')->where('lead_id', $lead)->where('sub_institute_id', self::T)->count(), 'the default is: move nothing');

        $lead2 = $this->lead();
        $this->seedRelated($lead2);
        $this->seedRelated($lead2, self::T2);
        $this->convert($lead2, ['transferRelatedTo' => 'organization'])->assertOk();
        $this->assertSame(2, DB::table('crm_lead_comments')->where('lead_id', $lead2)->where('sub_institute_id', self::T2)->count());
        $this->assertSame(1, DB::table('crm_email_links')->where('linked_type', 'LEAD')->where('linked_id', $lead2)->where('sub_institute_id', self::T2)->count());
        $this->assertSame(1, DB::table('task_management_calendar_events')->where('linked_type', 'LEAD')->where('linked_id', $lead2)->where('sub_institute_id', self::T2)->count());
    }

    public function test_a_target_the_conversion_does_not_make_or_attach_to_is_refused_and_the_lead_is_not_converted(): void
    {
        $lead = $this->lead();
        $this->seedRelated($lead);

        foreach ([
            'no organization' => [['createOrganization' => false, 'transferRelatedTo' => 'organization']],
            'no person' => [['createContact' => false, 'transferRelatedTo' => 'contact']],
            'no opportunity' => [['createOpportunity' => false, 'transferRelatedTo' => 'opportunity']],
            'a target that does not exist' => [['transferRelatedTo' => 'lead']],
        ] as [$over]) {
            $this->convert($lead, $over + ['createContact' => true, 'createOrganization' => true])->assertStatus(422);
        }
        $this->assertFalse($this->isConverted($lead));
        $this->assertSame(2, DB::table('crm_lead_comments')->where('lead_id', $lead)->count());
        $this->assertSame(0, DB::table('crm_organizations')->count());

        // A lead that names no person has no person to receive them.
        $signal = $this->lead(['first_name' => null, 'last_name' => 'Tapovan Vidya Vihar', 'company' => 'Tapovan Vidya Vihar']);
        $this->convert($signal, ['transferRelatedTo' => 'contact'])->assertStatus(422);
        $this->assertFalse($this->isConverted($signal));
    }

    public function test_moving_to_an_existing_organization_drops_a_link_it_already_has_instead_of_breaking_the_unique_index(): void
    {
        $acme = $this->makeOrganization(self::T, ['name' => 'Acme Schools']);
        $lead = $this->lead();
        $seed = $this->seedRelated($lead);
        DB::table('crm_email_links')->insert(['email_id' => $seed['email'], 'linked_type' => 'ORGANIZATION', 'linked_id' => $acme, 'sub_institute_id' => self::T, 'created_at' => now()]);

        $r = $this->convert($lead, ['createOrganization' => false, 'existingOrganizationId' => $acme, 'transferRelatedTo' => 'organization'])->assertOk();

        $this->assertSame(1, $r->json('data.transferred.emails'), 'the target carries the e-mail, once');
        $this->assertSame(1, DB::table('crm_email_links')->where('email_id', $seed['email'])->count());
        $this->assertSame(0, DB::table('crm_email_links')->where('linked_type', 'LEAD')->count());
    }

    public function test_a_failed_move_undoes_the_whole_conversion(): void
    {
        $lead = $this->lead();
        $this->seedRelated($lead);
        Schema::drop('crm_contact_comments');
        $row = DB::table('crm_leads')->where('id', $lead)->first();

        try {
            LeadConverter::convert($row, self::T, 9, 1, true, true, null, false, ['transfer_to' => 'contact']);
            $this->fail('the broken table must stop the conversion');
        } catch (\Illuminate\Database\QueryException) {
            // expected
        }

        $this->assertFalse($this->isConverted($lead));
        $this->assertSame(0, DB::table('crm_organizations')->count());
        $this->assertSame(0, DB::table('crm_contacts')->count());
        $this->assertSame(2, DB::table('crm_lead_comments')->where('lead_id', $lead)->count());
    }

    public function test_the_statement_count_of_a_move_does_not_grow_with_the_number_of_records(): void
    {
        $few = $this->lead();
        $this->seedRelated($few);
        $many = $this->lead(['company' => 'Another School']);   // a company of its own: a company that already exists is checked for links it has already
        for ($i = 0; $i < 6; $i++) {
            $this->seedRelated($many);
        }

        $count = function (int $lead): int {
            $n = 0;
            DB::listen(function ($q) use (&$n) {
                if (preg_match('/crm_(organization|lead)_comments|crm_email_links|document_library_links|task_management_calendar_events/', $q->sql)) {
                    $n++;
                }
            });
            $this->convert($lead, ['createContact' => false, 'transferRelatedTo' => 'organization'])->assertOk();

            return $n;
        };

        $this->assertSame($count($few), $count($many), 'one statement per table, however many rows');
    }
}
