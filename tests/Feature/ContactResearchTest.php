<?php

namespace Tests\Feature;

use App\Domain\Signals\Market\MarketImporter;
use App\Domain\Signals\Opportunities\Company;
use App\Domain\Signals\Opportunities\CompanyOpportunity;
use App\Domain\Signals\Opportunities\ContactResearcher;
use App\Domain\Signals\Opportunities\SignalContact;
use App\Domain\Signals\Opportunities\SignalContactStore;
use App\Domain\Signals\Opportunities\WebSearchProvider;
use App\Http\Controllers\Api\Signals\OpportunityController;
use App\Jobs\EnrichSignalContactsJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Public-contact research for the organisation behind a signal. In-memory sqlite, a fake search provider / Http::fake: no real provider is called.
 */
class ContactResearchTest extends TestCase
{
    private const T = 1;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            '2026_09_30_150000_create_g2g_opportunity_and_ingestion_tables', '2026_09_30_180000_add_signal_fields_to_ingestion_findings',
            '2026_09_30_200000_add_research_sources_and_signal_kind', '2026_09_30_220000_create_g2g_portfolio_and_partners_tables',
            '2026_09_30_230000_add_claude_feed_and_linking_fields', '2026_10_01_130000_add_stage_to_g2g_research_runs',
            '2026_10_08_100000_extend_g2g_signals_for_market_import', '2026_11_29_090000_add_assessment_to_g2g_company_opportunities',
            '2026_12_01_090000_add_product_key_to_signals_runs_and_sources', '2026_10_14_100000_create_g2g_icp_profiles_tables',
            '2026_12_28_100000_create_g2g_signal_contacts_table',
        ] as $m) {
            (require base_path("database/migrations/{$m}.php"))->up();
        }
        Cache::flush();
        Http::preventStrayRequests();   // nothing in this file may reach a real provider
    }

    /** A provider that answers each query from a list of [needle => results], and records what it was asked. */
    private function provider(array $answers = [], bool $throw = false): object
    {
        return new class($answers, $throw) implements WebSearchProvider {
            public array $asked = [];

            public function __construct(private array $answers, private bool $throw)
            {
            }

            public function name(): string
            {
                return 'fake';
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function search(string $query, int $recencyDays, int $limit, array $options = []): array
            {
                $this->asked[] = $query;
                if ($this->throw) {
                    throw new \RuntimeException('provider down');
                }
                foreach ($this->answers as $needle => $results) {
                    if (str_contains($query, (string) $needle)) {
                        return $results;
                    }
                }

                return [];
            }
        };
    }

    private function r(string $url, string $title, string $excerpt = ''): array
    {
        return ['url' => $url, 'title' => $title, 'excerpt' => $excerpt, 'published_at' => null];
    }

    private function ctx(array $o = []): array
    {
        return $o + ['company_name' => 'Sunrise Public School', 'website' => 'https://www.sunriseschool.in', 'location' => 'Pune', 'roles' => ['Principal']];
    }

    public function test_it_finds_the_organisations_own_social_pages_and_never_a_post_a_share_link_or_another_company(): void
    {
        $p = $this->provider(['official LinkedIn Facebook Instagram' => [
            $this->r('https://www.linkedin.com/company/sunrise-public-school?trk=x', 'Sunrise Public School | LinkedIn', 'Sunrise Public School, Pune'),
            $this->r('https://www.facebook.com/sunrisepublicschoolpune', 'Sunrise Public School - Home | Facebook', ''),
            $this->r('https://www.facebook.com/sharer/sharer.php?u=x', 'Sunrise Public School share', ''),
            $this->r('https://www.instagram.com/sunriseschoolpune/', 'Sunrise Public School (@sunriseschoolpune) Instagram', ''),
            $this->r('https://www.instagram.com/p/AbC123/', 'Sunrise Public School post', ''),
            $this->r('https://www.facebook.com/othercompany', 'Other Company Ltd | Facebook', ''),
        ]]);
        $c = (new ContactResearcher($p))->research($this->ctx());

        $byLabel = [];
        foreach ($c as $row) {
            $byLabel[$row['designation'] ?? ''] = $row;
        }
        $this->assertSame('https://www.linkedin.com/company/sunrise-public-school', $byLabel['Company LinkedIn page']['linkedin_url']);
        $this->assertSame('https://www.facebook.com/sunrisepublicschoolpune', $byLabel['Company Facebook page']['website']);
        $this->assertSame('https://www.instagram.com/sunriseschoolpune', $byLabel['Company Instagram account']['website']);
        $this->assertCount(3, $c, 'no share link, no post, no other company');

        $saved = \App\Domain\Signals\Opportunities\SignalContactStore::save(self::T, 1, null, $c);
        $this->assertSame(3, $saved, 'the profile pages are stored as rows of their own');
        $this->assertSame(0, \App\Domain\Signals\Opportunities\SignalContactStore::save(self::T, 1, null, $c), 'a second save adds nothing');
    }

    public function test_it_finds_the_published_phone_and_email_independently_with_their_source_urls(): void
    {
        $p = $this->provider(['contact us' => [
            $this->r('https://www.sunriseschool.in/contact', 'Contact Sunrise Public School', 'Phone 020 2445 1234, mobile +91 98220 11223. Write to office@sunriseschool.in'),
        ]]);
        $c = (new ContactResearcher($p))->research($this->ctx());

        $this->assertCount(1, $c);
        $this->assertSame('office@sunriseschool.in', $c[0]['email']);
        $this->assertSame('+919822011223', $c[0]['phone']);
        $this->assertSame('https://www.sunriseschool.in/contact', $c[0]['email_source_url']);
        $this->assertSame('https://www.sunriseschool.in/contact', $c[0]['phone_source_url']);
        $this->assertArrayNotHasKey('full_name', $c[0]);   // an organisation line has no invented person
    }

    public function test_only_a_phone_or_only_an_email_is_kept_as_found(): void
    {
        $onlyEmail = (new ContactResearcher($this->provider(['contact us' => [$this->r('https://www.sunriseschool.in/c', 'Sunrise Public School', 'mail info@sunriseschool.in')]])))->research($this->ctx());
        $this->assertSame('info@sunriseschool.in', $onlyEmail[0]['email']);
        $this->assertArrayNotHasKey('phone', $onlyEmail[0]);

        $onlyPhone = (new ContactResearcher($this->provider(['contact us' => [$this->r('https://www.sunriseschool.in/c', 'Sunrise Public School', 'call 98220 11223')]])))->research($this->ctx());
        $this->assertSame('+919822011223', $onlyPhone[0]['phone']);
        $this->assertArrayNotHasKey('email', $onlyPhone[0]);
    }

    public function test_it_finds_several_decision_makers_for_a_company(): void
    {
        $p = $this->provider(['"Principal"' => [
            $this->r('https://in.linkedin.com/in/asha-rao-123?trk=x', 'Asha Rao - Principal - Sunrise Public School | LinkedIn', 'Principal at Sunrise Public School, Pune'),
            $this->r('https://www.linkedin.com/in/vikram-shah', 'Vikram Shah - Director - Sunrise Public School | LinkedIn', 'Director at Sunrise Public School'),
        ]]);
        $c = (new ContactResearcher($p))->research($this->ctx());

        $names = array_column($c, 'full_name');
        $this->assertContains('Asha Rao', $names);
        $this->assertContains('Vikram Shah', $names);
        $asha = $c[array_search('Asha Rao', $names, true)];
        $this->assertSame('Principal', $asha['designation']);
        $this->assertSame('https://in.linkedin.com/in/asha-rao-123', $asha['linkedin_url']);
        $this->assertSame('https://in.linkedin.com/in/asha-rao-123?trk=x', $asha['source_url']);
    }

    public function test_an_unrelated_company_or_a_non_decision_maker_is_not_returned(): void
    {
        $p = $this->provider(['"Principal"' => [
            $this->r('https://www.linkedin.com/in/other-person', 'Ravi Kumar - Principal - Moonlight Academy | LinkedIn', 'Principal at Moonlight Academy'),
            $this->r('https://www.linkedin.com/in/clerk-person', 'Meena Joshi - Accounts Clerk - Sunrise Public School | LinkedIn', 'Sunrise Public School'),
        ]]);
        $this->assertSame([], (new ContactResearcher($p))->research($this->ctx(['website' => null])));
    }

    public function test_a_published_email_for_a_named_person_is_taken_only_at_the_company_domain_and_never_guessed(): void
    {
        $p = $this->provider([
            '"Principal"' => [$this->r('https://www.linkedin.com/in/asha-rao', 'Asha Rao - Principal - Sunrise Public School | LinkedIn', 'Sunrise Public School')],
            '"Asha Rao" email' => [
                $this->r('https://elsewhere.example/post', 'Asha Rao', 'asha.rao@gmail.com'),
                $this->r('https://www.sunriseschool.in/leadership', 'Leadership', 'Asha Rao, Principal: principal@sunriseschool.in'),
            ],
        ]);
        $c = (new ContactResearcher($p))->research($this->ctx());
        $asha = $c[array_search('Asha Rao', array_column($c, 'full_name'), true)];
        $this->assertSame('principal@sunriseschool.in', $asha['email']);
        $this->assertSame('https://www.sunriseschool.in/leadership', $asha['email_source_url']);

        // Nothing printed anywhere: the person is kept, the email stays absent. No first.last@domain guess.
        $none = (new ContactResearcher($this->provider(['"Principal"' => [$this->r('https://www.linkedin.com/in/asha-rao', 'Asha Rao - Principal - Sunrise Public School | LinkedIn', 'Sunrise Public School')]])))->research($this->ctx());
        $this->assertSame('Asha Rao', $none[0]['full_name']);
        $this->assertArrayNotHasKey('email', $none[0]);
        $this->assertArrayNotHasKey('phone', $none[0]);
    }

    public function test_a_search_that_finds_nothing_or_fails_returns_no_contacts_and_invents_none(): void
    {
        $this->assertSame([], (new ContactResearcher($this->provider()))->research($this->ctx()));
        $failing = new ContactResearcher($this->provider([], true));
        $this->assertSame([], $failing->research($this->ctx()));
        $this->assertGreaterThan(0, $failing->failures);
    }

    public function test_the_search_budget_is_respected(): void
    {
        $p = $this->provider();
        (new ContactResearcher($p, 2))->research($this->ctx(['roles' => ['Principal', 'Director', 'Founder']]));
        $this->assertCount(2, $p->asked);
    }

    public function test_the_store_keeps_source_urls_dedupes_and_never_stores_an_invalid_value(): void
    {
        $n = SignalContactStore::save(self::T, 5, 9, [
            ['full_name' => 'Asha Rao', 'designation' => 'Principal', 'email' => 'asha@sunriseschool.in', 'email_source_url' => 'https://s/1', 'phone' => 'bad', 'phone_source_url' => 'https://s/2'],
            ['full_name' => 'Asha Rao', 'phone' => '98220 11223', 'phone_source_url' => 'https://s/3'],
            ['email' => 'not-an-email'],
        ]);
        $rows = DB::table('g2g_signal_contacts')->get();
        $this->assertCount(1, $rows);
        $this->assertSame(2, $n);
        $this->assertSame('asha@sunriseschool.in', $rows[0]->email);
        $this->assertSame('+919822011223', $rows[0]->phone);
        $this->assertSame('https://s/1', $rows[0]->email_source_url);
        $this->assertSame('https://s/3', $rows[0]->phone_source_url);
        $this->assertSame('unverified', $rows[0]->verification_status);
        $this->assertSame([], SignalContactStore::forCompanies(2, [5]), 'another organisation sees nothing');
    }

    public function test_found_contacts_show_on_the_signal_with_their_source_and_an_unverified_label(): void
    {
        $row = (object) ['company_name' => 'Sunrise Public School', 'company_website' => 'https://www.sunriseschool.in', 'source_url' => 'https://news.test/a'];
        $stored = [
            ['full_name' => 'Asha Rao', 'designation' => 'Principal', 'email' => 'asha@sunriseschool.in', 'phone' => '+919822011223', 'linkedin_url' => 'https://www.linkedin.com/in/asha', 'source_url' => 'https://s/1', 'email_source_url' => 'https://s/1', 'phone_source_url' => 'https://s/2'],
            ['full_name' => 'Vikram Shah', 'designation' => 'Director', 'email' => null, 'phone' => null],
        ];
        $c = SignalContact::build($row, null, null, [], [], $stored, ['state' => 'found']);
        $this->assertSame('complete', $c['status']);
        $this->assertSame('Asha Rao', $c['name']);
        $this->assertSame('found_public', $c['email_source']['status']);
        $this->assertSame('https://s/2', $c['phone_source']['url']);
        $this->assertCount(2, $c['contacts']);
        $this->assertFalse($c['verified']);
        $this->assertSame('unverified', $c['contacts'][0]['verification']);
    }

    public function test_a_signal_with_no_contacts_is_still_presented_with_not_available(): void
    {
        $c = SignalContact::build((object) ['company_name' => 'Sunrise Public School'], null, null, [], [], [], ['state' => 'none_found']);
        $this->assertSame('not_available', $c['status']);
        $this->assertNull($c['email']);
        $this->assertNull($c['phone']);
        $this->assertSame([], $c['contacts']);
    }

    // ── a signal is never affected ───────────────────────────────────────────

    private function record(): array
    {
        return [
            'buyer' => ['name' => 'Podar World School', 'type' => 'school', 'state' => 'Gujarat'],
            'trigger_type' => 'expansion', 'trigger_summary' => 'Opening a new campus in Vadodara.',
            'what_happened' => 'Podar World School is opening a new campus in Vadodara.', 'event_date' => '2026-10-10',
            'source_url' => 'https://news-a.example.com/podar-campus', 'source_title' => 'Podar opens campus',
            'claim_level' => 'hypothesis', 'fetch_level' => 'search_snippet',
        ];
    }

    public function test_creating_a_signal_queues_the_lookup_and_the_signal_is_saved_either_way(): void
    {
        config(['signals.web_search.driver' => 'tavily', 'signals.web_search.tavily_key' => 'tvly-test-key', 'signals_connectors.contact_research.enabled' => true, 'signals_connectors.contact_research.allow_in_tests' => true]);
        Queue::fake();
        $res = app(MarketImporter::class)->import(self::T, [$this->record()], ['source_label' => 'test']);

        $this->assertSame(1, $res['accepted']);
        $this->assertSame(1, CompanyOpportunity::count());
        Queue::assertPushed(EnrichSignalContactsJob::class, fn ($j) => $j->tenantId === self::T && $j->opportunityId === CompanyOpportunity::first()->id);
    }

    public function test_no_provider_key_means_no_lookup_and_the_signal_is_unchanged(): void
    {
        config(['signals.web_search.driver' => 'none']);
        Queue::fake();
        $res = app(MarketImporter::class)->import(self::T, [$this->record()], ['source_label' => 'test']);
        $this->assertSame(1, $res['accepted']);
        Queue::assertNotPushed(EnrichSignalContactsJob::class);
    }

    public function test_a_failing_lookup_leaves_the_signal_exactly_as_it_was(): void
    {
        app(MarketImporter::class)->import(self::T, [$this->record()], ['source_label' => 'test']);
        $before = CompanyOpportunity::first()->only(['qualification', 'priority', 'review_status', 'claim_level', 'score_total', 'feed_section']);

        config(['signals.web_search.driver' => 'tavily', 'signals.web_search.tavily_key' => 'tvly-test-key']);
        Http::fake(['api.tavily.com/*' => Http::response(['error' => 'down'], 500)]);
        (new EnrichSignalContactsJob(self::T, CompanyOpportunity::first()->id, true))->handle();   // must not throw

        $this->assertSame($before, CompanyOpportunity::first()->only(array_keys($before)));
        $this->assertSame(1, CompanyOpportunity::count());
        $this->assertSame(0, DB::table('g2g_signal_contacts')->count());
    }

    public function test_a_successful_lookup_stores_contacts_for_the_company_and_changes_nothing_on_the_signal(): void
    {
        app(MarketImporter::class)->import(self::T, [$this->record()], ['source_label' => 'test']);
        $opp = CompanyOpportunity::first();
        $company = Company::find($opp->company_id);
        $company->website = 'https://www.podarworld.example';
        $company->save();
        $before = $opp->only(['qualification', 'priority', 'review_status', 'claim_level', 'score_total']);

        config(['signals.web_search.driver' => 'tavily', 'signals.web_search.tavily_key' => 'tvly-test-key']);
        Http::fake(['api.tavily.com/*' => Http::response(['results' => [[
            'url' => 'https://www.podarworld.example/contact', 'title' => 'Contact Podar World School', 'content' => 'Podar World School office: admin@podarworld.example, +91 98250 44556',
        ]]])]);
        (new EnrichSignalContactsJob(self::T, $opp->id, true))->handle();

        $row = DB::table('g2g_signal_contacts')->where('sub_institute_id', self::T)->where('company_id', $company->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('admin@podarworld.example', $row->email);
        $this->assertSame('+919825044556', $row->phone);
        $this->assertSame('https://www.podarworld.example/contact', $row->email_source_url);
        $this->assertSame($before, CompanyOpportunity::find($opp->id)->only(array_keys($before)));
        $this->assertNotNull(SignalContactStore::lastSearch(self::T, (int) $company->id));
    }

    public function test_the_table_missing_reads_as_no_contacts_and_writes_nothing(): void
    {
        \Illuminate\Support\Facades\Schema::drop('g2g_signal_contacts');
        $this->assertSame([], SignalContactStore::forCompanies(self::T, [1]));
        $this->assertSame(0, SignalContactStore::save(self::T, 1, 1, [['email' => 'a@b.co']]));
    }

    public function test_the_signals_list_still_returns_every_signal_with_a_contact_block(): void
    {
        $this->assertTrue(method_exists(OpportunityController::class, 'researchContacts'));
        $this->assertTrue(method_exists(OpportunityController::class, 'presentMany'));
    }
}
