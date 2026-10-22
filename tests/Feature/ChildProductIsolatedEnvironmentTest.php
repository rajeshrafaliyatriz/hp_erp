<?php

namespace Tests\Feature;

use App\Domain\Portfolio\ProductOffer;
use App\Domain\Signals\Icp\IcpProfile;
use App\Domain\Signals\Opportunities\CompanyOpportunity;
use App\Domain\Signals\Opportunities\ProductProfile;
use App\Domain\Signals\Opportunities\ResearchRun;
use App\Domain\Signals\Opportunities\ResearchSource;
use App\Domain\Signals\Support\SafeUrlFetcher;
use App\Support\RoleKey;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The isolated, no-external-call rehearsal of a live ScholarClone run for the tenant that has the child products.
 *
 *  - It refuses to run unless the database is the throw-away in-memory sqlite one (phpunit.xml): a configured MySQL host is never touched.
 *  - Every HTTP request is mocked; a request to any host that is not mocked fails the test (Http::preventStrayRequests).
 *  - The data is synthetic and shaped like the live tenant: a seeded-template ICP copy, seven child products described with the portfolio's
 *    own taxonomy codes, and a second organisation with its own portfolio to prove nothing crosses over.
 */
class ChildProductIsolatedEnvironmentTest extends TestCase
{
    private const T = 6;      // the organisation under test
    private const OTHER = 7;  // a different organisation with its own portfolio

    private const PAGE = 'Greenfield International School in Surat is hiring a career counsellor and will start a Grade 9 career guidance programme this session.';

    protected function setUp(): void
    {
        parent::setUp();

        // FAIL CLOSED: nothing below may run against a real database.
        $this->assertSame('sqlite', config('database.default'), 'refusing to run: not the throw-away sqlite connection');
        $this->assertSame(':memory:', config('database.connections.sqlite.database'), 'refusing to run: not the in-memory database');
        $this->assertSame('array', config('cache.default'));
        $this->assertSame('sync', config('queue.default'));

        Schema::create('tbluserprofilemaster', function ($t) {
            $t->id();
            $t->string('name');
            $t->string('role_key')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->softDeletes();
        });
        Schema::create('tenant_setting', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->string('setting_key');
            $t->text('setting_value')->nullable();
            $t->timestamps();
        });
        Schema::create('tbluser', function ($t) {
            $t->id();
            $t->string('user_name')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->unsignedBigInteger('user_profile_id')->nullable();
            $t->integer('status')->default(1);
        });
        foreach ([
            '2026_09_30_150000_create_g2g_opportunity_and_ingestion_tables', '2026_09_30_180000_add_signal_fields_to_ingestion_findings',
            '2026_09_30_200000_add_research_sources_and_signal_kind', '2026_09_30_220000_create_g2g_portfolio_and_partners_tables',
            '2026_09_30_230000_add_claude_feed_and_linking_fields', '2026_10_01_130000_add_stage_to_g2g_research_runs',
            '2026_10_08_100000_extend_g2g_signals_for_market_import', '2026_11_29_090000_add_assessment_to_g2g_company_opportunities', '2026_12_01_090000_add_product_key_to_signals_runs_and_sources', '2026_10_14_100000_create_g2g_icp_profiles_tables', '2026_11_29_100000_add_date_provenance_to_g2g_research_sources', '2026_11_29_110000_add_modified_date_to_g2g_research_sources', '2026_10_13_100000_create_g2g_target_accounts_table',
        ] as $m) {
            (require base_path("database/migrations/{$m}.php"))->up();
        }

        RoleKey::flushCache();
        Cache::flush();
        Storage::fake('local');
        Carbon::setTestNow('2026-10-22 10:00:00');

        config([
            'ai.provider.gemini.api_key' => 'test-key-not-real', 'signals.ai_attempts' => 1,
            'signals.web_search.driver' => 'tavily', 'signals.web_search.tavily_key' => 'tvly-test-key-not-real',
            'signals.web_search.min_interval_ms' => 0, 'signals.icp.scholar_k12_tenants' => [self::T],
        ]);
        $this->app->instance(SafeUrlFetcher::class, new SafeUrlFetcher(fn () => ['93.184.216.34']));
        DB::table('tbluserprofilemaster')->insert([['id' => 1, 'name' => 'Administrator', 'role_key' => 'administrator']]);

        foreach ([self::T, self::OTHER] as $tenant) {
            ProductProfile::create([
                'sub_institute_id' => $tenant, 'product_name' => 'ScholarClone K-12 Bundle', 'description' => 'School ERP, LMS, teacher and career intelligence.',
                'problems_solved' => 'Disconnected school systems', 'features' => 'ERP, LMS, careers', 'target_industries' => ['Education'],
                'target_markets' => ['Gujarat'], 'keywords' => ['school erp'], 'excluded' => [], 'research_enabled' => true, 'research_frequency' => 'daily', 'recency_days' => 30,
            ]);
            // The live tenant's ICP is the seeded copy of the built-in template (version 1, never saved by a person).
            IcpProfile::create(['sub_institute_id' => $tenant, 'key' => 'scholar_k12', 'name' => 'ScholarClone K-12 Bundle', 'product_line' => 'scholar',
                'status' => 'active', 'is_default' => true, 'version' => 1, 'config' => config('signals_icp.scholar_k12')]);
        }

        // Seven child products under the parent, described the way the portfolio does (taxonomy codes + a little internal jargon in the features).
        $children = [
            ['SCH-01', 'Synthetic School ERP', ['N12', 'N13'], 'jargon module list one'],
            ['SCH-02', 'Synthetic Fees Desk', ['N13', 'N09'], 'jargon module list two'],
            ['SCH-03', 'Synthetic Learning Platform', ['N14'], 'jargon module list three'],
            ['SCH-04', 'Synthetic Career Counselling', ['N15'], 'JARGON-CAREER-MODULE-X1 + Y2 framework'],
            ['SCH-05', 'Synthetic Video Lessons', ['N14'], 'jargon module list five'],
            ['SCH-06', 'Synthetic Mastery Tracker', ['N18'], 'jargon module list six'],
            ['SCH-07', 'Synthetic Digital Office', ['N16'], 'jargon module list seven'],
        ];
        foreach ([self::T, self::OTHER] as $tenant) {
            foreach ($children as [$id, $name, $needs, $features]) {
                ProductOffer::create([
                    'sub_institute_id' => $tenant, 'offer_id' => $id . ($tenant === self::OTHER ? '-OTHER' : ''), 'name' => $name . ($tenant === self::OTHER ? ' OTHERORG' : ''),
                    'parent_product' => 'Scholar K-12', 'what_it_does' => $name . ' does its job for schools.', 'modules_components' => $features,
                    'needs_solved' => $needs, 'primary_segments' => ['K12-PVT', 'K12-TRUST'], 'trigger_signals' => ['S-SCH-NEW', 'S-SCH-SEEK'],
                    'readiness_status' => 'Live',
                ]);
            }
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function lead(): array
    {
        return [
            'company_name' => 'Greenfield International School', 'website' => null, 'industry' => null, 'location' => 'Surat', 'buyer_type' => 'school', 'context' => null,
            'trigger_type' => 'hiring',
            'needs_statement' => 'Greenfield International School is hiring a career counsellor and needs a career programme.',
            'closing_date' => null,
            'what_happened' => 'Greenfield International School in Surat is hiring a career counsellor. It will start a Grade 9 career guidance programme this session.',
            'trigger_summary' => 'A counsellor hire and a new Grade 9 programme show career guidance is being set up now.',
            'likely_problem' => 'A school that hires a counsellor has no career programme yet. The career counselling product gives it assessments and a counsellor view.',
            'what_we_could_sell' => 'Career counselling product with assessments for the new Grade 9 batch',
            'product_part' => 'sch-04', 'fit_reason' => 'The school is starting a Grade 9 career programme, which student career guidance serves.',
            'intent_indicators' => [['indicator' => 'hiring', 'quote' => 'is hiring a career counsellor']], 'sales_pull' => [],
            'entry_point' => 'discovery_meeting', 'entry_point_reason' => null,
            'soft_marketing_angle' => 'Ask for the principal. Open with the assessments for the new Grade 9 programme.',
            'likely_stakeholder' => null,
            'scores' => ['buying_signal' => 4, 'problem_fit' => 4, 'product_fit' => 4, 'accessibility' => 3, 'urgency' => 4, 'potential_value' => 3, 'evidence_quality' => 3, 'reasoning' => 'A counsellor hire.'],
            'inference_reasoning' => null, 'event_date' => null, 'source_ids' => ['S1'],
            'evidence_quote' => 'Greenfield International School in Surat is hiring a career counsellor and will start a Grade 9 career guidance programme',
        ];
    }

    private function fake(array $leads, string $url = 'https://news.example.com/greenfield-counsellor'): void
    {
        $this->app->forgetInstance(\Illuminate\Http\Client\Factory::class);
        Http::clearResolvedInstance(\Illuminate\Http\Client\Factory::class);
        Http::preventStrayRequests();
        Http::fake([
            'api.tavily.com/*' => Http::response(['results' => [['url' => $url, 'title' => 'Greenfield hires counsellor', 'content' => self::PAGE, 'published_date' => '2026-10-20']]], 200),
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode(['opportunities' => $leads])]]], 'finishReason' => 'STOP']],
                'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
            ], 200),
            'news.example.com/robots.txt' => Http::response('', 404),
            'news.example.com/*' => Http::response('<html><body><p>' . self::PAGE . '</p><p>The school said it will add staff across admissions and teaching for the new session.</p></body></html>', 200, ['Content-Type' => 'text/html']),
        ]);
    }

    /** @return list<Request> */
    private function requests(): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])->values()->all();
    }

    private function research(int $tenant = self::T): void
    {
        $this->artisan('signals:research', ['--tenant' => $tenant, '--trigger' => 'manual', '--product' => 'scholarclone'])->assertExitCode(0);
    }

    public function test_the_whole_discovery_flow_runs_on_synthetic_data_with_only_mocked_traffic_and_no_cross_tenant_effects(): void
    {
        $this->fake([$this->lead()]);
        Cache::flush();

        $this->research();

        // 1. Only the mocked hosts were contacted. Nothing real, nothing else.
        $hosts = collect($this->requests())->map(fn (Request $r) => parse_url($r->url(), PHP_URL_HOST))->unique()->values()->all();
        foreach ($hosts as $h) {
            $this->assertContains($h, ['api.tavily.com', 'generativelanguage.googleapis.com', 'news.example.com'], "unexpected host {$h}");
        }

        // 2. The searches: catalogue + child + named accounts, with child searches in buyer language (not internal feature names).
        $queries = collect($this->requests())->filter(fn (Request $r) => $r->url() === 'https://api.tavily.com/search')->map(fn (Request $r) => (string) $r->data()['query'])->values()->all();
        $child = array_values(array_filter($queries, fn ($q) => str_contains($q, 'RFP') || str_contains($q, 'private school')));
        $this->assertGreaterThanOrEqual(2, count($child), 'child-product searches were sent: ' . json_encode($queries));
        $joined = implode(' | ', $queries);
        $this->assertStringNotContainsString('JARGON-CAREER-MODULE', $joined);
        $this->assertStringNotContainsString('OTHERORG', $joined);

        // 3. Everything written belongs to the organisation under test; the other organisation has nothing.
        $this->assertSame([self::T], ResearchRun::pluck('sub_institute_id')->unique()->values()->all());
        $this->assertSame(0, CompanyOpportunity::where('sub_institute_id', self::OTHER)->count());
        $this->assertSame(0, ResearchRun::where('sub_institute_id', self::OTHER)->count());
        $this->assertSame(0, DB::table('g2g_opportunity_matches')->where('sub_institute_id', self::OTHER)->count());
        $this->assertStringNotContainsString('OTHERORG', implode(' ', collect($this->requests())->map(fn (Request $r) => json_encode($r->data()))->all()));

        // 4. The signal: sourced, dated, tied to the child product, and matched to it.
        $opp = CompanyOpportunity::sole();
        $this->assertSame(self::T, (int) $opp->sub_institute_id);
        $this->assertSame('https://news.example.com/greenfield-counsellor', $opp->source_url);
        $this->assertSame('2026-10-20', $opp->source_published_at->toDateString());
        $fit = $opp->assessment['product_fit'];
        $this->assertSame(['child_product', 'Synthetic Career Counselling', 'Scholar K-12'], [$fit['kind'], $fit['name'], $fit['parent_product']]);
        $match = DB::table('g2g_opportunity_matches')->where('signal_id', $opp->id)->orderByDesc('match_score')->first();
        $this->assertSame('SCH-04', $match->offer_id);
        $this->assertTrue((bool) ResearchSource::where('research_run_id', $opp->research_run_id)->where('cited', true)->exists());

        // 5. The run row tells the story, and the day is listed for the date picker.
        $run = ResearchRun::sole();
        $this->assertSame(['scholarclone', 'success'], [$run->product_key, $run->status]);
        $this->assertSame(1, (int) $run->opportunities_new);
        $this->assertSame('2026-10-22', $run->report_date->toDateString());
    }

    public function test_a_second_run_the_next_day_is_unchanged_not_new_and_the_original_day_keeps_the_signal(): void
    {
        $this->fake([$this->lead()]);
        $this->research();
        $first = CompanyOpportunity::sole();

        Carbon::setTestNow('2026-10-23 10:00:00');
        Cache::flush();
        $this->fake([$this->lead()]);
        $this->research();

        $this->assertSame(1, CompanyOpportunity::count());
        $second = ResearchRun::orderByDesc('id')->first();
        $this->assertSame([0, 1], [(int) $second->opportunities_new, (int) $second->opportunities_qualified]);
        $this->assertSame(ResearchRun::orderBy('id')->first()->id, (int) $first->refresh()->research_run_id, 'the signal stays on 22 Oct, so 23 Oct is an honest empty day (the UI then shows the labelled fallback)');
    }

    public function test_the_same_event_found_for_two_organisations_stays_two_separate_records_each_matched_to_its_own_products(): void
    {
        $this->fake([$this->lead()]);
        $this->research(self::T);
        $other = array_replace($this->lead(), ['product_part' => 'sch-04-other']);
        Cache::flush();
        $this->fake([$other]);
        $this->research(self::OTHER);

        $this->assertSame(2, CompanyOpportunity::count(), 'one record per organisation, never merged');
        $mine = CompanyOpportunity::where('sub_institute_id', self::T)->sole();
        $theirs = CompanyOpportunity::where('sub_institute_id', self::OTHER)->sole();
        $this->assertNotSame($mine->fingerprint, $theirs->fingerprint);
        $this->assertNotSame($mine->company_id, $theirs->company_id, 'each has its own company row');
        $this->assertSame([self::T, self::OTHER], [(int) DB::table('g2g_companies')->where('id', $mine->company_id)->value('sub_institute_id'), (int) DB::table('g2g_companies')->where('id', $theirs->company_id)->value('sub_institute_id')]);

        // Each signal is matched only to its own organisation's products.
        $this->assertSame(['SCH-04'], DB::table('g2g_opportunity_matches')->where('signal_id', $mine->id)->where('match_score', '>=', 95)->pluck('offer_id')->all());
        $this->assertSame(['SCH-04-OTHER'], DB::table('g2g_opportunity_matches')->where('signal_id', $theirs->id)->where('match_score', '>=', 95)->pluck('offer_id')->all());
        $this->assertSame(0, DB::table('g2g_opportunity_matches')->where('sub_institute_id', self::T)->where('offer_id', 'like', '%OTHER')->count());
        $this->assertSame(0, DB::table('g2g_opportunity_matches')->where('sub_institute_id', self::OTHER)->whereRaw("offer_id not like '%OTHER'")->count());

        // Runs, sources and rejections are per organisation too.
        $this->assertSame(1, ResearchRun::where('sub_institute_id', self::T)->count());
        $this->assertSame(1, ResearchRun::where('sub_institute_id', self::OTHER)->count());
        $this->assertSame(0, ResearchSource::where('sub_institute_id', self::T)->whereIn('research_run_id', ResearchRun::where('sub_institute_id', self::OTHER)->pluck('id'))->count());

        // A rediscovery for one organisation never touches the other's record.
        $before = $theirs->fresh()->only(['updated_at', 'first_discovered_at', 'research_run_id']);
        Carbon::setTestNow('2026-10-23 10:00:00');
        Cache::flush();
        $this->fake([$this->lead()]);
        $this->research(self::T);
        $this->assertEquals($before, $theirs->fresh()->only(['updated_at', 'first_discovered_at', 'research_run_id']));
        $this->assertSame(2, CompanyOpportunity::count());
    }
}
