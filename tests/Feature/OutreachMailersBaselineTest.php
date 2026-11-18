<?php

namespace Tests\Feature;

use App\Domain\Gtm\OutreachMailer;
use App\Services\Crm\CrmOutreachMailer;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsOutreachWorld;
use Tests\TestCase;

/**
 * CHARACTERISATION of the two mailers (slice 10, track S10a). They had no PHPUnit test at all (their only tests are scripts that run against the shared dev
 * database), so this file pins what they do TODAY, written and run against the UNCHANGED classes before the outreach gate was wired in, and run again after.
 * It is the evidence that the gate adds a reason to refuse and changes nothing else:
 *
 *   - with sending OFF, or any earlier step of either ladder refusing, the answer is the same array, key for key and word for word, with no `checks` key;
 *   - a clean message with every switch on still reaches the (in-memory) transport exactly once and is recorded as before.
 *
 * Nothing here can deliver: the default mailer is an in-memory array transport (see BuildsOutreachWorld), every address is on example.test, and no SMTP
 * profile exists. Where a record is a "clean" recipient it has a twin in the CRM with the same address, because the gate maps a GTM recipient to a CRM record
 * for the consent check; the twin changes nothing in the mailer's own ladder.
 */
class OutreachMailersBaselineTest extends TestCase
{
    use BuildsOutreachWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildOutreachWorld();
    }

    // ---------------------------------------------------------------- GTM

    /** @return array{0: int, 1: string} [contact id, address]: a GTM contact with a CRM contact of the same address */
    private function cleanGtmContact(int $tenant = self::T): array
    {
        $email = 'clean.' . uniqid() . '@example.test';
        $this->makeContact($tenant, ['email' => $email]);

        return [$this->makeGtmContact($tenant, ['email' => $email]), $email];
    }

    public function test_gtm_refusals_before_the_gate_are_unchanged_word_for_word(): void
    {
        [$contact] = $this->cleanGtmContact();
        $mailer = app(OutreachMailer::class);

        // not approved
        $draft = $this->makeGtmMessage(self::T, $contact, ['status' => 'draft']);
        $this->assertSame(['ok' => false, 'http' => 409, 'code' => 'not_approved', 'message' => 'Only an approved message can be sent.'], $mailer->send($draft, 1));

        $m = $this->makeGtmMessage(self::T, $contact);

        // the platform gate (the organisation is not allowed to send)
        $this->assertSame(['ok' => false, 'http' => 403, 'code' => 'mail_disabled', 'message' => 'Outbound email is disabled for this organisation (G2G_NOTIFY_EMAIL is off and this tenant is not in G2G_NOTIFY_EMAIL_TENANTS).'], $mailer->send($m, 1));

        // GTM sending switched off
        $this->sendingOn();
        config(['gtm.outreach.sending_enabled' => false]);
        $this->assertSame(['ok' => false, 'http' => 403, 'code' => 'sending_disabled', 'message' => 'GTM outreach sending is switched off. Set GTM_OUTREACH_SENDING_ENABLED=true to enable it.'], $mailer->send($m, 1));
        config(['gtm.outreach.sending_enabled' => true]);

        // an unreachable unsubscribe link
        config(['app.url' => 'http://localhost']);
        $r = $mailer->send($m, 1);
        $this->assertSame([false, 422, 'unsubscribe_unreachable'], [$r['ok'], $r['http'], $r['code']]);
        $this->assertSame(['ok', 'http', 'code', 'message'], array_keys($r));
        config(['app.url' => 'https://crm.example.org']);

        // a mail driver that does not deliver
        config(['mail.default' => 'log']);
        $this->assertSame(['ok' => false, 'http' => 422, 'code' => 'mailer_not_delivering', 'message' => 'The mail driver does not deliver real email.'], $mailer->send($m, 1));
        config(['mail.default' => 'capture']);

        $this->assertSame(0, $this->captured());
        $this->assertSame('approved', DB::table('gtm_outreach_messages')->where('id', $m->id)->value('status'), 'a refusal leaves the message approved');
    }

    public function test_gtm_refusals_about_the_recipient_and_the_text_are_unchanged(): void
    {
        $this->sendingOn();
        $mailer = app(OutreachMailer::class);

        $none = $this->makeGtmContact(self::T, ['email' => null]);
        $this->assertSame(['ok' => false, 'http' => 422, 'code' => 'no_recipient', 'message' => 'The contact has no valid email address.'], $mailer->send($this->makeGtmMessage(self::T, $none), 1));

        [$bounced] = $this->cleanGtmContact();
        DB::table('gtm_contacts')->where('id', $bounced)->update(['status' => 'bounced']);
        $this->assertSame(['ok' => false, 'http' => 422, 'code' => 'contact_blocked', 'message' => "The contact is marked 'bounced'. Nothing was sent."], $mailer->send($this->makeGtmMessage(self::T, $bounced), 1));

        [$suppressed, $address] = $this->cleanGtmContact();
        DB::table('gtm_suppressions')->insert(['sub_institute_id' => self::T, 'kind' => 'email', 'value' => $address, 'reason' => 'unsubscribed', 'created_at' => now()]);
        $this->assertSame(['ok' => false, 'http' => 422, 'code' => 'suppressed', 'message' => "This address is on the suppression list (email {$address}, unsubscribed). Nothing was sent."], $mailer->send($this->makeGtmMessage(self::T, $suppressed), 1));

        [$edited] = $this->cleanGtmContact();
        $m = $this->makeGtmMessage(self::T, $edited);
        DB::table('gtm_outreach_messages')->where('id', $m->id)->update(['body' => self::CLEAN_BODY . ' (edited after approval)']);
        $this->assertSame(['ok' => false, 'http' => 409, 'code' => 'content_changed', 'message' => 'The text differs from what was approved. Resubmit it for approval.'], $mailer->send($m->fresh(), 1));

        [$capped] = $this->cleanGtmContact();
        config(['gtm.outreach.daily_cap' => 0]);
        $this->assertSame(['ok' => false, 'http' => 429, 'code' => 'daily_cap', 'message' => 'The daily sending limit has been reached. Try again tomorrow.'], $mailer->send($this->makeGtmMessage(self::T, $capped), 1));

        $this->assertSame(0, $this->captured(), 'every refusal above stopped before the transport');
        $this->assertSame(5, DB::table('gtm_outreach_attempts')->where('outcome', 'refused')->count(), 'each refusal is recorded as an attempt, as before');
    }

    public function test_a_clean_gtm_message_with_every_switch_on_is_handed_to_the_transport_once_and_recorded_as_before(): void
    {
        $this->sendingOn();
        [$contact, $address] = $this->cleanGtmContact();
        $m = $this->makeGtmMessage(self::T, $contact);

        $this->assertSame(['ok' => true, 'http' => 200, 'code' => 'sent', 'message' => 'Sent.'], app(OutreachMailer::class)->send($m, 7));

        $this->assertSame(1, $this->captured());
        $sent = $this->captureTransport()->messages()->first()->getOriginalMessage();
        $this->assertSame($address, $sent->getTo()[0]->getAddress());
        $this->assertSame('A short question about admissions', $sent->getSubject());
        $this->assertStringContainsString(self::CLEAN_BODY, $sent->getTextBody());
        $this->assertStringContainsString('unsubscribe here: ', $sent->getTextBody());
        $this->assertTrue($sent->getHeaders()->has('List-Unsubscribe'));
        $this->assertTrue($sent->getHeaders()->has('List-Unsubscribe-Post'));

        $row = DB::table('gtm_outreach_messages')->where('id', $m->id)->first();
        $this->assertSame('sent', $row->status);
        $this->assertNotNull($row->sent_at);
        $this->assertSame($address, $row->to_email);
        $this->assertMatchesRegularExpression('/^gtm\.1\.' . $m->id . '\.[0-9a-f]{12}@example\.test$/', (string) $row->provider_message_id);
        $this->assertSame(1, DB::table('gtm_activities')->where('contact_id', $contact)->where('type', 'email')->where('direction', 'outbound')->count());
        $this->assertSame(['sent'], DB::table('gtm_outreach_attempts')->where('message_id', $m->id)->pluck('outcome')->all());
    }

    // ---------------------------------------------------------------- CRM

    public function test_crm_refusals_before_the_gate_are_unchanged_word_for_word(): void
    {
        $lead = $this->makeLead(self::T, ['email' => 'lead.base@example.test']);
        $row = $this->makeCrmEmail(self::T, 'LEAD', $lead, ['to_address' => 'lead.base@example.test']);
        $mailer = new CrmOutreachMailer();

        $this->assertSame(['ok' => false, 'http' => 403, 'code' => 'sending_disabled', 'message' => 'CRM email sending is switched off. Set CRM_EMAIL_SENDING_ENABLED=true to enable it.'], $mailer->send($row, 1, 'LEAD', $lead));

        $this->sendingOn();
        DB::table('school_setup')->where('id', self::T)->update(['outbound_email_enabled' => 0]);
        $this->assertSame(['ok' => false, 'http' => 403, 'code' => 'mail_disabled', 'message' => 'Outbound email is disabled for this organisation (G2G_NOTIFY_EMAIL is off and this tenant is not in G2G_NOTIFY_EMAIL_TENANTS).'], $mailer->send($row, 1, 'LEAD', $lead));
        DB::table('school_setup')->where('id', self::T)->update(['outbound_email_enabled' => 1]);

        config(['app.url' => 'http://localhost']);
        $r = $mailer->send($row, 1, 'LEAD', $lead);
        $this->assertSame([false, 422, 'unsubscribe_unreachable'], [$r['ok'], $r['http'], $r['code']]);
        $this->assertSame(['ok', 'http', 'code', 'message'], array_keys($r));
        config(['app.url' => 'https://crm.example.org']);

        config(['mail.default' => 'log']);
        $this->assertSame(['ok' => false, 'http' => 422, 'code' => 'mailer_not_delivering', 'message' => 'The mail driver does not deliver real email.'], $mailer->send($row, 1, 'LEAD', $lead));
        config(['mail.default' => 'capture']);

        $this->assertSame(0, $this->captured());
        $this->assertSame('Queued', DB::table('crm_emails')->where('id', $row->id)->value('status'), 'a refusal leaves the email Queued');
    }

    public function test_crm_refusals_about_the_recipient_and_the_cap_are_unchanged(): void
    {
        $this->sendingOn();
        $mailer = new CrmOutreachMailer();

        $lead = $this->makeLead(self::T, ['email' => 'lead.a@example.test']);
        $this->assertSame(['ok' => false, 'http' => 422, 'code' => 'no_recipient', 'message' => 'This email has no valid recipient address.'], $mailer->send($this->makeCrmEmail(self::T, 'LEAD', $lead, ['to_address' => 'not-an-address']), 1, 'LEAD', $lead));

        $optedOut = $this->makeLead(self::T, ['email' => 'lead.b@example.test', 'email_opt_out' => 1]);
        $this->assertSame(['ok' => false, 'http' => 422, 'code' => 'recipient_blocked', 'message' => 'This recipient has opted out of emails. Nothing was sent.'], $mailer->send($this->makeCrmEmail(self::T, 'LEAD', $optedOut, ['to_address' => 'lead.b@example.test']), 1, 'LEAD', $optedOut));

        config(['crm.email_daily_cap' => 0]);
        $this->assertSame(['ok' => false, 'http' => 429, 'code' => 'daily_cap', 'message' => 'The daily sending limit has been reached. Try again tomorrow.'], $mailer->send($this->makeCrmEmail(self::T, 'LEAD', $lead, ['to_address' => 'lead.a@example.test']), 1, 'LEAD', $lead));
        config(['crm.email_daily_cap' => 50]);

        $done = $this->makeCrmEmail(self::T, 'LEAD', $lead, ['to_address' => 'lead.a@example.test', 'status' => 'Sent']);
        $this->assertSame(['ok' => false, 'http' => 409, 'code' => 'already_claimed', 'message' => 'This email is already being sent or was already sent.'], $mailer->send($done, 1, 'LEAD', $lead));

        $this->assertSame(0, $this->captured());
    }

    public function test_a_clean_crm_email_with_every_switch_on_is_handed_to_the_transport_once_and_recorded_as_before(): void
    {
        $this->sendingOn();
        $lead = $this->makeLead(self::T, ['email' => 'lead.clean@example.test']);
        $row = $this->makeCrmEmail(self::T, 'LEAD', $lead, ['to_address' => 'lead.clean@example.test']);

        $this->assertSame(['ok' => true, 'http' => 200, 'code' => 'sent', 'message' => 'Sent.'], (new CrmOutreachMailer())->send($row, 7, 'LEAD', $lead));

        $this->assertSame(1, $this->captured());
        $sent = $this->captureTransport()->messages()->first()->getOriginalMessage();
        $this->assertSame('lead.clean@example.test', $sent->getTo()[0]->getAddress());
        $this->assertStringContainsString(self::CLEAN_BODY, $sent->getTextBody());
        $this->assertStringContainsString('unsubscribe here: ', $sent->getTextBody());
        $this->assertTrue($sent->getHeaders()->has('List-Unsubscribe'));

        $stored = DB::table('crm_emails')->where('id', $row->id)->first();
        $this->assertSame('Sent', $stored->status);
        $this->assertNotNull($stored->sent_at);
        $this->assertMatchesRegularExpression('/^crm\.1\.' . $row->id . '\.[0-9a-f]{12}@example\.test$/', (string) $stored->message_id);
    }
}
