<?php

namespace Tests\Feature;

use App\Domain\Signals\Support\StructuredAi;
use App\Domain\Portfolio\ProductOffer;
use App\Domain\Signals\Icp\IcpProfile;
use App\Domain\Signals\Icp\TargetAccount;
use App\Domain\Signals\Opportunities\CompanyOpportunity;
use App\Domain\Signals\Opportunities\ProductProfile;
use App\Domain\Signals\Opportunities\ResearchRun;
use App\Domain\Signals\Ingestion\IngestionSource;
use App\Domain\Signals\Support\SafeUrlFetcher;
use App\Models\auth\tbluserModel;
use App\Support\RoleKey;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Product-driven discovery: the product chosen decides the ICP, the profile, the parts, the searches, what is stored and what is listed.
 * Nothing is borrowed from another product. All web and AI traffic is mocked; runs on the in-memory sqlite database.
 */
class ProductDrivenDiscoveryTest extends TestCase
{
    private const T = 1;
    private const T2 = 2;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('personal_access_tokens', function ($t) {
            $t->id();
            $t->morphs('tokenable');
            $t->text('name');
            $t->string('token', 64)->unique();
            $t->text('abilities')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
        });
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

    private function key(string $name, string $city): string
    {
        return StructuredAi::normalise($name) . '|' . StructuredAi::normalise($city);
    }

    private function account(string $name, string $city, int $priority, string $status = 'unscored', ?string $crm = null): TargetAccount
    {
        return TargetAccount::create([
            'sub_institute_id' => self::T, 'pack' => 'scholar_k12', 'account_key' => $this->key($name, $city), 'name' => $name, 'city' => $city,
            'state' => 'Gujarat', 'icp_status' => $status, 'watch_priority' => $priority, 'crm_ref' => $crm, 'crm_owner' => $crm ? 'kalpesh@triz.co.in' : null,
        ]);
    }

    private const SNIPPET = 'Podar World School announced a new campus in Vadodara and is hiring an ERP administrator ahead of admissions 2027.';

    /** One plain-English, concrete lead the model might return for SNIPPET. */
    private function lead(array $o = []): array
    {
        return $o + [
            'company_name' => 'Podar World School', 'website' => null, 'industry' => null, 'location' => 'Vadodara', 'buyer_type' => 'school', 'context' => null,
            'trigger_type' => 'expansion',
            'needs_statement' => 'Podar World School is opening a new campus and needs a school system running from day one.',
            'closing_date' => null,
            'what_happened' => 'Podar World School is opening a new campus in Vadodara. It is also hiring an ERP administrator.',
            'trigger_summary' => 'A new campus and an ERP hire point to systems being set up now.',
            'likely_problem' => 'A new campus needs admissions, fees and attendance working from the first day. Our school management system covers all three.',
            'what_we_could_sell' => 'school management system for the new Vadodara campus covering admissions, fees and attendance',
            'product_part' => 'school_erp', 'fit_reason' => 'A new campus needs admissions, fees and attendance set up from day one.',
            'intent_indicators' => [['indicator' => 'expansion', 'quote' => 'announced a new campus in Vadodara']], 'sales_pull' => [],
            'entry_point' => 'discovery_meeting', 'entry_point_reason' => null,
            'soft_marketing_angle' => 'Ask for the principal or the trustee. Open with running the new campus from one system.',
            'likely_stakeholder' => null,
            'scores' => ['buying_signal' => 4, 'problem_fit' => 4, 'product_fit' => 4, 'accessibility' => 3, 'urgency' => 4, 'potential_value' => 3, 'evidence_quality' => 3, 'reasoning' => 'A new campus and an ERP hire.'],
            'inference_reasoning' => null, 'event_date' => null, 'source_ids' => ['S1'],
            'evidence_quote' => 'Podar World School announced a new campus in Vadodara and is hiring an ERP administrator',
        ];
    }

    private function fake(array $leads, ?string $content = null, string $url = 'https://news.example.com/podar-vadodara', ?string $published = '2026-10-15'): void
    {
        $this->app->forgetInstance(\Illuminate\Http\Client\Factory::class);
        Http::clearResolvedInstance(\Illuminate\Http\Client\Factory::class);
        $content ??= self::SNIPPET;
        Http::fake([
            'api.tavily.com/*' => Http::response(['results' => [['url' => $url, 'title' => 'Podar opens campus', 'content' => $content, 'published_date' => $published]]], 200),
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode(['opportunities' => $leads])]]], 'finishReason' => 'STOP']],
                'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
            ], 200),
            '*/robots.txt' => Http::response('', 404),
            '*' => Http::response('<html><body><p>' . $content . '</p><p>The school said it will add staff across admissions, accounts and teaching for the new session.</p></body></html>', 200, ['Content-Type' => 'text/html']),
        ]);
    }

    /** @return list<array<string, mixed>> the JSON bodies sent to Tavily */
    private function tavilyPayloads(): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $r) => str_contains($r->url(), 'api.tavily.com'))->map(fn (Request $r) => $r->data())->values()->all();
    }


    // ── helpers for this file ────────────────────────────────────────────────

    private function h(int $tenant = self::T, int $profile = 1): array
    {
        $id = DB::table('tbluser')->insertGetId(['user_name' => 'u' . uniqid(), 'sub_institute_id' => $tenant, 'user_profile_id' => $profile]);

        return ['Authorization' => 'Bearer ' . tbluserModel::find($id)->createToken('t')->plainTextToken];
    }

    private function offer(string $id, string $name, string $parent, array $o = []): ProductOffer
    {
        return ProductOffer::create($o + [
            'sub_institute_id' => self::T, 'offer_id' => $id, 'name' => $name, 'parent_product' => $parent,
            'what_it_does' => $name . ' does its job.', 'readiness_status' => 'Live', 'needs_solved' => ['a need'], 'primary_segments' => ['a segment'],
        ]);
    }

    /** Enterprise Brain's own ICP: manufacturers, with their own parts, triggers and searches. */
    private function ebIcp(string $status = 'active', int $tenant = self::T): IcpProfile
    {
        $config = [
            'label' => 'Enterprise Brain for manufacturers', 'product_line' => 'eb', 'industry_label' => 'mid-size manufacturers',
            'buyer_types' => ['manufacturer'], 'regions' => ['Gujarat'], 'parts' => ['knowledge_layer' => 'one searchable memory across plants, documents and people'],
            'must_have' => [], 'disqualifiers' => [['label' => 'trader', 'phrases' => ['trading company']]],
            'triggers' => ['new_plant' => ['source' => 'news', 'part' => 'knowledge_layer', 'decay' => 90, 'pain' => 'a new plant needs shared knowledge from day one', 'phrases' => ['new plant']]],
            'queries' => [['id' => 'eb-news-plant', 'source' => 'news', 'topic' => 'news', 'days' => 45, 'q' => 'manufacturer announces new plant Gujarat']],
            'per_run' => ['catalogue' => 1, 'accounts' => 0], 'account_query' => '"{name}" {city} new plant', 'account_days' => 30, 'account_recheck_days' => 7,
        ];

        return IcpProfile::create(['sub_institute_id' => $tenant, 'key' => 'eb_manufacturing', 'name' => 'EB manufacturers', 'product_line' => 'eb',
            'status' => $status, 'is_default' => true, 'version' => 1, 'config' => $config]);
    }

    private const EB_SNIPPET = 'Kiran Alloys announced a new plant in Sanand and is hiring a knowledge manager to bring its three plants together.';

    private function ebLead(array $o = []): array
    {
        return $o + [
            'company_name' => 'Kiran Alloys', 'website' => null, 'industry' => 'manufacturing', 'location' => 'Sanand', 'buyer_type' => 'manufacturer', 'context' => null,
            'trigger_type' => 'expansion',
            'needs_statement' => 'Kiran Alloys is opening a new plant and needs its three plants to share what they know.',
            'closing_date' => null,
            'what_happened' => 'Kiran Alloys is opening a new plant in Sanand. It is hiring a knowledge manager.',
            'trigger_summary' => 'A new plant and a knowledge manager hire point to shared knowledge being set up now.',
            'likely_problem' => 'A new plant means three sites that must share know-how from the start. Our knowledge layer gives them one searchable memory.',
            'what_we_could_sell' => 'knowledge layer joining the new Sanand plant with the two existing plants',
            'product_part' => 'knowledge_layer', 'fit_reason' => 'Three plants must share know-how from the first day.',
            'intent_indicators' => [['indicator' => 'expansion', 'quote' => 'announced a new plant in Sanand']],
            'entry_point' => 'discovery_meeting', 'entry_point_reason' => null,
            'soft_marketing_angle' => 'Ask for the plant head. Open with joining the three plants into one memory.',
            'likely_stakeholder' => null, 'inference_reasoning' => null, 'event_date' => null, 'source_ids' => ['S1'],
            'evidence_quote' => 'Kiran Alloys announced a new plant in Sanand and is hiring a knowledge manager',
        ];
    }

    /** @return list<string> the prompt text sent to the AI provider */
    private function aiPrompts(): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $r) => str_contains($r->url(), 'generativelanguage.googleapis.com'))->map(fn (Request $r) => json_encode($r->data()))->values()->all();
    }

    private function runFor(string $product): void
    {
        $this->artisan('signals:research', ['--tenant' => self::T, '--trigger' => 'manual', '--product' => $product])->assertExitCode(0);
    }

    private function seedPortfolio(): void
    {
        $this->offer('SCH-01', 'Scholar School ERP', 'Scholar K-12');
        $this->offer('SCH-02', 'Fees Intelligence', 'Scholar K-12');
        $this->offer('EB-01', 'Enterprise Brain Intelligence Layer', 'EB');
        $this->offer('G2G-01', 'Competency Framework Studio', 'G2G');
    }

    // ── 1. product selection loads the persisted configuration ───────────────

    public function test_each_product_loads_its_own_stored_offers_and_icp_and_reports_what_is_missing(): void
    {
        $this->seedPortfolio();
        $this->ebIcp('draft');   // a draft is not an ICP in use

        $products = collect($this->getJson('/api/portfolio/products', $this->h())->assertOk()->json('data.products'))->keyBy('key');

        $this->assertSame(['scholarclone', 'enterprise_brain', 'g2g'], $products->keys()->all());
        $this->assertSame(['SCH-01', 'SCH-02'], array_column($products['scholarclone']['offers'], 'offer_id'));
        $this->assertSame(['EB-01'], array_column($products['enterprise_brain']['offers'], 'offer_id'));
        $this->assertSame(['G2G-01'], array_column($products['g2g']['offers'], 'offer_id'));
        $this->assertSame('Scholar K-12', $products['scholarclone']['portfolio_name'], 'the name the portfolio uses is kept');

        $this->assertTrue($products['scholarclone']['ready']);
        $this->assertSame('template', $products['scholarclone']['icp']['source']);
        $this->assertContains('school_erp', array_keys($products['scholarclone']['icp']['parts']));

        foreach (['enterprise_brain', 'g2g'] as $k) {
            $this->assertFalse($products[$k]['ready']);
            $this->assertNull($products[$k]['icp'], "{$k} has no ICP: nothing is borrowed from another product");
            $this->assertStringContainsString('No active ICP', $products[$k]['missing'][0]);
        }
        $this->assertNull($products['scholarclone']['offers'][0]['pricing_model'], 'no price is invented');

        // Activating Enterprise Brain\'s own ICP makes only Enterprise Brain ready, with its own definition.
        IcpProfile::query()->update(['status' => 'active']);
        $eb = collect($this->getJson('/api/portfolio/products/enterprise_brain', $this->h())->assertOk()->json('data.product'));
        $this->assertTrue($eb['ready']);
        $this->assertSame('database', $eb['icp']['source']);
        $this->assertSame('mid-size manufacturers', $eb['icp']['ideal_customer']);
        $this->assertSame(['knowledge_layer'], array_keys($eb['icp']['parts']));
        $this->assertFalse($this->getJson('/api/portfolio/products/g2g', $this->h())->json('data.product.ready'));
        $this->getJson('/api/portfolio/products/nonsense', $this->h())->assertStatus(404);
    }

    public function test_products_are_read_per_organisation(): void
    {
        $this->seedPortfolio();
        $this->ebIcp('active', self::T2);

        $other = collect($this->getJson('/api/portfolio/products', $this->h(self::T2))->assertOk()->json('data.products'))->keyBy('key');

        $this->assertSame([], $other['scholarclone']['offers'], 'another organisation sees none of this one\'s offers');
        $this->assertTrue($other['enterprise_brain']['ready'], 'and its own ICP');
        $this->getJson('/api/portfolio/products')->assertStatus(401);
    }

    public function test_the_research_status_reports_the_selected_products_readiness(): void
    {
        $this->seedPortfolio();

        $this->getJson('/api/signals/research/status?product=scholarclone', $this->h())->assertOk()->assertJsonPath('data.product.ready', true);
        $this->getJson('/api/signals/research/status?product=enterprise_brain', $this->h())->assertOk()
            ->assertJsonPath('data.product.ready', false)->assertJsonPath('data.product.label', 'Enterprise Brain');
        $this->getJson('/api/signals/research/status?product=nope', $this->h())->assertStatus(422);
    }

    // ── 2. a product that is not configured fails clearly ────────────────────

    public function test_a_product_with_no_icp_is_refused_with_what_is_missing_and_spends_nothing(): void
    {
        $this->seedPortfolio();
        Http::fake();

        $r = $this->postJson('/api/signals/research/run', ['product' => 'enterprise_brain'], $this->h())->assertStatus(422);
        $r->assertJsonPath('code', 'product_not_configured');
        $this->assertNotEmpty($r->json('missing'));
        $this->postJson('/api/signals/research/run', ['product' => 'nope'], $this->h())->assertStatus(422)->assertJsonPath('code', 'unknown_product');

        $this->assertSame(0, ResearchRun::count(), 'a refused request starts nothing');
        Http::assertNothingSent();

        // The scheduled path says the same instead of quietly researching ScholarClone.
        $this->artisan('signals:research', ['--tenant' => self::T, '--trigger' => 'manual', '--product' => 'g2g'])
            ->expectsOutputToContain('product_not_configured')->assertExitCode(0);
        $run = ResearchRun::sole();
        $this->assertSame('skipped', $run->status);
        $this->assertSame('g2g', $run->product_key);
        $this->assertStringContainsString('No active ICP for G2G', (string) $run->error_message);
        Http::assertNothingSent();
    }

    public function test_a_document_for_an_unconfigured_product_is_refused_before_anything_is_stored(): void
    {
        Queue::fake();
        Http::fake();

        $file = UploadedFile::fake()->createWithContent('plant.txt', self::EB_SNIPPET);
        $this->post('/api/signals/ingestion/upload', ['file' => $file, 'product' => 'enterprise_brain'], $this->h() + ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('code', 'product_not_configured');
        $this->postJson('/api/signals/ingestion/url', ['url' => 'https://news.example.com/x', 'product' => 'g2g'], $this->h())->assertStatus(422)->assertJsonPath('code', 'product_not_configured');

        $this->assertSame(0, IngestionSource::count());
        Http::assertNothingSent();
    }

    // ── 3. the product reaches ingestion, qualification and persistence ──────

    public function test_enterprise_brain_discovery_uses_its_own_icp_prompt_and_stores_records_for_its_product(): void
    {
        $this->seedPortfolio();
        $this->ebIcp();
        $school = $this->account('Podar World School', 'Vadodara', 1);
        $this->fake([$this->ebLead()], self::EB_SNIPPET, 'https://news.example.com/kiran-sanand');

        $this->runFor('enterprise_brain');

        $queries = collect($this->tavilyPayloads())->pluck('query')->all();
        $this->assertContains('manufacturer announces new plant Gujarat', $queries, 'its own ICP query');
        $this->assertEmpty(array_filter($queries, fn ($q) => str_contains(strtolower($q), 'school') || preg_match('/erp/i', $q) === 1), 'none of ScholarClone\'s searches');

        $prompt = implode(' ', $this->aiPrompts());
        $this->assertStringContainsString('Enterprise Brain', $prompt);
        $this->assertStringContainsString('knowledge_layer', $prompt);
        foreach (['ScholarClone', 'K-12', 'school_erp', 'SALES PULL'] as $other) {
            $this->assertStringNotContainsString($other, $prompt, "the prompt must not carry {$other}");
        }

        $opp = CompanyOpportunity::sole();
        $this->assertSame('eb', $opp->business_fit);
        $this->assertSame('Kiran Alloys', $opp->company->name);
        $this->assertSame('eb', $opp->assessment['product_fit'] === null ? null : 'eb');
        $this->assertSame('knowledge_layer', $opp->assessment['product_fit']['part']);

        $run = ResearchRun::sole();
        $this->assertSame('enterprise_brain', $run->product_key);
        $this->assertSame(IcpProfile::first()->id, (int) $run->icp_profile_id);
        $this->assertNotNull($opp->source_url, 'source provenance is kept');

        $this->assertNull($school->refresh()->last_checked_at, 'ScholarClone\'s target accounts are not touched by another product\'s run');
    }

    public function test_scholarclone_still_uses_its_own_icp_and_its_records_are_scholarclones(): void
    {
        $this->seedPortfolio();
        $this->ebIcp();
        $this->fake([$this->lead()]);

        $this->runFor('scholarclone');

        $queries = implode(' ', collect($this->tavilyPayloads())->pluck('query')->all());
        $this->assertStringNotContainsString('new plant Gujarat', $queries, 'Enterprise Brain\'s ICP is not used for ScholarClone');
        $prompt = implode(' ', $this->aiPrompts());
        $this->assertStringContainsString('ScholarClone', $prompt);
        $this->assertStringNotContainsString('knowledge_layer', $prompt);

        $this->assertSame('scholar', CompanyOpportunity::sole()->business_fit);
        $this->assertSame('scholarclone', ResearchRun::sole()->product_key);
    }

    public function test_the_same_event_found_for_two_products_is_two_records_and_the_product_filter_never_leaks(): void
    {
        $this->seedPortfolio();
        $this->ebIcp();
        $h = $this->h();

        $this->fake([$this->lead()]);
        $this->runFor('scholarclone');
        Cache::flush();
        $this->travel(11)->minutes();
        // Enterprise Brain finds the SAME organisation at the same page for its own reason.
        $this->fake([$this->ebLead(['company_name' => 'Podar World School', 'location' => 'Vadodara', 'buyer_type' => 'manufacturer',
            'evidence_quote' => 'Podar World School announced a new campus in Vadodara and is hiring an ERP administrator',
            'intent_indicators' => [['indicator' => 'expansion', 'quote' => 'announced a new campus in Vadodara']],
            'needs_statement' => 'Podar World School is opening a new campus and needs shared knowledge across both sites.',
            'what_happened' => 'Podar World School is opening a new campus in Vadodara. It is also hiring an ERP administrator.',
            'trigger_summary' => 'A new campus means two sites that must share what they know.'])]);
        $this->runFor('enterprise_brain');

        $this->assertSame(2, CompanyOpportunity::count(), 'one record per product, none duplicated by moving views');
        $this->assertEqualsCanonicalizing(['scholar', 'eb'], CompanyOpportunity::pluck('business_fit')->all());

        $keys = fn (string $q) => collect($this->getJson('/api/signals/opportunities' . $q, $h)->assertOk()->json('data'))->pluck('product')->all();
        $this->assertSame(['scholarclone'], $keys('?product=scholarclone'));
        $this->assertSame(['enterprise_brain'], $keys('?product=enterprise_brain'));
        $this->assertSame([], $keys('?product=g2g'));
        $this->assertSame([], $keys('?product=unassigned'));
        $this->assertEqualsCanonicalizing(['scholarclone', 'enterprise_brain'], $keys(''));
        $this->assertEqualsCanonicalizing(['scholarclone', 'enterprise_brain'], $keys('?product=all'));
        $this->getJson('/api/signals/opportunities?product=nonsense', $h)->assertStatus(422);

        // A tenant sees none of it.
        $this->assertSame([], collect($this->getJson('/api/signals/opportunities?product=scholarclone', $this->h(self::T2))->assertOk()->json('data'))->all());
    }

    public function test_records_stored_before_products_existed_stay_unassigned_and_are_never_guessed_into_one(): void
    {
        $this->seedPortfolio();
        $this->fake([$this->lead()]);
        $this->runFor('scholarclone');
        $legacy = CompanyOpportunity::sole();
        DB::table('g2g_company_opportunities')->where('id', $legacy->id)->update(['business_fit' => null]);   // a record from before products were recorded

        $keys = fn (string $q) => collect($this->getJson('/api/signals/opportunities' . $q, $this->h())->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$legacy->id], $keys('?product=unassigned'));
        $this->assertSame([], $keys('?product=scholarclone'), 'no guess from its title or company');
        $this->assertSame([$legacy->id], $keys(''), 'but it is still in All products');
        $this->assertSame(1, CompanyOpportunity::count(), 'nothing was duplicated or deleted');
    }

    public function test_a_document_keeps_its_product_and_the_lists_follow_it(): void
    {
        $this->seedPortfolio();
        Queue::fake();
        Http::fake();

        $file = UploadedFile::fake()->createWithContent('schools.txt', 'Podar World School opened a new campus.');
        $res = $this->post('/api/signals/ingestion/upload', ['file' => $file, 'product' => 'scholarclone'], $this->h() + ['Accept' => 'application/json']);
        $res->assertStatus(201);

        $this->assertSame('scholarclone', IngestionSource::sole()->product_key);
        $this->assertSame('scholarclone', $res->json('data.product_key'));
        $ids = fn (string $p) => collect($this->getJson('/api/signals/ingestion/sources' . $p, $this->h())->assertOk()->json('data'))->pluck('id')->all();
        $this->assertCount(1, $ids('?product=scholarclone'));
        $this->assertSame([], $ids('?product=enterprise_brain'), 'another product\'s documents are not listed');
        $this->assertCount(1, $ids(''));
        $this->getJson('/api/signals/ingestion/findings?product=enterprise_brain', $this->h())->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_document_added_before_products_were_recorded_belongs_to_scholarclone_only(): void
    {
        IngestionSource::create(['sub_institute_id' => self::T, 'type' => 'file', 'name' => 'old.txt', 'status' => 'ready', 'segments' => '[]']);
        $this->ebIcp();

        $count = fn (string $p) => count($this->getJson('/api/signals/ingestion/sources?product=' . $p, $this->h())->assertOk()->json('data'));

        $this->assertSame(1, $count('scholarclone'));
        $this->assertSame(0, $count('enterprise_brain'));
    }

    // ── 4. history and compatibility ─────────────────────────────────────────

    public function test_the_product_backfill_only_fills_runs_whose_own_records_prove_the_product(): void
    {
        $proved = ResearchRun::create(['sub_institute_id' => self::T, 'report_date' => '2026-10-01', 'trigger' => 'manual', 'status' => 'success']);
        $mixed = ResearchRun::create(['sub_institute_id' => self::T, 'report_date' => '2026-10-02', 'trigger' => 'manual', 'status' => 'success']);
        $empty = ResearchRun::create(['sub_institute_id' => self::T, 'report_date' => '2026-10-03', 'trigger' => 'manual', 'status' => 'success']);
        $keep = ResearchRun::create(['sub_institute_id' => self::T, 'report_date' => '2026-10-04', 'trigger' => 'manual', 'status' => 'success', 'product_key' => 'g2g']);
        $company = \App\Domain\Signals\Opportunities\Company::create(['sub_institute_id' => self::T, 'name' => 'X', 'normalized_name' => 'x'])->id;
        foreach ([[$proved->id, 'eb'], [$mixed->id, 'eb'], [$mixed->id, 'scholar'], [$keep->id, 'eb']] as $i => [$run, $fit]) {
            DB::table('g2g_company_opportunities')->insert(['sub_institute_id' => self::T, 'company_id' => $company, 'research_run_id' => $run, 'business_fit' => $fit,
                'title' => 't' . $i, 'category' => 'x', 'observed_event' => 'e', 'why_indicates_need' => 'w', 'product_fit' => 'p', 'recommended_action' => 'a', 'priority' => 'Low', 'qualification' => 'Monitoring', 'confidence' => 'Low', 'sources' => '[]', 'confirmed_facts' => '[]', 'unverified_claims' => '[]', 'fingerprint' => 'f' . $i, 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->artisan('signals:backfill-product')->expectsOutputToContain('would be updated')->assertExitCode(0);
        $this->assertNull($proved->refresh()->product_key, 'a dry run writes nothing');

        $this->artisan('signals:backfill-product', ['--apply' => true])->assertExitCode(0);

        $this->assertSame('enterprise_brain', $proved->refresh()->product_key);
        $this->assertNull($mixed->refresh()->product_key, 'several products: not guessed');
        $this->assertNull($empty->refresh()->product_key, 'no records: not guessed');
        $this->assertSame('g2g', $keep->refresh()->product_key, 'an existing value is never overwritten');
    }

    public function test_the_scheduled_command_runs_only_the_listed_products_and_rejects_an_unknown_one(): void
    {
        $this->artisan('signals:research', ['--tenant' => self::T, '--product' => 'nonsense'])->assertExitCode(1);
        $this->assertSame(0, ResearchRun::count());
    }

    public function test_the_signals_endpoints_the_old_screens_call_still_answer(): void
    {
        $this->seedPortfolio();
        $h = $this->h();
        foreach (['/api/signals/opportunities', '/api/signals/research/status', '/api/signals/research/runs', '/api/signals/ingestion/sources',
            '/api/signals/ingestion/findings', '/api/signals/product-profile', '/api/portfolio/offers', '/api/portfolio/partners', '/api/signals/icp/profiles'] as $url) {
            $this->getJson($url, $h)->assertOk();
        }
    }
}
