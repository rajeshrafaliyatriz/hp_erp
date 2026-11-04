<?php

namespace Tests\Feature;

use App\Domain\Gtm\GtmScheduler;
use App\Domain\Gtm\OutreachMailer;
use App\Domain\OutreachGate\Draft;
use App\Domain\OutreachGate\GateResult;
use App\Domain\OutreachGate\OutreachGate;
use App\Models\auth\tbluserModel;
use App\Services\Crm\CrmEmailQueue;
use App\Services\Crm\CrmOutreachMailer;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsOutreachWorld;
use Tests\TestCase;

/**
 * The outreach gate as wired into BOTH mailers (slice 10, acceptance 1): one more refusal, asked after every step that was already there and before the message is
 * claimed or handed to the transport.
 *
 * Every send here uses the in-memory transport (see BuildsOutreachWorld), every address is on example.test, and the assertion that matters is always the same pair:
 * what the answer says, and how many messages the transport was handed. The other half of acceptance 1 (sending OFF changes nothing; a clean message still goes out
 * exactly as before) is OutreachMailersBaselineTest, which was written against the unchanged mailers and passes unchanged.
 */
class OutreachMailersGateTest extends TestCase
{
    use BuildsOutreachWorld;

    private const BAD_BODY = 'Act now, {{first_name}}, to see our pricing.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildOutreachWorld();
    }

    /** @param array<string, mixed> $crm @return array{0: int, 1: string} [gtm contact id, address]: a GTM contact with a CRM contact of the same address */
    private function gtmContact(array $crm = []): array
    {
        $email = 'clean.' . uniqid() . '@example.test';
        $this->makeContact(self::T, $crm + ['email' => $email]);

        return [$this->makeGtmContact(self::T, ['email' => $email]), $email];
    }

    private function gtmMessage(string $body = self::CLEAN_BODY, array $contactCrm = [], array $over = []): \App\Domain\Gtm\GtmOutreachMessage
    {
        [$contact] = $this->gtmContact($contactCrm);

        return $this->makeGtmMessage(self::T, $contact, $over + ['body' => $body]);
    }

    /** @return array{0: object, 1: int} [email row, lead id] */
    private function crmEmail(string $body = self::CLEAN_BODY, array $lead = [], array $over = []): array
    {
        $address = 'lead.' . uniqid() . '@example.test';
        $lead = $this->makeLead(self::T, $lead + ['email' => $address]);

        return [$this->makeCrmEmail(self::T, 'LEAD', $lead, $over + ['to_address' => $address, 'body' => $body]), $lead];
    }

    private function gateRows(): int
    {
        return DB::table('g2g_outreach_gate_checks')->count();
    }

    // ---------------------------------------------------------------- GTM

    public function test_gtm_a_blocked_message_is_refused_with_the_failing_checks_and_nothing_reaches_the_transport(): void
    {
        $this->sendingOn();
        $m = $this->gtmMessage(self::BAD_BODY);

        $r = app(OutreachMailer::class)->send($m, 7);

        $this->assertFalse($r['ok']);
        $this->assertSame(422, $r['http']);
        $this->assertSame('gate_blocked', $r['code']);
        $this->assertStringStartsWith('The outreach checks stopped this message.', $r['message']);
        $this->assertSame(['ok', 'http', 'code', 'message', 'checks'], array_keys($r));
        $this->assertEqualsCanonicalizing(['banned_phrase', 'placeholder'], array_column($r['checks'], 'key'));
        $this->assertSame([false], array_unique(array_column($r['checks'], 'passed')));

        $this->assertSame(0, $this->captured(), 'nothing was handed to the transport');
        $row = DB::table('gtm_outreach_messages')->where('id', $m->id)->first();
        $this->assertSame('approved', $row->status, 'not claimed: it stays approved');
        $this->assertNull($row->provider_message_id);
        $this->assertNull($row->sent_at);
        $this->assertSame(0, DB::table('gtm_activities')->where('type', 'email')->count());
        $attempt = DB::table('gtm_outreach_attempts')->where('message_id', $m->id)->first();
        $this->assertSame(['refused', 'gate_blocked'], [$attempt->outcome, $attempt->code], 'the refusal is recorded like every other');
        $this->assertSame(10, DB::table('g2g_outreach_gate_checks')->where('message_id', $m->id)->where('message_table', 'gtm_outreach_messages')->count(), 'the whole set of checks is recorded');
    }

    public function test_gtm_every_kind_of_gate_failure_is_a_refusal_not_a_send(): void
    {
        $this->sendingOn();
        $product = $this->makeProduct(self::T, 'ScholarClone');

        $cases = [
            'two links' => [fn () => $this->gtmMessage('See https://a.example.org/x and https://b.example.org/y please.'), 'link_count'],
            'too long' => [fn () => $this->gtmMessage(str_repeat('A calm sentence about admissions. ', 60)), 'length'],
            'an unapproved claim' => [fn () => $this->gtmMessage('ScholarClone cuts paperwork by 60%. Would a call suit you?'), 'unapproved_claim'],
            'a twin that opted out in the CRM' => [fn () => $this->gtmMessage(self::CLEAN_BODY, ['email_opt_out' => 1]), 'consent'],
            'a twin with consent_status opted_out' => [fn () => $this->gtmMessage(self::CLEAN_BODY, ['consent_status' => 'opted_out']), 'consent'],
            'a contact with no CRM record' => [fn () => $this->makeGtmMessage(self::T, $this->makeGtmContact(self::T, ['email' => 'only.outreach@example.test'])), 'recipient'],
        ];
        foreach ($cases as $label => [$make, $key]) {
            $m = $make();
            $r = app(OutreachMailer::class)->send($m, 7);

            $this->assertSame('gate_blocked', $r['code'], $label);
            $this->assertContains($key, array_column($r['checks'], 'key'), $label);
            $this->assertSame('approved', DB::table('gtm_outreach_messages')->where('id', $m->id)->value('status'), $label);
        }
        $this->assertSame(0, $this->captured(), 'none of them reached the transport');
        $this->assertNotNull($product);
    }

    public function test_gtm_the_gate_comes_after_every_earlier_step_and_is_not_even_asked_when_one_of_them_refuses(): void
    {
        $mailer = app(OutreachMailer::class);
        $bad = fn () => $this->gtmMessage(self::BAD_BODY);

        // each earlier refusal still answers with ITS OWN code although the draft would also fail the gate
        $m = $bad();
        $this->assertSame('mail_disabled', $mailer->send($m, 1)['code']);

        $this->sendingOn();
        config(['gtm.outreach.sending_enabled' => false]);
        $this->assertSame('sending_disabled', $mailer->send($m, 1)['code']);
        config(['gtm.outreach.sending_enabled' => true]);

        config(['app.url' => 'http://localhost']);
        $this->assertSame('unsubscribe_unreachable', $mailer->send($m, 1)['code']);
        config(['app.url' => 'https://crm.example.org', 'mail.default' => 'log']);
        $this->assertSame('mailer_not_delivering', $mailer->send($m, 1)['code']);
        config(['mail.default' => 'capture']);

        [$noEmail] = [$this->makeGtmContact(self::T, ['email' => null])];
        $this->assertSame('no_recipient', $mailer->send($this->makeGtmMessage(self::T, $noEmail, ['body' => self::BAD_BODY]), 1)['code']);

        [$bouncedId] = $this->gtmContact();
        DB::table('gtm_contacts')->where('id', $bouncedId)->update(['status' => 'bounced']);
        $this->assertSame('contact_blocked', $mailer->send($this->makeGtmMessage(self::T, $bouncedId, ['body' => self::BAD_BODY]), 1)['code']);

        [$suppressedId, $address] = $this->gtmContact();
        DB::table('gtm_suppressions')->insert(['sub_institute_id' => self::T, 'kind' => 'email', 'value' => $address, 'reason' => 'manual', 'created_at' => now()]);
        $this->assertSame('suppressed', $mailer->send($this->makeGtmMessage(self::T, $suppressedId, ['body' => self::BAD_BODY]), 1)['code']);

        $tampered = $bad();
        DB::table('gtm_outreach_messages')->where('id', $tampered->id)->update(['body' => self::BAD_BODY . ' edited']);
        $this->assertSame('content_changed', $mailer->send($tampered->fresh(), 1)['code']);

        config(['gtm.outreach.daily_cap' => 0]);
        $this->assertSame('daily_cap', $mailer->send($bad(), 1)['code']);
        config(['gtm.outreach.daily_cap' => 50]);

        $this->assertSame(0, $this->gateRows(), 'the gate was never asked: no set was recorded by any of the refusals above');
        $this->assertSame(0, $this->captured());
    }

    public function test_gtm_the_gate_runs_before_the_message_is_claimed_and_before_the_transport(): void
    {
        $this->sendingOn();
        $m = $this->gtmMessage(self::BAD_BODY);

        $r = app(OutreachMailer::class)->send($m, 1);

        $this->assertSame('gate_blocked', $r['code']);
        $this->assertSame('approved', DB::table('gtm_outreach_messages')->where('id', $m->id)->value('status'), 'never moved to sending');
        $this->assertSame(0, $this->captured());

        // the same message once the text is fixed and approved again goes out exactly once
        $m->body = self::CLEAN_BODY;
        $m->approved_hash = $m->contentHash();
        $m->save();
        $this->assertSame(['ok' => true, 'http' => 200, 'code' => 'sent', 'message' => 'Sent.'], app(OutreachMailer::class)->send($m->fresh(), 1));
        $this->assertSame(1, $this->captured());
    }

    public function test_gtm_a_gate_that_cannot_run_blocks_the_message(): void
    {
        $this->sendingOn();
        $this->app->bind(OutreachGate::class, fn () => new class(app(\App\Domain\OutreachGate\CheckStore::class), app(\App\Domain\OutreachGate\ClaimBook::class), app(\App\Domain\Facts\ConsentGate::class), app(\App\Domain\Gtm\Outreach\Suppressions::class)) extends OutreachGate {
            public function evaluate(Draft $draft, bool $store = true): GateResult
            {
                throw new \RuntimeException('the gate is down');
            }
        });
        $m = $this->gtmMessage();

        $r = app(OutreachMailer::class)->send($m, 1);

        $this->assertSame('gate_blocked', $r['code']);
        $this->assertSame('gate', $r['checks'][0]['key']);
        $this->assertSame(0, $this->captured(), 'a clean message is not sent when the gate cannot vouch for it');
    }

    public function test_gtm_the_scheduler_defers_a_blocked_message_for_an_hour_and_does_not_ask_again_at_once(): void
    {
        $this->sendingOn();
        config(['gtm.outreach.scheduler_enabled' => true]);
        $m = $this->gtmMessage(self::BAD_BODY, [], ['scheduled_for' => now()->subMinute()]);
        $scheduler = app(GtmScheduler::class);

        $first = $scheduler->sendDue();

        $this->assertSame([1, 0, 1], [$first['due'], $first['sent'], $first['deferred']]);
        $row = DB::table('gtm_outreach_messages')->where('id', $m->id)->first();
        $this->assertSame('approved', $row->status, 'neither cancelled nor sent');
        $this->assertEqualsWithDelta(60, now()->diffInMinutes($row->next_attempt_at, false), 2, 'looked at again in an hour');
        $this->assertSame(0, $this->captured());

        $second = $scheduler->sendDue();
        $this->assertSame(0, $second['due'], 'not picked up again until then');
        $this->assertSame(1, DB::table('gtm_outreach_attempts')->where('message_id', $m->id)->count());
    }

    public function test_gtm_over_http_the_refusal_carries_the_checks_and_an_ordinary_refusal_is_unchanged(): void
    {
        \App\Support\RoleKey::flushCache();   // a static cache keyed by profile id, and every test's in-memory database starts its ids again
        $profile = DB::table('tbluserprofilemaster')->insertGetId(['name' => 'Administrator', 'role_key' => 'administrator', 'sub_institute_id' => self::T]);
        $userId = DB::table('tbluser')->insertGetId(['user_name' => 'admin1', 'first_name' => 'Admin', 'last_name' => 'One', 'sub_institute_id' => self::T, 'user_profile_id' => $profile, 'status' => 1]);
        $headers = ['Authorization' => 'Bearer ' . tbluserModel::find($userId)->createToken('t')->plainTextToken];
        $m = $this->gtmMessage(self::BAD_BODY);

        // sending off: the body of the answer is exactly what it was, with no `checks`
        $off = $this->postJson("/api/gtm/outreach/{$m->id}/send", [], $headers);
        $off->assertStatus(403)->assertJsonPath('code', 'mail_disabled');
        $this->assertArrayNotHasKey('checks', $off->json());

        $this->sendingOn();
        $on = $this->postJson("/api/gtm/outreach/{$m->id}/send", [], $headers);
        $on->assertStatus(422)->assertJsonPath('code', 'gate_blocked')->assertJsonPath('status', 0);
        $this->assertEqualsCanonicalizing(['banned_phrase', 'placeholder'], array_column($on->json('checks'), 'key'));
        $this->assertSame('approved', $on->json('data.message.status'));
        $this->assertSame(0, $this->captured());
    }

    // ---------------------------------------------------------------- CRM

    public function test_crm_a_blocked_email_is_refused_with_the_failing_checks_and_nothing_reaches_the_transport(): void
    {
        $this->sendingOn();
        [$row, $lead] = $this->crmEmail(self::BAD_BODY);

        $r = (new CrmOutreachMailer())->send($row, 7, 'LEAD', $lead);

        $this->assertFalse($r['ok']);
        $this->assertSame(422, $r['http']);
        $this->assertSame('gate_blocked', $r['code']);
        $this->assertSame(['ok', 'http', 'code', 'message', 'checks'], array_keys($r));
        $this->assertEqualsCanonicalizing(['banned_phrase', 'placeholder'], array_column($r['checks'], 'key'));

        $this->assertSame(0, $this->captured());
        $stored = DB::table('crm_emails')->where('id', $row->id)->first();
        $this->assertSame('Queued', $stored->status, 'not claimed: it stays Queued');
        $this->assertNull($stored->message_id);
        $this->assertNull($stored->sent_at);
        $this->assertSame(10, DB::table('g2g_outreach_gate_checks')->where('message_id', $row->id)->where('message_table', 'crm_emails')->count());
    }

    public function test_crm_the_consent_gap_defect_d7_is_closed_for_the_sources_the_mailer_never_read(): void
    {
        $this->sendingOn();
        $mailer = new CrmOutreachMailer();

        // 1. consent_status = opted_out: the mailer's own check reads only email_opt_out
        [$row, $lead] = $this->crmEmail(self::CLEAN_BODY, ['consent_status' => 'opted_out']);
        $r = $mailer->send($row, 1, 'LEAD', $lead);
        $this->assertSame('gate_blocked', $r['code']);
        $this->assertContains('consent', array_column($r['checks'], 'key'));

        // 2. a consent preference that says opted out for email
        [$row, $lead] = $this->crmEmail();
        DB::table('crm_consent_preferences')->insert(['entity_type' => 'lead', 'entity_id' => $lead, 'channel' => 'email', 'status' => 'opted_out', 'sub_institute_id' => self::T]);
        $this->assertSame('gate_blocked', $mailer->send($row, 1, 'LEAD', $lead)['code']);

        // 3. the address is on the suppression list
        [$row, $lead] = $this->crmEmail();
        DB::table('gtm_suppressions')->insert(['sub_institute_id' => self::T, 'kind' => 'email', 'value' => $row->to_address, 'reason' => 'complaint', 'created_at' => now()]);
        $r = $mailer->send($row, 1, 'LEAD', $lead);
        $this->assertSame('gate_blocked', $r['code']);
        $this->assertContains('suppression', array_column($r['checks'], 'key'));

        // 4. an outreach contact with the address is marked unsubscribed
        [$row, $lead] = $this->crmEmail();
        $this->makeGtmContact(self::T, ['email' => $row->to_address, 'status' => 'unsubscribed']);
        $r = $mailer->send($row, 1, 'LEAD', $lead);
        $this->assertSame('gate_blocked', $r['code']);
        $this->assertContains('contact_status', array_column($r['checks'], 'key'));

        $this->assertSame(0, $this->captured());
        $this->assertSame([], DB::table('crm_emails')->where('status', '!=', 'Queued')->pluck('id')->all(), 'every row stayed Queued');
    }

    public function test_crm_the_gate_comes_after_every_earlier_step_and_is_not_even_asked_when_one_of_them_refuses(): void
    {
        $mailer = new CrmOutreachMailer();
        [$row, $lead] = $this->crmEmail(self::BAD_BODY);

        $this->assertSame('sending_disabled', $mailer->send($row, 1, 'LEAD', $lead)['code']);

        $this->sendingOn();
        DB::table('school_setup')->where('id', self::T)->update(['outbound_email_enabled' => 0]);
        $this->assertSame('mail_disabled', $mailer->send($row, 1, 'LEAD', $lead)['code']);
        DB::table('school_setup')->where('id', self::T)->update(['outbound_email_enabled' => 1]);

        config(['app.url' => 'http://localhost']);
        $this->assertSame('unsubscribe_unreachable', $mailer->send($row, 1, 'LEAD', $lead)['code']);
        config(['app.url' => 'https://crm.example.org', 'mail.default' => 'log']);
        $this->assertSame('mailer_not_delivering', $mailer->send($row, 1, 'LEAD', $lead)['code']);
        config(['mail.default' => 'capture']);

        [$noAddress, $lead2] = $this->crmEmail(self::BAD_BODY, [], ['to_address' => 'nobody']);
        $this->assertSame('no_recipient', $mailer->send($noAddress, 1, 'LEAD', $lead2)['code']);

        [$optedOut, $lead3] = $this->crmEmail(self::BAD_BODY, ['email_opt_out' => 1]);
        $this->assertSame('recipient_blocked', $mailer->send($optedOut, 1, 'LEAD', $lead3)['code']);

        config(['crm.email_daily_cap' => 0]);
        $this->assertSame('daily_cap', $mailer->send($row, 1, 'LEAD', $lead)['code']);
        config(['crm.email_daily_cap' => 50]);

        $this->assertSame(0, $this->gateRows(), 'the gate was never asked');
        $this->assertSame(0, $this->captured());
    }

    public function test_crm_a_gate_that_cannot_run_blocks_the_email(): void
    {
        $this->sendingOn();
        $this->app->bind(OutreachGate::class, fn () => new class(app(\App\Domain\OutreachGate\CheckStore::class), app(\App\Domain\OutreachGate\ClaimBook::class), app(\App\Domain\Facts\ConsentGate::class), app(\App\Domain\Gtm\Outreach\Suppressions::class)) extends OutreachGate {
            public function evaluate(Draft $draft, bool $store = true): GateResult
            {
                throw new \RuntimeException('the gate is down');
            }
        });
        [$row, $lead] = $this->crmEmail();

        $r = (new CrmOutreachMailer())->send($row, 1, 'LEAD', $lead);

        $this->assertSame('gate_blocked', $r['code']);
        $this->assertSame(0, $this->captured());
        $this->assertSame('Queued', DB::table('crm_emails')->where('id', $row->id)->value('status'));
    }

    public function test_crm_the_queue_helper_passes_the_gate_refusal_on_and_leaves_the_row_queued(): void
    {
        $this->sendingOn();
        [$row, $lead] = $this->crmEmail(self::BAD_BODY);

        $r = CrmEmailQueue::deliver($row->id, 'LEAD', $lead, 7);

        $this->assertFalse($r['ok']);
        $this->assertSame('gate_blocked', $r['code']);
        $this->assertStringStartsWith('The outreach checks stopped this message.', $r['message']);
        $this->assertSame(0, $this->captured());
    }

    public function test_crm_an_opportunity_email_is_read_through_its_organisation(): void
    {
        $this->sendingOn();
        $org = $this->makeOrganization(self::T, ['email' => 'org.contact@example.test']);
        DB::table('crm_consent_preferences')->insert(['entity_type' => 'organization', 'entity_id' => $org, 'channel' => 'email', 'status' => 'opted_out', 'sub_institute_id' => self::T]);
        $opportunity = DB::table('crm_opportunities')->insertGetId(['organization_id' => $org, 'sub_institute_id' => self::T]);
        $row = $this->makeCrmEmail(self::T, 'OPPORTUNITY', $opportunity, ['to_address' => 'org.contact@example.test']);

        $r = (new CrmOutreachMailer())->send($row, 1, 'OPPORTUNITY', $opportunity);

        $this->assertSame('gate_blocked', $r['code']);
        $this->assertSame(0, $this->captured());
    }

    // ---------------------------------------------------------------- added after the mutation proofs (task 7)

    public function test_gtm_the_sequence_refusals_still_come_before_the_gate(): void
    {
        $this->sendingOn();
        $mailer = app(OutreachMailer::class);
        [$contact] = $this->gtmContact();
        // step 1 is still waiting, so step 2 is refused for its ORDER whatever its text says
        $first = $this->makeGtmMessage(self::T, $contact, ['sequence_key' => 'welcome', 'step' => 1]);
        $second = $this->makeGtmMessage(self::T, $contact, ['sequence_key' => 'welcome', 'step' => 2, 'body' => self::BAD_BODY]);
        $this->assertSame('sequence_order', $mailer->send($second, 1)['code']);

        // step 1 went out and the contact replied: step 2 is stopped for THAT reason
        DB::table('gtm_outreach_messages')->where('id', $first->id)->update(['status' => 'sent', 'sent_at' => now()->subHour()]);
        DB::table('gtm_activities')->insert(['sub_institute_id' => self::T, 'contact_id' => $contact, 'type' => 'email', 'direction' => 'inbound', 'occurred_at' => now()->subMinutes(10), 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame('reply_recorded', $mailer->send($second->fresh(), 1)['code']);

        $this->assertSame(0, $this->gateRows(), 'the gate was never asked');
        $this->assertSame(0, $this->captured());
    }

    public function test_gtm_a_message_the_draft_cannot_be_built_from_is_blocked_not_thrown(): void
    {
        $this->sendingOn();
        $real = $this->gtmMessage();
        $broken = new class extends \App\Domain\Gtm\GtmOutreachMessage {
            public function getAttribute($key)
            {
                if ($key === 'product_master_id') {
                    throw new \RuntimeException('the draft could not be built');
                }

                return parent::getAttribute($key);
            }
        };
        $broken->setRawAttributes($real->getAttributes(), true);
        $broken->exists = true;

        $r = app(OutreachMailer::class)->send($broken, 1);

        $this->assertSame('gate_blocked', $r['code']);
        $this->assertSame('gate', $r['checks'][0]['key']);
        $this->assertSame(0, $this->captured());
        $this->assertSame('approved', DB::table('gtm_outreach_messages')->where('id', $real->id)->value('status'));
    }

    public function test_crm_a_row_the_draft_cannot_be_built_from_is_blocked_not_thrown(): void
    {
        $this->sendingOn();
        [$row, $lead] = $this->crmEmail();
        $broken = clone $row;
        unset($broken->body);   // the mailer does not read the body before the gate; the draft cannot be built without it

        $r = (new CrmOutreachMailer())->send($broken, 1, 'LEAD', $lead);

        $this->assertSame('gate_blocked', $r['code']);
        $this->assertSame('gate', $r['checks'][0]['key']);
        $this->assertSame(0, $this->captured());
        $this->assertSame('Queued', DB::table('crm_emails')->where('id', $row->id)->value('status'));
    }
}
