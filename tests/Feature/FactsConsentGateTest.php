<?php

namespace Tests\Feature;

use App\Domain\Facts\ConsentGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsFactsWorld;
use Tests\TestCase;

/**
 * The consent gate (slice 4, defect D7): the truth table over its four sources, the channels, the hard stops, the fail-closed cases, and the promise that it
 * only ever READS. Nothing here sends anything; sending stays off everywhere until slice 10.
 */
class FactsConsentGateTest extends TestCase
{
    use BuildsFactsWorld;

    private ConsentGate $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFactsWorld();
        $this->gate = app(ConsentGate::class);
    }

    private function email(int $contact, int $tenant = self::T): array
    {
        return $this->gate->allows('contact', $contact, 'email', $tenant);
    }

    private function preference(string $type, int $id, string $channel, string $status, ?string $source = 'form', int $tenant = self::T): void
    {
        DB::table('crm_consent_preferences')->insert(['entity_type' => $type, 'entity_id' => $id, 'channel' => $channel, 'status' => $status, 'source' => $source, 'captured_at' => now(), 'sub_institute_id' => $tenant, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function suppress(string $kind, string $value, string $reason = 'unsubscribed', int $tenant = self::T): void
    {
        DB::table('gtm_suppressions')->insert(['sub_institute_id' => $tenant, 'kind' => $kind, 'value' => $value, 'reason' => $reason, 'created_at' => now()]);
    }

    private function outreach(string $email, string $status, int $tenant = self::T, ?string $deletedAt = null): void
    {
        DB::table('gtm_contacts')->insert(['sub_institute_id' => $tenant, 'account_id' => 1, 'full_name' => 'Outreach Person', 'email' => $email, 'status' => $status, 'deleted_at' => $deletedAt, 'created_at' => now(), 'updated_at' => now()]);
    }

    // ---- email: the four sources ----

    public function test_a_contact_with_an_address_and_nothing_against_it_may_be_emailed(): void
    {
        $c = $this->makeContact(self::T, ['email' => 'priya@school.example']);

        $this->assertSame(['allowed' => true, 'reason' => null, 'code' => null], $this->email($c));
    }

    public function test_each_of_the_four_sources_alone_stops_email_and_says_which(): void
    {
        $cases = [
            'the record\'s opt-out flag' => [fn ($c) => DB::table('crm_contacts')->where('id', $c)->update(['email_opt_out' => 1]), 'opted_out', 'Opted out of email (recorded on the record).'],
            'a consent preference' => [fn ($c) => $this->preference('contact', $c, 'email', 'opted_out', 'unsubscribe_link'), 'preference_opted_out', 'The email consent preference says opted out (source: unsubscribe_link).'],
            'the suppression list (the address)' => [fn ($c) => $this->suppress('email', 'priya@school.example', 'hard_bounce'), 'suppression_list', 'This address is on the suppression list (hard_bounce).'],
            'the suppression list (the whole domain)' => [fn ($c) => $this->suppress('domain', 'school.example', 'manual'), 'suppression_list', 'The domain of this address is on the suppression list (manual).'],
            'an outreach contact: unsubscribed' => [fn ($c) => $this->outreach('priya@school.example', 'unsubscribed'), 'outreach_contact_blocked', 'A matching outreach contact is marked unsubscribed.'],
            'an outreach contact: bounced' => [fn ($c) => $this->outreach('priya@school.example', 'bounced'), 'outreach_contact_blocked', 'A matching outreach contact is marked bounced.'],
            'an outreach contact: do not contact' => [fn ($c) => $this->outreach('priya@school.example', 'do_not_contact'), 'outreach_contact_blocked', 'A matching outreach contact is marked do_not_contact.'],
        ];

        foreach ($cases as $what => [$arrange, $code, $reason]) {
            $c = $this->makeContact(self::T, ['email' => 'priya@school.example']);
            $arrange($c);

            $r = $this->email($c);

            $this->assertSame(['allowed' => false, 'reason' => $reason, 'code' => $code], $r, $what);
            // clean up so the next case starts with none of the four
            DB::table('crm_consent_preferences')->delete();
            DB::table('gtm_suppressions')->delete();
            DB::table('gtm_contacts')->delete();
            DB::table('crm_contacts')->where('id', $c)->update(['email' => 'moved@elsewhere.example']);
        }
    }

    public function test_every_combination_of_the_four_sources_is_decided_by_whether_any_of_them_says_no(): void
    {
        $sources = [
            fn ($c) => DB::table('crm_contacts')->where('id', $c)->update(['email_opt_out' => 1]),
            fn ($c) => $this->preference('contact', $c, 'email', 'opted_out'),
            fn ($c) => $this->suppress('email', "combo{$c}@school.example"),
            fn ($c) => $this->outreach("combo{$c}@school.example", 'unsubscribed'),
        ];
        for ($mask = 0; $mask < 16; $mask++) {
            $c = $this->makeContact(self::T, ['email' => 'combo' . ($mask + 100) . '@school.example']);
            $email = 'combo' . $c . '@school.example';
            DB::table('crm_contacts')->where('id', $c)->update(['email' => $email]);
            foreach ($sources as $bit => $arrange) {
                if ($mask & (1 << $bit)) {
                    $arrange($c);
                }
            }

            $this->assertSame($mask === 0, $this->email($c)['allowed'], "sources set: " . decbin($mask));
        }
    }

    public function test_the_first_source_that_says_no_names_the_reason_in_the_order_record_preference_suppression_outreach(): void
    {
        $c = $this->makeContact(self::T, ['email' => 'priya@school.example', 'email_opt_out' => 1]);
        $this->preference('contact', $c, 'email', 'opted_out');
        $this->suppress('email', 'priya@school.example');
        $this->outreach('priya@school.example', 'unsubscribed');
        $order = [];

        $order[] = $this->email($c)['code'];
        DB::table('crm_contacts')->where('id', $c)->update(['email_opt_out' => 0]);
        $order[] = $this->email($c)['code'];
        DB::table('crm_consent_preferences')->delete();
        $order[] = $this->email($c)['code'];
        DB::table('gtm_suppressions')->delete();
        $order[] = $this->email($c)['code'];

        $this->assertSame(['opted_out', 'preference_opted_out', 'suppression_list', 'outreach_contact_blocked'], $order);
    }

    public function test_an_opt_in_preference_never_overrides_a_no_from_any_source(): void
    {
        $c = $this->makeContact(self::T, ['email' => 'priya@school.example']);
        $this->preference('contact', $c, 'email', 'opted_in');
        $this->assertTrue($this->email($c)['allowed']);

        $this->suppress('email', 'priya@school.example', 'complaint');

        $this->assertFalse($this->email($c)['allowed'], 'a person who opted in and later complained is not emailed');
    }

    public function test_addresses_are_compared_without_regard_to_case_and_only_for_the_same_organisation(): void
    {
        $c = $this->makeContact(self::T, ['email' => 'Priya@School.Example']);
        $this->suppress('email', 'priya@school.example', 'unsubscribed', self::T2);
        $this->outreach('priya@school.example', 'unsubscribed', self::T2);
        $this->assertTrue($this->email($c)['allowed'], 'the other organisation\'s lists are not this one\'s');

        $this->suppress('email', 'priya@school.example');

        $this->assertSame('suppression_list', $this->email($c)['code'], 'a stored address in capitals still meets the lower-case list entry');
    }

    public function test_an_outreach_contact_that_was_removed_or_is_active_does_not_block(): void
    {
        $c = $this->makeContact(self::T, ['email' => 'priya@school.example']);
        $this->outreach('priya@school.example', 'unsubscribed', self::T, '2026-10-01 00:00:00');
        $this->outreach('priya@school.example', 'active');
        $this->outreach('someone.else@school.example', 'unsubscribed');

        $this->assertTrue($this->email($c)['allowed']);
    }

    public function test_a_different_domain_or_address_on_the_suppression_list_does_not_block(): void
    {
        $c = $this->makeContact(self::T, ['email' => 'priya@school.example']);
        $this->suppress('domain', 'other.example');
        $this->suppress('email', 'priyanka@school.example');
        $this->suppress('domain', 'sub.school.example');

        $this->assertTrue($this->email($c)['allowed'], 'a domain entry blocks that domain and not its parent');
    }

    public function test_a_record_with_no_address_is_not_allowed_because_there_is_nobody_to_send_to(): void
    {
        $c = $this->makeContact(self::T, ['email' => null]);

        $this->assertSame(['allowed' => false, 'reason' => 'No email address on record.', 'code' => 'no_address'], $this->email($c));
    }

    // ---- hard stops and the other channels ----

    public function test_a_hard_stop_on_the_record_blocks_every_channel(): void
    {
        foreach ([['consent_status' => 'opted_out'], ['consent_status' => 'suppressed', 'suppressed_reason' => 'legal hold'], ['suppressed_at' => now(), 'suppressed_reason' => 'bounced']] as $flags) {
            $c = $this->makeContact(self::T, ['email' => 'priya@school.example', 'mobile' => '+91 98765 43210'] + $flags);
            foreach (['sms', 'whatsapp'] as $channel) {
                $this->preference('contact', $c, $channel, 'opted_in');
            }

            foreach (ConsentGate::CHANNELS as $channel) {
                $r = $this->gate->allows('contact', $c, $channel, self::T);
                $this->assertFalse($r['allowed'], json_encode($flags) . " {$channel}");
                $this->assertContains($r['code'], ['opted_out', 'suppressed']);
            }
            DB::table('crm_consent_preferences')->delete();
        }
        $this->assertSame('Suppressed (legal hold).', $this->gate->allows('contact', $this->makeContact(self::T, ['email' => 'a@b.example', 'consent_status' => 'suppressed', 'suppressed_reason' => 'legal hold']), 'email')['reason']);
    }

    public function test_the_email_opt_out_flag_is_for_email_only(): void
    {
        $c = $this->makeContact(self::T, ['email' => 'priya@school.example', 'mobile' => '+91 98765 43210', 'email_opt_out' => 1]);
        $this->preference('contact', $c, 'sms', 'opted_in');

        $this->assertFalse($this->gate->allows('contact', $c, 'email')['allowed']);
        $this->assertTrue($this->gate->allows('contact', $c, 'sms')['allowed'], 'a person who stopped the emails and agreed to texts is still texted');
    }

    public function test_sms_and_whatsapp_need_a_recorded_opt_in_and_an_opt_out_beats_it(): void
    {
        $c = $this->makeContact(self::T, ['email' => 'priya@school.example', 'mobile' => '+91 98765 43210']);

        foreach (['sms' => 'SMS', 'whatsapp' => 'WhatsApp'] as $channel => $label) {
            $none = $this->gate->allows('contact', $c, $channel);
            $this->assertSame(['allowed' => false, 'reason' => "No consent is recorded for {$label}: it needs an opt-in first.", 'code' => 'no_consent_recorded'], $none);
        }
        $this->preference('contact', $c, 'sms', 'unknown');
        $this->assertSame('no_consent_recorded', $this->gate->allows('contact', $c, 'sms')['code'], 'unknown is not consent');

        DB::table('crm_consent_preferences')->where('channel', 'sms')->update(['status' => 'opted_in']);
        $this->assertSame(['allowed' => true, 'reason' => null, 'code' => null], $this->gate->allows('contact', $c, 'sms'));
        $this->assertSame('no_consent_recorded', $this->gate->allows('contact', $c, 'whatsapp')['code'], 'consent is per channel');

        DB::table('crm_consent_preferences')->where('channel', 'sms')->update(['status' => 'opted_out', 'source' => 'inbound_reply']);
        $this->assertSame(['allowed' => false, 'reason' => 'The SMS consent preference says opted out (source: inbound_reply).', 'code' => 'preference_opted_out'], $this->gate->allows('contact', $c, 'sms'));
    }

    public function test_sms_needs_a_number_and_uses_the_mobile_before_the_phone(): void
    {
        $noNumber = $this->makeContact(self::T, ['email' => 'priya@school.example']);
        $this->preference('contact', $noNumber, 'sms', 'opted_in');
        $this->assertSame(['allowed' => false, 'reason' => 'No phone number on record.', 'code' => 'no_address'], $this->gate->allows('contact', $noNumber, 'sms'));

        $phoneOnly = $this->makeContact(self::T, ['phone' => '0265 234567']);
        $this->preference('contact', $phoneOnly, 'sms', 'opted_in');
        $this->assertTrue($this->gate->allows('contact', $phoneOnly, 'sms')['allowed']);

        $org = $this->makeOrganization(self::T, ['phone' => '0265 234567', 'email' => 'office@school.example']);
        $this->preference('organization', $org, 'whatsapp', 'opted_in');
        $this->assertTrue($this->gate->allows('organization', $org, 'whatsapp')['allowed'], 'an organisation has a phone and no mobile');
        $this->assertTrue($this->gate->allows('organization', $org, 'email')['allowed']);
    }

    public function test_a_lead_is_judged_the_same_way(): void
    {
        $lead = $this->makeLead(self::T, ['email' => 'rahul@school.example', 'email_opt_out' => 1]);
        $other = $this->makeLead(self::T, ['email' => 'asha@school.example']);

        $this->assertSame('opted_out', $this->gate->allows('lead', $lead, 'email')['code']);
        $this->assertTrue($this->gate->allows('lead', $other, 'email')['allowed']);
    }

    // ---- fail closed ----

    public function test_a_record_that_does_not_exist_is_removed_has_no_organisation_or_belongs_to_another_is_never_allowed(): void
    {
        $gone = $this->makeContact(self::T, ['email' => 'gone@school.example', 'deleted_at' => now()]);
        $theirs = $this->makeContact(self::T2, ['email' => 'theirs@school.example']);
        $orphan = $this->makeLead(0, ['email' => 'orphan@school.example']);
        DB::table('crm_leads')->where('id', $orphan)->update(['sub_institute_id' => null]);

        $cases = ['no such id' => ['contact', 999999, null], 'removed' => ['contact', $gone, null], 'a lead with no organisation' => ['lead', $orphan, null],
            'another organisation than the caller names' => ['contact', $theirs, self::T], 'a kind that is not a record' => ['opportunity', $theirs, null], 'id 0' => ['contact', 0, null]];
        foreach ($cases as $what => [$type, $id, $tenant]) {
            foreach (ConsentGate::CHANNELS as $channel) {
                $r = $this->gate->allows($type, $id, $channel, $tenant);
                $this->assertSame(['allowed' => false, 'reason' => 'This record does not exist for this organisation.', 'code' => 'unknown_recipient'], $r, "{$what}: {$channel}");
            }
        }
        $this->assertTrue($this->gate->allows('contact', $theirs, 'email', self::T2)['allowed'], 'the same record is fine when its own organisation is named');
        $this->assertTrue($this->gate->allows('contact', $theirs, 'email')['allowed'], 'and when none is: the organisation is read from the record itself');
    }

    public function test_an_unknown_channel_is_not_allowed(): void
    {
        $c = $this->makeContact(self::T, ['email' => 'priya@school.example']);

        $this->assertSame(['allowed' => false, 'reason' => "'carrier_pigeon' is not a channel this gate knows.", 'code' => 'unknown_channel'], $this->gate->allows('contact', $c, 'carrier_pigeon'));
    }

    public function test_a_source_this_host_does_not_have_says_nothing_and_the_others_still_speak(): void
    {
        $c = $this->makeContact(self::T, ['email' => 'priya@school.example']);
        Schema::drop('gtm_suppressions');
        Schema::drop('gtm_contacts');
        $this->assertTrue($this->email($c)['allowed'], 'no outreach engine tables, nothing to consult there');

        DB::table('crm_contacts')->where('id', $c)->update(['email_opt_out' => 1]);
        $this->assertFalse($this->email($c)['allowed'], 'the record\'s own flag still stops it');

        Schema::drop('crm_consent_preferences');
        $this->assertFalse($this->email($c)['allowed']);
        $this->assertFalse($this->gate->allows('contact', $c, 'sms')['allowed'], 'without a preferences table nothing is opted in');
    }

    // ---- the report ----

    public function test_the_report_lists_every_signal_found_with_where_and_when(): void
    {
        $c = $this->makeContact(self::T, ['email' => 'priya@school.example', 'email_opt_out' => 1, 'consent_status' => 'opted_out', 'suppressed_at' => '2026-10-02 08:00:00', 'suppressed_reason' => 'bounced']);
        $this->preference('contact', $c, 'email', 'opted_out', 'unsubscribe_link');
        $this->suppress('email', 'priya@school.example', 'complaint');
        $this->outreach('priya@school.example', 'bounced');

        $sources = $this->gate->report('contact', $c, self::T)['sources'];

        $this->assertSame(['email_opt_out', 'consent_status', 'suppressed_at', 'crm_consent_preferences', 'gtm_suppressions', 'gtm_contacts'], array_column($sources, 'source'));
        $values = array_column($sources, 'value', 'source');
        $this->assertSame(['1', 'opted_out', 'bounced', 'email: opted_out (unsubscribe_link)', 'complaint (email)', 'bounced'], array_values($values));
        $this->assertSame(\Carbon\Carbon::parse('2026-10-02 08:00:00')->toIso8601String(), array_column($sources, 'at', 'source')['suppressed_at']);
    }

    public function test_the_gate_only_reads_it_never_writes(): void
    {
        $c = $this->makeContact(self::T, ['email' => 'priya@school.example', 'email_opt_out' => 1]);
        $this->preference('contact', $c, 'email', 'opted_out');
        $this->suppress('email', 'priya@school.example');
        $this->outreach('priya@school.example', 'unsubscribed');
        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = strtolower(strtok(ltrim($query->sql), ' '));
        });

        foreach (ConsentGate::CHANNELS as $channel) {
            $this->gate->allows('contact', $c, $channel, self::T);
        }
        $this->gate->report('contact', $c);
        $this->gate->allows('contact', 999999, 'email');

        $this->assertNotSame([], $statements);
        $this->assertSame(['select'], array_values(array_unique($statements)), 'every statement the gate runs is a SELECT');
    }
}
