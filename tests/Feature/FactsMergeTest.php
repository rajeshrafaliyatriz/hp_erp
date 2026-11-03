<?php

namespace Tests\Feature;

use App\Domain\Facts\FactRepoint;
use App\Domain\Facts\FactWriter;
use App\Domain\OrgAccounts\OrganisationMerger;
use App\Domain\OrgAccounts\OrganisationReferences;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsFactsWorld;
use Tests\TestCase;

/**
 * Merging two records keeps the facts pointing at the survivor (slice 4): contacts through FactRepoint (called by the contact merge), organisations through
 * the one reviewed list the organisation merge walks (OrganisationReferences). A merge is also where an opt-out used to be lost (defect D7); the contact
 * merge now carries it.
 */
class FactsMergeTest extends TestCase
{
    use BuildsFactsWorld;

    private FactWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFactsWorld();
        $this->writer = app(FactWriter::class);
    }

    private function propose(string $type, int $id, string $field, string $value, int $tenant = self::T): int
    {
        $r = $this->writer->propose($tenant, $type, $id, $field, $value, $this->evidence('site.own-page'), 'm', 'agent:x');
        $this->assertTrue($r['stored'], (string) $r['reason']);

        return (int) $r['fact']->id;
    }

    private function factStatus(int $id): string
    {
        return (string) DB::table('g2g_entity_facts')->where('id', $id)->value('status');
    }

    // ---- FactRepoint ----

    public function test_facts_the_survivor_does_not_hold_move_with_their_status_and_evidence(): void
    {
        $from = $this->makeContact();
        $to = $this->makeContact();
        $waiting = $this->propose('contact', $from, 'email', 'a@school.example');
        $applied = $this->propose('contact', $from, 'phone', '+91 98765 43210');
        $this->writer->accept(self::T, $applied, 9);

        $r = app(FactRepoint::class)->move(self::T, 'contact', $from, $to);

        $this->assertSame(['moved' => 2, 'dropped' => 0, 'dismissals_kept' => 0, 'superseded' => 0], $r);
        $this->assertSame([$to, $to], [(int) DB::table('g2g_entity_facts')->where('id', $waiting)->value('entity_id'), (int) DB::table('g2g_entity_facts')->where('id', $applied)->value('entity_id')]);
        $this->assertSame(['proposed', 'applied'], [$this->factStatus($waiting), $this->factStatus($applied)]);
        $this->assertSame(2, DB::table('g2g_entity_fact_evidence')->count(), 'the evidence stays with its facts');
        $this->assertSame(0, DB::table('g2g_entity_facts')->where('entity_id', $from)->count());
    }

    public function test_a_fact_the_survivor_already_holds_is_dropped_with_its_evidence_and_the_survivors_row_stands(): void
    {
        $from = $this->makeContact();
        $to = $this->makeContact();
        $dup = $this->propose('contact', $from, 'email', 'same@school.example');
        $keep = $this->propose('contact', $to, 'email', 'SAME@school.example');
        $this->writer->accept(self::T, $keep, 9);

        $r = app(FactRepoint::class)->move(self::T, 'contact', $from, $to);

        $this->assertSame(['moved' => 0, 'dropped' => 1, 'dismissals_kept' => 0, 'superseded' => 0], $r);
        $this->assertNull(DB::table('g2g_entity_facts')->find($dup));
        $this->assertSame('applied', $this->factStatus($keep));
        $this->assertSame([$keep], DB::table('g2g_entity_fact_evidence')->pluck('fact_id')->map(fn ($i) => (int) $i)->all(), 'no evidence is left behind for the dropped fact');
    }

    public function test_a_value_a_person_dismissed_on_the_removed_record_is_not_offered_again_on_the_survivor(): void
    {
        $from = $this->makeContact();
        $to = $this->makeContact();
        $dismissed = $this->propose('contact', $from, 'title', 'Head of Trust');
        $this->writer->dismiss(self::T, $dismissed, 9, 'wrong school');
        $waiting = $this->propose('contact', $to, 'title', 'Head of Trust');

        $r = app(FactRepoint::class)->move(self::T, 'contact', $from, $to);

        $this->assertSame(1, $r['dismissals_kept']);
        $row = DB::table('g2g_entity_facts')->find($waiting);
        $this->assertSame(['dismissed', 9, 'wrong school'], [$row->status, (int) $row->decided_by, $row->decision_note], 'the dismissal outranks a suggestion that was still waiting');
        $again = $this->writer->propose(self::T, 'contact', $to, 'title', 'Head of Trust', $this->evidence('profile.email-match'), 'm', 'agent:x');
        $this->assertSame(FactWriter::REFUSED_DISMISSED, $again['outcome']);
    }

    public function test_a_waiting_fact_on_the_removed_record_never_overrides_a_decision_on_the_survivor(): void
    {
        $from = $this->makeContact();
        $to = $this->makeContact();
        $this->propose('contact', $from, 'title', 'Principal');
        $mine = $this->propose('contact', $to, 'title', 'Principal');
        $this->writer->dismiss(self::T, $mine, 9);

        app(FactRepoint::class)->move(self::T, 'contact', $from, $to);

        $this->assertSame('dismissed', $this->factStatus($mine));
        $this->assertSame(1, DB::table('g2g_entity_facts')->where('field', 'title')->count());
    }

    public function test_the_replacement_chain_follows_the_rows_that_are_dropped_and_a_one_value_field_keeps_one_current_fact(): void
    {
        $from = $this->makeContact();
        $to = $this->makeContact();
        $oldFrom = $this->propose('contact', $from, 'title', 'Teacher');
        $newFrom = $this->propose('contact', $from, 'title', 'Principal');
        $this->writer->accept(self::T, $oldFrom, 9);
        $this->writer->accept(self::T, $newFrom, 9);           // oldFrom superseded by newFrom
        $survivorTitle = $this->propose('contact', $to, 'title', 'Vice Principal');
        $this->writer->accept(self::T, $survivorTitle, 9);     // applied on the survivor too
        DB::table('g2g_entity_facts')->where('id', $survivorTitle)->update(['decided_at' => now()->subDay()]);

        $r = app(FactRepoint::class)->move(self::T, 'contact', $from, $to);

        $this->assertSame(2, $r['moved']);
        $applied = DB::table('g2g_entity_facts')->where('entity_id', $to)->where('field', 'title')->where('status', 'applied')->pluck('id')->map(fn ($i) => (int) $i)->all();
        $this->assertSame([$newFrom], $applied, 'one accepted title is current: the newer decision');
        $this->assertSame('superseded', $this->factStatus($survivorTitle));
        $this->assertSame(1, $r['superseded']);
        $this->assertSame($oldFrom, (int) DB::table('g2g_entity_facts')->where('id', $newFrom)->value('supersedes_id'), 'the chain of the moved facts is intact');
        $this->assertSame('superseded', $this->factStatus($oldFrom));
    }

    public function test_a_field_that_holds_several_values_keeps_all_of_them_accepted(): void
    {
        $from = $this->makeContact();
        $to = $this->makeContact();
        $a = $this->propose('contact', $from, 'email', 'a@school.example');
        $b = $this->propose('contact', $to, 'email', 'b@school.example');
        $this->writer->accept(self::T, $a, 9);
        $this->writer->accept(self::T, $b, 9);

        $r = app(FactRepoint::class)->move(self::T, 'contact', $from, $to);

        $this->assertSame(0, $r['superseded']);
        $this->assertSame(['applied', 'applied'], [$this->factStatus($a), $this->factStatus($b)]);
    }

    public function test_it_only_touches_the_named_organisation_and_kind_and_is_idempotent(): void
    {
        $from = $this->makeContact();
        $to = $this->makeContact();
        $theirs = $this->makeContact(self::T2);
        $mine = $this->propose('contact', $from, 'email', 'a@school.example');
        $other = $this->propose('contact', $theirs, 'email', 'x@school.example', self::T2);
        // a fact of ANOTHER organisation that happens to carry the removed record's id and a different kind
        $wrongKind = $this->propose('lead', $this->makeLead(), 'title', 'Principal');
        DB::table('g2g_entity_facts')->where('id', $wrongKind)->update(['entity_id' => $from]);
        $otherTenantSameId = $this->propose('contact', $theirs, 'phone', '+91 98765 43210', self::T2);
        DB::table('g2g_entity_facts')->where('id', $otherTenantSameId)->update(['entity_id' => $from]);
        $repoint = app(FactRepoint::class);

        $first = $repoint->move(self::T, 'contact', $from, $to);
        $second = $repoint->move(self::T, 'contact', $from, $to);

        $this->assertSame(1, $first['moved']);
        $this->assertSame(['moved' => 0, 'dropped' => 0, 'dismissals_kept' => 0, 'superseded' => 0], $second);
        $this->assertSame($to, (int) DB::table('g2g_entity_facts')->where('id', $mine)->value('entity_id'));
        $this->assertSame($theirs, (int) DB::table('g2g_entity_facts')->where('id', $other)->value('entity_id'), 'another organisation\'s fact is not touched');
        $this->assertSame($from, (int) DB::table('g2g_entity_facts')->where('id', $wrongKind)->value('entity_id'), 'a fact of another kind that carries the same id is not touched');
        $this->assertSame($from, (int) DB::table('g2g_entity_facts')->where('id', $otherTenantSameId)->value('entity_id'), 'nor another organisation\'s fact that carries the same id');
        $this->assertSame(['moved' => 0, 'dropped' => 0, 'dismissals_kept' => 0, 'superseded' => 0], $repoint->move(self::T, 'contact', $to, $to), 'merging a record into itself is nothing');
        $this->assertSame(['moved' => 0, 'dropped' => 0, 'dismissals_kept' => 0, 'superseded' => 0], $repoint->move(self::T, 'opportunity', $from, $to));
    }

    // ---- the contact merge, end to end ----

    public function test_the_contact_merge_moves_the_facts_and_keeps_the_duplicates_opt_out(): void
    {
        foreach (['2026_11_20_090400_create_crm_campaigns_table', '2026_11_20_090500_create_crm_campaign_targets_table'] as $migration) {
            (require base_path("database/migrations/{$migration}.php"))->up();
        }
        $survivor = $this->makeContact(self::T, ['email' => 'priya@school.example']);
        $duplicate = $this->makeContact(self::T, ['email' => 'priya@school.example', 'email_opt_out' => 1, 'consent_status' => 'opted_out']);
        DB::table('crm_consent_preferences')->insert(['entity_type' => 'contact', 'entity_id' => $duplicate, 'channel' => 'email', 'status' => 'opted_out', 'source' => 'unsubscribe_link', 'sub_institute_id' => self::T]);
        $fact = $this->propose('contact', $duplicate, 'phone', '+91 98765 43210');
        $user = $this->user([self::CONTACTS_LINK => ['view', 'delete']]);

        $this->postJson('/api/crm/contacts/merge', ['survivorId' => $survivor, 'duplicateIds' => [$duplicate]], $user['h'])->assertOk()->assertJsonPath('data.merged', 1);

        $this->assertSame($survivor, (int) DB::table('g2g_entity_facts')->where('id', $fact)->value('entity_id'), 'the duplicate\'s facts follow it to the survivor');
        $row = DB::table('crm_contacts')->where('id', $survivor)->first();
        $this->assertSame([1, 'opted_out'], [(int) $row->email_opt_out, $row->consent_status], 'a merge no longer loses an opt-out');
        $this->assertSame('opted_out', DB::table('crm_consent_preferences')->where('entity_type', 'contact')->where('entity_id', $survivor)->value('status'));
        $this->assertFalse(app(\App\Domain\Facts\ConsentGate::class)->allows('contact', $survivor, 'email')['allowed']);
        $this->assertNotNull(DB::table('crm_contacts')->where('id', $duplicate)->value('deleted_at'));
    }

    // ---- the organisation merge, through the registry ----

    public function test_the_organisation_registry_lists_the_ledger_so_the_merge_moves_its_facts(): void
    {
        $entry = collect(OrganisationReferences::polymorphic())->firstWhere('table', 'g2g_entity_facts');

        $this->assertNotNull($entry, 'g2g_entity_facts is in the one list the organisation merge walks');
        $this->assertSame(['id' => 'entity_id', 'type' => 'entity_type', 'values' => ['organization'], 'unique' => ['field', 'value_hash']], array_intersect_key($entry, array_flip(['id', 'type', 'values', 'unique'])));
        $this->assertSame('sub_institute_id', $entry['scope']);
    }

    public function test_an_organisation_merge_moves_the_removed_organisations_facts_and_drops_a_repeat(): void
    {
        (require base_path('database/migrations/2026_12_09_090000_create_g2g_org_accounts_tables.php'))->up();
        $from = $this->makeOrganization(self::T, ['name' => 'Fernhill School']);
        $to = $this->makeOrganization(self::T, ['name' => 'Fernhill School (copy)']);
        $theirs = $this->makeOrganization(self::T2, ['name' => 'Theirs']);
        $moves = $this->propose('organization', $from, 'website', 'fernhill.example');
        $repeat = $this->propose('organization', $from, 'phone', '+91 98765 43210');
        $keeps = $this->propose('organization', $to, 'phone', '+91-98765-43210');
        $other = $this->propose('organization', $theirs, 'website', 'theirs.example', self::T2);
        $contactFact = $this->propose('contact', $this->makeContact(), 'email', 'a@school.example');
        DB::table('g2g_entity_facts')->where('id', $contactFact)->update(['entity_id' => $from]);

        $counts = OrganisationMerger::repoint(self::T, $from, $to);

        $this->assertSame($to, (int) DB::table('g2g_entity_facts')->where('id', $moves)->value('entity_id'));
        $this->assertNull(DB::table('g2g_entity_facts')->find($repeat), 'the same phone number, written another way, is held once');
        $this->assertSame($to, (int) DB::table('g2g_entity_facts')->where('id', $keeps)->value('entity_id'));
        $this->assertSame($theirs, (int) DB::table('g2g_entity_facts')->where('id', $other)->value('entity_id'));
        $this->assertSame($from, (int) DB::table('g2g_entity_facts')->where('id', $contactFact)->value('entity_id'), 'a CONTACT fact that carries the removed organisation\'s id is not an organisation\'s fact');
        $this->assertIsArray($counts);
    }
}
