<?php

namespace Tests\Feature;

use App\Domain\OutreachGate\Draft;
use App\Domain\OutreachGate\DraftFactory;
use App\Domain\OutreachGate\GateResult;
use App\Domain\OutreachGate\OutreachGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsOutreachWorld;
use Tests\TestCase;

/**
 * The outreach gate against a real (in-memory) database: the checks that read records (claims, consent, suppression, outreach status, who the recipient is), the stored
 * set and the newest-set rule, and the rule that nothing is vouched for when something cannot be read. The pure text checks are in tests/Unit/OutreachGateTextChecksTest.
 * Nothing here can send: the gate does not, and these tests never call a mailer.
 */
class OutreachGateChecksTest extends TestCase
{
    use BuildsOutreachWorld;

    private OutreachGate $gate;

    private DraftFactory $drafts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildOutreachWorld();
        config(['app.url' => 'https://crm.example.org']);
        $this->gate = app(OutreachGate::class);
        $this->drafts = new DraftFactory();
    }

    // ---- builders ----

    /** A GTM draft to a contact that also exists in the CRM (so consent can be read). @param array<string, mixed> $message @return array{0: Draft, 1: string, 2: int, 3: int} [draft, address, crm contact id, gtm contact id] */
    private function gtmDraft(array $message = [], array $crm = []): array
    {
        $email = 'person.' . uniqid() . '@example.test';
        $crmId = $this->makeContact(self::T, $crm + ['email' => $email]);
        $gtmId = $this->makeGtmContact(self::T, ['email' => $email]);
        $m = $this->makeGtmMessage(self::T, $gtmId, $message);

        return [$this->drafts->gtm($m), $email, $crmId, $gtmId];
    }

    /** A CRM-email draft linked to a CRM contact. @param array<string, mixed> $crm @param array<string, mixed> $email @return array{0: Draft, 1: string, 2: int} */
    private function crmDraft(array $crm = [], array $email = [], string $type = 'CONTACT'): array
    {
        $address = 'person.' . uniqid() . '@example.test';
        $id = match ($type) {
            'LEAD' => $this->makeLead(self::T, $crm + ['email' => $address]),
            'ORGANIZATION' => $this->makeOrganization(self::T, $crm + ['email' => $address]),
            default => $this->makeContact(self::T, $crm + ['email' => $address]),
        };
        $row = $this->makeCrmEmail(self::T, $type, $id, $email + ['to_address' => $address]);

        return [$this->drafts->crm($row, $type, $id), $address, $id];
    }

    private function check(GateResult $r, string $key): \App\Domain\OutreachGate\CheckResult
    {
        foreach ($r->checks as $c) {
            if ($c->key === $key) {
                return $c;
            }
        }
        $this->fail("no check named {$key}: " . implode(',', array_map(fn ($c) => $c->key, $r->checks)));
    }

    /** @return list<string> the keys of the checks that failed */
    private function failedKeys(GateResult $r): array
    {
        return array_map(fn ($c) => $c->key, $r->failed());
    }

    // ---- the whole gate on a clean draft ----

    public function test_a_clean_gtm_draft_and_a_clean_crm_email_pass_every_check_and_the_set_is_stored_under_one_run(): void
    {
        [$gtm] = $this->gtmDraft();
        [$crm] = $this->crmDraft();

        foreach ([$gtm, $crm] as $draft) {
            $r = $this->gate->evaluate($draft);
            $this->assertSame([], $this->failedKeys($r), json_encode($r->toArray()));
            $this->assertTrue($r->passed());
            $this->assertSame(
                ['banned_phrase', 'length', 'link_count', 'placeholder', 'unapproved_claim', 'recipient', 'consent', 'suppression', 'contact_status', 'unsubscribe_link'],
                array_map(fn ($c) => $c->key, $r->checks)
            );
            $rows = DB::table('g2g_outreach_gate_checks')->where('run_id', $r->runId)->get();
            $this->assertCount(10, $rows);
            $this->assertSame([$draft->table], $rows->pluck('message_table')->unique()->values()->all());
            $this->assertSame([$draft->id], $rows->pluck('message_id')->unique()->map(fn ($v) => (int) $v)->values()->all());
            $this->assertSame([self::T], $rows->pluck('sub_institute_id')->unique()->map(fn ($v) => (int) $v)->values()->all());
            $this->assertSame([1], $rows->pluck('passed')->unique()->map(fn ($v) => (int) $v)->values()->all());
        }
    }

    public function test_evaluating_without_storing_writes_nothing(): void
    {
        [$draft] = $this->gtmDraft();

        $this->gate->evaluate($draft, false);

        $this->assertSame(0, DB::table('g2g_outreach_gate_checks')->count());
    }

    public function test_every_text_failure_is_reported_by_its_own_check_on_a_stored_message(): void
    {
        [$draft] = $this->gtmDraft(['body' => 'Act now, {{first_name}}! See https://a.example.org/x and https://b.example.org/y for a 60% saving.']);

        $r = $this->gate->evaluate($draft);

        $this->assertFalse($r->passed());
        $this->assertEqualsCanonicalizing(['banned_phrase', 'link_count', 'placeholder', 'unapproved_claim'], $this->failedKeys($r));
    }

    // ---- claims ----

    private function claimDraft(string $body, array $message = []): Draft
    {
        [$draft] = $this->gtmDraft($message + ['body' => $body]);

        return $draft;
    }

    public function test_a_claim_with_no_claim_rows_at_all_does_not_pass_the_safe_default(): void
    {
        $this->makeProduct(self::T, 'ScholarClone');

        $r = $this->gate->evaluate($this->claimDraft('ScholarClone cuts paperwork by 60%. Would a call suit you?'));

        $c = $this->check($r, 'unapproved_claim');
        $this->assertFalse($c->passed);
        $this->assertStringContainsString('No claim is approved for the product yet', $c->detail);
        $this->assertStringContainsString('cuts paperwork by 60%', $c->detail);
    }

    public function test_an_approved_claim_lets_the_sentence_through_normalised_for_case_spacing_and_punctuation(): void
    {
        $product = $this->makeProduct(self::T, 'ScholarClone');
        $this->makeClaim(self::T, $product, 'ScholarClone cuts paperwork by 60% for schools.');

        foreach (['ScholarClone cuts paperwork by 60% for schools.', 'SCHOLARCLONE   cuts  paperwork by 60 % for schools', 'scholarclone cuts paperwork by 60% for schools!'] as $sentence) {
            $r = $this->gate->evaluate($this->claimDraft("{$sentence}\nWould a call suit you?"));
            $this->assertTrue($this->check($r, 'unapproved_claim')->passed, $sentence . ' => ' . $this->check($r, 'unapproved_claim')->detail);
        }
    }

    public function test_a_changed_number_an_added_word_or_a_second_claim_is_not_covered(): void
    {
        $product = $this->makeProduct(self::T, 'ScholarClone');
        $this->makeClaim(self::T, $product, 'ScholarClone cuts paperwork by 60% for schools.');

        foreach (['ScholarClone cuts paperwork by 70% for schools.', 'ScholarClone cuts paperwork by 60% for schools, guaranteed.', 'ScholarClone cuts paperwork by 60% for schools. It is the best tool.'] as $text) {
            $this->assertFalse($this->check($this->gate->evaluate($this->claimDraft($text)), 'unapproved_claim')->passed, $text);
        }
    }

    public function test_only_an_approved_claim_counts_not_a_proposed_or_a_retired_one(): void
    {
        $product = $this->makeProduct(self::T, 'ScholarClone');
        $this->makeClaim(self::T, $product, 'ScholarClone cuts paperwork by 60% for schools.', 'proposed');
        $this->makeClaim(self::T, $product, 'ScholarClone is the best admissions tool.', 'retired');

        $this->assertFalse($this->check($this->gate->evaluate($this->claimDraft('ScholarClone cuts paperwork by 60% for schools.')), 'unapproved_claim')->passed);
        $this->assertFalse($this->check($this->gate->evaluate($this->claimDraft('ScholarClone is the best admissions tool.')), 'unapproved_claim')->passed);
    }

    public function test_another_organisations_approved_claim_and_another_products_claim_do_not_count(): void
    {
        $product = $this->makeProduct(self::T, 'ScholarClone');
        $other = $this->makeProduct(self::T, 'PayrollCloud');
        $this->makeClaim(self::T2, $product, 'ScholarClone cuts paperwork by 60% for schools.');
        $this->makeClaim(self::T, $other, 'ScholarClone cuts paperwork by 60% for schools.');

        $this->assertFalse($this->check($this->gate->evaluate($this->claimDraft('ScholarClone cuts paperwork by 60% for schools.')), 'unapproved_claim')->passed);
    }

    public function test_a_shared_product_is_found_by_name_and_the_organisations_own_claim_for_it_counts(): void
    {
        $shared = $this->makeProduct(0, 'ScholarClone', ['kind' => 'product']);
        $this->makeClaim(self::T, $shared, 'ScholarClone cuts paperwork by 60% for schools.');

        $this->assertTrue($this->check($this->gate->evaluate($this->claimDraft('ScholarClone cuts paperwork by 60% for schools.')), 'unapproved_claim')->passed);
    }

    public function test_the_product_a_message_points_at_counts_even_when_its_text_does_not_name_it(): void
    {
        $product = $this->makeProduct(self::T, 'ScholarClone');
        $this->makeClaim(self::T, $product, 'Cuts paperwork by 60% for schools.');
        $text = 'Cuts paperwork by 60% for schools. Would a call suit you?';

        $this->assertFalse($this->check($this->gate->evaluate($this->claimDraft($text)), 'unapproved_claim')->passed, 'no pointer and no name: nothing to rest on');
        $this->assertTrue($this->check($this->gate->evaluate($this->claimDraft($text, ['product_master_id' => $product])), 'unapproved_claim')->passed, 'the message points at the product');
    }

    public function test_a_pointer_to_another_organisations_product_is_ignored(): void
    {
        $theirs = $this->makeProduct(self::T2, 'TheirProduct');
        $this->makeClaim(self::T, $theirs, 'Cuts paperwork by 60% for schools.');

        $r = $this->gate->evaluate($this->claimDraft('Cuts paperwork by 60% for schools.', ['product_master_id' => $theirs]));

        $this->assertFalse($this->check($r, 'unapproved_claim')->passed);
        $this->assertStringContainsString('names no product', $this->check($r, 'unapproved_claim')->detail);
    }

    public function test_a_claim_in_a_draft_that_names_no_product_fails_with_that_reason(): void
    {
        $c = $this->check($this->gate->evaluate($this->claimDraft('We guarantee a reply within a day.')), 'unapproved_claim');

        $this->assertFalse($c->passed);
        $this->assertStringContainsString('names no product', $c->detail);
    }

    public function test_a_draft_without_a_claim_needs_no_claim_rows(): void
    {
        $this->assertTrue($this->check($this->gate->evaluate($this->claimDraft('We only need 15 minutes of your time next week.')), 'unapproved_claim')->passed);
    }

    // ---- consent, suppression and outreach status: every source that can say no ----

    public function test_a_crm_contact_flagged_opted_out_by_consent_status_blocks_a_crm_email(): void
    {
        [$draft] = $this->crmDraft(['consent_status' => 'opted_out']);

        $r = $this->gate->evaluate($draft);

        $this->assertContains('consent', $this->failedKeys($r));
        $this->assertStringContainsString('Opted out', $this->check($r, 'consent')->detail);
    }

    public function test_the_email_opt_out_flag_blocks_a_crm_email(): void
    {
        [$draft] = $this->crmDraft(['email_opt_out' => 1], [], 'LEAD');

        $r = $this->gate->evaluate($draft);

        $this->assertContains('consent', $this->failedKeys($r));
        $this->assertStringContainsString('Opted out of email', $this->check($r, 'consent')->detail);
    }

    public function test_a_consent_preference_that_says_opted_out_for_email_blocks_a_crm_email(): void
    {
        [$draft, , $id] = $this->crmDraft();
        DB::table('crm_consent_preferences')->insert(['entity_type' => 'contact', 'entity_id' => $id, 'channel' => 'email', 'status' => 'opted_out', 'sub_institute_id' => self::T, 'source' => 'unsubscribe link']);

        $r = $this->gate->evaluate($draft);

        $this->assertContains('consent', $this->failedKeys($r));
        $this->assertStringContainsString('consent preference says opted out', $this->check($r, 'consent')->detail);
    }

    public function test_a_suppressed_record_blocks_a_crm_email_on_every_check_that_can_see_it(): void
    {
        [$draft] = $this->crmDraft(['suppressed_at' => now(), 'suppressed_reason' => 'complaint']);

        $this->assertContains('consent', $this->failedKeys($this->gate->evaluate($draft)));
    }

    public function test_an_address_on_the_suppression_list_blocks_both_kinds_of_message(): void
    {
        [$crm, $crmAddress] = $this->crmDraft();
        [$gtm, $gtmAddress] = $this->gtmDraft();
        DB::table('gtm_suppressions')->insert([
            ['sub_institute_id' => self::T, 'kind' => 'email', 'value' => $crmAddress, 'reason' => 'unsubscribed', 'created_at' => now()],
            ['sub_institute_id' => self::T, 'kind' => 'email', 'value' => $gtmAddress, 'reason' => 'hard_bounce', 'created_at' => now()],
        ]);

        foreach ([$crm, $gtm] as $draft) {
            $r = $this->gate->evaluate($draft);
            $this->assertContains('suppression', $this->failedKeys($r));
            $this->assertStringContainsString('suppression list', $this->check($r, 'suppression')->detail);
        }
    }

    public function test_a_suppressed_domain_blocks_every_address_on_it(): void
    {
        [$draft] = $this->crmDraft();
        DB::table('gtm_suppressions')->insert(['sub_institute_id' => self::T, 'kind' => 'domain', 'value' => 'example.test', 'reason' => 'manual', 'created_at' => now()]);

        $c = $this->check($this->gate->evaluate($draft), 'suppression');

        $this->assertFalse($c->passed);
        $this->assertStringContainsString('domain example.test', $c->detail);
    }

    public function test_another_organisations_suppression_list_does_not_apply(): void
    {
        [$draft, $address] = $this->crmDraft();
        DB::table('gtm_suppressions')->insert(['sub_institute_id' => self::T2, 'kind' => 'email', 'value' => $address, 'reason' => 'manual', 'created_at' => now()]);

        $this->assertTrue($this->check($this->gate->evaluate($draft), 'suppression')->passed);
    }

    public function test_an_outreach_contact_marked_bounced_unsubscribed_or_do_not_contact_blocks_a_crm_email_by_address(): void
    {
        foreach (['bounced', 'unsubscribed', 'do_not_contact'] as $status) {
            [$draft, $address] = $this->crmDraft();
            $this->makeGtmContact(self::T, ['email' => strtoupper($address), 'status' => $status]);

            $r = $this->gate->evaluate($draft);

            $this->assertContains('contact_status', $this->failedKeys($r), $status);
            $this->assertStringContainsString($status, $this->check($r, 'contact_status')->detail);
        }
    }

    public function test_an_active_or_removed_outreach_contact_does_not_block(): void
    {
        [$draft, $address] = $this->crmDraft();
        $this->makeGtmContact(self::T, ['email' => $address, 'status' => 'active']);
        $this->makeGtmContact(self::T, ['email' => $address, 'status' => 'bounced', 'deleted_at' => now()]);

        $this->assertTrue($this->check($this->gate->evaluate($draft), 'contact_status')->passed);
    }

    public function test_a_gtm_message_to_a_contact_whose_crm_twin_opted_out_is_blocked(): void
    {
        [$draft] = $this->gtmDraft([], ['email_opt_out' => 1]);

        $r = $this->gate->evaluate($draft);

        $this->assertContains('consent', $this->failedKeys($r));
        $this->assertStringContainsString('Contact #', $this->check($r, 'consent')->detail);
    }

    public function test_a_gtm_message_to_a_contact_now_marked_do_not_contact_is_blocked(): void
    {
        [$draft, , , $gtmId] = $this->gtmDraft();
        DB::table('gtm_contacts')->where('id', $gtmId)->update(['status' => 'do_not_contact']);

        $this->assertContains('contact_status', $this->failedKeys($this->gate->evaluate($draft)));
    }

    public function test_any_one_of_several_crm_records_with_the_address_that_says_no_blocks_the_message(): void
    {
        [$draft, $address] = $this->gtmDraft();
        $this->makeLead(self::T, ['email' => $address, 'email_opt_out' => 1]);   // the contact is fine, a lead of the same person opted out

        $this->assertContains('consent', $this->failedKeys($this->gate->evaluate($draft)));
    }

    // ---- who is the recipient: what cannot be mapped fails closed ----

    public function test_a_gtm_contact_with_no_crm_record_fails_closed_with_a_plain_reason(): void
    {
        $gtmId = $this->makeGtmContact(self::T, ['email' => 'only.outreach@example.test']);
        $draft = $this->drafts->gtm($this->makeGtmMessage(self::T, $gtmId));

        $r = $this->gate->evaluate($draft);

        $this->assertContains('recipient', $this->failedKeys($r));
        $this->assertContains('consent', $this->failedKeys($r));
        $this->assertStringContainsString('Add the person to the CRM first', $this->check($r, 'recipient')->detail);
    }

    public function test_the_configuration_can_let_a_gtm_only_contact_rest_on_its_outreach_status_and_the_suppression_list(): void
    {
        config(['outreach_gate.unmapped_gtm_recipient' => 'gtm_only']);
        $gtmId = $this->makeGtmContact(self::T, ['email' => 'only.outreach@example.test']);
        $draft = $this->drafts->gtm($this->makeGtmMessage(self::T, $gtmId));

        $r = $this->gate->evaluate($draft);
        $this->assertSame([], $this->failedKeys($r));
        $this->assertStringContainsString('consent rests on the outreach contact status', $this->check($r, 'consent')->detail, 'the person is told on what the answer rests');

        // and the two lists that remain are still enforced
        DB::table('gtm_suppressions')->insert(['sub_institute_id' => self::T, 'kind' => 'email', 'value' => 'only.outreach@example.test', 'reason' => 'manual', 'created_at' => now()]);
        $this->assertContains('suppression', $this->failedKeys($this->gate->evaluate($draft)));
        DB::table('gtm_suppressions')->delete();
        DB::table('gtm_contacts')->where('id', $gtmId)->update(['status' => 'bounced']);
        $this->assertContains('contact_status', $this->failedKeys($this->gate->evaluate($draft)));
    }

    public function test_a_crm_record_of_another_organisation_with_the_same_address_is_not_a_mapping(): void
    {
        $email = 'shared.address@example.test';
        $this->makeContact(self::T2, ['email' => $email]);
        $gtmId = $this->makeGtmContact(self::T, ['email' => $email]);
        $draft = $this->drafts->gtm($this->makeGtmMessage(self::T, $gtmId));

        $this->assertContains('recipient', $this->failedKeys($this->gate->evaluate($draft)));
    }

    public function test_a_removed_crm_record_is_not_a_mapping(): void
    {
        $email = 'removed.contact@example.test';
        $this->makeContact(self::T, ['email' => $email, 'deleted_at' => now()]);
        $gtmId = $this->makeGtmContact(self::T, ['email' => $email]);

        $this->assertContains('recipient', $this->failedKeys($this->gate->evaluate($this->drafts->gtm($this->makeGtmMessage(self::T, $gtmId)))));
    }

    public function test_a_removed_or_foreign_outreach_contact_or_one_with_a_bad_address_fails_every_recipient_check(): void
    {
        $removed = $this->makeGtmContact(self::T, ['deleted_at' => now()]);
        $foreign = $this->makeGtmContact(self::T2);
        $noAddress = $this->makeGtmContact(self::T, ['email' => null]);
        $badAddress = $this->makeGtmContact(self::T, ['email' => 'not-an-address']);
        $ownMessageOfForeignContact = $this->makeGtmMessage(self::T, $foreign);

        foreach ([$this->makeGtmMessage(self::T, $removed), $ownMessageOfForeignContact, $this->makeGtmMessage(self::T, $noAddress), $this->makeGtmMessage(self::T, $badAddress)] as $m) {
            $failed = $this->failedKeys($this->gate->evaluate($this->drafts->gtm($m)));
            foreach (['recipient', 'consent', 'suppression', 'contact_status'] as $key) {
                $this->assertContains($key, $failed, "message {$m->id}: {$key}");
            }
        }
    }

    public function test_a_crm_email_with_an_invalid_address_or_an_unknown_kind_of_link_fails_closed(): void
    {
        [$bad] = $this->crmDraft([], ['to_address' => 'nobody']);
        $this->assertContains('recipient', $this->failedKeys($this->gate->evaluate($bad)));

        $row = $this->makeCrmEmail(self::T, 'INVOICE', 5);
        $r = $this->gate->evaluate($this->drafts->crm($row, 'INVOICE', 5));
        $this->assertContains('recipient', $this->failedKeys($r));
        $this->assertStringContainsString('does not know (INVOICE)', $this->check($r, 'recipient')->detail);
    }

    public function test_a_crm_email_linked_to_a_record_of_another_organisation_is_blocked_by_the_consent_gate(): void
    {
        $foreign = $this->makeContact(self::T2, ['email' => 'theirs@example.test']);
        $row = $this->makeCrmEmail(self::T, 'CONTACT', $foreign, ['to_address' => 'theirs@example.test']);

        $r = $this->gate->evaluate($this->drafts->crm($row, 'CONTACT', $foreign));

        $this->assertContains('consent', $this->failedKeys($r));
        $this->assertStringContainsString('does not exist for this organisation', $this->check($r, 'consent')->detail);
    }

    public function test_an_opportunity_is_read_through_its_organisation_and_a_ticket_through_its_contact_then_its_organisation(): void
    {
        $org = $this->makeOrganization(self::T, ['email_opt_out' => 1]);
        $contact = $this->makeContact(self::T, ['email' => 'ticket.person@example.test']);
        $opportunity = DB::table('crm_opportunities')->insertGetId(['organization_id' => $org, 'sub_institute_id' => self::T]);
        $ticketOnContact = DB::table('crm_tickets')->insertGetId(['contact_id' => $contact, 'organization_id' => $org, 'sub_institute_id' => self::T]);
        $ticketOnOrg = DB::table('crm_tickets')->insertGetId(['organization_id' => $org, 'sub_institute_id' => self::T]);
        $ticketNowhere = DB::table('crm_tickets')->insertGetId(['sub_institute_id' => self::T]);

        $cases = [
            'OPPORTUNITY' => [$opportunity, false, 'Organization #' . $org],
            'TICKET via its contact' => [$ticketOnContact, true, 'contact #' . $contact],
            'TICKET via its organisation' => [$ticketOnOrg, false, 'Organization #' . $org],
        ];
        foreach ($cases as $label => [$id, $allowed, $named]) {
            $type = str_starts_with($label, 'OPPORTUNITY') ? 'OPPORTUNITY' : 'TICKET';
            $row = $this->makeCrmEmail(self::T, $type, $id, ['to_address' => 'ticket.person@example.test']);
            $r = $this->gate->evaluate($this->drafts->crm($row, $type, $id));
            $this->assertSame($allowed, $this->check($r, 'consent')->passed, $label . ' => ' . $this->check($r, 'consent')->detail);
            $this->assertStringContainsString($named, $allowed ? $this->check($r, 'recipient')->detail : $this->check($r, 'consent')->detail, $label);
        }

        $row = $this->makeCrmEmail(self::T, 'TICKET', $ticketNowhere, ['to_address' => 'ticket.person@example.test']);
        $this->assertContains('recipient', $this->failedKeys($this->gate->evaluate($this->drafts->crm($row, 'TICKET', $ticketNowhere))), 'a ticket with neither contact nor organisation cannot be mapped');

        $foreignOpportunity = DB::table('crm_opportunities')->insertGetId(['organization_id' => $this->makeOrganization(self::T2), 'sub_institute_id' => self::T2]);
        $row = $this->makeCrmEmail(self::T, 'OPPORTUNITY', $foreignOpportunity, ['to_address' => 'x@example.test']);
        $this->assertContains('recipient', $this->failedKeys($this->gate->evaluate($this->drafts->crm($row, 'OPPORTUNITY', $foreignOpportunity))), "another organisation's opportunity is not read");
    }

    public function test_a_crm_email_to_a_record_with_no_address_of_its_own_fails_closed_because_its_consent_cannot_be_read(): void
    {
        [$draft] = $this->crmDraft(['email' => null]);

        $c = $this->check($this->gate->evaluate($draft), 'consent');

        $this->assertFalse($c->passed);
        $this->assertStringContainsString('No email address on record', $c->detail);
    }

    // ---- the unsubscribe link: the mailers' own rule, reused ----

    public function test_the_unsubscribe_check_asks_each_mailer_its_own_question(): void
    {
        [$gtm] = $this->gtmDraft();
        [$crm] = $this->crmDraft();
        config(['app.url' => 'http://localhost', 'gtm.outreach.allow_local_unsubscribe' => false, 'crm.email_allow_local_unsubscribe' => false]);
        $this->assertFalse($this->check($this->gate->evaluate($gtm), 'unsubscribe_link')->passed);
        $this->assertFalse($this->check($this->gate->evaluate($crm), 'unsubscribe_link')->passed);

        config(['gtm.outreach.allow_local_unsubscribe' => true]);   // only the GTM mailer's own switch
        $this->assertTrue($this->check($this->gate->evaluate($gtm), 'unsubscribe_link')->passed);
        $this->assertFalse($this->check($this->gate->evaluate($crm), 'unsubscribe_link')->passed, 'the CRM mailer has its own switch');
    }

    // ---- the stored set and the newest-set rule ----

    public function test_a_re_check_adds_a_set_and_the_newest_set_is_the_current_one(): void
    {
        [$draft, , , $gtmId] = $this->gtmDraft();

        $first = $this->gate->evaluate($draft);
        $this->assertTrue($first->passed());
        DB::table('gtm_contacts')->where('id', $gtmId)->update(['status' => 'bounced']);
        $second = $this->gate->evaluate($draft);

        $this->assertNotSame($first->runId, $second->runId);
        $this->assertFalse($second->passed());
        $this->assertSame(20, DB::table('g2g_outreach_gate_checks')->count(), 'both sets are kept');
        $latest = $this->gate->latest(self::T, $draft->table, $draft->id);
        $this->assertSame($second->runId, $latest->runId);
        $this->assertFalse($latest->passed());
        $this->assertSame(['consent', 'contact_status'], $this->failedKeys($latest), 'the consent gate reads the outreach status too, so both checks say no');
        $this->assertSame('Outreach contact is reachable', $this->check($latest, 'contact_status')->label, 'the labels are restored from the check list');

        DB::table('gtm_contacts')->where('id', $gtmId)->update(['status' => 'active']);
        $third = $this->gate->evaluate($draft);
        $this->assertSame($third->runId, $this->gate->latest(self::T, $draft->table, $draft->id)->runId);
        $this->assertTrue($this->gate->latest(self::T, $draft->table, $draft->id)->passed());
    }

    public function test_the_newest_set_is_per_message_and_per_table_and_per_organisation(): void
    {
        [$a] = $this->gtmDraft();
        [$b] = $this->gtmDraft();
        [$crm] = $this->crmDraft();
        $ra = $this->gate->evaluate($a);
        $rb = $this->gate->evaluate($b);
        $rc = $this->gate->evaluate($crm);

        $this->assertSame($ra->runId, $this->gate->latest(self::T, Draft::GTM, $a->id)->runId);
        $this->assertSame($rb->runId, $this->gate->latest(self::T, Draft::GTM, $b->id)->runId);
        $this->assertSame($rc->runId, $this->gate->latest(self::T, Draft::CRM, $crm->id)->runId);
        $this->assertNull($this->gate->latest(self::T2, Draft::GTM, $a->id), "another organisation sees none of this organisation's sets");
        $this->assertNull($this->gate->latest(self::T, Draft::GTM, 999999));
    }

    public function test_the_same_id_in_the_other_table_is_not_the_same_message(): void
    {
        [$gtm] = $this->gtmDraft();
        $this->gate->evaluate($gtm);

        $this->assertNotNull($this->gate->latest(self::T, Draft::GTM, $gtm->id));
        $this->assertNull($this->gate->latest(self::T, Draft::CRM, $gtm->id), 'a CRM email with the same number was never evaluated');
    }

    public function test_a_failure_to_store_never_changes_the_answer(): void
    {
        [$ok] = $this->gtmDraft();
        [$bad] = $this->gtmDraft(['body' => 'Act now please, this will take a minute.']);
        Schema::drop('g2g_outreach_gate_checks');

        $this->assertTrue($this->gate->evaluate($ok)->passed());
        $this->assertFalse($this->gate->evaluate($bad)->passed());
        $this->assertNull($this->gate->latest(self::T, $ok->table, $ok->id));
    }

    // ---- fail closed ----

    public function test_a_check_that_throws_counts_as_failed_and_the_message_is_held_back(): void
    {
        [$draft] = $this->crmDraft([], [], 'LEAD');
        Schema::drop('crm_leads');   // the consent gate cannot read the lead: its query throws

        $r = $this->gate->evaluate($draft);

        $this->assertFalse($r->passed());
        $c = $this->check($r, 'consent');
        $this->assertFalse($c->passed);
        $this->assertStringContainsString('could not run', $c->detail);
        $this->assertStringNotContainsString('SQLSTATE', $c->detail, 'a database message never reaches a person');
    }

    public function test_for_send_never_throws_and_never_passes_by_accident(): void
    {
        [$draft] = $this->crmDraft();
        $throwing = new class(app(\App\Domain\OutreachGate\CheckStore::class), app(\App\Domain\OutreachGate\ClaimBook::class), app(\App\Domain\Facts\ConsentGate::class), app(\App\Domain\Gtm\Outreach\Suppressions::class)) extends OutreachGate {
            public function evaluate(Draft $draft, bool $store = true): GateResult
            {
                throw new \RuntimeException('boom');
            }
        };

        $answer = $throwing->forSend($draft);

        $this->assertFalse($answer['passed']);
        $this->assertSame('gate', $answer['checks'][0]['key']);
        $this->assertStringContainsString('could not run', $answer['checks'][0]['detail']);
        $this->assertStringNotContainsString('boom', $answer['message']);
    }

    public function test_for_send_lists_only_the_failing_checks_in_a_message_a_row_can_hold(): void
    {
        [$draft] = $this->gtmDraft(['body' => 'Act now, {{first_name}}, ' . str_repeat('more words here ', 100)]);

        $answer = $this->gate->forSend($draft);

        $this->assertFalse($answer['passed']);
        $this->assertEqualsCanonicalizing(['banned_phrase', 'placeholder', 'length'], array_column($answer['checks'], 'key'));
        $this->assertSame([false], array_values(array_unique(array_column($answer['checks'], 'passed'))));
        $this->assertLessThanOrEqual(380, mb_strlen($answer['message']));
        $this->assertStringStartsWith('The outreach checks stopped this message.', $answer['message']);
        $this->assertStringEndsWith('Nothing was sent.', $answer['message']);
        $this->assertSame(['passed' => true, 'message' => '', 'checks' => []], $this->gate->forSend($this->gtmDraft()[0]));
    }

    // ---- finding a draft for the preview ----

    public function test_a_draft_is_found_only_in_the_organisation_that_owns_it_and_only_if_it_is_outgoing(): void
    {
        [$gtm] = $this->gtmDraft();
        [$crm] = $this->crmDraft();
        $inbound = $this->makeCrmEmail(self::T, 'LEAD', $this->makeLead(), ['direction' => 'inbound', 'status' => 'Received']);

        $this->assertNotNull($this->drafts->find(self::T, Draft::GTM, $gtm->id));
        $this->assertNotNull($this->drafts->find(self::T, Draft::CRM, $crm->id));
        $this->assertNull($this->drafts->find(self::T2, Draft::GTM, $gtm->id), 'another organisation');
        $this->assertNull($this->drafts->find(self::T2, Draft::CRM, $crm->id));
        $this->assertNull($this->drafts->find(self::T, Draft::CRM, $inbound->id), 'an inbound email is not a draft');
        $this->assertNull($this->drafts->find(self::T, 'tbluser', 1), 'a table the gate does not know');
        $this->assertNull($this->drafts->find(self::T, Draft::GTM, 0));
        $this->assertSame('CONTACT', $this->drafts->find(self::T, Draft::CRM, $crm->id)->linkedType);
    }

    // ---- added after the mutation proofs (task 7): each test below closes a gap where changing the code left every earlier test green ----

    public function test_an_unsubscribe_link_already_in_the_body_is_not_a_second_link_through_the_whole_gate(): void
    {
        [$draft] = $this->gtmDraft(['body' => 'Hello. See https://example.org/demo for details. Unsubscribe: https://crm.example.org/api/gtm/unsubscribe/5?signature=abc']);
        [$two] = $this->gtmDraft(['body' => 'See https://example.org/a and https://example.org/b please.']);

        $this->assertTrue($this->check($this->gate->evaluate($draft), 'link_count')->passed, 'one link plus the unsubscribe link');
        $this->assertFalse($this->check($this->gate->evaluate($two), 'link_count')->passed, 'two ordinary links');
    }

    public function test_a_gate_that_cannot_be_set_up_holds_the_message_back(): void
    {
        [$draft] = $this->gtmDraft();
        config(['outreach_gate.placeholder_patterns' => ['(unclosed']]);   // a pattern that does not compile: the list of checks cannot be built

        $r = $this->gate->evaluate($draft);

        $this->assertFalse($r->passed());
        $this->assertSame(['gate'], $this->failedKeys($r));
        $this->assertStringContainsString('could not be set up', $this->check($r, 'gate')->detail);
        $answer = $this->gate->forSend($draft);
        $this->assertFalse($answer['passed']);
        $this->assertSame(['gate'], array_column($answer['checks'], 'key'));
    }

    public function test_a_gate_with_no_check_to_run_vouches_for_nothing(): void
    {
        [$draft] = $this->gtmDraft();
        $empty = new class(app(\App\Domain\OutreachGate\CheckStore::class), app(\App\Domain\OutreachGate\ClaimBook::class), app(\App\Domain\Facts\ConsentGate::class), app(\App\Domain\Gtm\Outreach\Suppressions::class)) extends OutreachGate {
            public function checks(\App\Domain\OutreachGate\RecipientMap $map): array
            {
                return [];
            }
        };

        $this->assertFalse($empty->evaluate($draft)->passed());
        $answer = $empty->forSend($draft);
        $this->assertFalse($answer['passed']);
        $this->assertSame(['gate'], array_column($answer['checks'], 'key'));
        $this->assertStringContainsString('No check ran', $answer['checks'][0]['detail']);
    }

    public function test_a_storage_failure_that_throws_never_changes_the_answer(): void
    {
        [$ok] = $this->gtmDraft();
        [$bad] = $this->gtmDraft(['body' => 'Act now please, this will take a minute.']);
        Schema::drop('g2g_outreach_gate_checks');
        Schema::create('g2g_outreach_gate_checks', fn ($t) => $t->id());   // the table is there but the wrong shape: the insert THROWS (the test above drops it, which only skips)

        $this->assertTrue($this->gate->evaluate($ok)->passed());
        $this->assertFalse($this->gate->evaluate($bad)->passed());
        $this->assertSame(['passed' => true, 'message' => '', 'checks' => []], $this->gate->forSend($ok));
        $this->assertFalse($this->gate->forSend($bad)['passed']);
    }

    public function test_nothing_is_stored_for_an_empty_set_or_for_no_organisation(): void
    {
        $store = app(\App\Domain\OutreachGate\CheckStore::class);
        $at = now();

        $this->assertFalse($store->save(new GateResult('run-a', Draft::GTM, 1, self::T, [], $at)), 'an empty set');
        $this->assertFalse($store->save(new GateResult('run-b', Draft::GTM, 1, 0, [\App\Domain\OutreachGate\CheckResult::pass('k', 'L')], $at)), 'no organisation');
        $this->assertSame(0, DB::table('g2g_outreach_gate_checks')->count());
        $this->assertTrue($store->save(new GateResult('run-c', Draft::GTM, 1, self::T, [\App\Domain\OutreachGate\CheckResult::pass('k', 'L', 'ok')], $at)));
        $this->assertSame(1, DB::table('g2g_outreach_gate_checks')->count());
    }

    public function test_a_crm_email_with_no_link_or_in_no_organisation_cannot_be_mapped(): void
    {
        $row = $this->makeCrmEmail(self::T, 'LEAD', 0, ['to_address' => 'nolink@example.test']);

        $this->assertContains('recipient', $this->failedKeys($this->gate->evaluate($this->drafts->crm($row, 'LEAD', 0))), 'a link to record number 0');
        $lead = $this->makeLead(self::T, ['email' => 'inno.org@example.test']);
        $homeless = new Draft(Draft::CRM, 1, 0, 'A subject', 'A body', 'inno.org@example.test', null, 'LEAD', $lead);
        $this->assertContains('recipient', $this->failedKeys($this->gate->evaluate($homeless, false)), 'a draft that belongs to no organisation');
    }

    public function test_a_removed_opportunity_is_not_read(): void
    {
        $org = $this->makeOrganization(self::T, ['email' => 'org.person@example.test']);
        $gone = DB::table('crm_opportunities')->insertGetId(['organization_id' => $org, 'sub_institute_id' => self::T, 'deleted_at' => now()]);
        $row = $this->makeCrmEmail(self::T, 'OPPORTUNITY', $gone, ['to_address' => 'org.person@example.test']);

        $this->assertContains('recipient', $this->failedKeys($this->gate->evaluate($this->drafts->crm($row, 'OPPORTUNITY', $gone))));
    }

    public function test_the_address_is_matched_whatever_its_case(): void
    {
        $email = 'Mixed.Case@Example.TEST';
        $this->makeContact(self::T, ['email' => $email]);
        $gtmId = $this->makeGtmContact(self::T, ['email' => $email]);

        $r = $this->gate->evaluate($this->drafts->gtm($this->makeGtmMessage(self::T, $gtmId)));

        $this->assertSame([], $this->failedKeys($r), json_encode($r->toArray()));
    }

    public function test_an_outreach_contact_that_belongs_to_no_organisation_is_not_a_recipient_even_when_the_configuration_is_lenient(): void
    {
        config(['outreach_gate.unmapped_gtm_recipient' => 'gtm_only']);
        $shared = $this->makeGtmContact(0, ['email' => 'shared.contact@example.test']);

        $r = $this->gate->evaluate(new Draft(Draft::GTM, 1, 0, 'A subject', 'A body', '', $shared), false);

        $this->assertContains('recipient', $this->failedKeys($r));
    }

    public function test_one_recipient_map_keeps_two_drafts_apart_and_answers_the_same_draft_once(): void
    {
        [$a] = $this->gtmDraft();
        [$b] = $this->gtmDraft();
        $map = new \App\Domain\OutreachGate\RecipientMap();

        $ra = $map->for($a);
        $rb = $map->for($b);

        $this->assertNotSame($ra->email, $rb->email);
        $this->assertSame($ra, $map->for($a), 'the same draft is looked up once');
        $this->assertSame($rb, $map->for($b));
    }

    public function test_an_outreach_contact_of_another_organisation_with_the_same_address_does_not_block(): void
    {
        [$draft, $address] = $this->crmDraft();
        $this->makeGtmContact(self::T2, ['email' => $address, 'status' => 'bounced']);

        $this->assertTrue($this->check($this->gate->evaluate($draft), 'contact_status')->passed);
    }

    public function test_the_product_named_in_a_draft_is_this_organisations_own_or_a_shared_one_found_as_a_whole_word_of_three_letters_or_more(): void
    {
        $this->makeProduct(self::T, 'ScholarClone');
        $this->makeProduct(self::T, 'AI');
        $this->makeProduct(self::T2, 'TheirProduct');
        $unnamed = fn (string $text) => str_contains($this->check($this->gate->evaluate($this->claimDraft($text)), 'unapproved_claim')->detail, 'names no product');

        $this->assertTrue($unnamed('TheirProduct cuts waiting by 40% for schools.'), "another organisation's product is not named");
        $this->assertTrue($unnamed('ScholarClones cut paperwork by 60% for schools.'), 'a longer word after the name');
        $this->assertTrue($unnamed('MyScholarClone cuts paperwork by 60% for schools.'), 'a longer word in front of the name');
        $this->assertTrue($unnamed('We use AI to cut paperwork by 60% for schools.'), 'a name of two letters is never searched for');
        $this->assertFalse($unnamed('scholarclone cuts paperwork by 60% for schools.'), 'the name itself, in any case');
    }

    public function test_the_first_link_of_a_crm_email_in_its_own_organisation_is_the_one_used(): void
    {
        [$crm, , $contact] = $this->crmDraft();
        $second = $this->makeLead(self::T, ['email' => 'second.link@example.test']);
        DB::table('crm_email_links')->where('email_id', $crm->id)->delete();
        $link = fn (int $tenant, string $type, int $id) => DB::table('crm_email_links')->insert(['email_id' => $crm->id, 'linked_type' => $type, 'linked_id' => $id, 'sub_institute_id' => $tenant, 'created_at' => now()]);
        $link(self::T2, 'ORGANIZATION', 999);   // another organisation's row, written FIRST: it must never be read
        $link(self::T, 'CONTACT', $contact);
        $link(self::T, 'LEAD', $second);

        $found = $this->drafts->find(self::T, Draft::CRM, $crm->id);

        $this->assertSame(['CONTACT', $contact], [$found->linkedType, $found->linkedId]);
    }

    public function test_an_organisation_of_zero_finds_no_draft(): void
    {
        $contact = $this->makeGtmContact(0, ['email' => 'zero.org@example.test']);
        $message = $this->makeGtmMessage(0, $contact);

        $this->assertNull($this->drafts->find(0, Draft::GTM, $message->id), 'a row of organisation 0 is shared data, never a draft of anybody');
    }
}
