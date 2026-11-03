<?php

namespace Tests\Feature;

use App\Domain\Crm\Leads\LeadConverter;
use App\Domain\Crm\Leads\LeadReplyProcessor;
use App\Domain\Facts\ConsentGate;
use App\Domain\Facts\ConsentRecorder;
use App\Domain\Gtm\Outreach\ReplyBounceProcessor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Tests\Support\BuildsFactsWorld;
use Tests\TestCase;

/**
 * The consent write-through (slice 4, defect D7): an unsubscribe, a complaint or a bounce recorded by ANY of the platform's handlers is seen by every other
 * place that holds consent, so the CRM and the outreach engine agree; and what a lead said about being contacted survives its conversion. The handlers are
 * the real ones (the signed unsubscribe links, the reply processors, the lead converter); the gate is the one every sender will ask.
 */
class FactsConsentRecorderTest extends TestCase
{
    use BuildsFactsWorld;

    private const ADDRESS = 'priya@school.example';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFactsWorld();
    }

    private function gate(string $type, int $id, string $channel = 'email'): array
    {
        return app(ConsentGate::class)->allows($type, $id, $channel);
    }

    private function outreachContact(string $email = self::ADDRESS, int $tenant = self::T, string $status = 'active'): int
    {
        return (int) DB::table('gtm_contacts')->insertGetId(['sub_institute_id' => $tenant, 'account_id' => 1, 'full_name' => 'Priya Sharma', 'email' => $email, 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
    }

    // ---- the recorder ----

    public function test_an_opt_out_reaches_every_record_that_shares_the_address_and_every_list_and_nothing_else(): void
    {
        $contact = $this->makeContact(self::T, ['email' => self::ADDRESS]);
        $secondContact = $this->makeContact(self::T, ['email' => 'other@school.example', 'secondary_email' => 'PRIYA@school.example']);
        $lead = $this->makeLead(self::T, ['email' => self::ADDRESS]);
        $org = $this->makeOrganization(self::T, ['email' => self::ADDRESS]);
        $stranger = $this->makeContact(self::T, ['email' => 'someone.else@school.example']);
        $otherTenant = $this->makeContact(self::T2, ['email' => self::ADDRESS]);
        $outreach = $this->outreachContact();
        $outreachOther = $this->outreachContact(self::ADDRESS, self::T2);

        $r = app(ConsentRecorder::class)->optOut(self::T, 'contact', $contact, 'unsubscribed', 'unsubscribe_link', 9, 'clicked the link');

        $this->assertSame(['records' => 4, 'preferences' => 4, 'suppression' => true, 'outreach_contacts' => 1], $r);
        foreach ([['contact', $contact], ['contact', $secondContact], ['lead', $lead], ['organization', $org]] as [$type, $id]) {
            $table = ['contact' => 'crm_contacts', 'lead' => 'crm_leads', 'organization' => 'crm_organizations'][$type];
            $row = DB::table($table)->where('id', $id)->first();
            $this->assertSame(1, (int) $row->email_opt_out, "{$type} {$id}");
            if ($type !== 'organization') {
                $this->assertSame('opted_out', $row->consent_status, "{$type} {$id}");
            }
            $pref = DB::table('crm_consent_preferences')->where('entity_type', $type)->where('entity_id', $id)->first();
            $this->assertSame(['email', 'opted_out', 'unsubscribe_link', 9, self::T], [$pref->channel, $pref->status, $pref->source, (int) $pref->created_by, (int) $pref->sub_institute_id]);
            $this->assertFalse($this->gate($type, $id)['allowed']);
        }
        $suppression = DB::table('gtm_suppressions')->first();
        $this->assertSame([self::T, 'email', self::ADDRESS, 'unsubscribed'], [(int) $suppression->sub_institute_id, $suppression->kind, $suppression->value, $suppression->reason]);
        $this->assertSame('unsubscribed', DB::table('gtm_contacts')->where('id', $outreach)->value('status'));

        $this->assertSame(0, (int) DB::table('crm_contacts')->where('id', $stranger)->value('email_opt_out'), 'a different address is untouched');
        $this->assertSame(0, (int) DB::table('crm_contacts')->where('id', $otherTenant)->value('email_opt_out'), 'so is the same address in another organisation');
        $this->assertSame('active', DB::table('gtm_contacts')->where('id', $outreachOther)->value('status'));
        $this->assertSame(1, DB::table('gtm_suppressions')->count());
        $this->assertTrue($this->gate('contact', $stranger)['allowed']);
    }

    public function test_it_is_idempotent_and_only_ever_escalates(): void
    {
        $c = $this->makeContact(self::T, ['email' => self::ADDRESS]);
        $recorder = app(ConsentRecorder::class);
        $first = $recorder->optOut(self::T, 'contact', $c, 'unsubscribed', 'unsubscribe_link');

        $second = $recorder->optOut(self::T, 'contact', $c, 'unsubscribed', 'unsubscribe_link');

        $this->assertSame(['records' => 1, 'preferences' => 1, 'suppression' => true, 'outreach_contacts' => 0], $first);
        $this->assertSame(['records' => 0, 'preferences' => 0, 'suppression' => false, 'outreach_contacts' => 0], $second, 'a repeat changes nothing');
        $this->assertSame([1, 1], [DB::table('crm_consent_preferences')->count(), DB::table('gtm_suppressions')->count()]);

        // a bounce after an opt-out never softens it into a mere suppression, and never touches the preference row
        $recorder->optOut(self::T, 'contact', $c, 'hard_bounce', 'inbound_reply');
        $row = DB::table('crm_contacts')->where('id', $c)->first();
        $this->assertSame([1, 'opted_out'], [(int) $row->email_opt_out, $row->consent_status]);
        $this->assertSame('opted_out', DB::table('crm_consent_preferences')->value('status'));
    }

    public function test_an_opt_out_replaces_an_opt_in_preference_but_nothing_ever_writes_an_opt_in(): void
    {
        $c = $this->makeContact(self::T, ['email' => self::ADDRESS, 'consent_status' => 'opted_in']);
        DB::table('crm_consent_preferences')->insert(['entity_type' => 'contact', 'entity_id' => $c, 'channel' => 'email', 'status' => 'opted_in', 'source' => 'form', 'sub_institute_id' => self::T]);
        DB::table('crm_consent_preferences')->insert(['entity_type' => 'contact', 'entity_id' => $c, 'channel' => 'sms', 'status' => 'opted_in', 'source' => 'form', 'sub_institute_id' => self::T]);

        app(ConsentRecorder::class)->optOut(self::T, 'contact', $c, 'complaint', 'inbound_reply');

        $prefs = DB::table('crm_consent_preferences')->pluck('status', 'channel')->all();
        $this->assertSame(['email' => 'opted_out', 'sms' => 'opted_in'], $prefs, 'the email consent is withdrawn; the SMS consent is a different channel and stays');
        $this->assertSame('inbound_reply', DB::table('crm_consent_preferences')->where('channel', 'email')->value('source'));
        $this->assertSame('opted_out', DB::table('crm_contacts')->where('id', $c)->value('consent_status'));
    }

    public function test_a_bounce_suppresses_the_address_everywhere_without_withdrawing_consent(): void
    {
        $contact = $this->makeContact(self::T, ['email' => self::ADDRESS]);
        $lead = $this->makeLead(self::T, ['email' => self::ADDRESS]);
        $outreach = $this->outreachContact();

        $r = app(ConsentRecorder::class)->optOut(self::T, 'lead', $lead, 'hard_bounce', 'inbound_reply', null, 'mailbox full for good');

        $this->assertSame(['records' => 2, 'preferences' => 0, 'suppression' => true, 'outreach_contacts' => 1], $r);
        foreach ([['contact', $contact, 'crm_contacts'], ['lead', $lead, 'crm_leads']] as [$type, $id, $table]) {
            $row = DB::table($table)->where('id', $id)->first();
            $this->assertSame([0, 'suppressed', 'hard_bounce'], [(int) $row->email_opt_out, $row->consent_status, $row->suppressed_reason], $type);
            $this->assertNotNull($row->suppressed_at);
            $this->assertSame('suppressed', $this->gate($type, $id)['code']);
        }
        $this->assertSame(0, DB::table('crm_consent_preferences')->count(), 'a bounce is a dead address, not a withdrawn consent');
        $this->assertSame(['bounced', 'hard_bounce'], [DB::table('gtm_contacts')->where('id', $outreach)->value('status'), DB::table('gtm_suppressions')->value('reason')]);
    }

    public function test_an_outreach_contact_that_is_already_blocked_keeps_its_own_status(): void
    {
        $c = $this->makeContact(self::T, ['email' => self::ADDRESS]);
        $dnc = $this->outreachContact(self::ADDRESS, self::T, 'do_not_contact');

        app(ConsentRecorder::class)->optOut(self::T, 'contact', $c, 'unsubscribed', 'unsubscribe_link');

        $this->assertSame('do_not_contact', DB::table('gtm_contacts')->where('id', $dnc)->value('status'), 'the stronger status is never downgraded');
    }

    public function test_a_record_without_an_address_is_still_flagged_and_an_unknown_reason_is_refused(): void
    {
        $c = $this->makeContact(self::T, ['email' => null]);
        $r = app(ConsentRecorder::class)->optOut(self::T, 'contact', $c, 'unsubscribed', 'manual');

        $this->assertSame(['records' => 1, 'preferences' => 1, 'suppression' => false, 'outreach_contacts' => 0], $r);
        $this->assertSame(0, DB::table('gtm_suppressions')->count(), 'there is no address to put on the list');

        $this->expectException(\InvalidArgumentException::class);
        app(ConsentRecorder::class)->optOutAddress(self::T, self::ADDRESS, 'because', 'manual');
    }

    public function test_a_host_without_the_consent_tables_or_columns_is_skipped_not_broken(): void
    {
        $c = $this->makeContact(self::T, ['email' => self::ADDRESS]);
        Schema::drop('crm_consent_preferences');
        Schema::drop('gtm_suppressions');
        Schema::drop('gtm_contacts');

        $r = app(ConsentRecorder::class)->optOut(self::T, 'contact', $c, 'unsubscribed', 'unsubscribe_link');

        $this->assertSame(['records' => 1, 'preferences' => 0, 'suppression' => false, 'outreach_contacts' => 0], $r);
        $this->assertSame(1, (int) DB::table('crm_contacts')->where('id', $c)->value('email_opt_out'));
    }

    // ---- the handlers ----

    public function test_the_crm_unsubscribe_link_opts_the_address_out_everywhere(): void
    {
        $contact = $this->makeContact(self::T, ['email' => self::ADDRESS]);
        $lead = $this->makeLead(self::T, ['email' => self::ADDRESS]);
        $outreach = $this->outreachContact();

        $this->get(URL::signedRoute('crm.email.unsubscribe', ['type' => 'CONTACT', 'id' => $contact]))->assertOk()->assertSee('You have been unsubscribed.');

        $this->assertSame([1, 1], [(int) DB::table('crm_contacts')->where('id', $contact)->value('email_opt_out'), (int) DB::table('crm_leads')->where('id', $lead)->value('email_opt_out')]);
        $this->assertSame('opted_out', DB::table('crm_consent_preferences')->where('entity_type', 'contact')->where('entity_id', $contact)->value('status'));
        $this->assertSame('unsubscribe_link', DB::table('crm_consent_preferences')->where('entity_type', 'lead')->where('entity_id', $lead)->value('source'));
        $this->assertSame(['email', self::ADDRESS, 'unsubscribed'], [DB::table('gtm_suppressions')->value('kind'), DB::table('gtm_suppressions')->value('value'), DB::table('gtm_suppressions')->value('reason')], 'the outreach engine now knows');
        $this->assertSame('unsubscribed', DB::table('gtm_contacts')->where('id', $outreach)->value('status'));

        $this->get(URL::signedRoute('crm.email.unsubscribe', ['type' => 'CONTACT', 'id' => $contact]))->assertOk();
        $this->assertSame([2, 1], [DB::table('crm_consent_preferences')->count(), DB::table('gtm_suppressions')->count()], 'a second click adds nothing');
    }

    public function test_an_unsigned_or_forged_unsubscribe_link_does_nothing(): void
    {
        $contact = $this->makeContact(self::T, ['email' => self::ADDRESS]);

        $this->get("/api/crm/email-unsubscribe/CONTACT/{$contact}")->assertStatus(403);
        $this->get("/api/crm/email-unsubscribe/CONTACT/{$contact}?signature=forged")->assertStatus(403);

        $this->assertSame(0, (int) DB::table('crm_contacts')->where('id', $contact)->value('email_opt_out'));
        $this->assertSame([0, 0], [DB::table('crm_consent_preferences')->count(), DB::table('gtm_suppressions')->count()]);
    }

    public function test_a_failure_in_the_write_through_never_undoes_or_hides_the_unsubscribe(): void
    {
        $contact = $this->makeContact(self::T, ['email' => self::ADDRESS]);
        DB::beforeExecuting(function (string $query) {
            if (str_contains($query, 'crm_consent_preferences')) {
                throw new \RuntimeException('the consent table is broken');
            }
        });

        $this->get(URL::signedRoute('crm.email.unsubscribe', ['type' => 'CONTACT', 'id' => $contact]))->assertOk()->assertSee('You have been unsubscribed.');

        $this->assertSame(1, (int) DB::table('crm_contacts')->where('id', $contact)->value('email_opt_out'), 'the person is unsubscribed whatever happened to the extra write');
    }

    public function test_the_outreach_unsubscribe_link_opts_the_crm_records_with_that_address_out_too(): void
    {
        $contact = $this->makeContact(self::T, ['email' => self::ADDRESS]);
        $org = $this->makeOrganization(self::T, ['email' => 'info@other.example']);
        $outreach = $this->outreachContact('Priya@School.example');

        $this->get(URL::signedRoute('gtm.unsubscribe', ['contact' => $outreach]))->assertOk();

        $this->assertSame('unsubscribed', DB::table('gtm_contacts')->where('id', $outreach)->value('status'), 'what the outreach engine always did');
        $this->assertSame(1, DB::table('gtm_suppressions')->count());
        $this->assertSame([1, 'opted_out'], [(int) DB::table('crm_contacts')->where('id', $contact)->value('email_opt_out'), DB::table('crm_contacts')->where('id', $contact)->value('consent_status')], 'and now the CRM contact too');
        $this->assertSame('opted_out', DB::table('crm_consent_preferences')->where('entity_type', 'contact')->where('entity_id', $contact)->value('status'));
        $this->assertSame(0, (int) DB::table('crm_organizations')->where('id', $org)->value('email_opt_out'));
        $this->assertFalse($this->gate('contact', $contact)['allowed']);
    }

    public function test_a_lead_that_complains_or_asks_to_stop_is_opted_out_everywhere_with_the_right_reason(): void
    {
        $processor = app(LeadReplyProcessor::class);
        $complainer = $this->makeLead(self::T, ['email' => 'complainer@school.example']);
        $asker = $this->makeLead(self::T, ['email' => 'asker@school.example']);
        $contactOfAsker = $this->makeContact(self::T, ['email' => 'asker@school.example']);

        $processor->optOutLead(self::T, DB::table('crm_leads')->find($complainer), 'inbound', 'complaint');
        $processor->optOutLead(self::T, DB::table('crm_leads')->find($asker), 'inbound');

        $this->assertSame(['complaint', 'unsubscribed'], [DB::table('gtm_suppressions')->where('value', 'complainer@school.example')->value('reason'), DB::table('gtm_suppressions')->where('value', 'asker@school.example')->value('reason')]);
        $this->assertSame(1, (int) DB::table('crm_contacts')->where('id', $contactOfAsker)->value('email_opt_out'), 'the contact with the same address too');
        $this->assertSame('inbound_reply', DB::table('crm_consent_preferences')->where('entity_type', 'lead')->where('entity_id', $asker)->value('source'));
        $this->assertFalse($this->gate('lead', $complainer)['allowed']);
        $this->assertSame(1, DB::table('g2g_event')->where('type', 'crm.lead.updated')->where('entity_id', $complainer)->count(), 'the lead\'s own event is still written');
    }

    public function test_a_lead_that_was_already_opted_out_is_left_exactly_as_the_handler_always_left_it(): void
    {
        $lead = $this->makeLead(self::T, ['email' => 'done@school.example', 'email_opt_out' => 1]);

        app(LeadReplyProcessor::class)->optOutLead(self::T, DB::table('crm_leads')->find($lead), 'inbound', 'complaint');

        $this->assertSame([0, 0, 0], [DB::table('gtm_suppressions')->count(), DB::table('crm_consent_preferences')->count(), DB::table('g2g_event')->count()], 'the early return is unchanged: no event, no write-through (consent:reconcile repairs the old ones)');
    }

    public function test_the_lead_bounce_handler_suppresses_the_address_with_the_reason_it_found(): void
    {
        // The handler itself needs the follow-up, comment and task tables of the lead module; the wiring is checked in the source and by the mutation in the evidence file.
        $source = (string) file_get_contents(base_path('app/Domain/Crm/Leads/LeadReplyProcessor.php'));

        $this->assertStringContainsString("optOutSafely(\$tenant, 'lead', (int) \$lead->id, \$soft !== null ? 'soft_bounce_limit' : 'hard_bounce', 'inbound_reply'", $source);
        $this->assertStringContainsString("optOutLead(\$tenant, \$lead, 'inbound', 'complaint')", $source, 'a spam complaint says so on the suppression list');
        $this->assertLessThan(strpos($source, "LeadFollowUp::stopFor(\$tenant, (int) \$lead->id, 'bounced'"), strpos($source, "'soft_bounce_limit' : 'hard_bounce'"), 'the write-through comes before the cadence is stopped, as the bounce branch is only reached for a hard bounce');
    }

    public function test_the_outreach_bounce_and_complaint_handlers_suppress_and_opt_out_the_crm_records(): void
    {
        $bounced = $this->makeContact(self::T, ['email' => 'bounced@school.example']);
        $complained = $this->makeContact(self::T, ['email' => 'complained@school.example']);
        $b = (object) ['id' => $this->outreachContact('bounced@school.example'), 'email' => 'bounced@school.example', 'account_id' => 1];
        $c = (object) ['id' => $this->outreachContact('complained@school.example'), 'email' => 'complained@school.example', 'account_id' => 1];
        $processor = (new \ReflectionClass(ReplyBounceProcessor::class))->newInstanceWithoutConstructor();
        $stop = new \ReflectionMethod(ReplyBounceProcessor::class, 'suppressAndStop');

        $stop->invoke($processor, self::T, $b, 'bounced', 'hard_bounce', 'Delivery failed', 'Delivery failed');
        $stop->invoke($processor, self::T, $c, 'unsubscribed', 'complaint', 'Spam complaint', 'Spam complaint');

        $row = DB::table('crm_contacts')->where('id', $bounced)->first();
        $this->assertSame([0, 'suppressed', 'hard_bounce'], [(int) $row->email_opt_out, $row->consent_status, $row->suppressed_reason]);
        $this->assertSame('suppressed', $this->gate('contact', $bounced)['code']);
        $this->assertSame([1, 'opted_out'], [(int) DB::table('crm_contacts')->where('id', $complained)->value('email_opt_out'), DB::table('crm_contacts')->where('id', $complained)->value('consent_status')]);
        $this->assertFalse($this->gate('contact', $complained)['allowed']);
        $this->assertSame(['hard_bounce', 'complaint'], DB::table('gtm_suppressions')->orderBy('id')->pluck('reason')->all());
        $this->assertSame(1, DB::table('crm_consent_preferences')->where('entity_id', $complained)->count());
        $this->assertSame(0, DB::table('crm_consent_preferences')->where('entity_id', $bounced)->count(), 'a bounce writes no preference');
    }

    // ---- conversion ----

    /** A lead as the converter reads it: every column it touches. */
    private function leadObject(int $id, array $over = []): object
    {
        return (object) ($over + ['id' => $id, 'salutation' => null, 'first_name' => 'Rahul', 'last_name' => 'Verma', 'company' => 'Greenfield', 'designation' => null, 'email' => self::ADDRESS, 'secondary_email' => null,
            'phone' => null, 'mobile' => null, 'fax' => null, 'lead_source' => null, 'email_opt_out' => 0, 'city' => null, 'state' => null, 'country' => null, 'postal_code' => null, 'street' => null, 'po_box' => null,
            'description' => null, 'industry' => null, 'rating' => null, 'website' => null]);
    }

    public function test_a_converted_lead_keeps_what_it_said_about_being_contacted_in_its_new_contact(): void
    {
        $lead = $this->makeLead(self::T, ['email' => self::ADDRESS, 'email_opt_out' => 1, 'consent_status' => 'opted_out', 'suppressed_at' => '2026-10-02 08:00:00', 'suppressed_reason' => 'complaint']);
        DB::table('crm_consent_preferences')->insert([
            ['entity_type' => 'lead', 'entity_id' => $lead, 'channel' => 'email', 'status' => 'opted_out', 'source' => 'unsubscribe_link', 'sub_institute_id' => self::T],
            ['entity_type' => 'lead', 'entity_id' => $lead, 'channel' => 'sms', 'status' => 'opted_in', 'source' => 'form', 'sub_institute_id' => self::T],
        ]);

        $out = LeadConverter::convert($this->leadObject($lead, ['email_opt_out' => 1]), self::T, 9, 1, false, true, null, false);

        $contact = DB::table('crm_contacts')->where('id', $out['contact_id'])->first();
        $this->assertSame([1, 'opted_out', 'complaint'], [(int) $contact->email_opt_out, $contact->consent_status, $contact->suppressed_reason]);
        $this->assertNotNull($contact->suppressed_at);
        $this->assertSame(['email' => 'opted_out', 'sms' => 'opted_in'], DB::table('crm_consent_preferences')->where('entity_type', 'contact')->where('entity_id', $contact->id)->pluck('status', 'channel')->all());
        $this->assertFalse($this->gate('contact', (int) $contact->id)['allowed']);
        $this->assertFalse($this->gate('contact', (int) $contact->id, 'sms')['allowed'], 'a suppressed contact is not texted either');
    }

    public function test_a_lead_converted_into_an_existing_contact_carries_its_opt_out_to_it_but_never_an_opt_in_over_a_choice(): void
    {
        $optedOutLead = $this->makeLead(self::T, ['email' => 'a@school.example', 'email_opt_out' => 1, 'consent_status' => 'opted_out']);
        $optedInContact = $this->makeContact(self::T, ['email' => 'a@school.example', 'consent_status' => 'opted_in']);
        $optedInLead = $this->makeLead(self::T, ['email' => 'b@school.example', 'consent_status' => 'opted_in']);
        $optedOutContact = $this->makeContact(self::T, ['email' => 'b@school.example', 'email_opt_out' => 1, 'consent_status' => 'opted_out']);
        $unknownContact = $this->makeContact(self::T, ['email' => 'b@school.example']);

        LeadConverter::convert($this->leadObject($optedOutLead, ['email' => 'a@school.example', 'email_opt_out' => 1]), self::T, 9, 1, false, true, $optedInContact, false);
        LeadConverter::convert($this->leadObject($optedInLead, ['email' => 'b@school.example']), self::T, 9, 1, false, true, $optedOutContact, false);
        LeadConverter::convert($this->leadObject($optedInLead, ['email' => 'b@school.example']), self::T, 9, 1, false, true, $unknownContact, false);

        $get = fn ($id) => DB::table('crm_contacts')->where('id', $id)->first();
        $this->assertSame([1, 'opted_out'], [(int) $get($optedInContact)->email_opt_out, $get($optedInContact)->consent_status], 'the lead\'s opt-out wins over the contact\'s earlier opt-in');
        $this->assertSame([1, 'opted_out'], [(int) $get($optedOutContact)->email_opt_out, $get($optedOutContact)->consent_status], 'the lead\'s opt-in never undoes the contact\'s opt-out');
        $this->assertSame('opted_in', $get($unknownContact)->consent_status, 'an opt-in is carried only where nothing was recorded');
        $this->assertFalse($this->gate('contact', $optedInContact)['allowed']);
    }

    public function test_a_conversion_still_works_when_the_carry_over_cannot_run(): void
    {
        $lead = $this->makeLead(self::T, ['email' => self::ADDRESS]);
        DB::beforeExecuting(function (string $query) {
            if (str_contains($query, 'crm_consent_preferences')) {
                throw new \RuntimeException('the consent table is broken');
            }
        });

        $out = LeadConverter::convert($this->leadObject($lead), self::T, 9, 1, false, true, null, false);

        $this->assertNotNull($out['contact_id'], 'the contact is made whatever happened to the consent copy');
        $this->assertSame($out['contact_id'], (int) DB::table('crm_leads')->where('id', $lead)->value('converted_contact_id'));
    }
}
