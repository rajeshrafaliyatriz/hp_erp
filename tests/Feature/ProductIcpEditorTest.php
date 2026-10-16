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
 * The product ICP editor API: a product's own ICP is created, edited, versioned and activated BY PRODUCT, never through another product,
 * and saving it starts no research. All web and AI traffic is mocked (and asserted to be absent); runs on the in-memory sqlite database.
 */
class ProductIcpEditorTest extends TestCase
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


    // ── helpers for this file ────────────────────────────────────────────────

    /** What the editor sends for Enterprise Brain: only what the person typed. */
    private function ebConfig(array $o = []): array
    {
        return $o + [
            'label' => 'Enterprise Brain for manufacturers', 'industry_label' => 'mid-size manufacturers', 'pitch' => 'One searchable memory across plants.',
            'buyer_types' => ['manufacturer'], 'regions' => ['Gujarat'],
            'parts' => ['knowledge_layer' => 'one searchable memory across plants, documents and people'],
            'triggers' => ['new_plant' => ['source' => 'news', 'part' => 'knowledge_layer', 'decay' => 90, 'pain' => 'a new plant needs shared knowledge from day one', 'phrases' => ['new plant']]],
            'queries' => [['id' => 'eb-news-plant', 'source' => 'news', 'topic' => 'news', 'days' => 45, 'q' => 'manufacturer announces new plant Gujarat']],
            'disqualifiers' => [['label' => 'trader', 'phrases' => ['trading company']]],
            'per_run' => ['catalogue' => 1, 'accounts' => 0],
        ];
    }

    private function g2gConfig(): array
    {
        return [
            'label' => 'G2G capability platform', 'industry_label' => 'hospitals and large employers',
            'buyer_types' => ['hospital'], 'regions' => ['Gujarat'], 'parts' => ['capability_gap' => 'measure and close capability gaps'],
            'triggers' => ['nurse_hiring' => ['source' => 'job', 'part' => 'capability_gap', 'decay' => 60, 'pain' => 'bulk hiring needs capability checks', 'phrases' => ['staff nurse']]],
            'queries' => [['id' => 'g2g-job-nurse', 'source' => 'job', 'topic' => 'general', 'days' => 60, 'q' => 'hospital recruitment staff nurse Gujarat']],
        ];
    }

    private function saveIcp(string $product, array $config, array $extra = [], ?array $headers = null)
    {
        return $this->putJson("/api/signals/icp/products/{$product}", ['config' => $config] + $extra, $headers ?? $this->h());
    }

    private function noProviderCalls(): void
    {
        Http::fake();
    }

    // ── create ───────────────────────────────────────────────────────────────

    public function test_an_icp_is_created_for_enterprise_brain_and_for_g2g_each_under_its_own_product_line(): void
    {
        $this->seedPortfolio();
        $this->noProviderCalls();

        $this->saveIcp('enterprise_brain', $this->ebConfig())->assertOk()
            ->assertJsonPath('data.profile.status', 'draft')->assertJsonPath('data.profile.version', 1)
            ->assertJsonPath('data.product.state', 'needs_setup');
        $this->saveIcp('g2g', $this->g2gConfig(), ['activate' => true])->assertOk()
            ->assertJsonPath('data.profile.status', 'active')->assertJsonPath('data.product.ready', true)->assertJsonPath('data.product.state', 'ready');

        $rows = IcpProfile::orderBy('id')->get();
        $this->assertSame(['eb', 'g2g'], $rows->pluck('product_line')->all());
        $this->assertSame(['eb_icp', 'g2g_icp'], $rows->pluck('key')->all());
        $this->assertSame('mid-size manufacturers', $rows[0]->config['industry_label']);
        $this->assertSame('hospitals and large employers', $rows[1]->config['industry_label'], 'G2G has its own definition, none of Enterprise Brain\'s');
        $this->assertArrayNotHasKey('knowledge_layer', $rows[1]->config['parts']);

        $this->saveIcp('enterprise_brain', $this->ebConfig(), ['activate' => true])->assertOk()->assertJsonPath('data.product.ready', true);
        $products = collect($this->getJson('/api/portfolio/products', $this->h())->assertOk()->json('data.products'))->keyBy('key');
        $this->assertSame(['ready', 'ready', 'ready'], [$products['scholarclone']['state'], $products['enterprise_brain']['state'], $products['g2g']['state']]);
        $this->assertSame('database', $products['enterprise_brain']['icp']['source']);
        $this->noProviderCallsMade();
        $this->assertSame(0, ResearchRun::count(), 'saving an ICP starts no research');
    }

    private function noProviderCallsMade(): void
    {
        Http::assertNothingSent();
    }

    public function test_the_editor_state_starts_empty_for_a_product_with_no_icp_and_never_borrows_another_products(): void
    {
        $this->seedPortfolio();

        $eb = $this->getJson('/api/signals/icp/products/enterprise_brain', $this->h())->assertOk()->json('data');

        $this->assertNull($eb['profile']);
        $this->assertSame('empty', $eb['config_source']);
        $this->assertSame([], $eb['config'], 'no value is guessed or copied');
        $this->assertSame('needs_setup', $eb['product']['state']);
        $this->assertStringContainsString('Ideal Customer Profile', $eb['product']['next_step']);

        $scholar = $this->getJson('/api/signals/icp/products/scholarclone', $this->h())->assertOk()->json('data');
        $this->assertSame('template', $scholar['config_source'], 'ScholarClone starts from its own built-in definition, and says so');
        $this->assertSame('ready', $scholar['product']['state']);
        $this->getJson('/api/signals/icp/products/nonsense', $this->h())->assertStatus(404)->assertJsonPath('code', 'unknown_product');
    }

    // ── edit, versions, one active ───────────────────────────────────────────

    public function test_editing_a_product_icp_makes_a_new_version_of_the_same_profile_and_keeps_what_the_editor_does_not_manage(): void
    {
        $this->saveIcp('enterprise_brain', $this->ebConfig() + ['tiers' => ['A' => 'top'], 'priority' => [1 => 70]], [])->assertOk();   // unmanaged keys are ignored on input
        $this->assertArrayNotHasKey('tiers', IcpProfile::sole()->config);
        $this->saveIcp('enterprise_brain', $this->ebConfig(), ['activate' => true])->assertOk();
        $profile = IcpProfile::sole();
        $profile->update(['config' => $profile->config + ['tiers' => ['A' => 'top'], 'priority' => [1 => 70]]]);   // scoring set up elsewhere
        $this->assertSame(1, (int) $profile->version);

        $this->saveIcp('enterprise_brain', $this->ebConfig(['pitch' => 'A second sentence about the pitch.']), ['note' => 'sharper pitch'])->assertOk()
            ->assertJsonPath('data.profile.version', 2)->assertJsonPath('data.config.pitch', 'A second sentence about the pitch.');

        $this->assertSame(1, IcpProfile::count(), 'edited in place, no duplicate');
        $this->assertSame(1, IcpProfile::where('status', 'active')->count());
        $profile->refresh();
        $this->assertSame(['A' => 'top'], $profile->config['tiers'], 'settings the editor does not manage are kept as stored');
        $this->assertSame(['sharper pitch'], \App\Domain\Signals\Icp\IcpProfileVersion::where('icp_profile_id', $profile->id)->where('version', 2)->pluck('note')->all());
        $this->assertSame(2, \App\Domain\Signals\Icp\IcpProfileVersion::where('icp_profile_id', $profile->id)->count(), 'history kept');

        $this->saveIcp('enterprise_brain', $this->ebConfig(['pitch' => 'A second sentence about the pitch.']))->assertOk()->assertJsonPath('data.profile.version', 2);   // nothing changed: no new version
    }

    public function test_activating_an_icp_leaves_exactly_one_active_for_the_product_and_touches_no_other_product(): void
    {
        $scholarBefore = $this->scholarRow();
        $old = IcpProfile::create(['sub_institute_id' => self::T, 'key' => 'eb_old', 'name' => 'old', 'product_line' => 'eb', 'status' => 'active', 'is_default' => true, 'version' => 1, 'config' => $this->ebConfig() + ['product_line' => 'eb']]);

        $this->saveIcp('enterprise_brain', $this->ebConfig(['pitch' => 'new']), ['activate' => true])->assertOk();

        $this->assertSame(1, IcpProfile::where('product_line', 'eb')->where('status', 'active')->count());
        $this->assertSame('active', $old->refresh()->status, 'the existing active ICP is edited, not duplicated');
        $this->assertSame(2, (int) $old->version);

        // The generic admin endpoint keeps the same rule.
        $second = IcpProfile::create(['sub_institute_id' => self::T, 'key' => 'eb_two', 'name' => 'two', 'product_line' => 'eb', 'status' => 'draft', 'is_default' => false, 'version' => 1, 'config' => $this->ebConfig() + ['product_line' => 'eb']]);
        $this->patchJson("/api/signals/icp/profiles/{$second->id}/status", ['status' => 'active'], $this->h())->assertOk();
        $this->assertSame(['archived', 'active'], [$old->refresh()->status, $second->refresh()->status]);
        $this->assertSame(1, IcpProfile::where('product_line', 'eb')->where('status', 'active')->count());

        $this->assertSame($scholarBefore, $this->scholarRow(), 'ScholarClone\'s ICP is untouched');
    }

    private function scholarRow(): array
    {
        return IcpProfile::where('product_line', 'scholar')->get()->map(fn ($p) => [$p->id, $p->status, $p->version, json_encode($p->config)])->all();
    }

    // ── product mapping ──────────────────────────────────────────────────────

    public function test_the_product_line_is_stamped_by_the_server_and_an_icp_cannot_be_moved_to_another_product(): void
    {
        $this->seedPortfolio();
        $scholar = IcpProfile::create(['sub_institute_id' => self::T, 'key' => 'scholar_k12', 'name' => 'S', 'product_line' => 'scholar', 'status' => 'active', 'is_default' => true, 'version' => 1,
            'config' => config('signals_icp.scholar_k12')]);
        $before = $this->scholarRow();

        // A client that claims another product's line in the config still gets Enterprise Brain's.
        $this->saveIcp('enterprise_brain', $this->ebConfig(['product_line' => 'scholar']), ['activate' => true])->assertOk();
        $this->assertSame('eb', IcpProfile::where('key', 'eb_icp')->sole()->product_line);
        $this->assertSame('eb', IcpProfile::where('key', 'eb_icp')->sole()->config['product_line']);
        $this->assertSame($before, $this->scholarRow(), 'ScholarClone\'s ICP was not edited through Enterprise Brain\'s address');

        // The generic endpoint refuses to re-point a product\'s ICP at another product.
        $eb = IcpProfile::where('key', 'eb_icp')->sole();
        $this->putJson("/api/signals/icp/profiles/{$eb->id}", ['config' => $this->ebConfig(['product_line' => 'scholar'])], $this->h())->assertStatus(422);
        $this->putJson("/api/signals/icp/profiles/{$eb->id}", ['config' => $this->ebConfig() + ['product_line' => 'eb']], $this->h())->assertOk();
        $this->assertSame('eb', $eb->refresh()->product_line);

        // ScholarClone's own address edits only ScholarClone's.
        $this->saveIcp('scholarclone', ['guidance' => 'Prefer schools that are opening a campus.'])->assertOk();
        $this->assertSame(2, (int) $scholar->refresh()->version);
        $this->assertSame('scholar', $scholar->product_line);
        $this->assertSame('mid-size manufacturers', IcpProfile::where('key', 'eb_icp')->sole()->config['industry_label']);
    }

    // ── validation ───────────────────────────────────────────────────────────

    public function test_invalid_or_incomplete_criteria_are_refused_with_the_exact_problems_and_nothing_is_saved(): void
    {
        $this->noProviderCalls();

        $this->saveIcp('enterprise_brain', ['industry_label' => 'x'])->assertStatus(422)->assertJsonPath('code', 'icp_invalid');   // no label
        $bad = $this->ebConfig(['triggers' => ['new_plant' => ['source' => 'rumour', 'decay' => 90, 'phrases' => ['new plant']]]]);
        $r = $this->saveIcp('enterprise_brain', $bad)->assertStatus(422);
        $this->assertStringContainsString('triggers.new_plant.source', implode(' ', $r->json('problems')));
        $this->saveIcp('enterprise_brain', $this->ebConfig(['queries' => [['id' => 'q', 'source' => 'news', 'topic' => 'news', 'days' => 0, 'q' => 'ab']]]))->assertStatus(422);
        $this->saveIcp('enterprise_brain', $this->ebConfig(['per_run' => ['catalogue' => 6, 'accounts' => 6]]))->assertStatus(422);
        $this->putJson('/api/signals/icp/products/enterprise_brain', ['config' => 'nope'], $this->h())->assertStatus(422);

        $this->assertSame(0, IcpProfile::count());

        // Valid to save as a draft, but not activatable: no searches.
        $noQueries = $this->ebConfig();
        unset($noQueries['queries']);
        $this->saveIcp('enterprise_brain', $noQueries)->assertOk()->assertJsonPath('data.product.state', 'needs_setup');
        $r = $this->saveIcp('enterprise_brain', $noQueries, ['activate' => true])->assertStatus(422);
        $this->assertStringContainsString('no search queries', implode(' ', $r->json('problems')));
        $this->assertSame('draft', IcpProfile::sole()->status);
        $this->patchJson('/api/signals/icp/products/enterprise_brain/status', ['status' => 'active'], $this->h())->assertStatus(422)->assertJsonPath('code', 'icp_invalid');

        $check = $this->postJson('/api/signals/icp/products/enterprise_brain/validate', ['config' => $this->ebConfig()], $this->h())->assertOk();
        $this->assertTrue($check->json('data.valid'));
        $this->assertSame(1, IcpProfile::count(), 'validating saves nothing');
        Http::assertNothingSent();
    }

    // ── readiness ────────────────────────────────────────────────────────────

    public function test_readiness_tells_ready_from_needs_setup_from_invalid_with_a_next_step(): void
    {
        $this->seedPortfolio();
        $state = fn (string $p) => $this->getJson('/api/portfolio/products/' . $p, $this->h())->assertOk()->json('data.product');

        $none = $state('enterprise_brain');
        $this->assertSame(['needs_setup', false], [$none['state'], $none['ready']]);
        $this->assertStringContainsString('Product Portfolio', $none['next_step']);

        $this->saveIcp('enterprise_brain', $this->ebConfig());
        $draft = $state('enterprise_brain');
        $this->assertSame('needs_setup', $draft['state']);
        $this->assertStringContainsString('not active', $draft['next_step']);
        $this->assertSame('draft', $draft['draft_icp'] ? 'draft' : '');

        // A stored active ICP that fails validation is reported as invalid, with the problem.
        IcpProfile::query()->update(['status' => 'active', 'config' => array_replace(IcpProfile::sole()->config, ['regions' => 'not-a-list'])]);
        $bad = $state('enterprise_brain');
        $this->assertSame(['invalid', false], [$bad['state'], $bad['ready']]);
        $this->assertStringContainsString('invalid', $bad['missing'][0]);
        $this->assertStringContainsString('fix', strtolower($bad['next_step']));

        $this->saveIcp('enterprise_brain', $this->ebConfig(), ['activate' => true])->assertOk();
        $this->assertSame('ready', $state('enterprise_brain')['state']);
        $this->patchJson('/api/signals/icp/products/enterprise_brain/status', ['status' => 'draft'], $this->h())->assertOk()->assertJsonPath('data.product.state', 'needs_setup');
    }

    // ── security ─────────────────────────────────────────────────────────────

    public function test_only_administrators_reach_the_editor_and_organisations_never_see_each_others_icps(): void
    {
        $this->saveIcp('enterprise_brain', $this->ebConfig(), ['activate' => true])->assertOk();
        DB::table('tbluserprofilemaster')->insert(['id' => 2, 'name' => 'Employee', 'role_key' => 'employee']);
        $employee = $this->h(self::T, 2);

        $this->getJson('/api/signals/icp/products/enterprise_brain', $employee)->assertStatus(403);
        $this->putJson('/api/signals/icp/products/enterprise_brain', ['config' => $this->ebConfig()], $employee)->assertStatus(403);
        $this->patchJson('/api/signals/icp/products/enterprise_brain/status', ['status' => 'archived'], $employee)->assertStatus(403);
        $this->postJson('/api/signals/icp/products/enterprise_brain/validate', ['config' => []], $employee)->assertStatus(403);
        $this->getJson('/api/signals/icp/products/enterprise_brain')->assertStatus(401);

        $other = $this->getJson('/api/signals/icp/products/enterprise_brain', $this->h(self::T2))->assertOk()->json('data');
        $this->assertNull($other['profile'], 'another organisation\'s ICP is invisible');
        $this->saveIcp('enterprise_brain', $this->ebConfig(['pitch' => 'theirs']), [], $this->h(self::T2))->assertOk();
        $this->assertSame(2, IcpProfile::count());
        $this->assertSame('One searchable memory across plants.', IcpProfile::where('sub_institute_id', self::T)->sole()->config['pitch'], 'the first organisation\'s ICP is unchanged');
        $this->assertSame(1, IcpProfile::where('sub_institute_id', self::T2)->count());
    }

    // ── research ─────────────────────────────────────────────────────────────

    public function test_research_is_refused_before_the_icp_is_active_and_then_uses_exactly_the_saved_one(): void
    {
        $this->seedPortfolio();
        $this->noProviderCalls();
        $this->postJson('/api/signals/research/run', ['product' => 'enterprise_brain'], $this->h())->assertStatus(422)->assertJsonPath('code', 'product_not_configured');
        $this->saveIcp('enterprise_brain', $this->ebConfig());   // saved as a draft: still not usable
        $this->postJson('/api/signals/research/run', ['product' => 'enterprise_brain'], $this->h())->assertStatus(422)->assertJsonPath('code', 'product_not_configured');
        $this->assertSame(0, ResearchRun::count());
        Http::assertNothingSent();

        $this->saveIcp('enterprise_brain', $this->ebConfig(), ['activate' => true])->assertOk();
        $this->fake([$this->ebLead()], self::EB_SNIPPET, 'https://news.example.com/kiran-sanand');
        $this->account('Podar World School', 'Vadodara', 1);

        $this->runFor('enterprise_brain');

        $queries = collect($this->tavilyPayloads())->pluck('query')->all();
        $this->assertContains('manufacturer announces new plant Gujarat', $queries, 'the saved ICP\'s own search');
        $this->assertEmpty(array_filter($queries, fn ($q) => str_contains(strtolower($q), 'school')), 'none of ScholarClone\'s');
        $prompt = implode(' ', $this->aiPrompts());
        $this->assertStringContainsString('knowledge_layer', $prompt, 'the saved ICP\'s parts');
        $this->assertStringContainsString('mid-size manufacturers', $prompt, 'the saved ideal customer');
        $this->assertStringContainsString('a new plant needs shared knowledge', $prompt, 'the saved pain point');
        foreach (['ScholarClone', 'K-12', 'school_erp'] as $other) {
            $this->assertStringNotContainsString($other, $prompt);
        }
        $run = ResearchRun::sole();
        $this->assertSame(IcpProfile::sole()->id, (int) $run->icp_profile_id);
        $this->assertSame('enterprise_brain', $run->product_key);
        $this->assertSame('eb', CompanyOpportunity::sole()->business_fit);
        $this->assertNull(TargetAccount::first()->last_checked_at, 'ScholarClone\'s accounts untouched');
    }
}
