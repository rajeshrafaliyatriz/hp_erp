<?php

namespace Tests\Feature;

use App\Domain\Crm\Leads\LeadLinks;
use App\Domain\Crm\Leads\LeadSignalEvidence;
use App\Domain\Crm\Records\RecordNote;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsFactsWorld;
use Tests\Support\BuildsLeadsWorld;
use Tests\TestCase;

/**
 * Turning a signal into a lead (slice 6, acceptance 3): the lead is filed under the product the signal supports and under the organisation identity that
 * holds the signal's company, each only when it resolves and only when it is this organisation's to link; and ONE structured note says where the lead came
 * from (the quote, the source address, the date, the fit), written through the shared note writer, once per signal, in the same transaction as the lead.
 */
class LeadPromotionLinksTest extends TestCase
{
    use BuildsFactsWorld;
    use BuildsLeadsWorld;

    private array $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildLeadsWorld();
        $this->buildSignalsWorld();
        $this->admin = $this->signalsAdmin();
    }

    private function product(int $tenant, string $key, string $signalKey, string $name, int $parent = 0): int
    {
        return (int) DB::table('g2g_product_master')->insertGetId([
            'sub_institute_id' => $tenant, 'kind' => $parent > 0 ? 'sub_product' : 'product', 'parent_id' => $parent ?: null, 'product_key' => $key, 'signal_key' => $signalKey,
            'portfolio_name' => $name, 'name' => $name, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function identity(int $tenant, int $companyId, string $name = 'Greenfield School'): int
    {
        return (int) DB::table('g2g_org_accounts')->insertGetId(['sub_institute_id' => $tenant, 'name' => $name, 'normalized_name' => strtolower($name), 'lifecycle' => 'discovered', 'g2g_company_id' => $companyId, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function promote(int $signalId, ?array $as = null)
    {
        return $this->postJson("/api/signals/opportunities/{$signalId}/promote-to-crm", [], ($as ?? $this->admin)['h']);
    }

    /** @return list<object> */
    private function notes(int $leadId): array
    {
        return DB::table('crm_lead_comments')->where('lead_id', $leadId)->orderBy('id')->get()->all();
    }

    public function test_a_lead_is_filed_under_the_signals_product_and_its_companys_identity(): void
    {
        $product = $this->product(0, 'scholarclone', 'scholar', 'ScholarClone');
        $signal = $this->makeSignal(self::T, ['product_master_id' => $product, 'business_fit' => 'scholar']);
        $identity = $this->identity(self::T, $signal->company_id);

        $leadId = (int) $this->promote($signal->id)->assertCreated()->json('data.lead_id');

        $lead = DB::table('crm_leads')->where('id', $leadId)->first();
        $this->assertSame([$product, $identity], [(int) $lead->product_master_id, (int) $lead->org_account_id]);
        $this->assertSame(self::T, (int) $lead->sub_institute_id);
    }

    public function test_with_no_product_on_the_signal_the_one_its_stored_fit_names_is_used(): void
    {
        $product = $this->product(0, 'scholarclone', 'scholar', 'ScholarClone');
        $signal = $this->makeSignal(self::T, ['business_fit' => 'scholar']);

        $leadId = (int) $this->promote($signal->id)->assertCreated()->json('data.lead_id');

        $this->assertSame($product, (int) DB::table('crm_leads')->where('id', $leadId)->value('product_master_id'));
        $this->assertNull(DB::table('crm_leads')->where('id', $leadId)->value('org_account_id'), 'no identity holds this company: nothing is made up');
    }

    public function test_a_signal_with_no_product_and_no_identity_makes_a_lead_with_neither(): void
    {
        $signal = $this->makeSignal(self::T, ['business_fit' => 'multiple']);

        $leadId = (int) $this->promote($signal->id)->assertCreated()->json('data.lead_id');

        $lead = DB::table('crm_leads')->where('id', $leadId)->first();
        $this->assertSame([null, null], [$lead->product_master_id, $lead->org_account_id]);
        $this->assertStringContainsString('Fit: stored fit "multiple"', $this->notes($leadId)[0]->comment);
    }

    public function test_another_organisations_product_and_identity_are_never_linked(): void
    {
        $theirProduct = $this->product(self::T2, 'their_own', 'their', 'Their Own Product');
        $signal = $this->makeSignal(self::T, ['product_master_id' => $theirProduct]);
        $this->identity(self::T2, $signal->company_id, 'Their Identity');

        $leadId = (int) $this->promote($signal->id)->assertCreated()->json('data.lead_id');

        $lead = DB::table('crm_leads')->where('id', $leadId)->first();
        $this->assertNull($lead->product_master_id, 'a product of another organisation is not this lead\'s product');
        $this->assertNull($lead->org_account_id, 'an identity of another organisation does not hold this company');
    }

    /** The two layers each stand on their own: the lookups (LeadLinks) never offer another organisation's record, and the factory never stores one a caller hands it. */
    public function test_the_lookups_never_offer_another_organisations_product_or_identity(): void
    {
        $own = $this->product(self::T, 'own', 'own', 'Own Product');
        $shared = $this->product(0, 'shared', 'shared', 'Shared Product');
        $theirs = $this->product(self::T2, 'theirs', 'theirs', 'Their Product');
        $ownIdentity = $this->identity(self::T, 11);
        $this->identity(self::T2, 12);

        $this->assertSame($own, LeadLinks::productForSignal(self::T, (object) ['product_master_id' => $own]));
        $this->assertSame($shared, LeadLinks::productForSignal(self::T, (object) ['product_master_id' => $shared]), 'a shared product (organisation 0) is everybody\'s');
        $this->assertNull(LeadLinks::productForSignal(self::T, (object) ['product_master_id' => $theirs, 'business_fit' => null]));
        $this->assertSame($ownIdentity, LeadLinks::orgAccountForCompany(self::T, 11));
        $this->assertNull(LeadLinks::orgAccountForCompany(self::T, 12), 'company 12 is held by the other organisation\'s identity');
        $this->assertNull(LeadLinks::orgAccountForCompany(self::T, null));
        $this->assertNull(LeadLinks::orgAccountForCompany(self::T, 0));
        $this->assertNull(LeadLinks::orgAccountForCrmOrganization(self::T, 11), 'a company id is not a CRM organisation id');

        DB::table('g2g_org_accounts')->where('id', $ownIdentity)->update(['deleted_at' => now()]);
        $this->assertNull(LeadLinks::orgAccountForCompany(self::T, 11), 'a removed identity holds nothing');
    }

    public function test_the_factory_stores_only_links_that_are_the_organisations_to_make(): void
    {
        $own = $this->product(self::T, 'own', 'own', 'Own Product');
        $shared = $this->product(0, 'shared', 'shared', 'Shared Product');
        $theirs = $this->product(self::T2, 'theirs', 'theirs', 'Their Product');
        $ownIdentity = $this->identity(self::T, 11);
        $theirIdentity = $this->identity(self::T2, 12);
        $make = fn (array $links) => (array) DB::table('crm_leads')->where('id', \App\Domain\Crm\Leads\LeadFactory::create(['last_name' => 'Verma', 'assigned_to' => 1] + $links, self::T, 1))->first(['product_master_id', 'org_account_id']);

        $this->assertEquals(['product_master_id' => $own, 'org_account_id' => $ownIdentity], $make(['product_master_id' => $own, 'org_account_id' => $ownIdentity]));
        $this->assertEquals(['product_master_id' => $shared, 'org_account_id' => null], $make(['product_master_id' => $shared]));
        $this->assertEquals(['product_master_id' => null, 'org_account_id' => null], $make(['product_master_id' => $theirs, 'org_account_id' => $theirIdentity]), 'another organisation\'s records are dropped, not stored');
        $this->assertEquals(['product_master_id' => null, 'org_account_id' => null], $make(['product_master_id' => 0, 'org_account_id' => 424242]));
        $this->assertEquals(['product_master_id' => null, 'org_account_id' => null], $make(['product_master_id' => null, 'org_account_id' => null]));
    }

    /** The code reaches a server before its migrations do: promotion must keep working there, and simply not file the lead. */
    public function test_a_host_that_has_not_run_the_columns_migration_still_promotes(): void
    {
        $product = $this->product(0, 'scholarclone', 'scholar', 'ScholarClone');
        $signal = $this->makeSignal(self::T, ['product_master_id' => $product]);
        $this->identity(self::T, $signal->company_id);
        (require base_path('database/migrations/2026_12_11_100100_add_product_and_org_account_to_crm_leads.php'))->down();
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('crm_leads', 'product_master_id'));

        $leadId = (int) $this->promote($signal->id)->assertCreated()->json('data.lead_id');

        $this->assertCount(1, $this->notes($leadId));
        $this->assertStringContainsString('Fit: ScholarClone', $this->notes($leadId)[0]->comment, 'the note still names the product the signal supports');
    }

    public function test_one_structured_note_says_where_the_lead_came_from(): void
    {
        $product = $this->product(0, 'scholarclone', 'scholar', 'ScholarClone');
        $signal = $this->makeSignal(self::T, [
            'title' => 'Keystone opens in Bengaluru', 'product_master_id' => $product, 'business_fit' => 'scholar', 'event_date' => '2026-09-25', 'source_published_at' => '2026-09-26',
            'source_url' => 'https://news.example.com/keystone',
            'sources' => [['url' => 'https://news.example.com/keystone', 'title' => 'Keystone', 'published_at' => '2026-09-26', 'excerpt' => 'Keystone will hire 200 people in Bengaluru.']],
        ]);

        $leadId = (int) $this->promote($signal->id)->assertCreated()->json('data.lead_id');

        $notes = $this->notes($leadId);
        $this->assertCount(1, $notes);
        $this->assertSame(
            "From a signal: Keystone opens in Bengaluru (signal #{$signal->id})\n"
            . "Quote: \"Keystone will hire 200 people in Bengaluru.\"\n"
            . "Source: https://news.example.com/keystone\n"
            . "Event date: 2026-09-25\n"
            . 'Fit: ScholarClone, stored fit "scholar"',
            $notes[0]->comment
        );
        $this->assertSame([$this->admin['id'], self::T], [(int) $notes[0]->created_by, (int) $notes[0]->sub_institute_id], 'written for the person who pressed the button, in their organisation');
    }

    public function test_pressing_promote_again_writes_no_second_note_and_no_second_lead(): void
    {
        $signal = $this->makeSignal(self::T);
        $leadId = (int) $this->promote($signal->id)->assertCreated()->json('data.lead_id');

        $again = $this->promote($signal->id)->assertOk();
        $this->promote($signal->id)->assertOk();

        $this->assertFalse($again->json('data.created'));
        $this->assertSame((string) $leadId, $again->json('data.lead_id'));
        $this->assertCount(1, $this->notes($leadId), 'still one note');
        $this->assertSame(1, DB::table('crm_leads')->count());
        $this->assertSame(1, DB::table('crm_lead_comments')->count());
    }

    public function test_the_lead_its_history_line_and_its_note_stand_together_or_not_at_all(): void
    {
        $signal = $this->makeSignal(self::T);
        DB::listen(function ($query) {
            if (str_starts_with(strtolower($query->sql), 'insert into "crm_lead_comments"')) {
                throw new \RuntimeException('the note could not be written');
            }
        });

        $this->promote($signal->id)->assertStatus(500);

        $this->assertSame(0, DB::table('crm_leads')->count(), 'no lead without its note');
        $this->assertSame(0, DB::table('g2g_signal_reviews')->count(), 'and no line in the signal\'s history');
        $this->assertSame(0, DB::table('crm_lead_comments')->count());
    }

    public function test_the_lead_keeps_the_whole_summary_not_the_first_255_bytes(): void
    {
        $signal = $this->makeSignal(self::T, ['observed_event' => str_repeat('Observed. ', 60), 'why_indicates_need' => str_repeat('Needs. ', 40), 'recommended_action' => 'Call them.']);

        $leadId = (int) $this->promote($signal->id)->assertCreated()->json('data.lead_id');

        $description = (string) DB::table('crm_leads')->where('id', $leadId)->value('description');
        $this->assertGreaterThan(255, strlen($description));
        $this->assertLessThanOrEqual(2000, mb_strlen($description));
        $this->assertStringEndsWith('Call them.', $description);
    }

    public function test_promoting_another_organisations_signal_is_refused_and_writes_nothing(): void
    {
        $theirs = $this->makeSignal(self::T2);

        $this->promote($theirs->id)->assertNotFound();

        $this->assertSame(0, DB::table('crm_leads')->count());
        $this->assertSame(0, DB::table('crm_lead_comments')->count());
    }

    // ── what the evidence says, and what it refuses to say ───────────────────

    public function test_the_evidence_is_only_what_the_signal_holds(): void
    {
        $base = ['id' => 7, 'title' => 'T', 'sources' => '[{"url":"https://a.example/x","published_at":"2026-01-02","excerpt":"  Line one\n  line two  "}]',
            'observed_event' => 'Observed it.', 'source_url' => null, 'event_date' => null, 'source_published_at' => null, 'business_fit' => ' scholar '];

        $e = LeadSignalEvidence::of((object) $base);

        $this->assertSame('Line one line two', $e['quote'], 'whitespace is squeezed, the excerpt is the quote');
        $this->assertSame('excerpt', $e['quote_kind']);
        $this->assertSame('https://a.example/x', $e['source_url'], 'the first source\'s address when the signal has none of its own');
        $this->assertNull($e['event_date']);
        $this->assertSame('2026-01-02', $e['source_published_at'], 'the source\'s date when the signal has none');
        $this->assertSame('scholar', $e['business_fit']);
    }

    public function test_without_an_excerpt_the_observed_event_is_labelled_for_what_it_is(): void
    {
        $e = LeadSignalEvidence::of((object) ['id' => 7, 'title' => 'T', 'sources' => [], 'observed_event' => 'They opened a campus.', 'business_fit' => null]);

        $this->assertSame(['They opened a campus.', 'observed_event'], [$e['quote'], $e['quote_kind']]);
        $text = LeadSignalEvidence::noteText($e, null);
        $this->assertStringContainsString('What the signal observed: They opened a campus.', $text);
        $this->assertStringNotContainsString('Quote:', $text, 'it is not passed off as a quote');
        $this->assertStringContainsString("Source: no address saved\nDate: not stated\nFit: not matched to a product", $text);
    }

    public function test_a_bad_address_and_a_bad_date_are_no_address_and_no_date(): void
    {
        $e = LeadSignalEvidence::of((object) ['id' => 1, 'title' => 'T', 'sources' => [['url' => 'javascript:alert(1)', 'excerpt' => 'x']], 'observed_event' => '', 'source_url' => 'ftp://nope',
            'event_date' => '0000-00-00', 'source_published_at' => '2026-02-31']);

        $this->assertNull($e['source_url']);
        $this->assertNull($e['event_date'], 'a zero date is no date');
        $this->assertNull($e['source_published_at'], 'a date that does not exist is no date');
    }

    public function test_an_undated_event_with_a_publication_date_says_so(): void
    {
        $e = LeadSignalEvidence::of((object) ['id' => 1, 'title' => 'T', 'sources' => [], 'observed_event' => 'o', 'event_date' => null, 'source_published_at' => '2026-03-04']);

        $this->assertStringContainsString('Published: 2026-03-04 (the event date is not stated)', LeadSignalEvidence::noteText($e, 'ScholarClone'));
    }

    public function test_a_long_quote_is_cut_and_the_whole_note_fits_the_note_writer(): void
    {
        $e = LeadSignalEvidence::of((object) ['id' => 1, 'title' => str_repeat('T', 400), 'sources' => [['excerpt' => str_repeat('q', 5000), 'url' => 'https://a.example/' . str_repeat('u', 900)]], 'observed_event' => 'o', 'business_fit' => 'scholar']);

        $text = LeadSignalEvidence::noteText($e, str_repeat('P', 100));

        $this->assertLessThanOrEqual(RecordNote::MAX_LENGTH, mb_strlen($text), 'inside the note writer\'s maximum, so it never has to cut the last line (the fit) off');
        $this->assertSame('Fit: ' . str_repeat('P', 100) . ', stored fit "scholar"', collect(explode("\n", $text))->last(), 'the last line, the fit, is whole');
        $this->assertStringContainsString(str_repeat('q', 300), $text, 'the quote still has room');
        $this->assertStringNotContainsString(str_repeat('q', 701), $text, 'and is cut at 700 at most');

        // With a short address the quote gets its full 700 characters.
        $short = LeadSignalEvidence::noteText(LeadSignalEvidence::of((object) ['id' => 1, 'title' => 'T', 'sources' => [['excerpt' => str_repeat('q', 5000)]], 'observed_event' => 'o']), null);
        $this->assertStringContainsString(str_repeat('q', 700) . '"', $short);
        $this->assertStringNotContainsString(str_repeat('q', 701), $short);
    }
}
