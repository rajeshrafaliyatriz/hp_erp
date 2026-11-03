<?php

namespace Tests\Feature;

use App\Domain\Facts\FactWriter;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsFactsWorld;
use Tests\TestCase;

/**
 * The facts and consent API (slice 4): the shapes the front end (track S4b) builds against, the decisions and their answers, the queue and its paging, and
 * what a caller is NOT shown. The cross-organisation sweep and the rights matrix are in FactsTenantSweepTest and FactsRightsTest.
 */
class FactsApiTest extends TestCase
{
    use BuildsFactsWorld;

    /** @var array{id: int, h: array<string, string>} */
    private array $me;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFactsWorld();
        $this->me = $this->editor(self::T, 'Asha', 'Rao');
    }

    /** Proposes (as the agent would) and returns the fact id. */
    private function propose(string $type, int $id, string $field, string $value, ?array $evidence = null, int $tenant = self::T): int
    {
        $r = app(FactWriter::class)->propose($tenant, $type, $id, $field, $value, $evidence ?? $this->evidence('site.own-page'), 'test.method', 'agent:organisation', null, 7);
        $this->assertTrue($r['stored'], (string) $r['reason']);

        return (int) $r['fact']->id;
    }

    // ---- the facts of one record ----

    public function test_a_contacts_facts_come_with_the_shape_the_screen_is_built_against(): void
    {
        $contact = $this->makeContact(self::T, ['first_name' => 'Priya', 'last_name' => 'Sharma', 'email' => 'old@school.example']);
        $waiting = $this->propose('contact', $contact, 'email', 'Principal@School.Example', $this->evidence('site.own-page', 'directory.listing'));

        $res = $this->getJson("/api/facts/entity/contact/{$contact}", $this->me['h'])->assertOk();

        $res->assertJsonPath('status', 1)->assertJsonPath('data.entity', ['type' => 'contact', 'id' => $contact, 'name' => 'Priya Sharma'])
            ->assertJsonPath('data.counts', ['proposed' => 1, 'applied' => 0, 'dismissed' => 0]);
        $fact = $res->json('data.facts.0');
        $this->assertSame(['id', 'entity_type', 'entity_id', 'field', 'field_label', 'value', 'value_kind', 'status', 'band', 'score', 'method', 'source_url', 'proposed_by', 'decided_by_name',
            'decided_at', 'decision_note', 'column_written', 'record_value', 'supersedes_id', 'created_at', 'rationale', 'evidence'], array_keys($fact));
        $this->assertSame(['id' => $waiting, 'entity_type' => 'contact', 'entity_id' => $contact, 'field' => 'email', 'field_label' => 'Email address', 'value' => 'principal@school.example',
            'value_kind' => 'role', 'status' => 'proposed', 'band' => 'verified', 'method' => 'test.method', 'proposed_by' => 'agent:organisation', 'decided_by_name' => null, 'decided_at' => null,
            'decision_note' => null, 'column_written' => false, 'record_value' => 'old@school.example', 'supersedes_id' => null],
            array_intersect_key($fact, array_flip(['id', 'entity_type', 'entity_id', 'field', 'field_label', 'value', 'value_kind', 'status', 'band', 'method', 'proposed_by', 'decided_by_name', 'decided_at', 'decision_note', 'column_written', 'record_value', 'supersedes_id'])));
        $this->assertEqualsWithDelta(0.91, $fact['score'], 0.0001, '1 - 0.2 * 0.45');
        $this->assertIsFloat($fact['score']);
        $this->assertSame(['kind', 'label', 'weight', 'source_url', 'quote', 'retrieved_at'], array_keys($fact['evidence'][0]));
        $this->assertSame(['site.own-page', "the organisation's own website states it", 0.8, 'https://source.example/0', 'saw site.own-page'], array_values(array_diff_key($fact['evidence'][0], ['retrieved_at' => 1])));
        $this->assertSame("The organisation's own website states it and an official directory or tender notice lists it", $fact['rationale']);
        $this->assertNotNull($fact['created_at']);
        $this->assertNotNull($fact['evidence'][0]['retrieved_at']);
    }

    public function test_an_organisation_and_a_leads_facts_are_read_the_same_way(): void
    {
        $org = $this->makeOrganization(self::T, ['name' => 'Fernhill School']);
        $lead = $this->makeLead(self::T, ['first_name' => 'Rahul', 'last_name' => 'Verma']);
        $this->propose('organization', $org, 'website', 'fernhill.example');
        $this->propose('lead', $lead, 'title', 'Principal');

        $o = $this->getJson("/api/facts/entity/organization/{$org}", $this->me['h'])->assertOk();
        $l = $this->getJson("/api/facts/entity/lead/{$lead}", $this->me['h'])->assertOk();

        $this->assertSame(['organization', 'Fernhill School', 'https://fernhill.example'], [$o->json('data.entity.type'), $o->json('data.entity.name'), $o->json('data.facts.0.value')]);
        $this->assertSame(['lead', 'Rahul Verma', 'Principal'], [$l->json('data.entity.type'), $l->json('data.entity.name'), $l->json('data.facts.0.value')]);
        $this->assertNull($l->json('data.facts.0.record_value'), 'a blank column is null, not an empty string');
    }

    public function test_the_facts_of_a_record_are_ordered_waiting_first_and_all_statuses_are_listed_with_counts(): void
    {
        $c = $this->makeContact();
        $dismissed = $this->propose('contact', $c, 'email', 'a@school.example');
        $applied = $this->propose('contact', $c, 'email', 'b@school.example');
        $waiting = $this->propose('contact', $c, 'email', 'c@school.example');
        $old = $this->propose('contact', $c, 'title', 'Teacher');
        $new = $this->propose('contact', $c, 'title', 'Principal');
        $writer = app(FactWriter::class);
        $writer->dismiss(self::T, $dismissed, $this->me['id'], 'wrong');
        $writer->accept(self::T, $applied, $this->me['id']);
        $writer->accept(self::T, $old, $this->me['id']);
        $writer->accept(self::T, $new, $this->me['id']);

        $res = $this->getJson("/api/facts/entity/contact/{$c}", $this->me['h'])->assertOk();

        $this->assertSame([$waiting, $new, $applied, $dismissed, $old], array_column($res->json('data.facts'), 'id'), 'waiting, then applied, then dismissed, then replaced; newest first inside each');
        $this->assertSame(['proposed' => 1, 'applied' => 2, 'dismissed' => 1], $res->json('data.counts'), 'a replaced fact is not counted');
        $byId = collect($res->json('data.facts'))->keyBy('id');
        $this->assertSame(['Asha Rao', 'wrong'], [$byId[$dismissed]['decided_by_name'], $byId[$dismissed]['decision_note']]);
        $this->assertSame([true, 'b@school.example'], [$byId[$applied]['column_written'], $byId[$applied]['record_value']], 'the record shows what accepting wrote');
        $this->assertSame([$old, 'superseded'], [$byId[$new]['supersedes_id'], $byId[$old]['status']]);
    }

    public function test_a_value_from_the_web_is_returned_as_plain_text_and_never_interpreted(): void
    {
        $c = $this->makeContact();
        $this->propose('contact', $c, 'title', 'Head <img src=x onerror=alert(1)> of "Sales" & Co', [['kind' => 'site.own-page', 'source_url' => 'https://school.example/x?a=1&b=2', 'quote' => '<script>alert(1)</script>']]);

        $res = $this->getJson("/api/facts/entity/contact/{$c}", $this->me['h'])->assertOk();

        $this->assertStringContainsString('application/json', (string) $res->headers->get('Content-Type'));
        $this->assertSame('Head <img src=x onerror=alert(1)> of "Sales" & Co', $res->json('data.facts.0.value'), 'stored and returned as the text it is');
        $this->assertSame('<script>alert(1)</script>', $res->json('data.facts.0.evidence.0.quote'));
    }

    public function test_the_record_value_is_what_the_column_holds_now(): void
    {
        $c = $this->makeContact(self::T, ['phone' => '99999 11111']);
        $this->propose('contact', $c, 'phone', '+91 98765 43210');
        $this->propose('contact', $c, 'employer', 'Fernhill');

        $facts = collect($this->getJson("/api/facts/entity/contact/{$c}", $this->me['h'])->json('data.facts'))->keyBy('field');

        $this->assertSame('99999 11111', $facts['phone']['record_value']);
        $this->assertNull($facts['employer']['record_value'], 'a field with no column has no record value');
    }

    public function test_a_caller_with_no_token_or_a_bad_one_is_refused(): void
    {
        $c = $this->makeContact();

        $this->getJson("/api/facts/entity/contact/{$c}")->assertStatus(401);
        $this->getJson("/api/facts/entity/contact/{$c}", ['Authorization' => 'Bearer nonsense'])->assertStatus(401);
        $this->postJson('/api/facts/1/accept')->assertStatus(401);
    }

    // ---- the queue ----

    public function test_the_queue_lists_waiting_suggestions_newest_first_with_the_name_of_each_record(): void
    {
        $c = $this->makeContact(self::T, ['first_name' => 'Priya', 'last_name' => 'Sharma']);
        $o = $this->makeOrganization(self::T, ['name' => 'Fernhill School']);
        $first = $this->propose('contact', $c, 'email', 'a@school.example');
        $second = $this->propose('organization', $o, 'website', 'fernhill.example');
        $third = $this->propose('contact', $c, 'phone', '+91 98765 43210');
        $done = $this->propose('contact', $c, 'email', 'z@school.example');
        app(FactWriter::class)->dismiss(self::T, $done, $this->me['id']);

        $res = $this->getJson('/api/facts/queue', $this->me['h'])->assertOk();

        $this->assertSame([$third, $second, $first], array_column($res->json('data.rows'), 'id'), 'newest first, waiting only');
        $this->assertSame(['Priya Sharma', 'Fernhill School', 'Priya Sharma'], array_column($res->json('data.rows'), 'entity_name'));
        $this->assertSame(['page' => 1, 'per_page' => 25, 'total' => 3], $res->json('data.pagination'));
        $this->assertArrayHasKey('evidence', $res->json('data.rows.0'));
    }

    public function test_the_queue_pages_and_caps_a_page_at_fifty(): void
    {
        $c = $this->makeContact();
        for ($i = 1; $i <= 7; $i++) {
            $this->propose('contact', $c, 'email', "p{$i}@school.example");
        }

        $p1 = $this->getJson('/api/facts/queue?per_page=3', $this->me['h'])->assertOk();
        $p3 = $this->getJson('/api/facts/queue?per_page=3&page=3', $this->me['h'])->assertOk();
        $big = $this->getJson('/api/facts/queue?per_page=500', $this->me['h'])->assertOk();

        $this->assertSame(['page' => 1, 'per_page' => 3, 'total' => 7], $p1->json('data.pagination'));
        $this->assertCount(3, $p1->json('data.rows'));
        $this->assertCount(1, $p3->json('data.rows'));
        $this->assertSame(50, $big->json('data.pagination.per_page'));
        $this->assertSame(['p7@school.example', 'p6@school.example', 'p5@school.example'], array_column($p1->json('data.rows'), 'value'));
        $this->assertSame(['p1@school.example'], array_column($p3->json('data.rows'), 'value'));
    }

    public function test_the_queue_can_be_narrowed_by_kind_and_by_status_and_rejects_nonsense(): void
    {
        $c = $this->makeContact();
        $o = $this->makeOrganization();
        $a = $this->propose('contact', $c, 'email', 'a@school.example');
        $b = $this->propose('organization', $o, 'website', 'fernhill.example');
        app(FactWriter::class)->accept(self::T, $a, $this->me['id']);

        $this->assertSame([$b], array_column($this->getJson('/api/facts/queue?type=organization', $this->me['h'])->json('data.rows'), 'id'));
        $this->assertSame([], array_column($this->getJson('/api/facts/queue?type=lead', $this->me['h'])->json('data.rows'), 'id'));
        $this->assertSame([$a], array_column($this->getJson('/api/facts/queue?status=applied', $this->me['h'])->json('data.rows'), 'id'));
        foreach (['?type=user', '?status=pending', '?page=0', '?per_page=0', '?page=abc'] as $bad) {
            $this->getJson('/api/facts/queue' . $bad, $this->me['h'])->assertStatus(422);
        }
    }

    public function test_the_queue_leaves_out_what_is_about_a_removed_record_or_belongs_to_another_organisation(): void
    {
        $mine = $this->makeContact();
        $gone = $this->makeContact();
        $theirs = $this->makeContact(self::T2);
        $keep = $this->propose('contact', $mine, 'email', 'a@school.example');
        $this->propose('contact', $gone, 'email', 'b@school.example');
        $this->propose('contact', $theirs, 'email', 'c@school.example', null, self::T2);
        DB::table('crm_contacts')->where('id', $gone)->update(['deleted_at' => now()]);

        $res = $this->getJson('/api/facts/queue', $this->me['h'])->assertOk();

        $this->assertSame([$keep], array_column($res->json('data.rows'), 'id'));
        $this->assertSame(1, $res->json('data.pagination.total'));
    }

    // ---- accept and dismiss ----

    public function test_accepting_answers_with_the_fact_as_it_now_stands_and_writes_the_blank_column(): void
    {
        $c = $this->makeContact();
        $id = $this->propose('contact', $c, 'email', 'info@school.example');

        $res = $this->postJson("/api/facts/{$id}/accept", [], $this->me['h'])->assertOk();

        $res->assertJsonPath('status', 1)->assertJsonPath('data.outcome', 'applied');
        $fact = $res->json('data.fact');
        $this->assertSame(['applied', true, 'Asha Rao', 'info@school.example'], [$fact['status'], $fact['column_written'], $fact['decided_by_name'], $fact['record_value']]);
        $this->assertNotNull($fact['decided_at']);
        $this->assertSame('info@school.example', DB::table('crm_contacts')->where('id', $c)->value('email'));
    }

    public function test_accepting_over_a_value_a_person_typed_keeps_it_and_says_so(): void
    {
        $c = $this->makeContact(self::T, ['email' => 'typed@school.example']);
        $id = $this->propose('contact', $c, 'email', 'web@school.example');

        $fact = $this->postJson("/api/facts/{$id}/accept", [], $this->me['h'])->assertOk()->json('data.fact');

        $this->assertSame(['applied', false, 'typed@school.example'], [$fact['status'], $fact['column_written'], $fact['record_value']]);
        $this->assertSame('typed@school.example', DB::table('crm_contacts')->where('id', $c)->value('email'));
    }

    public function test_a_fact_that_was_already_decided_answers_409_not_pending_with_the_fact(): void
    {
        $c = $this->makeContact();
        $id = $this->propose('contact', $c, 'email', 'info@school.example');
        $this->postJson("/api/facts/{$id}/accept", [], $this->me['h'])->assertOk();

        foreach (['accept', 'dismiss'] as $verb) {
            $res = $this->postJson("/api/facts/{$id}/{$verb}", ['reason' => 'x'], $this->me['h'])->assertStatus(409);
            $res->assertJsonPath('status', 0)->assertJsonPath('code', 'not_pending')->assertJsonPath('data.fact.id', $id)->assertJsonPath('data.fact.status', 'applied');
            $this->assertNotSame('', (string) $res->json('message'));
        }
    }

    public function test_dismissing_keeps_the_reason_and_the_value_is_never_offered_again(): void
    {
        $c = $this->makeContact();
        $id = $this->propose('contact', $c, 'email', 'info@school.example');

        $res = $this->postJson("/api/facts/{$id}/dismiss", ['reason' => 'This school closed in 2024'], $this->me['h'])->assertOk();

        $res->assertJsonPath('data.outcome', 'dismissed')->assertJsonPath('data.fact.status', 'dismissed')->assertJsonPath('data.fact.decision_note', 'This school closed in 2024')
            ->assertJsonPath('data.fact.decided_by_name', 'Asha Rao');
        $this->assertNull(DB::table('crm_contacts')->where('id', $c)->value('email'));
        $again = app(FactWriter::class)->propose(self::T, 'contact', $c, 'email', 'INFO@school.example', $this->evidence('profile.email-match'), 'm', 'agent:x');
        $this->assertSame(FactWriter::REFUSED_DISMISSED, $again['outcome'], 'the dismissed value is refused with a reason');
        $this->assertStringContainsString('dismissed', (string) $again['reason']);
    }

    public function test_a_dismiss_reason_over_500_characters_is_refused_and_nothing_is_decided(): void
    {
        $c = $this->makeContact();
        $id = $this->propose('contact', $c, 'email', 'info@school.example');

        $this->postJson("/api/facts/{$id}/dismiss", ['reason' => str_repeat('x', 501)], $this->me['h'])->assertStatus(422)->assertJsonPath('status', 0);
        $this->postJson("/api/facts/{$id}/dismiss", ['reason' => ['not', 'text']], $this->me['h'])->assertStatus(422);

        $this->assertSame('proposed', DB::table('g2g_entity_facts')->where('id', $id)->value('status'));
        $this->postJson("/api/facts/{$id}/dismiss", ['reason' => str_repeat('x', 500)], $this->me['h'])->assertOk();
        $this->assertSame(500, mb_strlen((string) DB::table('g2g_entity_facts')->where('id', $id)->value('decision_note')));
    }

    public function test_dismissing_without_a_reason_is_fine(): void
    {
        $c = $this->makeContact();
        $id = $this->propose('contact', $c, 'email', 'info@school.example');

        $this->postJson("/api/facts/{$id}/dismiss", [], $this->me['h'])->assertOk()->assertJsonPath('data.fact.decision_note', null);
    }

    public function test_accepting_a_fact_whose_record_was_removed_answers_409_and_changes_nothing(): void
    {
        $c = $this->makeContact();
        $id = $this->propose('contact', $c, 'email', 'info@school.example');
        DB::table('crm_contacts')->where('id', $c)->update(['deleted_at' => now()]);

        $res = $this->postJson("/api/facts/{$id}/accept", [], $this->me['h'])->assertStatus(409);

        $res->assertJsonPath('code', 'entity_gone')->assertJsonPath('data.fact.status', 'proposed');
        $this->assertSame('proposed', DB::table('g2g_entity_facts')->where('id', $id)->value('status'));
    }

    public function test_ids_that_are_not_numbers_do_not_reach_the_controller(): void
    {
        $this->postJson('/api/facts/abc/accept', [], $this->me['h'])->assertStatus(404);
        $this->postJson('/api/facts/1.5/dismiss', [], $this->me['h'])->assertStatus(404);
        $this->getJson('/api/facts/entity/contact/abc', $this->me['h'])->assertStatus(404);
        $this->getJson('/api/consent/contact/1;drop', $this->me['h'])->assertStatus(404);
        $this->getJson('/api/facts/entity/user/1', $this->me['h'])->assertStatus(404);
    }

    // ---- consent line ----

    public function test_the_consent_line_has_the_channels_and_the_signals_found(): void
    {
        $c = $this->makeContact(self::T, ['email' => 'priya@school.example', 'mobile' => '+91 98765 43210', 'email_opt_out' => 1]);
        DB::table('crm_consent_preferences')->insert(['entity_type' => 'contact', 'entity_id' => $c, 'channel' => 'sms', 'status' => 'opted_in', 'source' => 'form', 'captured_at' => '2026-10-01 10:00:00', 'sub_institute_id' => self::T]);

        $res = $this->getJson("/api/consent/contact/{$c}", $this->me['h'])->assertOk();

        $res->assertJsonPath('status', 1);
        $this->assertSame(['email', 'sms', 'whatsapp'], array_keys($res->json('data.channels')));
        $this->assertSame(['allowed' => false, 'reason' => 'Opted out of email (recorded on the record).', 'code' => 'opted_out'], $res->json('data.channels.email'));
        $this->assertSame(['allowed' => true, 'reason' => null, 'code' => null], $res->json('data.channels.sms'));
        $this->assertSame('no_consent_recorded', $res->json('data.channels.whatsapp.code'));
        $this->assertSame([['source' => 'email_opt_out', 'value' => '1', 'at' => null], ['source' => 'crm_consent_preferences', 'value' => 'sms: opted_in (form)', 'at' => \Carbon\Carbon::parse('2026-10-01 10:00:00')->toIso8601String()]],
            $res->json('data.sources'));
    }

    public function test_an_organisations_and_a_leads_consent_line_are_read_too(): void
    {
        $o = $this->makeOrganization(self::T, ['email' => 'office@school.example']);
        $l = $this->makeLead(self::T, ['email' => 'rahul@school.example']);

        $this->assertTrue($this->getJson("/api/consent/organization/{$o}", $this->me['h'])->assertOk()->json('data.channels.email.allowed'));
        $this->assertTrue($this->getJson("/api/consent/lead/{$l}", $this->me['h'])->assertOk()->json('data.channels.email.allowed'));
    }
}
