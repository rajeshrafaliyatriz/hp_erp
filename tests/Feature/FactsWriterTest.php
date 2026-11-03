<?php

namespace Tests\Feature;

use App\Domain\Facts\FactEvents;
use App\Domain\Facts\FactNotFound;
use App\Domain\Facts\FactWriter;
use App\Services\Events\EventCatalogue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsFactsWorld;
use Tests\TestCase;

/**
 * The write path of the facts ledger (slice 4): proposing, the promises it makes (a dismissed value is never offered again, a human value is never
 * overwritten, a fact is applied exactly once, nothing is applied by a proposal) and the events. Ported where the original has the same rule from Comp AI's
 * facts.integration.spec.ts (MIT licence); the rest is G2G's.
 */
class FactsWriterTest extends TestCase
{
    use BuildsFactsWorld;

    private FactWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFactsWorld();
        $this->writer = app(FactWriter::class);
    }

    /** Proposes with evidence that earns the PROBABLE band ('site.own-page' 0.80), and returns the result. */
    private function propose(string $field, string $value, ?int $contact = null, ?array $evidence = null, string $type = 'contact', int $tenant = self::T): array
    {
        return $this->writer->propose($tenant, $type, $contact ?? $this->contactId, $field, $value, $evidence ?? $this->evidence('site.own-page'), 'test.method', 'agent:test', null, 7);
    }

    private int $contactId = 0;

    private function contact(array $over = []): int
    {
        return $this->contactId = $this->makeContact(self::T, $over);
    }

    private function fact(int $id): object
    {
        return DB::table('g2g_entity_facts')->find($id);
    }

    // ---- proposing ----

    public function test_a_proposal_is_stored_waiting_with_its_evidence_score_band_and_event_and_applies_nothing(): void
    {
        $this->contact();

        $r = $this->propose('email', 'Principal@School.Example');

        $this->assertTrue($r['stored']);
        $this->assertSame(FactWriter::STORED, $r['outcome']);
        $fact = $r['fact'];
        $this->assertSame(['proposed', 'principal@school.example', 'role', 'probable'], [$fact->status, $fact->value, $fact->value_kind, $fact->band]);
        $this->assertEquals(0.8, $fact->score);
        $this->assertSame(['contact', $this->contactId, 'email', 'test.method', 'agent:test'], [$fact->entity_type, (int) $fact->entity_id, $fact->field, $fact->method, $fact->proposed_by]);
        $this->assertSame(0, (int) $fact->column_written);
        $this->assertNull($fact->decided_by);

        $evidence = DB::table('g2g_entity_fact_evidence')->get();
        $this->assertCount(1, $evidence);
        $this->assertSame([(int) $fact->id, 'site.own-page', 'saw site.own-page', 'https://source.example/0'], [(int) $evidence[0]->fact_id, $evidence[0]->kind, $evidence[0]->quote, $evidence[0]->source_url]);
        $this->assertEquals(0.8, $evidence[0]->weight);
        $this->assertNotNull($evidence[0]->retrieved_at);

        $this->assertNull(DB::table('crm_contacts')->where('id', $this->contactId)->value('email'), 'a proposal applies NOTHING: a person accepts it (decision D3)');
        $event = DB::table('g2g_event')->where('type', 'fact.proposed')->first();
        $this->assertSame("fact.proposed:1:{$fact->id}", $event->idempotency_key);
        $this->assertSame('g2g_entity_facts', $event->entity_type);
        $this->assertSame(['email', 'probable', 'agent:test'], [json_decode($event->payload, true)['field'], json_decode($event->payload, true)['band'], json_decode($event->payload, true)['proposed_by']]);
    }

    public function test_an_address_of_a_person_is_marked_personal_and_other_fields_have_no_kind(): void
    {
        $this->contact();

        $this->assertSame('personal', $this->propose('email', 'priya.sharma@school.example')['fact']->value_kind);
        $this->assertNull($this->propose('phone', '+91 98765 43210')['fact']->value_kind);
    }

    public function test_evidence_too_weak_to_keep_is_not_stored_and_says_why(): void
    {
        $this->contact();

        $r = $this->propose('title', 'Principal', null, $this->evidence('employer-only'));

        $this->assertFalse($r['stored']);
        $this->assertSame(FactWriter::REFUSED_BELOW_FLOOR, $r['outcome']);
        $this->assertStringContainsString('Below the floor', (string) $r['reason']);
        $this->assertNull($r['band']);
        $this->assertSame(0, DB::table('g2g_entity_facts')->count());
        $this->assertSame(0, DB::table('g2g_entity_fact_evidence')->count());
        $this->assertSame(0, DB::table('g2g_event')->count(), 'a refusal writes no event');
        $this->assertSame(FactWriter::REFUSED_BELOW_FLOOR, $this->propose('title', 'Principal', null, [])['outcome'], 'no evidence at all is below the floor too');
    }

    public function test_a_bad_evidence_kind_or_address_is_refused_before_anything_is_stored(): void
    {
        $this->contact();

        $unknown = $this->propose('email', 'a@school.example', null, [['kind' => 'psychic.hunch']]);
        $badUrl = $this->propose('email', 'a@school.example', null, [['kind' => 'site.own-page', 'source_url' => 'javascript:alert(1)']]);
        $badSource = $this->writer->propose(self::T, 'contact', $this->contactId, 'email', 'a@school.example', $this->evidence('site.own-page'), 'm', 'agent:test', 'file:///etc/passwd');

        foreach ([$unknown, $badUrl, $badSource] as $r) {
            $this->assertSame(FactWriter::REFUSED_EVIDENCE, $r['outcome']);
            $this->assertFalse($r['stored']);
        }
        $this->assertStringContainsString("Unknown evidence kind 'psychic.hunch'", (string) $unknown['reason']);
        $this->assertSame(0, DB::table('g2g_entity_facts')->count());
    }

    public function test_the_date_a_source_was_seen_is_kept_in_the_database_form_and_a_non_date_is_refused(): void
    {
        $this->contact();

        $ok = $this->propose('email', 'a@school.example', null, [['kind' => 'site.own-page', 'source_url' => 'https://source.example/p', 'retrieved_at' => '2026-10-31T08:30:00+00:00']]);
        $bad = $this->propose('email', 'b@school.example', null, [['kind' => 'site.own-page', 'retrieved_at' => 'last Tuesday-ish, maybe']]);

        $this->assertTrue($ok['stored']);
        $this->assertSame('2026-10-31 08:30:00', DB::table('g2g_entity_fact_evidence')->where('fact_id', $ok['fact']->id)->value('retrieved_at'));
        $this->assertSame(FactWriter::REFUSED_EVIDENCE, $bad['outcome']);
        $this->assertStringContainsString('retrieved_at', (string) $bad['reason']);
    }

    public function test_a_value_that_is_not_one_of_its_field_is_refused(): void
    {
        $this->contact();

        foreach ([['email', 'not an email'], ['phone', 'call me'], ['linkedin_url', 'https://x.com/someone'], ['title', '=HYPERLINK("http://evil")'], ['title', str_repeat('x', 101)]] as [$field, $value]) {
            $r = $this->propose($field, $value);
            $this->assertSame(FactWriter::REFUSED_VALUE, $r['outcome'], "{$field}: {$value}");
        }
        $this->assertSame(0, DB::table('g2g_entity_facts')->count());
    }

    public function test_a_field_that_does_not_apply_to_the_kind_of_record_is_refused(): void
    {
        $org = $this->makeOrganization();

        $this->assertSame(FactWriter::REFUSED_FIELD, $this->propose('title', 'Principal', $org, null, 'organization')['outcome'], 'an organisation has no job title');
        $this->assertSame(FactWriter::REFUSED_FIELD, $this->propose('shoe_size', '9', $org, null, 'organization')['outcome']);
        $this->assertSame(FactWriter::REFUSED_FIELD, $this->propose('email', 'a@b.example', $org, null, 'opportunity')['outcome']);
        $this->assertSame(0, DB::table('g2g_entity_facts')->count());
    }

    public function test_a_record_of_another_organisation_or_a_removed_one_or_none_is_refused_alike(): void
    {
        $other = $this->makeContact(self::T2);
        $gone = $this->makeContact(self::T, ['deleted_at' => now()]);

        foreach ([$other, $gone, 999999] as $id) {
            $r = $this->propose('email', 'a@school.example', $id);
            $this->assertSame(FactWriter::REFUSED_ENTITY, $r['outcome'], "id {$id}");
            $this->assertSame('No such contact.', $r['reason']);
        }
        $this->assertSame(0, DB::table('g2g_entity_facts')->count());
    }

    public function test_the_same_value_is_not_offered_twice_however_it_is_written(): void
    {
        $this->contact();
        $first = $this->propose('email', 'info@school.example');

        foreach (['INFO@school.example', 'mailto:info@school.example', ' info@School.Example '] as $variant) {
            $again = $this->propose('email', $variant, null, $this->evidence('site.own-page', 'web.cited-claim'));
            $this->assertSame(FactWriter::REFUSED_WAITING, $again['outcome'], $variant);
        }
        $this->assertSame(1, DB::table('g2g_entity_facts')->count());
        $this->assertSame(1, DB::table('g2g_event')->where('type', 'fact.proposed')->count());
        $this->assertTrue($first['stored']);
    }

    public function test_a_dismissed_value_is_never_offered_again_whatever_the_evidence_or_the_spelling(): void
    {
        $this->contact();
        $id = (int) $this->propose('title', 'Head of Trust')['fact']->id;
        $this->writer->dismiss(self::T, $id, 9, 'wrong person');

        foreach (['Head of Trust', 'head  of TRUST', ' Head of Trust '] as $variant) {
            $again = $this->propose('title', $variant, null, $this->evidence('profile.email-match', 'crm.thread-reply'));
            $this->assertSame(FactWriter::REFUSED_DISMISSED, $again['outcome'], $variant);
            $this->assertStringContainsString('dismissed', (string) $again['reason']);
        }
        $this->assertSame(1, DB::table('g2g_entity_facts')->count(), 'still one row: the dismissed value keeps its place in the unique key');
        $this->assertSame('dismissed', $this->fact($id)->status);
        $other = $this->propose('title', 'Head of Security');
        $this->assertTrue($other['stored'], 'a different value is a different fact');
    }

    public function test_the_same_dismissed_value_is_fine_for_another_record_or_field_or_organisation(): void
    {
        $a = $this->contact();
        $id = (int) $this->propose('email', 'info@school.example')['fact']->id;
        $this->writer->dismiss(self::T, $id, 9);
        $b = $this->makeContact();

        $this->assertTrue($this->propose('email', 'info@school.example', $b)['stored'], 'another contact');
        $this->assertTrue($this->propose('secondary_email', 'info@school.example', $a)['stored'], 'another field');
        $other = $this->makeContact(self::T2);
        $this->assertTrue($this->propose('email', 'info@school.example', $other, null, 'contact', self::T2)['stored'], 'another organisation');
    }

    public function test_an_accepted_value_and_a_replaced_one_are_not_offered_again(): void
    {
        $this->contact();
        $old = (int) $this->propose('title', 'Teacher')['fact']->id;
        $new = (int) $this->propose('title', 'Principal')['fact']->id;
        $this->writer->accept(self::T, $old, 9);
        $this->writer->accept(self::T, $new, 9);

        $this->assertSame(FactWriter::REFUSED_ON_RECORD, $this->propose('title', 'Principal')['outcome'], 'accepted');
        $this->assertSame(FactWriter::REFUSED_REPLACED, $this->propose('title', 'Teacher')['outcome'], 'replaced by a newer accepted value');
        $this->assertSame(2, DB::table('g2g_entity_facts')->count());
    }

    public function test_a_value_the_record_already_holds_is_not_proposed_however_it_is_written(): void
    {
        $this->contact(['phone' => '+91-98765-43210', 'email' => 'Info@School.Example', 'title' => 'principal']);

        $this->assertSame(FactWriter::REFUSED_ON_RECORD, $this->propose('phone', '+91 98765 43210')['outcome']);
        $this->assertSame(FactWriter::REFUSED_ON_RECORD, $this->propose('email', 'info@school.example')['outcome']);
        $this->assertSame(FactWriter::REFUSED_ON_RECORD, $this->propose('title', 'Principal')['outcome']);
        $this->assertSame(0, DB::table('g2g_entity_facts')->count());
    }

    public function test_a_different_value_than_the_record_holds_is_still_offered_to_a_person(): void
    {
        $this->contact(['email' => 'old@school.example']);

        $this->assertTrue($this->propose('email', 'new@school.example')['stored'], 'decision D8: the person sees the record next to the suggestion and decides');
        $this->assertSame('old@school.example', DB::table('crm_contacts')->where('id', $this->contactId)->value('email'));
    }

    public function test_one_page_cited_three_ways_is_one_witness_in_the_stored_score_and_the_evidence_rows(): void
    {
        $this->contact();
        $page = ['kind' => 'web.cited-claim', 'source_url' => 'https://school.example/contact'];

        $r = $this->propose('email', 'info@school.example', null, [$page, ['source_url' => 'https://www.school.example/contact/'] + $page, ['source_url' => 'http://school.example/contact?x=1'] + $page]);

        $this->assertEquals(0.4, $r['fact']->score);
        $this->assertSame(1, DB::table('g2g_entity_fact_evidence')->count());
    }

    public function test_a_second_request_that_stored_the_same_value_first_is_answered_like_any_repeat(): void
    {
        $this->contact();
        // The other request commits its row between this request's check and its insert. The hook stages that: on this request's insert it plants the row
        // (inside this transaction, which the failed insert then rolls back) and, on the read that follows the rollback, plants it again, committed, as the
        // other connection's row would be.
        $row = ['sub_institute_id' => self::T, 'entity_type' => 'contact', 'entity_id' => $this->contactId, 'field' => 'email', 'value' => 'info@school.example', 'value_hash' => sha1('info@school.example'),
            'status' => 'dismissed', 'band' => 'probable', 'score' => 0.8, 'method' => 'other', 'proposed_by' => 'user:1', 'created_at' => now(), 'updated_at' => now()];
        $inserts = 0;
        $reads = 0;
        DB::beforeExecuting(function (string $query) use ($row, &$inserts, &$reads) {
            if (str_contains($query, 'insert into "g2g_entity_facts"') && $inserts++ === 0) {
                DB::table('g2g_entity_facts')->insert($row);
            }
            if (str_contains($query, 'select * from "g2g_entity_facts"') && str_contains($query, '"value_hash"') && ++$reads === 2) {
                DB::table('g2g_entity_facts')->insert($row);
            }
        });

        $r = $this->propose('email', 'info@school.example');

        $this->assertFalse($r['stored']);
        $this->assertSame(FactWriter::REFUSED_DISMISSED, $r['outcome'], 'the unique key refused the insert and the answer is what the key says');
        $this->assertSame(1, DB::table('g2g_entity_facts')->count());
        $this->assertSame(0, DB::table('g2g_entity_fact_evidence')->count(), 'the failed attempt left no evidence behind');
        $this->assertSame(0, DB::table('g2g_event')->where('type', 'fact.proposed')->count(), 'nor an event');
    }

    // ---- accepting ----

    public function test_accepting_writes_a_blank_column_once_marks_the_fact_applied_and_records_the_event(): void
    {
        $this->contact();
        $id = (int) $this->propose('email', 'info@school.example')['fact']->id;

        $r = $this->writer->accept(self::T, $id, 9);

        $this->assertSame(FactWriter::APPLIED, $r['outcome']);
        $this->assertTrue($r['column_written']);
        $fact = $this->fact($id);
        $this->assertSame(['applied', 9, 1], [$fact->status, (int) $fact->decided_by, (int) $fact->column_written]);
        $this->assertNotNull($fact->decided_at);
        $contact = DB::table('crm_contacts')->where('id', $this->contactId)->first();
        $this->assertSame('info@school.example', $contact->email);
        $this->assertSame(9, (int) $contact->updated_by, 'the person who accepted it is the one who changed the record');
        $event = DB::table('g2g_event')->where('type', 'fact.decided')->first();
        $this->assertSame("fact.decided:1:{$id}", $event->idempotency_key);
        $this->assertSame(9, (int) $event->actor_id);
        $payload = json_decode($event->payload, true);
        $this->assertSame(['accepted', 'email', true, []], [$payload['decision'], $payload['field'], $payload['column_written'], $payload['superseded']]);
    }

    public function test_a_value_a_person_typed_is_never_overwritten_by_an_accepted_fact(): void
    {
        $this->contact(['email' => 'typed.by.a.person@school.example']);
        $id = (int) $this->propose('email', 'found.on.the.web@school.example')['fact']->id;

        $r = $this->writer->accept(self::T, $id, 9);

        $this->assertSame(FactWriter::APPLIED, $r['outcome']);
        $this->assertFalse($r['column_written']);
        $this->assertSame('typed.by.a.person@school.example', DB::table('crm_contacts')->where('id', $this->contactId)->value('email'), 'the person wins');
        $this->assertSame(['applied', 0], [$this->fact($id)->status, (int) $this->fact($id)->column_written], 'the fact is kept in the ledger, and says it did not touch the record');
    }

    public function test_a_value_typed_after_the_record_was_read_is_not_overwritten_either(): void
    {
        $this->contact();
        $id = (int) $this->propose('email', 'found.on.the.web@school.example')['fact']->id;
        // A person types an address between this request's read of the record and its write: staged on the claim, which sits between the two.
        $typed = false;
        DB::beforeExecuting(function (string $query) use (&$typed) {
            if (! $typed && str_contains($query, 'update "g2g_entity_facts"')) {
                $typed = true;
                DB::table('crm_contacts')->where('id', $this->contactId)->update(['email' => 'typed.meanwhile@school.example']);
            }
        });

        $r = $this->writer->accept(self::T, $id, 9);

        $this->assertTrue($typed);
        $this->assertSame(FactWriter::APPLIED, $r['outcome']);
        $this->assertFalse($r['column_written'], 'the write is conditional on the column still being blank');
        $this->assertSame('typed.meanwhile@school.example', DB::table('crm_contacts')->where('id', $this->contactId)->value('email'));
    }

    public function test_a_field_without_a_column_stays_in_the_ledger_only(): void
    {
        $this->contact();
        $id = (int) $this->propose('employer', 'Fernhill Group')['fact']->id;

        $r = $this->writer->accept(self::T, $id, 9);

        $this->assertSame([FactWriter::APPLIED, false], [$r['outcome'], $r['column_written']]);
    }

    public function test_a_column_this_host_does_not_have_yet_stays_in_the_ledger_only(): void
    {
        $this->contact();
        Schema::table('crm_contacts', fn ($t) => $t->dropColumn('linkedin_url'));
        $id = (int) $this->propose('linkedin_url', 'https://www.linkedin.com/in/priya-sharma')['fact']->id;

        $r = $this->writer->accept(self::T, $id, 9);

        $this->assertSame([FactWriter::APPLIED, false], [$r['outcome'], $r['column_written']], 'no error where the migration has not run (live, until the owner\'s go)');
    }

    public function test_an_accepted_linkedin_address_and_a_lead_title_go_to_their_columns(): void
    {
        $this->contact();
        $lead = $this->makeLead();
        $a = (int) $this->propose('linkedin_url', 'linkedin.com/in/priya-sharma/')['fact']->id;
        $b = (int) $this->propose('title', 'Principal', $lead, null, 'lead')['fact']->id;

        $this->writer->accept(self::T, $a, 9);
        $this->writer->accept(self::T, $b, 9);

        $this->assertSame('https://www.linkedin.com/in/priya-sharma', DB::table('crm_contacts')->where('id', $this->contactId)->value('linkedin_url'));
        $this->assertSame('Principal', DB::table('crm_leads')->where('id', $lead)->value('designation'), 'a lead keeps a job title in `designation`');
    }

    public function test_a_fact_is_applied_exactly_once_when_two_requests_race(): void
    {
        $this->contact();
        $id = (int) $this->propose('email', 'info@school.example')['fact']->id;
        // The other request decides the fact after this one has read it and before it claims it: staged on the read of the record, which sits between the two
        // and runs before this request's transaction opens (so the other request's write is committed on its own, as from another connection).
        $fired = false;
        DB::beforeExecuting(function (string $query) use (&$fired, $id) {
            if (! $fired && str_contains($query, 'from "crm_contacts"')) {
                $fired = true;
                DB::table('g2g_entity_facts')->where('id', $id)->update(['status' => 'applied', 'decided_by' => 99, 'column_written' => 1]);
            }
        });

        $r = $this->writer->accept(self::T, $id, 9);

        $this->assertTrue($fired);
        $this->assertSame(FactWriter::NOT_PENDING, $r['outcome'], 'the request that lost the claim reports what the winner did');
        $this->assertNull(DB::table('crm_contacts')->where('id', $this->contactId)->value('email'), 'and writes nothing');
        $this->assertSame(0, DB::table('g2g_event')->where('type', 'fact.decided')->count(), 'nor reports a decision it did not make');
        $this->assertSame(99, (int) $this->fact($id)->decided_by, "the winner's decision stands");
    }

    public function test_accepting_twice_changes_nothing_the_second_time(): void
    {
        $this->contact();
        $id = (int) $this->propose('email', 'info@school.example')['fact']->id;
        $this->writer->accept(self::T, $id, 9);
        DB::table('crm_contacts')->where('id', $this->contactId)->update(['email' => null]);

        $again = $this->writer->accept(self::T, $id, 10);

        $this->assertSame(FactWriter::NOT_PENDING, $again['outcome']);
        $this->assertNull(DB::table('crm_contacts')->where('id', $this->contactId)->value('email'), 'a second accept does not write the column again');
        $this->assertSame(9, (int) $this->fact($id)->decided_by);
        $this->assertSame(1, DB::table('g2g_event')->where('type', 'fact.decided')->count());
    }

    public function test_a_fact_whose_record_was_removed_is_not_applied_and_stays_waiting(): void
    {
        $this->contact();
        $id = (int) $this->propose('email', 'info@school.example')['fact']->id;
        DB::table('crm_contacts')->where('id', $this->contactId)->update(['deleted_at' => now()]);

        $r = $this->writer->accept(self::T, $id, 9);

        $this->assertSame(FactWriter::ENTITY_GONE, $r['outcome']);
        $this->assertSame('proposed', $this->fact($id)->status);
        $this->assertSame(0, DB::table('g2g_event')->where('type', 'fact.decided')->count());
    }

    public function test_a_one_value_field_supersedes_the_previous_accepted_fact_and_keeps_the_chain(): void
    {
        $this->contact();
        $teacher = (int) $this->propose('title', 'Teacher')['fact']->id;
        $principal = (int) $this->propose('title', 'Principal')['fact']->id;

        $this->writer->accept(self::T, $teacher, 9);
        $r = $this->writer->accept(self::T, $principal, 9);

        $this->assertSame('superseded', $this->fact($teacher)->status);
        $this->assertSame(['applied', $teacher], [$this->fact($principal)->status, (int) $this->fact($principal)->supersedes_id]);
        $this->assertSame('Teacher', DB::table('crm_contacts')->where('id', $this->contactId)->value('title'), 'decision D7: the record is written only when blank, so it keeps the first accepted title');
        $this->assertFalse($r['column_written']);
        $payload = json_decode((string) DB::table('g2g_event')->where('type', 'fact.decided')->orderByDesc('id')->value('payload'), true);
        $this->assertSame([$teacher], $payload['superseded'], 'the replaced fact is named in the decision event');
    }

    public function test_a_field_that_can_hold_several_values_supersedes_nothing(): void
    {
        $this->contact();
        $a = (int) $this->propose('email', 'info@school.example')['fact']->id;
        $b = (int) $this->propose('email', 'principal@school.example')['fact']->id;

        $this->writer->accept(self::T, $a, 9);
        $this->writer->accept(self::T, $b, 9);

        $this->assertSame(['applied', 'applied'], [$this->fact($a)->status, $this->fact($b)->status]);
        $this->assertNull($this->fact($b)->supersedes_id);
        $this->assertSame('info@school.example', DB::table('crm_contacts')->where('id', $this->contactId)->value('email'));
    }

    // ---- dismissing ----

    public function test_dismissing_records_who_when_and_why_and_changes_nothing_else(): void
    {
        $this->contact();
        $id = (int) $this->propose('email', 'info@school.example')['fact']->id;

        $r = $this->writer->dismiss(self::T, $id, 9, "  Wrong\x00 school \n  entirely  ");

        $this->assertSame(FactWriter::DISMISSED, $r['outcome']);
        $fact = $this->fact($id);
        $this->assertSame(['dismissed', 9, 'Wrong school entirely'], [$fact->status, (int) $fact->decided_by, $fact->decision_note]);
        $this->assertNotNull($fact->decided_at);
        $this->assertNull(DB::table('crm_contacts')->where('id', $this->contactId)->value('email'));
        $payload = json_decode((string) DB::table('g2g_event')->where('type', 'fact.decided')->value('payload'), true);
        $this->assertSame(['dismissed', 'Wrong school entirely'], [$payload['decision'], $payload['reason']]);
    }

    public function test_a_dismiss_reason_is_cut_at_500_characters_and_an_empty_one_is_nothing(): void
    {
        $this->contact();
        $a = (int) $this->propose('email', 'a@school.example')['fact']->id;
        $b = (int) $this->propose('email', 'b@school.example')['fact']->id;

        $this->writer->dismiss(self::T, $a, 9, str_repeat('é', 600));
        $this->writer->dismiss(self::T, $b, 9, "  \n ");

        $this->assertSame(500, mb_strlen((string) $this->fact($a)->decision_note));
        $this->assertNull($this->fact($b)->decision_note);
    }

    public function test_a_fact_that_was_already_decided_cannot_be_decided_again_either_way(): void
    {
        $this->contact();
        $dismissed = (int) $this->propose('email', 'a@school.example')['fact']->id;
        $applied = (int) $this->propose('email', 'b@school.example')['fact']->id;
        $this->writer->dismiss(self::T, $dismissed, 9);
        $this->writer->accept(self::T, $applied, 9);

        foreach ([[$dismissed, 'accept'], [$dismissed, 'dismiss'], [$applied, 'accept'], [$applied, 'dismiss']] as [$id, $verb]) {
            $before = (array) $this->fact($id);
            $r = $verb === 'accept' ? $this->writer->accept(self::T, $id, 10) : $this->writer->dismiss(self::T, $id, 10, 'again');
            $this->assertSame(FactWriter::NOT_PENDING, $r['outcome'], "{$verb} fact {$id}");
            $this->assertSame($before, (array) $this->fact($id), 'nothing about it changed');
        }
        $this->assertSame(2, DB::table('g2g_event')->where('type', 'fact.decided')->count());
    }

    public function test_dismissing_loses_a_race_quietly_too(): void
    {
        $this->contact();
        $id = (int) $this->propose('email', 'a@school.example')['fact']->id;
        // The other request decides it right AFTER this one has read it as waiting and before it claims it: staged from a listener on the read (it runs once the
        // read has executed, when no transaction is open, so the other request's write is committed on its own, as from another connection).
        $fired = false;
        DB::listen(function ($query) use (&$fired, $id) {
            if (! $fired && str_contains($query->sql, 'select * from "g2g_entity_facts"')) {
                $fired = true;
                DB::table('g2g_entity_facts')->where('id', $id)->update(['status' => 'applied', 'decided_by' => 99]);
            }
        });

        $r = $this->writer->dismiss(self::T, $id, 9, 'late');

        $this->assertTrue($fired);
        $this->assertSame(FactWriter::NOT_PENDING, $r['outcome'], 'the request that lost the claim reports what the winner did');
        $this->assertSame(['applied', 99, null], [$this->fact($id)->status, (int) $this->fact($id)->decided_by, $this->fact($id)->decision_note], "the winner's decision stands and the loser left no note");
        $this->assertSame(0, DB::table('g2g_event')->where('type', 'fact.decided')->count());
    }

    public function test_another_organisations_fact_is_not_found_for_accept_and_dismiss(): void
    {
        $other = $this->makeContact(self::T2);
        $id = (int) $this->propose('email', 'a@school.example', $other, null, 'contact', self::T2)['fact']->id;

        foreach (['accept', 'dismiss'] as $verb) {
            try {
                $verb === 'accept' ? $this->writer->accept(self::T, $id, 9) : $this->writer->dismiss(self::T, $id, 9);
                $this->fail("{$verb} found another organisation's fact");
            } catch (FactNotFound) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('proposed', $this->fact($id)->status);
        $this->expectException(FactNotFound::class);
        $this->writer->accept(self::T, 999999, 9);
    }

    // ---- events ----

    public function test_both_events_are_registered_and_the_catalogue_is_still_consistent(): void
    {
        $this->assertSame(['AuditLogProjector' => EventCatalogue::PROJECTOR], EventCatalogue::SHIPPED['fact.proposed']);
        $this->assertSame(['AuditLogProjector' => EventCatalogue::PROJECTOR], EventCatalogue::SHIPPED['fact.decided']);
        $this->assertSame([], EventCatalogue::assertInvariants());
    }

    public function test_an_event_repeated_by_a_retry_returns_the_stored_one_and_a_different_fact_gets_its_own(): void
    {
        $events = app(FactEvents::class);

        $first = $events->decided(1, 5, 9, ['decision' => 'accepted']);
        $retry = $events->decided(1, 5, 9, ['decision' => 'accepted']);
        $other = $events->decided(1, 6, 9, ['decision' => 'accepted']);
        $proposed = $events->proposed(1, 5, 9, ['field' => 'email']);

        $this->assertSame($first, $retry, 'the same key is the same event');
        $this->assertNotSame($first, $other);
        $this->assertNotSame($first, $proposed, 'proposed and decided are different events of one fact');
        $this->assertSame(3, DB::table('g2g_event')->count());
        $this->assertSame(1, DB::table('g2g_event')->where('idempotency_key', 'fact.decided:1:5')->count());
    }
}
