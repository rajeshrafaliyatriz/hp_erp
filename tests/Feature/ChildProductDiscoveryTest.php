<?php

namespace Tests\Feature;

use App\Domain\Portfolio\ProductOffer;
use App\Domain\Signals\Icp\IcpPack;
use App\Domain\Signals\Icp\IcpResearchPlanner;
use App\Domain\Signals\Opportunities\CompanyOpportunity;
use App\Domain\Signals\Opportunities\ProductProfile;
use App\Domain\Signals\Opportunities\ResearchRun;
use App\Domain\Signals\Opportunities\ResearchSource;
use App\Domain\Signals\Products\ProductCatalog;
use App\Domain\Signals\Products\ProductQueryBuilder;
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
 * A child product added under a parent product must reach the whole research pipeline: its stored details write searches, a signal can be
 * built on it, and a repeated run or a second need of the same company is judged as the event it is. All web and AI traffic is mocked (no
 * provider quota is used); runs on the in-memory sqlite database.
 */
class ChildProductDiscoveryTest extends TestCase
{
    private const T = 1;
    private const T2 = 2;

    private const PAGE = 'Podar World School in Vadodara is hiring a career counsellor to start a Grade 9 career guidance programme this session.';

    protected function setUp(): void
    {
        parent::setUp();

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
        Carbon::setTestNow('2026-10-20 10:00:00');

        config([
            'ai.provider.gemini.api_key' => 'test-key', 'signals.ai_attempts' => 1,
            'signals.web_search.driver' => 'tavily', 'signals.web_search.tavily_key' => 'tvly-test-key',
            'signals.web_search.min_interval_ms' => 0, 'signals.icp.scholar_k12_tenants' => [self::T],
        ]);
        $this->app->instance(SafeUrlFetcher::class, new SafeUrlFetcher(fn () => ['93.184.216.34']));
        DB::table('tbluserprofilemaster')->insert([['id' => 1, 'name' => 'Administrator', 'role_key' => 'administrator']]);

        ProductProfile::create([
            'sub_institute_id' => self::T, 'product_name' => 'ScholarClone K-12 Bundle', 'description' => 'School ERP, LMS, teacher and career intelligence.',
            'problems_solved' => 'Disconnected school systems', 'features' => 'ERP, LMS, competency, careers', 'target_industries' => ['Education'],
            'target_markets' => ['Gujarat'], 'keywords' => ['school erp'], 'excluded' => [], 'research_enabled' => true, 'research_frequency' => 'daily', 'recency_days' => 30,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** A child product a person added under the ScholarClone parent. */
    private function child(string $id = 'SCH-CAREER', array $o = [], int $tenant = self::T, string $parent = 'Scholar K-12'): ProductOffer
    {
        return ProductOffer::create($o + [
            'sub_institute_id' => $tenant, 'offer_id' => $id, 'name' => 'Career Guidance Programme', 'parent_product' => $parent,
            'what_it_does' => 'Career assessments and counselling for students from Grade 6.',
            'modules_components' => 'aptitude assessment, counsellor dashboard, parent reports',
            'needs_solved' => ['No career programme for students'], 'primary_segments' => ['Private school groups'],
            'trigger_signals' => ['Hiring a career counsellor'], 'readiness_status' => 'Live',
        ]);
    }

    private function fees(): ProductOffer
    {
        return $this->child('SCH-FEES', ['name' => 'Fee Collection Desk', 'what_it_does' => 'Online fee receipts and defaulter follow-up for school accounts offices.', 'modules_components' => 'online fee receipts, defaulter follow-up', 'needs_solved' => ['Late fee collection'], 'trigger_signals' => []]);
    }

    private function icp(): IcpPack
    {
        return (new ProductCatalog())->resolve(self::T, 'scholarclone')->pack;
    }

    private function lead(array $o = []): array
    {
        return $o + [
            'company_name' => 'Podar World School', 'website' => null, 'industry' => null, 'location' => 'Vadodara', 'buyer_type' => 'school', 'context' => null,
            'trigger_type' => 'hiring',
            'needs_statement' => 'Podar World School is hiring a career counsellor and needs a career programme.',
            'closing_date' => null,
            'what_happened' => 'Podar World School in Vadodara is hiring a career counsellor. It plans a Grade 9 career guidance programme this session.',
            'trigger_summary' => 'A counsellor hire and a new Grade 9 programme show career guidance is being set up now.',
            'likely_problem' => 'A school that hires a counsellor has no career programme yet. The Career Guidance Programme gives it assessments and a counsellor view.',
            'what_we_could_sell' => 'Career Guidance Programme with aptitude assessments for the new Grade 9 batch',
            'product_part' => 'sch-career', 'fit_reason' => 'The school is starting a Grade 9 career programme, which the aptitude assessments serve.',
            'intent_indicators' => [['indicator' => 'hiring', 'quote' => 'is hiring a career counsellor']], 'sales_pull' => [],
            'entry_point' => 'discovery_meeting', 'entry_point_reason' => null,
            'soft_marketing_angle' => 'Ask for the principal. Open with the aptitude assessments for the new Grade 9 programme.',
            'likely_stakeholder' => null,
            'scores' => ['buying_signal' => 4, 'problem_fit' => 4, 'product_fit' => 4, 'accessibility' => 3, 'urgency' => 4, 'potential_value' => 3, 'evidence_quality' => 3, 'reasoning' => 'A counsellor hire.'],
            'inference_reasoning' => null, 'event_date' => null, 'source_ids' => ['S1'],
            'evidence_quote' => 'Podar World School in Vadodara is hiring a career counsellor to start a Grade 9 career guidance programme',
        ];
    }

    private function fake(array $leads, string $content = self::PAGE, string $url = 'https://news.example.com/podar-counsellor', ?string $published = '2026-10-15'): void
    {
        $this->app->forgetInstance(\Illuminate\Http\Client\Factory::class);
        Http::clearResolvedInstance(\Illuminate\Http\Client\Factory::class);
        Http::fake([
            'api.tavily.com/*' => Http::response(['results' => [['url' => $url, 'title' => 'Podar hires counsellor', 'content' => $content, 'published_date' => $published]]], 200),
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode(['opportunities' => $leads])]]], 'finishReason' => 'STOP']],
                'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
            ], 200),
            '*/robots.txt' => Http::response('', 404),
            '*' => Http::response('<html><body><p>' . $content . '</p><p>The school said it will add staff across admissions, accounts and teaching for the new session.</p></body></html>', 200, ['Content-Type' => 'text/html']),
        ]);
    }

    /** @return list<string> the searches sent to Tavily */
    private function searches(): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $r) => str_contains($r->url(), 'api.tavily.com'))->map(fn (Request $r) => (string) $r->data()['query'])->values()->all();
    }

    /** @return list<string> */
    private function aiPrompts(): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $r) => str_contains($r->url(), 'generativelanguage.googleapis.com'))->map(fn (Request $r) => json_encode($r->data()))->values()->all();
    }

    private function research(): void
    {
        $this->artisan('signals:research', ['--tenant' => self::T, '--trigger' => 'manual', '--product' => 'scholarclone'])->assertExitCode(0);
    }

    private function plan(?string $product = 'scholarclone'): array
    {
        return (new IcpResearchPlanner())->plan(self::T, $this->icp(), null, $product);
    }

    private function nextDay(): void
    {
        Carbon::setTestNow('2026-10-21 10:00:00');
        Cache::flush();
    }

    // ── parent and child product matching ────────────────────────────────────

    public function test_a_child_added_under_the_parent_is_one_of_the_parts_a_signal_can_be_built_on(): void
    {
        $ctx = (new ProductCatalog())->resolve(self::T, 'scholarclone');
        $this->assertArrayNotHasKey('sch-career', $ctx->parts, 'no child yet');

        $this->child();
        $ctx = (new ProductCatalog())->resolve(self::T, 'scholarclone');

        $this->assertArrayHasKey('sch-career', $ctx->parts);
        $this->assertTrue($ctx->parts['sch-career']['child_product']);
        $this->assertSame('Scholar K-12', $ctx->parts['sch-career']['parent_product']);
        $this->assertArrayHasKey('school_erp', $ctx->parts, 'the parent\'s own parts are kept');
        $this->assertSame('SCH-CAREER', $ctx->children[0]['id'], 'and the prompt still lists it in full');
    }

    // ── product details influence query generation ───────────────────────────

    public function test_a_child_product_writes_searches_from_its_stored_details(): void
    {
        $this->child();

        $plan = $this->plan();
        $child = collect($plan['specs'])->filter(fn ($s) => str_starts_with((string) ($s['id'] ?? ''), 'child-sch-career'))->values();

        $this->assertNotEmpty($child, 'the child product adds searches');
        $all = mb_strtolower(implode(' | ', $child->pluck('q')->all()));
        $this->assertStringContainsString('career programme for students', $all, 'the problem it solves');
        $this->assertStringContainsString('gujarat', $all, 'the ICP\'s region');
        foreach ($child as $s) {
            $this->assertSame(0, substr_count($s['q'], '"') % 2, 'quotes are balanced');
            $this->assertStringNotContainsString('site:', $s['q']);
            $this->assertLessThanOrEqual(220, mb_strlen($s['q']));
        }
        $this->assertNotEmpty($plan['child_searches']);

        $every = mb_strtolower(implode(' | ', array_column((new ProductQueryBuilder())->forOffer($this->child_offer(), $this->icp()), 'q')));
        $this->assertStringNotContainsString('aptitude assessment', $every, 'internal feature names are not search words while a buyer-language problem is stored');
        $this->assertStringNotContainsString('/', $every, 'no stray separators');
        $this->assertStringContainsString('private school groups', $every, 'who it is sold to');
        $this->assertStringContainsString('tender', $every);
        $this->assertStringContainsString('hiring', $every);
    }

    private function child_offer(): ProductOffer
    {
        return ProductOffer::where('offer_id', 'SCH-CAREER')->first();
    }

    public function test_different_stored_details_give_different_searches_and_no_details_give_none(): void
    {
        $this->child();
        $this->fees();
        $this->child('SCH-EMPTY', ['name' => '', 'what_it_does' => '', 'modules_components' => null, 'needs_solved' => [], 'primary_segments' => [], 'trigger_signals' => []]);

        $specs = (new ProductQueryBuilder())->forOffers((new ProductCatalog())->researchOffers(self::T, 'scholarclone'), $this->icp());
        $byChild = collect($specs)->groupBy('child_offer_id');

        $this->assertEqualsCanonicalizing(['SCH-CAREER', 'SCH-FEES'], $byChild->keys()->all(), 'a product with nothing stored is not searched by its name alone');
        $this->assertStringContainsString('late fee collection', mb_strtolower(implode(' ', $byChild['SCH-FEES']->pluck('q')->all())));
        $this->assertStringNotContainsString('late fee collection', mb_strtolower(implode(' ', $byChild['SCH-CAREER']->pluck('q')->all())));
        $this->assertGreaterThan(1, $byChild['SCH-CAREER']->pluck('source')->unique()->count(), 'tender, hiring, news and concept evidence are all covered');
    }

    public function test_adding_a_child_changes_the_plan_and_the_switch_turns_it_off(): void
    {
        $before = array_column($this->plan()['specs'], 'q');
        $this->child();
        $after = array_column($this->plan()['specs'], 'q');

        $this->assertCount(2, array_diff($after, $before), 'two new child searches (signals.discovery.child_product_queries)');
        $this->assertSame([], array_diff($before, $after), 'nothing the plan searched before is dropped');

        config(['signals.discovery.child_product_queries' => 0]);
        $this->assertSame($before, array_column($this->plan()['specs'], 'q'), 'switched off, the plan is exactly the earlier one');
    }

    public function test_the_preview_without_a_product_adds_nothing(): void
    {
        $this->child();
        $this->assertSame([], $this->plan(null)['child_searches']);
    }

    // ── repeated runs discover new searches ──────────────────────────────────

    public function test_repeated_runs_on_one_day_search_different_child_queries_until_every_one_was_covered(): void
    {
        $this->child();
        $this->fees();
        $total = count((new ProductQueryBuilder())->forOffers((new ProductCatalog())->researchOffers(self::T, 'scholarclone'), $this->icp()));
        $this->assertGreaterThanOrEqual(8, $total);

        $seen = [];
        for ($i = 0; $i < 4; $i++) {
            foreach ($this->plan()['child_searches'] as $c) {
                $seen[$c['id']] = true;
            }
            // a finished run today moves the rotation on (as the researcher does)
            ResearchRun::create(['sub_institute_id' => self::T, 'report_date' => '2026-10-20', 'trigger' => 'manual', 'status' => 'success', 'product_key' => 'scholarclone', 'started_at' => now()]);
        }

        $this->assertCount(min($total, 8), $seen, 'four runs of two searches never repeat a child search');
        $this->assertGreaterThan(1, collect(array_keys($seen))->map(fn ($id) => explode('-', $id)[1] . '-' . explode('-', $id)[2])->unique()->count(), 'both children are searched');
    }

    // ── a signal for the child product, end to end (mocked providers) ───────

    public function test_a_run_searches_for_the_child_and_stores_a_signal_built_on_it_with_its_evidence(): void
    {
        $this->child();
        $this->fake([$this->lead()]);

        $this->research();

        $this->assertNotEmpty(array_filter($this->searches(), fn ($q) => str_contains(mb_strtolower($q), 'career programme for students')), 'a child-product search was sent');
        $prompt = implode(' ', $this->aiPrompts());
        $this->assertStringContainsString('SCH-CAREER', $prompt, 'the model is shown the child in full');
        $this->assertStringContainsString('sch-career', $prompt, 'and may name it as the part');

        $opp = CompanyOpportunity::sole();
        $this->assertSame('Podar World School', $opp->company->name);
        $this->assertSame('scholar', $opp->business_fit);
        $fit = $opp->assessment['product_fit'];
        $this->assertSame('child_product', $fit['kind']);
        $this->assertSame('Career Guidance Programme', $fit['name']);
        $this->assertSame('Scholar K-12', $fit['parent_product']);
        $this->assertSame(['No career programme for students'], $fit['solves']);
        $this->assertStringContainsString('aptitude', $fit['reason']);

        // evidence and source URL persisted, and the page is on the run's audit list
        $this->assertSame('https://news.example.com/podar-counsellor', $opp->source_url);
        $this->assertStringContainsString('hiring a career counsellor', $opp->sources[0]['excerpt']);
        $this->assertTrue((bool) ResearchSource::where('url', 'https://news.example.com/podar-counsellor')->value('cited'));

        // the named child is the explicit, top match, with its reason
        $match = DB::table('g2g_opportunity_matches')->where('signal_id', $opp->id)->orderByDesc('match_score')->first();
        $this->assertSame('SCH-CAREER', $match->offer_id);
        $this->assertGreaterThanOrEqual(95, (int) $match->match_score);
        $this->assertStringContainsString('explicitly matched', $match->rationale);
    }

    public function test_a_snippet_only_match_is_marked_as_needing_further_research(): void
    {
        $this->child();
        $this->fake([$this->lead()]);
        config(['signals.opportunities.fetch_pages' => 0]);

        $this->research();

        $this->assertTrue(CompanyOpportunity::sole()->assessment['product_fit']['needs_further_research']);
    }

    // ── qualification rules are not weakened ─────────────────────────────────

    public function test_a_part_that_is_not_one_of_the_products_is_still_rejected(): void
    {
        $this->child();
        $this->fake([$this->lead(['product_part' => 'warp_drive'])]);

        $this->research();

        $this->assertSame(0, CompanyOpportunity::count());
        $this->assertSame(1, (int) ResearchRun::sole()->opportunities_rejected);
    }

    public function test_a_child_signal_without_a_verbatim_quote_is_still_rejected(): void
    {
        $this->child();
        $this->fake([$this->lead(['evidence_quote' => 'Podar World School has signed a three year contract with a rival vendor'])]);

        $this->research();

        $this->assertSame(0, CompanyOpportunity::count());
    }

    // ── tenant isolation ─────────────────────────────────────────────────────

    public function test_another_organisations_child_product_is_never_searched_or_offered(): void
    {
        $this->child('SCH-OTHER-TENANT', ['name' => 'Rival Tenant Secret Module', 'modules_components' => 'secret rival feature', 'needs_solved' => ['A rival tenant need']], self::T2);

        $this->assertSame([], $this->plan()['child_searches']);
        $this->assertArrayNotHasKey('sch-other-tenant', (new ProductCatalog())->resolve(self::T, 'scholarclone')->parts);

        $this->fake([]);
        $this->research();
        $this->assertStringNotContainsString('secret rival', mb_strtolower(implode(' ', $this->searches())));
        $this->assertStringNotContainsString('Rival Tenant', implode(' ', $this->aiPrompts()));
    }

    public function test_a_child_of_another_product_is_not_searched_for_this_one(): void
    {
        $this->child('EB-X', ['name' => 'Plant Memory', 'modules_components' => 'plant knowledge base'], self::T, 'EB');

        $this->assertSame([], $this->plan()['child_searches']);
    }

    // ── repeated runs, duplicates and genuinely new events ───────────────────

    public function test_the_same_event_found_again_on_another_run_is_not_a_new_signal(): void
    {
        $this->child();
        $this->fake([$this->lead()]);
        $this->research();
        $first = CompanyOpportunity::sole();

        $this->nextDay();
        $this->fake([$this->lead()]);
        $this->research();

        $this->assertSame(1, CompanyOpportunity::count());
        $this->assertSame($first->id, CompanyOpportunity::sole()->id);
        $this->assertSame(2, ResearchRun::count(), 'the second run is on record');
        $second = ResearchRun::orderByDesc('id')->first();
        $this->assertSame(0, (int) $second->opportunities_new);
        $this->assertSame(1, (int) $second->opportunities_qualified, 'and says what it re-found');
    }

    public function test_a_second_need_of_a_company_that_already_has_a_signal_is_a_new_signal(): void
    {
        $this->child();
        $this->fees();
        $this->fake([$this->lead()], self::PAGE, 'https://news.example.com/podar-counsellor', null);
        $this->research();

        // the same school, the same kind of event (hiring), the same site, both undated - but a different page about a different need
        $page = 'Podar World School in Vadodara is hiring a fee accountant to run online fee collection and receipts for all its branches.';
        $this->nextDay();
        $this->fake([$this->lead([
            'product_part' => 'sch-fees', 'needs_statement' => 'Podar World School is hiring a fee accountant and needs online fee collection.',
            'what_happened' => 'Podar World School in Vadodara is hiring a fee accountant. The post covers online fee collection and receipts for all branches.',
            'trigger_summary' => 'An accountant hire for online fees shows fee collection is being rebuilt.',
            'likely_problem' => 'A school that hires for fee collection is fixing it now. The Fee Collection Desk covers online receipts.',
            'what_we_could_sell' => 'Fee Collection Desk with online receipts for all Podar branches', 'fit_reason' => 'The post is about online fee collection and receipts, which the Fee Collection Desk covers.',
            'intent_indicators' => [['indicator' => 'hiring', 'quote' => 'is hiring a fee accountant']],
            'evidence_quote' => 'Podar World School in Vadodara is hiring a fee accountant to run online fee collection and receipts',
        ])], $page, 'https://news.example.com/podar-fees', null);
        $this->research();

        $this->assertSame(2, CompanyOpportunity::count(), 'the second need is not swallowed by the first');
        $this->assertEqualsCanonicalizing(['sch-career', 'sch-fees'], CompanyOpportunity::all()->map(fn ($o) => $o->assessment['product_fit']['part'])->all());
        $this->assertSame(1, CompanyOpportunity::distinct()->count('company_id'), 'both belong to one company');
    }

    public function test_the_same_story_on_a_second_site_with_another_part_chosen_is_still_one_event(): void
    {
        $this->child();
        $this->fees();
        $this->fake([$this->lead()], self::PAGE, 'https://news.example.com/podar-counsellor', null);
        $this->research();

        $this->nextDay();
        // the model names a general part for the SAME story, found on another site
        $this->fake([$this->lead(['product_part' => 'school_erp'])], self::PAGE, 'https://other.example.org/podar-counsellor-story', null);
        $this->research();

        $this->assertSame(1, CompanyOpportunity::count(), 'described in the same words: one event, however the part was named');
        $this->assertCount(2, CompanyOpportunity::sole()->sources, 'and the second site is kept as a source');
    }

    public function test_an_older_record_without_a_part_is_judged_the_same_way_as_before(): void
    {
        // Records stored before product parts were kept carry none: nothing says they are another need, so they dedup exactly as before.
        $this->child();
        $this->fake([$this->lead()], self::PAGE, 'https://news.example.com/podar-counsellor', null);
        $this->research();
        $opp = CompanyOpportunity::sole();
        $assessment = $opp->assessment;
        unset($assessment['product_fit']);
        $opp->forceFill(['assessment' => $assessment])->save();

        $this->nextDay();
        $this->fake([$this->lead()], self::PAGE, 'https://other.example.org/podar-counsellor-story', null);
        $this->research();

        $this->assertSame(1, CompanyOpportunity::count());
    }

    // ── history and date-wise display keep working ───────────────────────────

    public function test_each_day_keeps_its_own_signals_and_a_rerun_day_keeps_the_original_ones(): void
    {
        $this->child();
        $this->fake([$this->lead()]);
        $this->research();
        $opp = CompanyOpportunity::sole();
        $day1 = ResearchRun::sole();
        $this->assertSame($day1->id, (int) $opp->research_run_id);

        $this->nextDay();
        $this->fake([$this->lead()]);
        $this->research();

        $this->assertSame($day1->id, (int) $opp->refresh()->research_run_id, 'the signal stays on the day it was first found');
        $this->assertSame(2, ResearchRun::whereIn('status', ['success', 'partial'])->count(), 'both days are in the research history');
        $this->assertSame('2026-10-21', ResearchRun::orderByDesc('id')->first()->report_date->toDateString());
    }

    // ── rotation: fair across children, varied in kind (no web requests) ────

    private function sevenChildren(): void
    {
        foreach (range(1, 7) as $i) {
            $this->child('SCH-0' . $i, ['name' => 'Product number ' . $i, 'what_it_does' => 'Does job number ' . $i . ' for schools.', 'modules_components' => 'internal module ' . $i,
                'needs_solved' => ['Problem number ' . $i . ' in the school office'], 'trigger_signals' => ['S-SCH-NEW']]);
        }
    }

    public function test_rotation_covers_every_child_under_every_kind_exactly_once_and_never_fills_a_run_with_one_kind(): void
    {
        $this->sevenChildren();
        $builder = new ProductQueryBuilder();
        $all = $builder->forOffers((new ProductCatalog())->researchOffers(self::T, 'scholarclone'), $this->icp());

        $this->assertCount(42, $all, '7 children x 6 kinds of evidence');
        $this->assertCount(42, array_unique(array_column($all, 'id')), 'no search is listed twice');
        $this->assertSame($all, $builder->forOffers((new ProductCatalog())->researchOffers(self::T, 'scholarclone'), $this->icp()), 'deterministic');

        $seen = [];
        $base = Carbon::parse('2026-03-01');
        for ($run = 0; $run < 21; $run++) {
            $window = $builder->window($all, $base->copy()->addDays($run), 2);
            $this->assertCount(2, $window);
            $this->assertNotSame($window[0]['facet'], $window[1]['facet'], "run {$run}: two slots, two different kinds of evidence");
            $this->assertNotSame($window[0]['child_offer_id'], $window[1]['child_offer_id'], "run {$run}: two slots, two different products");
            foreach ($window as $w) {
                $seen[$w['id']] = $w;
            }
        }
        $this->assertCount(42, $seen, '21 runs of 2 searches cover all 42 combinations once');
        $perChild = array_count_values(array_column($seen, 'child_offer_id'));
        $this->assertSame([6], array_values(array_unique($perChild)), 'every child is searched equally often');
        $this->assertCount(7, $perChild);
        $perKind = array_count_values(array_column($seen, 'facet'));
        $this->assertSame([7], array_values(array_unique($perKind)), 'and so is every kind of evidence');
    }

    public function test_a_child_without_a_stored_event_still_gets_its_other_kinds_and_loses_nothing_else(): void
    {
        $this->child();
        $this->child('SCH-NOEVENT', ['name' => 'Plain Product', 'what_it_does' => 'Plain job.', 'needs_solved' => ['Plain office problem'], 'trigger_signals' => []]);
        $all = (new ProductQueryBuilder())->forOffers((new ProductCatalog())->researchOffers(self::T, 'scholarclone'), $this->icp());

        $this->assertCount(11, $all);
        $this->assertCount(11, array_unique(array_column($all, 'id')));
        $this->assertSame(5, count(array_filter($all, fn ($s) => $s['child_offer_id'] === 'SCH-NOEVENT')));
    }

    public function test_the_planner_varies_children_and_kinds_from_one_run_to_the_next(): void
    {
        $this->sevenChildren();
        $kinds = [];
        $children = [];
        for ($i = 0; $i < 6; $i++) {
            $slots = $this->plan()['child_searches'];
            $this->assertCount(2, $slots);
            $this->assertNotSame($slots[0]['kind'], $slots[1]['kind'], 'two different kinds in one run');
            foreach ($slots as $c) {
                $kinds[$c['kind']] = true;
                $children[$c['product']] = true;
            }
            ResearchRun::create(['sub_institute_id' => self::T, 'report_date' => '2026-10-20', 'trigger' => 'manual', 'status' => 'success', 'product_key' => 'scholarclone', 'started_at' => now()]);
        }
        $this->assertGreaterThanOrEqual(5, count($kinds), 'six runs touch most kinds of evidence');
        $this->assertGreaterThanOrEqual(6, count($children), 'and nearly every one of the seven products');
    }

    // ── evidence classes ─────────────────────────────────────────────────────

    /** @return array{class: string, status: string} */
    private function classify(string $level, bool $pageRead, bool $reasoned, bool $dated, string $reliability): array
    {
        $m = new \ReflectionMethod(\App\Domain\Signals\Opportunities\OpportunityResearcher::class, 'evidenceClass');
        $m->setAccessible(true);

        return $m->invoke(app(\App\Domain\Signals\Opportunities\OpportunityResearcher::class), $level, $pageRead, $reasoned, $dated, $reliability);
    }

    public function test_the_evidence_class_follows_what_was_verified_and_never_flatters_it(): void
    {
        // [level, page read, reasoned by the model, dated, source reliability] => class
        $cases = [
            'purchase step, page read' => [['explicit', true, false, true, 'high'], 'direct_evidence', 'verified'],
            'purchase step, snippet only' => [['explicit', false, false, true, 'high'], 'strong_indicator', 'reviewable'],
            'purchase step, but reasoned by the model' => [['explicit', true, true, true, 'high'], 'strong_indicator', 'reviewable'],
            'documented project' => [['strong', true, false, true, 'low'], 'strong_indicator', 'reviewable'],
            'one project indicator, weak source' => [['moderate', true, false, true, 'low'], 'weak_indicator', 'reviewable'],
            'one project indicator, official page read' => [['moderate', true, false, true, 'high'], 'strong_indicator', 'reviewable'],
            'reasoned need at moderate level' => [['moderate', true, true, true, 'high'], 'weak_indicator', 'reviewable'],
            'context event only' => [['weak', true, false, true, 'medium'], 'weak_indicator', 'reviewable'],
            'context event, undated snippet' => [['weak', false, false, false, 'low'], 'needs_further_research', 'needs_further_research'],
            'nothing verified shows a need' => [['unconfirmed', true, false, true, 'high'], 'needs_further_research', 'needs_further_research'],
        ];
        foreach ($cases as $name => [$args, $class, $status]) {
            $this->assertSame(['class' => $class, 'status' => $status], $this->classify(...$args), $name);
        }
    }

    public function test_a_stored_signal_carries_its_class_its_gaps_and_the_public_fact_apart_from_the_interpretation(): void
    {
        $this->child();
        $this->fake([$this->lead()]);
        $this->research();

        $a = CompanyOpportunity::sole()->assessment;
        $this->assertContains($a['evidence_class'], ['strong_indicator', 'weak_indicator', 'needs_further_research'], 'a job post is never direct evidence of intent');
        $this->assertContains($a['research_status'], ['reviewable', 'needs_further_research']);
        $this->assertIsArray($a['missing_evidence']);
        $this->assertContains('Only one source reports this.', $a['missing_evidence'], 'what is not established is said');

        $fact = $a['provenance']['public_fact'];
        $this->assertSame('Podar World School in Vadodara is hiring a career counsellor to start a Grade 9 career guidance programme', $fact['quote']);
        $this->assertSame('https://news.example.com/podar-counsellor', $fact['source_url']);
        $this->assertSame('Podar hires counsellor', $fact['source_title']);
        $this->assertNotEmpty($a['provenance']['interpretation']['why_a_need']);
        $this->assertNotEmpty($a['provenance']['interpretation']['product_fit']);
        $this->assertStringNotContainsString($a['provenance']['interpretation']['why_a_need'], $fact['quote'], 'the system\'s reading is not mixed into the quote');
    }

    public function test_a_candidate_with_no_verified_indicator_is_never_presented_as_confirmed(): void
    {
        $this->child();
        $this->fake([$this->lead(['intent_indicators' => []])]);
        $this->research();

        $opps = CompanyOpportunity::all();
        $this->assertLessThanOrEqual(1, $opps->count());
        foreach ($opps as $o) {
            $this->assertSame('needs_further_research', $o->assessment['evidence_class']);
            $this->assertNotSame('confirmed', $o->claim_level);
            $this->assertTrue($o->assessment['product_fit']['needs_further_research']);
        }
    }

    // ── product-fit validation ───────────────────────────────────────────────

    public function test_a_child_product_the_evidence_does_not_support_is_rejected_with_a_recorded_reason(): void
    {
        $this->child();
        $this->fees();
        // The evidence is about a career counsellor; the model names the FEES product.
        $this->fake([$this->lead(['product_part' => 'sch-fees', 'fit_reason' => 'The school will need to collect money from parents for the programme.'])]);

        $this->research();

        $this->assertSame(0, CompanyOpportunity::count(), 'an unrelated child is not matched merely because the model named it');
        $row = DB::table('g2g_signal_import_rejections')->where('reason_code', 'product_fit_unsupported')->first();
        $this->assertNotNull($row, 'the reason is recorded');
        $this->assertSame('rejected', json_decode($row->payload, true)['evidence_class']);
        $this->assertSame(self::T, (int) DB::table('g2g_signal_scan_log')->where('id', $row->scan_log_id)->value('sub_institute_id'));
    }

    public function test_the_child_the_evidence_does_support_is_accepted_beside_an_unrelated_one(): void
    {
        $this->child();
        $this->fees();
        $this->fake([$this->lead()]);
        $this->research();

        $this->assertSame('sch-career', CompanyOpportunity::sole()->assessment['product_fit']['part']);
    }

    public function test_other_products_are_stored_beside_the_named_one_only_when_they_clearly_match(): void
    {
        $this->child();
        $this->fees();
        $this->child('SCH-COUNSEL', ['name' => 'Counsellor Toolkit', 'what_it_does' => 'Tools for the career counsellor a school hires, with career guidance sessions and counsellor reports.', 'needs_solved' => ['Career counsellor workload'], 'trigger_signals' => []]);
        config(['signals.matching.secondary_min_score' => 100]);
        $this->fake([$this->lead()]);
        $this->research();

        $stored = DB::table('g2g_opportunity_matches')->where('signal_id', CompanyOpportunity::sole()->id)->pluck('offer_id')->all();
        $this->assertSame(['SCH-CAREER'], $stored, 'with a high bar only the named product is kept');
    }

    // ── freshness and duplicates ─────────────────────────────────────────────

    private const DATED = 'On 15 October 2026 Podar World School in Vadodara announced that it is hiring a career counsellor to start a Grade 9 career guidance programme.';

    private function datedLead(): array
    {
        return $this->lead(['event_date' => '2026-10-15', 'evidence_quote' => 'Podar World School in Vadodara announced that it is hiring a career counsellor to start a Grade 9 career guidance programme']);
    }

    public function test_a_second_source_for_the_same_event_keeps_the_original_event_date_and_discovery_day(): void
    {
        $this->child();
        $this->fake([$this->datedLead()], self::DATED, 'https://news.example.com/podar-dated', '2026-10-16');
        $this->research();
        $first = CompanyOpportunity::sole();
        $this->assertSame('2026-10-15', $first->event_date->toDateString());
        $discovered = $first->first_discovered_at->toDateTimeString();

        $this->nextDay();
        $this->fake([$this->datedLead()], self::DATED, 'https://other.example.org/podar-dated-copy', '2026-10-21');
        $this->research();

        $again = CompanyOpportunity::sole();
        $this->assertSame(1, CompanyOpportunity::count(), 'the same event on a second site is one opportunity');
        $this->assertCount(2, $again->sources);
        $this->assertSame('2026-10-15', $again->event_date->toDateString(), 'the original event date is not replaced');
        $this->assertSame($discovered, $again->first_discovered_at->toDateTimeString(), 'it did not become new');
        $this->assertSame($first->research_run_id, $again->research_run_id);
        $this->assertSame(0, (int) ResearchRun::orderByDesc('id')->first()->opportunities_new);
    }

    public function test_the_same_page_found_again_changes_nothing_and_is_not_new(): void
    {
        $this->child();
        $this->fake([$this->datedLead()], self::DATED, 'https://news.example.com/podar-dated', '2026-10-16');
        $this->research();
        $keys = ['id', 'review_status', 'research_run_id', 'score_total'];
        $before = CompanyOpportunity::sole();
        $snapshot = [$before->only($keys), $before->event_date->toDateString(), $before->first_discovered_at->toDateTimeString()];

        $this->nextDay();
        $this->fake([$this->datedLead()], self::DATED, 'https://news.example.com/podar-dated', '2026-10-16');
        $this->research();

        $this->assertSame(1, CompanyOpportunity::count());
        $after = CompanyOpportunity::sole();
        $this->assertEquals($snapshot, [$after->only($keys), $after->event_date->toDateString(), $after->first_discovered_at->toDateTimeString()]);
        $this->assertSame(0, (int) ResearchRun::orderByDesc('id')->first()->opportunities_new);
    }

    public function test_a_review_decision_survives_a_rediscovery(): void
    {
        $this->child();
        $this->fake([$this->datedLead()], self::DATED, 'https://news.example.com/podar-dated', '2026-10-16');
        $this->research();
        CompanyOpportunity::sole()->forceFill(['review_status' => 'Follow-up', 'pipeline_status' => 'REVIEWING'])->save();

        $this->nextDay();
        $this->fake([$this->datedLead()], self::DATED, 'https://other.example.org/copy', '2026-10-21');
        $this->research();

        $o = CompanyOpportunity::sole();
        $this->assertSame(['Follow-up', 'REVIEWING'], [$o->review_status, $o->pipeline_status]);
    }
}
