<?php

namespace Tests\Feature;

use App\Domain\Signals\Ingestion\IngestionAnalysis;
use App\Domain\Signals\Ingestion\IngestionFinding;
use App\Domain\Signals\Ingestion\IngestionSource;
use App\Domain\Signals\Opportunities\Company;
use App\Domain\Signals\Opportunities\CompanyOpportunity;
use App\Domain\Signals\Opportunities\ProductProfile;
use App\Domain\Signals\Opportunities\ProductProfileService;
use App\Domain\Signals\Opportunities\ResearchRun;
use App\Domain\Signals\Support\RobotsPolicy;
use App\Domain\Signals\Support\SafeUrlFetcher;
use App\Domain\Signals\Support\UnsafeUrlException;
use App\Models\auth\tbluserModel;
use App\Support\RoleKey;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\OfficeFixtures;
use Tests\TestCase;

/**
 * Daily company-opportunity research + manual ingestion.
 *
 * ALL provider traffic here is MOCKED (Http::fake): Tavily, Gemini and the fetched web
 * pages. These tests prove our handling of provider responses, not that a live
 * provider works. Runs on the in-memory sqlite database from phpunit.xml.
 */
class OpportunityIngestionTest extends TestCase
{
    private const T1 = 1;
    private const T2 = 2;

    protected function setUp(): void
    {
        parent::setUp();
        config(['signals.discovery.screen_off_topic' => false]);   // fixtures here use non-school pages to exercise the gate itself

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

        (require base_path('database/migrations/2026_09_30_150000_create_g2g_opportunity_and_ingestion_tables.php'))->up();
        (require base_path('database/migrations/2026_09_30_180000_add_signal_fields_to_ingestion_findings.php'))->up();
        (require base_path('database/migrations/2026_09_30_200000_add_research_sources_and_signal_kind.php'))->up();
        if (file_exists(base_path('database/migrations/2026_09_30_220000_create_g2g_portfolio_and_partners_tables.php'))) {
            (require base_path('database/migrations/2026_09_30_220000_create_g2g_portfolio_and_partners_tables.php'))->up();
        }
        (require base_path('database/migrations/2026_09_30_230000_add_claude_feed_and_linking_fields.php'))->up();
        if (file_exists(base_path('database/migrations/2026_10_01_130000_add_stage_to_g2g_research_runs.php'))) {
            (require base_path('database/migrations/2026_10_01_130000_add_stage_to_g2g_research_runs.php'))->up();
        }
        // The market-import columns the opportunity feed now reads (expiry, samples, evidence).
        (require base_path('database/migrations/2026_10_08_100000_extend_g2g_signals_for_market_import.php'))->up();
        (require base_path('database/migrations/2026_11_29_090000_add_assessment_to_g2g_company_opportunities.php'))->up();
        (require base_path('database/migrations/2026_10_14_100000_create_g2g_icp_profiles_tables.php'))->up();
        (require base_path('database/migrations/2026_12_01_090000_add_product_key_to_signals_runs_and_sources.php'))->up();
        (require base_path('database/migrations/2026_12_01_090000_add_product_key_to_signals_runs_and_sources.php'))->up();

        RoleKey::flushCache();
        Cache::flush();
        Storage::fake('local');
        Carbon::setTestNow();

        config([
            'ai.provider.gemini.api_key' => 'test-key', 'signals.ai_attempts' => 1,
            'signals.web_search.driver' => 'tavily', 'signals.web_search.tavily_key' => 'tvly-test-key',
        ]);

        // Public-looking DNS for every hostname; the network is never touched.
        $this->app->instance(SafeUrlFetcher::class, new SafeUrlFetcher(fn () => ['93.184.216.34']));

        DB::table('tbluserprofilemaster')->insert([
            ['id' => 1, 'name' => 'Administrator', 'role_key' => 'administrator'],
            ['id' => 2, 'name' => 'Employee', 'role_key' => 'employee'],
            ['id' => 3, 'name' => 'Reporting Manager', 'role_key' => 'reporting_manager'],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function h(int $tenant = self::T1, int $profile = 1): array
    {
        $id = DB::table('tbluser')->insertGetId(['user_name' => 'u' . uniqid(), 'sub_institute_id' => $tenant, 'user_profile_id' => $profile]);

        return ['Authorization' => 'Bearer ' . tbluserModel::find($id)->createToken('t')->plainTextToken];
    }

    private function profile(int $tenant = self::T1, array $o = []): ProductProfile
    {
        return ProductProfile::create($o + [
            'sub_institute_id' => $tenant, 'product_name' => 'PayrollPro', 'description' => 'Payroll software for mid-size firms.',
            'problems_solved' => 'Manual payroll errors', 'features' => 'Automated payroll runs',
            'target_industries' => ['Manufacturing'], 'target_markets' => ['India'], 'keywords' => ['payroll automation'],
            'excluded' => ['Gambling'], 'research_enabled' => true, 'research_frequency' => 'daily', 'recency_days' => 30,
        ]);
    }

    private const SNIPPET = 'Acme Industries announced a new plant in Pune and said it is hiring 200 people while replacing its manual payroll system.';

    private function fakeWorld(array $opportunities, ?array $searchResults = null, int $searchStatus = 200, int $aiStatus = 200, ?string $aiText = null): void
    {
        $this->freshHttp();
        $searchResults ??= [['url' => 'https://news.example.com/acme-pune', 'title' => 'Acme opens Pune plant', 'content' => self::SNIPPET, 'published_date' => now()->subDays(2)->toDateString()]];
        $ai = $aiText ?? json_encode(['opportunities' => $opportunities]);

        Http::fake([
            'api.tavily.com/*' => Http::response(['results' => $searchResults], $searchStatus),
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => $ai]]], 'finishReason' => 'STOP']],
                'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
            ], $aiStatus),
            '*/robots.txt' => Http::response('', 404),
            '*' => Http::response('<html><head><title>Acme</title></head><body><p>' . self::SNIPPET . '</p><p>The company said the plant will employ staff across engineering, finance and operations.</p></body></html>', 200, ['Content-Type' => 'text/html']),
        ]);
    }

    /** Http::fake() stacks stubs (first match wins); tests that re-fake need a clean factory. */
    private function freshHttp(): void
    {
        $this->app->forgetInstance(\Illuminate\Http\Client\Factory::class);
        Http::clearResolvedInstance(\Illuminate\Http\Client\Factory::class);
    }

    private function opportunity(array $o = []): array
    {
        return $o + [
            'company_name' => 'Acme Industries', 'website' => 'https://acme.example.com', 'industry' => null, 'location' => 'Pune',
            'buyer_type' => 'enterprise', 'context' => null, 'trigger_type' => 'expansion',
            'what_happened' => 'Acme announced a Pune plant and 200 hires.',
            'trigger_summary' => 'Hiring 200 people in Pune while replacing its manual payroll system.',
            'likely_problem' => 'Fast hiring is straining its manual payroll. Our payroll automation fits that gap.',
            'what_we_could_sell' => 'payroll automation pilot for the new Pune plant HR team', 'product_part' => 'school_erp', 'fit_reason' => 'The new plant needs one system for its hiring and payroll.', 'intent_indicators' => [],
            'entry_point' => 'pilot', 'entry_point_reason' => null,
            'soft_marketing_angle' => 'Open with the cost of manual payroll errors during a 200-person hiring surge.',
            'likely_stakeholder' => null,
            'scores' => ['buying_signal' => 4, 'problem_fit' => 4, 'product_fit' => 4, 'accessibility' => 3, 'urgency' => 3, 'potential_value' => 3, 'evidence_quality' => 3, 'reasoning' => 'Clear hiring surge.'],
            'inference_reasoning' => null, 'event_date' => null, 'source_ids' => ['S1'],
            'evidence_quote' => 'Acme Industries announced a new plant in Pune and said it is hiring 200 people',
        ];
    }

    private function runResearch(?array $headers = null)
    {
        return $this->postJson('/api/signals/research/run', [], $headers ?? $this->h());
    }

    // ── product profile ──────────────────────────────────────────────────────

    // ── daily research ───────────────────────────────────────────────────────

    public function test_research_is_refused_when_no_search_provider_is_configured(): void
    {
        $this->profile();
        config(['signals.web_search.driver' => 'none']);
        Http::fake();

        $this->runResearch()->assertStatus(422)->assertJsonPath('code', 'research_not_configured');
        $this->getJson('/api/signals/research/status', $this->h())->assertJsonPath('data.requirements.search_configured', false);
        Http::assertNothingSent();

        // The scheduled path records the same reason instead of fabricating anything.
        $this->artisan('signals:research', ['--tenant' => self::T1])->assertExitCode(0);
        $this->assertSame('research_not_configured', ResearchRun::sole()->error_code);
        $this->assertSame(0, CompanyOpportunity::count());
    }

    public function test_placeholder_search_key_counts_as_not_configured(): void
    {
        $this->profile();
        config(['signals.web_search.tavily_key' => 'YOUR_TAVILY_KEY']);
        Http::fake();
        $this->runResearch()->assertStatus(422)->assertJsonPath('code', 'research_not_configured');
    }

    public static function searchFailures(): array
    {
        return ['bad key' => [401, 'research_invalid_credentials'], 'rate' => [429, 'research_rate_limited'], 'billing' => [402, 'research_billing'], 'outage' => [503, 'research_provider_failed']];
    }

    public function test_invalid_ai_json_fails_the_run(): void
    {
        $this->profile();
        $this->fakeWorld([], aiText: 'definitely not json');
        $this->runResearch()->assertStatus(202);

        $this->assertSame('failed', ResearchRun::sole()->status);
        $this->assertSame(0, CompanyOpportunity::count());
    }

    public function test_manual_research_cooldown_and_duplicate_submission(): void
    {
        $this->profile();
        $this->fakeWorld([]);
        $h = $this->h();

        $this->runResearch($h)->assertStatus(202);
        $this->runResearch($h)->assertStatus(429)->assertJsonPath('code', 'cooldown');
    }

    public function test_employee_cannot_start_research(): void
    {
        $this->profile();
        Http::fake();
        $this->runResearch($this->h(self::T1, 2))->assertStatus(403);
        Http::assertNothingSent();
    }

    // ── scheduling ───────────────────────────────────────────────────────────

    public function test_next_run_and_due_logic_follow_the_configured_time_and_timezone(): void
    {
        $profile = $this->profile(self::T1, ['schedule_time' => null]);
        $svc = app(ProductProfileService::class);
        config(['signals.timezone' => 'Asia/Kolkata']);

        // 09:00 IST (03:30 UTC) - before 10:00.
        $before = Carbon::parse('2026-10-05 03:30:00', 'UTC'); // Monday
        $this->assertFalse($svc->isDue($profile, $before));
        $this->assertSame('2026-10-05 10:00', $svc->nextRun($profile, $before)->format('Y-m-d H:i'));

        // 10:05 IST - due until a scheduled run exists for today.
        $after = Carbon::parse('2026-10-05 04:35:00', 'UTC');
        $this->assertTrue($svc->isDue($profile, $after));
        ResearchRun::create(['sub_institute_id' => self::T1, 'report_date' => '2026-10-05', 'trigger' => 'scheduled', 'status' => 'success']);
        $this->assertFalse($svc->isDue($profile, $after));
        $this->assertSame('2026-10-06 10:00', $svc->nextRun($profile, $after)->format('Y-m-d H:i'));

        // Custom time and weekday-only frequency.
        $profile->update(['schedule_time' => '14:30', 'research_frequency' => 'weekdays']);
        $friday = Carbon::parse('2026-10-09 05:00:00', 'UTC'); // 10:30 IST Friday
        $this->assertSame('2026-10-09 14:30', $svc->nextRun($profile->fresh(), $friday)->format('Y-m-d H:i'));
        $saturday = Carbon::parse('2026-10-10 05:00:00', 'UTC');
        $this->assertFalse($svc->isDue($profile->fresh(), $saturday));
        $this->assertSame('2026-10-12 14:30', $svc->nextRun($profile->fresh(), $saturday)->format('Y-m-d H:i'));
    }

    public function test_scheduler_registers_the_research_tick(): void
    {
        $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'signals:research'));
        $this->assertNotNull($event);
        $this->assertSame('*/15 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    // ── opportunity feed ─────────────────────────────────────────────────────

    private function seedOpportunity(int $tenant, array $o = []): CompanyOpportunity
    {
        $run = ResearchRun::firstOrCreate(['sub_institute_id' => $tenant, 'report_date' => '2026-10-05', 'trigger' => 'scheduled', 'status' => 'success']);
        $company = Company::create(['sub_institute_id' => $tenant, 'name' => 'Co ' . uniqid(), 'normalized_name' => uniqid()]);

        return CompanyOpportunity::create($o + [
            'sub_institute_id' => $tenant, 'company_id' => $company->id, 'research_run_id' => $run->id, 'title' => 'T ' . uniqid(),
            'category' => 'hiring', 'observed_event' => 'e', 'why_indicates_need' => 'w', 'product_fit' => 'f', 'recommended_action' => 'a',
            'priority' => 'Medium', 'qualification' => 'Monitoring', 'confidence' => 'Medium', 'review_status' => 'New',
            'sources' => [['url' => 'https://x.example.com', 'title' => 'x']], 'fingerprint' => uniqid(), 'first_discovered_at' => now(), 'last_verified_at' => now(),
        ]);
    }

    public function test_opportunity_feed_filters_summary_and_tenant_isolation(): void
    {
        $h = $this->h();
        $a = $this->seedOpportunity(self::T1, ['priority' => 'High', 'category' => 'expansion', 'title' => 'Alpha plant']);
        $this->seedOpportunity(self::T1, ['review_status' => 'Follow-up']);
        $foreign = $this->seedOpportunity(self::T2, ['priority' => 'High']);

        $this->getJson('/api/signals/opportunities', $h)->assertOk()
            ->assertJsonPath('meta.total', 2)->assertJsonPath('summary.new', 1)->assertJsonPath('summary.high_priority', 1)->assertJsonPath('summary.follow_up', 1);
        $this->getJson('/api/signals/opportunities?category=expansion&priority=High&search=Alpha', $h)->assertJsonPath('meta.total', 1);
        $this->getJson('/api/signals/opportunities?report_date=2026-10-05', $h)->assertJsonPath('meta.total', 2);
        $this->getJson('/api/signals/opportunities?report_date=2026-01-01', $h)->assertJsonPath('meta.total', 0);
        $this->getJson('/api/signals/opportunities?priority=Urgent', $h)->assertStatus(422);

        $this->getJson("/api/signals/opportunities/{$a->id}", $h)->assertOk()->assertJsonPath('data.sources.0.url', 'https://x.example.com');
        $this->getJson("/api/signals/opportunities/{$foreign->id}", $h)->assertStatus(404);
        $this->patchJson("/api/signals/opportunities/{$foreign->id}/review", [], $h)->assertStatus(404);
        $this->assertSame('New', $foreign->fresh()->review_status);
    }

    public function test_review_dismiss_and_follow_up_only_change_status(): void
    {
        $h = $this->h();
        $o = $this->seedOpportunity(self::T1);
        Http::fake();

        $this->patchJson("/api/signals/opportunities/{$o->id}/review", [], $h)->assertOk()->assertJsonPath('data.review_status', 'Reviewed');
        $this->patchJson("/api/signals/opportunities/{$o->id}/follow-up", [], $h)->assertOk()->assertJsonPath('data.review_status', 'Follow-up');
        $this->patchJson("/api/signals/opportunities/{$o->id}/dismiss", [], $h)->assertOk()->assertJsonPath('data.review_status', 'Dismissed');
        $this->patchJson("/api/signals/opportunities/{$o->id}/explode", [], $h)->assertStatus(404);
        $this->patchJson("/api/signals/opportunities/{$o->id}/review", [], $this->h(self::T1, 2))->assertStatus(403);
        Http::assertNothingSent(); // nothing contacts anyone
    }

    // ── ingestion: extraction, no AI ─────────────────────────────────────────

    private function upload(string $name, string $bytes, ?array $headers = null)
    {
        return $this->post('/api/signals/ingestion/upload', ['file' => UploadedFile::fake()->createWithContent($name, $bytes)], ($headers ?? $this->h()) + ['Accept' => 'application/json']);
    }

    public function test_each_supported_format_is_extracted_with_references_even_when_ai_is_unavailable(): void
    {
        // AI unconfigured: proves ingestion (extraction) is independent of analysis.
        config(['ai.provider.gemini.api_key' => null]);
        Http::fake();
        $h = $this->h();

        $notes = $this->upload('notes.txt', "Acme needs a payroll system.\nSecond line.", $h);
        $notes->assertStatus(201)->assertJsonPath('data.status', 'ready')->assertJsonPath('data.processing_status', 'uploaded')->assertJsonPath('analysis_started', false)->assertJsonPath('analysis_code', 'ai_not_configured');
        $this->upload('brief.docx', OfficeFixtures::docx(['Acme plans a Pune plant.', 'Hiring 200 people.']), $h)->assertStatus(201);
        $this->upload('leads.xlsx', OfficeFixtures::xlsx(['Leads' => [['Company', 'Need'], ['Acme', 'ERP migration']]]), $h)->assertStatus(201);
        $this->upload('report.pdf', OfficeFixtures::pdf(['Acme quarterly report', 'Second page about hiring']), $h)->assertStatus(201);

        Http::assertNothingSent();

        $refs = fn (string $name) => collect(json_decode(IngestionSource::where('name', $name)->sole()->segments, true))->pluck('ref')->all();
        $this->assertSame(['Section 1'], $refs('notes.txt'));
        $this->assertSame(['Leads row 1', 'Leads row 2'], $refs('leads.xlsx'));
        $this->assertSame(['Page 1', 'Page 2'], $refs('report.pdf'));
        $this->assertStringContainsString('Acme plans a Pune plant.', IngestionSource::where('name', 'brief.docx')->sole()->segments);
        $this->assertStringContainsString('B2: ERP migration', IngestionSource::where('name', 'leads.xlsx')->sole()->segments);
        $this->assertSame(0, IngestionAnalysis::count());

        $sources = $this->getJson('/api/signals/ingestion/sources', $h)->assertOk();
        $this->assertStringNotContainsString('storage_path', $sources->getContent());
        $this->assertStringNotContainsString('signals/ingestion', $sources->getContent());
        $sources->assertJsonPath('data.0.can_retry', true);
        Storage::disk('local')->assertExists(IngestionSource::where('name', 'notes.txt')->sole()->storage_path);
    }

    public function test_unsupported_and_unreadable_files_are_rejected_clearly(): void
    {
        $h = $this->h();
        $this->upload('legacy.xls', 'not really excel', $h)->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, '.xlsx'));
        $this->upload('bad.docx', 'this is not a zip', $h)->assertStatus(422);
        $this->upload('malware.exe', 'MZ', $h)->assertStatus(422)->assertJsonValidationErrors('file');
        $this->upload('empty.pdf', 'not a pdf at all', $h)->assertStatus(422);
        $this->assertSame(0, Storage::disk('local')->allFiles() === [] ? 0 : count(Storage::disk('local')->allFiles()));
    }

    public function test_ingestion_is_admin_hr_only_and_tenant_scoped(): void
    {
        $this->upload('a.txt', 'Acme text for tenant one.')->assertStatus(201);
        $source = IngestionSource::sole();

        $this->getJson('/api/signals/ingestion/sources', $this->h(self::T1, 2))->assertStatus(403);
        $this->getJson('/api/signals/ingestion/sources', $this->h(self::T1, 3))->assertStatus(403);
        $other = $this->h(self::T2);
        $this->getJson('/api/signals/ingestion/sources', $other)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/signals/ingestion/sources/{$source->id}", $other)->assertStatus(404);
        $this->postJson("/api/signals/ingestion/sources/{$source->id}/analyze", [], $other)->assertStatus(404);
        $this->deleteJson("/api/signals/ingestion/sources/{$source->id}", [], $other)->assertStatus(404);
    }

    // ── ingestion: URL + SSRF ────────────────────────────────────────────────

    public function test_url_ingestion_stores_title_time_and_referenced_text(): void
    {
        Http::fake([
            '*/robots.txt' => Http::response("User-agent: *\nDisallow: /private", 200),
            '*' => Http::response('<html><head><title>Acme Careers</title></head><body><nav>menu</nav><p>' . str_repeat('Acme is hiring engineers in Pune for its new plant. ', 3) . '</p><script>evil()</script></body></html>', 200, ['Content-Type' => 'text/html; charset=utf-8']),
        ]);

        $this->postJson('/api/signals/ingestion/url', ['url' => 'https://acme.example.com/careers'], $this->h())
            ->assertStatus(201)->assertJsonPath('data.type', 'url')->assertJsonPath('data.page_title', 'Acme Careers');

        $source = IngestionSource::sole();
        $this->assertSame('https://acme.example.com/careers', $source->url);
        $this->assertNotNull($source->retrieved_at);
        $this->assertStringContainsString('hiring engineers in Pune', $source->segments);
        $this->assertStringNotContainsString('evil()', $source->segments);
        $this->assertStringNotContainsString('menu', $source->segments);
    }

    public function test_robots_txt_disallow_blocks_url_ingestion(): void
    {
        Http::fake(['*/robots.txt' => Http::response("User-agent: *\nDisallow: /careers", 200), '*' => Http::response('<p>' . str_repeat('x', 100) . '</p>')]);

        $this->postJson('/api/signals/ingestion/url', ['url' => 'https://acme.example.com/careers/eng'], $this->h())
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'robots'));
        $this->assertSame(0, IngestionSource::count());
    }

    public function test_robots_group_selection_and_longest_match(): void
    {
        $robots = "User-agent: *\nDisallow: /\nAllow: /public\n\nUser-agent: g2g-signals-bot\nDisallow: /secret";
        $this->assertTrue(RobotsPolicy::evaluate($robots, '/anything', 'g2g-signals-bot'));
        $this->assertFalse(RobotsPolicy::evaluate($robots, '/secret/x', 'g2g-signals-bot'));
        $this->assertTrue(RobotsPolicy::evaluate($robots, '/public/page', 'otherbot'));
        $this->assertFalse(RobotsPolicy::evaluate($robots, '/private', 'otherbot'));
    }

    #[DataProvider('blockedUrls')]
    public function test_ssrf_and_unsafe_urls_are_blocked_before_any_request(string $url): void
    {
        Http::fake();
        $this->postJson('/api/signals/ingestion/url', ['url' => $url], $this->h())->assertStatus(422);
        Http::assertNothingSent();
        $this->assertSame(0, IngestionSource::count());
    }

    public static function blockedUrls(): array
    {
        return [
            'loopback ip' => ['http://127.0.0.1/admin'], 'localhost' => ['http://localhost/'], 'subdomain of localhost' => ['http://api.localhost/'],
            'private 10.x' => ['http://10.0.0.5/'], 'private 192.168' => ['https://192.168.1.10/'], 'private 172.16' => ['http://172.16.0.1/'],
            'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'], 'ipv6 loopback' => ['http://[::1]/'],
            'ipv6 ula' => ['http://[fd00::1]/'], 'ipv4-mapped ipv6' => ['http://[::ffff:10.0.0.1]/'], 'cgnat' => ['http://100.64.0.1/'],
            'zero' => ['http://0.0.0.0/'], 'file scheme' => ['file:///etc/passwd'], 'ftp scheme' => ['ftp://example.com/'],
            'gopher' => ['gopher://example.com/'], 'credentials' => ['https://user:pass@example.com/'],
            'odd port' => ['http://example.com:8080/'], 'ssh port' => ['http://example.com:22/'], 'internal tld' => ['http://db.internal/'],
            'garbage' => ['not a url'], 'no host' => ['http:///path'],
        ];
    }

    public function test_hostname_resolving_to_a_private_address_is_blocked(): void
    {
        $fetcher = new SafeUrlFetcher(fn () => ['93.184.216.34', '10.1.2.3']); // ONE bad answer is enough
        Http::fake();
        $this->expectException(UnsafeUrlException::class);
        try {
            $fetcher->fetch('https://sneaky.example.com/');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_redirect_to_an_internal_address_is_refused(): void
    {
        Http::fake(['*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/'])]);
        $fetcher = new SafeUrlFetcher(fn () => ['93.184.216.34']);

        try {
            $fetcher->fetch('https://public.example.com/redirect');
            $this->fail('redirect to metadata address was followed');
        } catch (UnsafeUrlException) {
            Http::assertSentCount(1); // the metadata URL was never requested
        }
    }

    public function test_redirect_chain_is_bounded_and_non_html_is_rejected(): void
    {
        Http::fake(['*' => Http::response('', 302, ['Location' => 'https://public.example.com/loop'])]);
        $fetcher = new SafeUrlFetcher(fn () => ['93.184.216.34']);
        try {
            $fetcher->fetch('https://public.example.com/loop');
            $this->fail('redirect loop not stopped');
        } catch (UnsafeUrlException $e) {
            $this->assertSame('Too many redirects.', $e->getMessage());
        }

        Http::fake(['*' => Http::response('%PDF-1.4', 200, ['Content-Type' => 'application/pdf'])]);
        $this->expectException(UnsafeUrlException::class);
        $fetcher->fetch('https://public.example.com/file.pdf');
    }

    public function test_public_ip_classification(): void
    {
        foreach (['93.184.216.34', '8.8.8.8', '2606:4700:4700::1111'] as $ip) {
            $this->assertTrue(SafeUrlFetcher::isPublicIp($ip), $ip);
        }
        foreach (['127.0.0.1', '10.0.0.1', '172.16.5.4', '192.168.0.1', '169.254.169.254', '100.64.1.1', '0.0.0.0', '::1', 'fe80::1', 'fc00::1', '::ffff:127.0.0.1', '224.0.0.1', 'nonsense'] as $ip) {
            $this->assertFalse(SafeUrlFetcher::isPublicIp($ip), $ip);
        }
    }

    // ── ingestion: automatic analysis ────────────────────────────────────────

    private function fakeAnalysis(array $findings, int $status = 200, ?string $text = null): void
    {
        $this->freshHttp();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => $text ?? json_encode(['findings' => $findings])]]], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
        ], $status)]);
    }

    private function finding(array $o = []): array
    {
        return $o + [
            'kind' => 'requirement', 'title' => 'Needs a new payroll system', 'detail' => 'The document states payroll must be replaced.',
            'business_impact' => 'Manual payroll will not scale with hiring.', 'suggested_action' => 'Shortlist automated payroll vendors.',
            'priority' => 'High', 'confidence' => 'Medium',
            'evidence' => [['ref' => 'Section 1', 'quote' => 'Acme needs a payroll system by March']],
        ];
    }

    public function test_invalid_ai_json_is_a_failed_analysis_with_retry(): void
    {
        $h = $this->h();
        $this->fakeAnalysis([], text: 'garbage, not json');
        $this->upload('req.txt', 'Acme needs a payroll system by March.', $h)->assertStatus(201);

        $this->assertSame('failed', IngestionAnalysis::sole()->status);
        $this->assertSame('ready', IngestionSource::sole()->status);
        $this->assertSame(0, IngestionFinding::count());
    }

    public function test_without_ai_the_source_is_kept_and_retry_explains_why(): void
    {
        $h = $this->h();
        config(['ai.provider.gemini.api_key' => null]);
        Http::fake();

        $this->upload('req.txt', 'Acme needs a payroll system by March.', $h)->assertStatus(201)->assertJsonPath('analysis_started', false);
        $source = IngestionSource::sole();
        $this->postJson("/api/signals/ingestion/sources/{$source->id}/analyze", [], $h)->assertStatus(422)->assertJsonPath('code', 'ai_not_configured');
        Http::assertNothingSent();
        $this->assertSame(0, IngestionAnalysis::count());
    }

    public function test_failed_extraction_is_never_analysed_and_retry_is_refused(): void
    {
        $h = $this->h();
        Http::fake();
        $this->upload('legacy.xls', 'x', $h)->assertStatus(422);
        $bad = IngestionSource::where('status', 'failed')->sole();
        $this->getJson('/api/signals/ingestion/sources', $h)->assertJsonPath('data.0.processing_status', 'failed')->assertJsonPath('data.0.can_retry', false);
        $this->postJson("/api/signals/ingestion/sources/{$bad->id}/analyze", [], $h)->assertStatus(422)->assertJsonPath('code', 'source_not_ready');
        Http::assertNothingSent();
    }

    public function test_an_analysis_that_lost_its_worker_is_closed_as_stale_and_can_be_retried(): void
    {
        $h = $this->h();
        config(['ai.provider.gemini.api_key' => null]);
        $this->upload('req.txt', 'Acme needs a payroll system by March.', $h);
        $source = IngestionSource::sole();
        IngestionAnalysis::create(['sub_institute_id' => self::T1, 'source_id' => $source->id, 'status' => 'running', 'started_at' => now()->subHour()]);

        $listed = $this->getJson('/api/signals/ingestion/sources', $h)->assertOk();
        $listed->assertJsonPath('data.0.processing_status', 'failed')->assertJsonPath('data.0.can_retry', true);
        $this->assertStringContainsString('queue worker', (string) $listed->json('data.0.processing_message'));
        $this->assertSame('timed_out', IngestionAnalysis::sole()->error_code);
    }

    // ── providers: status + real connection tests ────────────────────────────

    private function fakeAiOk(string $text = '{"ok":true}', int $status = 200): void
    {
        $this->freshHttp();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => $text]]], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
        ], $status)]);
    }

    public function test_provider_status_shows_masked_keys_and_never_the_key_itself(): void
    {
        config(['ai.provider.gemini.api_key' => 'AIzaSyEXAMPLEexampleEXAMPLEexample12345', 'signals.web_search.tavily_key' => 'tvly-dev-SECRETSECRETSECRET1234']);
        $body = $this->getJson('/api/signals/providers', $this->h())->assertOk();

        $body->assertJsonPath('data.ai.provider', 'gemini')->assertJsonPath('data.ai.configured', true)
            ->assertJsonPath('data.search.driver', 'tavily')->assertJsonPath('data.search.configured', true)
            ->assertJsonPath('data.ai.last_test', null)->assertJsonPath('data.search.last_test', null);
        $this->assertStringNotContainsString('EXAMPLEexampleEXAMPLE', $body->getContent());
        $this->assertStringNotContainsString('SECRETSECRET', $body->getContent());
        $this->assertStringStartsWith('AIza', $body->json('data.ai.key_masked'));
        $this->assertStringContainsString('••••', $body->json('data.search.key_masked'));
    }

    public function test_configured_is_not_connected_until_a_real_test_succeeds(): void
    {
        $h = $this->h();
        $this->getJson('/api/signals/providers', $h)->assertJsonPath('data.ai.configured', true)->assertJsonPath('data.ai.last_test', null);

        $this->fakeAiOk();
        $this->postJson('/api/signals/providers/ai/test', [], $h)->assertOk()->assertJsonPath('data.ok', true)->assertJsonPath('data.category', 'ok')->assertJsonPath('data.model', fn ($m) => $m !== null);
        Http::assertSentCount(1);

        $state = $this->getJson('/api/signals/providers', $h)->assertOk();
        $state->assertJsonPath('data.ai.last_test.ok', true);
        $this->assertNotNull($state->json('data.ai.last_test.last_success_at'));
    }

    public function test_ai_test_reports_the_real_failure_category_and_keeps_the_last_success(): void
    {
        $h = $this->h();
        $this->fakeAiOk();
        $this->postJson('/api/signals/providers/ai/test', [], $h)->assertJsonPath('data.ok', true);

        $this->fakeAiOk('{}', 401);
        $failed = $this->postJson('/api/signals/providers/ai/test', [], $h)->assertOk();
        $failed->assertJsonPath('data.ok', false)->assertJsonPath('data.category', 'ai_invalid_credentials');
        $this->assertNotNull($failed->json('data.last_success_at')); // "last proven working" survives a later failure
        $this->assertStringNotContainsString('test-key', $failed->getContent());

        $this->fakeAiOk('not json at all');
        $this->postJson('/api/signals/providers/ai/test', [], $h)->assertJsonPath('data.ok', false)->assertJsonPath('data.category', 'invalid_response');

        config(['ai.provider.gemini.api_key' => null]);
        $this->postJson('/api/signals/providers/ai/test', [], $h)->assertJsonPath('data.category', 'not_configured');
    }

    public function test_search_test_validates_a_real_response_and_classifies_failures(): void
    {
        $h = $this->h();
        $this->freshHttp();
        Http::fake(['api.tavily.com/*' => Http::response(['results' => [
            ['url' => 'https://news.example.com/a', 'title' => 'A', 'content' => 'x', 'published_date' => '2026-09-01'],
            ['url' => 'javascript:alert(1)', 'title' => 'bad url', 'content' => 'x'],
            ['url' => 'https://no-title.example.com', 'title' => '', 'content' => 'x'],
        ]])]);
        $this->postJson('/api/signals/providers/search/test', [], $h)->assertOk()
            ->assertJsonPath('data.ok', true)->assertJsonPath('data.results', 1)->assertJsonPath('data.sample_domain', 'news.example.com');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.tavily.com'));

        $this->freshHttp();
        Http::fake(['api.tavily.com/*' => Http::response(['detail' => 'Unauthorized: missing or invalid API key.'], 401)]);
        $bad = $this->postJson('/api/signals/providers/search/test', [], $h)->assertOk();
        $bad->assertJsonPath('data.ok', false)->assertJsonPath('data.category', 'research_invalid_credentials');
        $this->assertStringNotContainsString('tvly-test-key', $bad->getContent());

        $this->freshHttp();
        Http::fake(['api.tavily.com/*' => Http::response(['results' => []])]);
        $this->postJson('/api/signals/providers/search/test', [], $h)->assertJsonPath('data.category', 'no_results');

        config(['signals.web_search.tavily_key' => null]);
        Http::fake();
        $this->postJson('/api/signals/providers/search/test', [], $h)->assertJsonPath('data.category', 'not_configured');
    }

    public function test_provider_tests_are_admin_hr_only_and_status_is_tenant_scoped(): void
    {
        Http::fake();
        $this->postJson('/api/signals/providers/ai/test', [], $this->h(self::T1, 2))->assertStatus(403);
        $this->postJson('/api/signals/providers/search/test', [], $this->h(self::T1, 3))->assertStatus(403);
        $this->getJson('/api/signals/providers', $this->h(self::T1, 2))->assertStatus(403);
        $this->getJson('/api/signals/providers', $this->h(self::T1, 3))->assertOk(); // manager may read status
        Http::assertNothingSent();

        $this->fakeAiOk();
        $this->postJson('/api/signals/providers/ai/test', [], $this->h(self::T1))->assertJsonPath('data.ok', true);
        // Another organisation has not proven anything.
        $this->getJson('/api/signals/providers', $this->h(self::T2))->assertJsonPath('data.ai.last_test', null);
    }

    public function test_scheduler_heartbeat_and_queue_backlog_are_reported_honestly(): void
    {
        $h = $this->h();
        $this->getJson('/api/signals/providers', $h)->assertJsonPath('data.runtime.scheduler_running', false)->assertJsonPath('data.runtime.scheduler_last_tick_at', null);

        $this->artisan('signals:research')->assertExitCode(0); // a scheduler tick, nothing due
        $this->getJson('/api/signals/providers', $h)->assertJsonPath('data.runtime.scheduler_running', true);

        Carbon::setTestNow(now()->addHour());
        $this->getJson('/api/signals/providers', $h)->assertJsonPath('data.runtime.scheduler_running', false); // stale tick
        Carbon::setTestNow();

        config(['queue.default' => 'database']);
        \Illuminate\Support\Facades\Schema::create('jobs', function ($t) {
            $t->id(); $t->string('queue')->index(); $t->longText('payload'); $t->unsignedTinyInteger('attempts');
            $t->unsignedInteger('reserved_at')->nullable(); $t->unsignedInteger('available_at'); $t->unsignedInteger('created_at');
        });
        DB::table('jobs')->insert(['queue' => 'signals', 'payload' => '{}', 'attempts' => 0, 'available_at' => now()->subMinutes(10)->getTimestamp(), 'created_at' => now()->subMinutes(10)->getTimestamp()]);
        $this->getJson('/api/signals/providers', $h)->assertJsonPath('data.runtime.queue_worker_suspected_down', true)->assertJsonPath('data.runtime.queue_jobs_waiting_over_2_min', 1);
    }

    // ── daily research: source records and signal kinds ──────────────────────

    // ── independence ─────────────────────────────────────────────────────────

    public function test_a_research_failure_does_not_affect_ingestion_and_vice_versa(): void
    {
        $this->profile();
        $h = $this->h();
        $this->fakeWorld([], searchStatus: 500);
        $this->runResearch($h)->assertStatus(202);
        $this->assertSame('failed', ResearchRun::sole()->status);

        $this->upload('still-works.txt', 'Acme needs a payroll system by March.', $h)->assertStatus(201);
        $this->assertSame('ready', IngestionSource::sole()->status);

        // And the two stores never mix provenance.
        $this->assertSame(0, CompanyOpportunity::count());
        $this->assertSame(0, IngestionFinding::count());
    }

    // ── ingestion: leads from the document go through the same gate as web research ──

    /** One answer for the findings call, another for the leads call (told apart by the system prompt). */
    private function fakeDocumentAi(array $opportunities): void
    {
        $this->freshHttp();
        Http::fake(function ($request) use ($opportunities) {
            $isFindings = str_contains((string) $request->body(), 'extract signals relevant to the business');
            $text = json_encode($isFindings ? ['summary' => 'A note.', 'entities' => [], 'findings' => []] : ['opportunities' => $opportunities]);

            return Http::response([
                'candidates' => [['content' => ['parts' => [['text' => $text]]], 'finishReason' => 'STOP']],
                'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
            ], 200);
        });
    }

    public function test_a_document_that_states_a_reason_becomes_a_gated_company_opportunity_linked_to_the_file(): void
    {
        $this->profile();
        $this->fakeDocumentAi([$this->opportunity()]);

        $this->upload('note.txt', self::SNIPPET, $this->h())->assertStatus(201);

        $source = IngestionSource::sole();
        $opp = CompanyOpportunity::sole();
        $this->assertSame($source->id, (int) $opp->ingestion_source_id);
        $this->assertSame('Acme Industries', Company::find($opp->company_id)->name);
        $this->assertSame('page_text', $opp->fetch_level);
        $this->assertStringContainsString('/api/signals/ingestion/sources/' . $source->id, (string) $opp->sources[0]['url']);
        $this->assertNotNull($opp->feed_section);
        // Not the old shortcut: no pseudo-company named after the file, no generic "Enterprise Solution" fit.
        $this->assertNotSame('Enterprise Solution', $opp->product_name);
    }

    public function test_a_quote_the_document_does_not_contain_makes_no_lead(): void
    {
        $this->profile();
        $this->fakeDocumentAi([$this->opportunity(['evidence_quote' => 'Acme Industries has approved a five million dollar payroll budget'])]);

        $this->upload('note.txt', self::SNIPPET, $this->h())->assertStatus(201);

        $this->assertSame(0, CompanyOpportunity::count());
    }

    public function test_a_list_of_names_with_no_event_makes_no_lead(): void
    {
        $this->profile();
        $this->fakeDocumentAi([]);

        $this->upload('names.csv', "name,city
Acme Industries,Pune
Beta Works,Surat
", $this->h())->assertStatus(201);

        $this->assertSame(0, CompanyOpportunity::count());
        $this->assertSame(1, IngestionSource::count());
    }

    public function test_analysis_still_succeeds_when_the_lead_step_fails(): void
    {
        $this->profile();
        $this->fakeDocumentAi([['company_name' => 12345]]);   // junk from the model

        $this->upload('note.txt', self::SNIPPET, $this->h())->assertStatus(201);

        $this->assertSame('success', IngestionAnalysis::sole()->status);
        $this->assertSame(0, CompanyOpportunity::count());
    }

    public function test_a_lead_whose_headline_is_too_long_is_shortened_not_thrown_away(): void
    {
        $this->profile();
        $long = 'Acme Industries is opening a brand new plant in Pune this year and urgently needs a payroll system that can handle two hundred new hires without errors.';
        $this->freshHttp();
        Http::fake(function ($request) use ($long) {
            $body = (string) $request->body();
            $text = match (true) {
                str_contains($body, 'extract signals relevant to the business') => json_encode(['summary' => 'x', 'entities' => [], 'findings' => []]),
                str_contains($body, 'You fix lead cards') => json_encode(['fixes' => [['index' => 0, 'headline' => 'Acme Industries needs a payroll system for its new Pune plant.']]]),
                default => json_encode(['opportunities' => [$this->opportunity(['needs_statement' => $long])]]),
            };

            return Http::response(['candidates' => [['content' => ['parts' => [['text' => $text]]], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1]], 200);
        });

        $this->upload('note.txt', self::SNIPPET, $this->h())->assertStatus(201);

        $opp = CompanyOpportunity::sole();
        $this->assertSame('Acme Industries needs a payroll system for its new Pune plant.', $opp->title);
    }
}
