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
 * Run-date history and the repairs behind "Signal generation failed": only dates with a successful run, saved results per date without any
 * research call, the built-in ScholarClone definition not being replaced by a frozen seeded copy, and no use of the column check that
 * MariaDB 10.1 cannot answer. All provider traffic is mocked (and asserted absent where it must be); sqlite in memory.
 */
class ResearchDateHistoryTest extends TestCase
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

    private function runOn(string $date, string $status, ?string $product = 'scholarclone', int $tenant = self::T, int $qualified = 0): ResearchRun
    {
        return ResearchRun::create([
            'sub_institute_id' => $tenant, 'report_date' => $date, 'trigger' => 'manual', 'status' => $status, 'product_key' => $product,
            'started_at' => $date . ' 10:00:00', 'completed_at' => $date . ' 10:05:00', 'opportunities_qualified' => $qualified,
        ]);
    }

    private function savedSignal(ResearchRun $run, string $fit = 'scholar', string $name = 'Sig', int $tenant = self::T): int
    {
        $company = \App\Domain\Signals\Opportunities\Company::create(['sub_institute_id' => $tenant, 'name' => $name . uniqid(), 'normalized_name' => strtolower($name . uniqid())]);

        return (int) DB::table('g2g_company_opportunities')->insertGetId([
            'sub_institute_id' => $tenant, 'company_id' => $company->id, 'research_run_id' => $run->id, 'business_fit' => $fit,
            'title' => $name, 'category' => 'x', 'observed_event' => 'e', 'why_indicates_need' => 'w', 'product_fit' => 'p', 'recommended_action' => 'a',
            'priority' => 'Low', 'qualification' => 'Monitoring', 'confidence' => 'Low', 'sources' => '[]', 'confirmed_facts' => '[]', 'unverified_claims' => '[]',
            'fingerprint' => uniqid('f'), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function dates(string $product, ?array $headers = null)
    {
        return $this->getJson('/api/signals/research/dates?product=' . $product, $headers ?? $this->h());
    }

    // ── the date dropdown ────────────────────────────────────────────────────

    public function test_only_dates_with_a_successful_run_of_that_product_are_offered_newest_first_without_labels(): void
    {
        $this->runOn('2026-10-12', 'success', 'scholarclone', self::T, 3);
        $this->runOn('2026-10-12', 'success');                         // same day: one date
        $this->runOn('2026-10-13', 'partial');                         // finished with limits: the existing rule counts it
        $this->runOn('2026-10-11', 'success');                         // zero opportunities is still a successful run
        $this->runOn('2026-10-14', 'failed');
        $this->runOn('2026-10-15', 'skipped');
        $this->runOn('2026-10-16', 'running');
        $this->runOn('2026-10-10', 'success', null);                   // made before products were recorded: no product, not offered
        $this->runOn('2026-10-09', 'success', 'enterprise_brain');
        $this->runOn('2026-10-08', 'success', 'scholarclone', self::T2);
        $before = ResearchRun::count();
        Http::fake();

        $r = $this->dates('scholarclone')->assertOk();

        $this->assertSame(['2026-10-13', '2026-10-12', '2026-10-11'], array_column($r->json('data.dates'), 'date'));
        $this->assertSame('Asia/Kolkata', $r->json('data.timezone'));
        $byDate = collect($r->json('data.dates'))->keyBy('date');
        $this->assertSame([2, 'success'], [$byDate['2026-10-12']['runs'], $byDate['2026-10-12']['status']]);
        $this->assertSame('partial', $byDate['2026-10-13']['status']);
        $this->assertSame(3, $byDate['2026-10-12']['qualified']);
        $this->assertSame(0, $byDate['2026-10-11']['qualified']);

        $this->assertSame(['2026-10-09'], array_column($this->dates('enterprise_brain')->json('data.dates'), 'date'));
        $this->assertSame([], $this->dates('g2g')->json('data.dates'));
        $this->assertSame(['2026-10-08'], array_column($this->dates('scholarclone', $this->h(self::T2))->json('data.dates'), 'date'), 'another organisation sees only its own');

        $this->assertSame($before, ResearchRun::count(), 'reading the history starts no run');
        Http::assertNothingSent();
        $this->dates('nonsense')->assertStatus(422);
        $this->getJson('/api/signals/research/dates?product=scholarclone')->assertStatus(401);
    }

    public function test_a_product_with_no_successful_run_has_no_dates_and_today_is_not_invented(): void
    {
        $this->runOn('2026-10-14', 'failed');

        $this->assertSame([], $this->dates('scholarclone')->assertOk()->json('data.dates'));
    }

    // ── a past day's saved results ───────────────────────────────────────────

    public function test_choosing_a_date_returns_exactly_what_that_days_successful_runs_saved_without_any_research(): void
    {
        $a = $this->runOn('2026-10-12', 'success');
        $b = $this->runOn('2026-10-12', 'success');
        $partial = $this->runOn('2026-10-13', 'partial');
        $failed = $this->runOn('2026-10-14', 'failed');
        $eb = $this->runOn('2026-10-12', 'success', 'enterprise_brain');
        $other = $this->runOn('2026-10-12', 'success', 'scholarclone', self::T2);
        $s1 = $this->savedSignal($a);
        $s2 = $this->savedSignal($b);
        $s3 = $this->savedSignal($partial);
        $this->savedSignal($failed);
        $this->savedSignal($eb, 'eb');
        $this->savedSignal($other, 'scholar', 'Theirs', self::T2);
        $legacy = $this->savedSignal($a, 'scholar', 'Legacy');
        DB::table('g2g_company_opportunities')->where('id', $legacy)->update(['business_fit' => null, 'research_run_id' => $a->id]);
        $runsBefore = ResearchRun::count();
        $latest = ResearchRun::orderByDesc('id')->first()->only(['id', 'status']) + ['at' => (string) ResearchRun::orderByDesc('id')->first()->completed_at];
        Http::fake();

        $ids = fn (string $q) => collect($this->getJson('/api/signals/opportunities?' . $q, $this->h())->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

        $this->assertSame([$s1, $s2], $ids('product=scholarclone&run_date=2026-10-12'), 'both runs of the day, once each, only this product');
        $this->assertSame([$s3], $ids('product=scholarclone&run_date=2026-10-13'));
        $this->assertSame([], $ids('product=scholarclone&run_date=2026-10-14'), 'a failed run\'s day has no results to show');
        $this->assertSame([], $ids('product=g2g&run_date=2026-10-12'));
        $this->assertSame([], $ids('product=scholarclone&run_date=2026-10-01'), 'a day nothing ran: nothing, and no signal borrowed from a source date');
        $this->assertNotContains($legacy, $ids('product=scholarclone&run_date=2026-10-12'), 'an unassigned record is never taken for ScholarClone');
        $this->getJson('/api/signals/opportunities?product=scholarclone&run_date=12-10-2026', $this->h())->assertStatus(422);

        $row = $this->getJson('/api/signals/opportunities?product=scholarclone&run_date=2026-10-13', $this->h())->json('data.0');
        $this->assertSame('2026-10-13', $row['report_date']);

        $this->assertSame($runsBefore, ResearchRun::count(), 'viewing a date starts no run');
        $this->assertSame($latest, ResearchRun::orderByDesc('id')->first()->only(['id', 'status']) + ['at' => (string) ResearchRun::orderByDesc('id')->first()->completed_at], 'the current run status is untouched');
        Http::assertNothingSent();
    }

    public function test_a_finished_generation_adds_its_date_and_its_saved_signal_to_the_history(): void
    {
        $this->seedPortfolio();
        $this->fake([$this->lead()]);
        $this->runFor('scholarclone');

        $dates = $this->dates('scholarclone')->assertOk()->json('data.dates');
        $this->assertSame(['2026-10-20'], array_column($dates, 'date'));
        $rows = $this->getJson('/api/signals/opportunities?product=scholarclone&run_date=2026-10-20', $this->h())->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('Podar World School', $rows[0]['company_name']);
        $this->assertNotNull($rows[0]['source_url'], 'the stored evidence comes back with it');
    }

    // ── ScholarClone keeps its own current definition ────────────────────────

    public function test_a_seeded_copy_of_the_built_in_definition_does_not_replace_it_but_a_persons_saved_settings_do(): void
    {
        $this->seedPortfolio();
        $template = config('signals_icp.scholar_k12');
        $stale = ['queries' => array_slice($template['queries'], 0, 2)] + $template;
        $row = IcpProfile::create(['sub_institute_id' => self::T, 'key' => 'scholar_k12', 'name' => 'seed', 'product_line' => 'scholar', 'status' => 'active',
            'is_default' => true, 'version' => 1, 'config' => $stale]);   // no author: made by signals:icp-seed

        $ctx = (new \App\Domain\Signals\Products\ProductCatalog())->resolve(self::T, 'scholarclone');
        $this->assertCount(count($template['queries']), $ctx->pack->config()['queries'], 'the full current searches are used, not the frozen copy');
        $this->assertSame($row->id, $ctx->pack->profileId);

        // A person saves their own settings: those now apply.
        $row->update(['version' => 2, 'updated_by' => 5, 'config' => $stale]);
        $ctx = (new \App\Domain\Signals\Products\ProductCatalog())->resolve(self::T, 'scholarclone');
        $this->assertCount(2, $ctx->pack->config()['queries']);
    }

    public function test_the_editor_shows_and_saves_from_the_definition_that_actually_runs_for_a_seeded_scholarclone_icp(): void
    {
        $template = config('signals_icp.scholar_k12');
        IcpProfile::create(['sub_institute_id' => self::T, 'key' => 'scholar_k12', 'name' => 'seed', 'product_line' => 'scholar', 'status' => 'active',
            'is_default' => true, 'version' => 1, 'config' => ['queries' => array_slice($template['queries'], 0, 2)] + $template]);

        $state = $this->getJson('/api/signals/icp/products/scholarclone', $this->h())->assertOk()->json('data');
        $this->assertSame('template', $state['config_source']);
        $this->assertCount(count($template['queries']), $state['config']['queries']);

        $this->putJson('/api/signals/icp/products/scholarclone', ['config' => ['guidance' => 'Prefer schools opening a campus.']], $this->h())->assertOk()->assertJsonPath('data.profile.version', 2);
        $saved = IcpProfile::sole();
        $this->assertCount(count($template['queries']), $saved->config['queries'], 'saving does not shrink the searches to the frozen copy');
        $this->assertSame('Prefer schools opening a campus.', $saved->config['guidance']);
    }

    // ── the failure itself ───────────────────────────────────────────────────

    public function test_a_research_run_never_asks_the_database_for_the_column_list_that_mariadb_10_1_cannot_answer(): void
    {
        $this->seedPortfolio();
        // MariaDB 10.1 fails Schema::hasColumn() with "Unknown column 'generation_expression'". Make that exact call blow up and run a whole research.
        // (App\Support\ColumnExists is the one allowed way to ask; on sqlite it delegates to the real check, so only OTHER callers blow up.)
        Schema::swap(new class(DB::connection()) extends \Illuminate\Database\Schema\SQLiteBuilder {
            private function viaHelper(): bool
            {
                foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8) as $f) {
                    if (($f['class'] ?? null) === \App\Support\ColumnExists::class) {
                        return true;
                    }
                }

                return false;
            }

            public function hasColumn($table, $column)
            {
                return $this->viaHelper() ? parent::hasColumn($table, $column) : throw new \RuntimeException("Unknown column 'generation_expression' in 'field list'");
            }

            public function getColumnListing($table)
            {
                return $this->viaHelper() ? parent::getColumnListing($table) : throw new \RuntimeException("Unknown column 'generation_expression' in 'field list'");
            }
        });
        $this->fake([$this->lead()]);

        $this->runFor('scholarclone');

        $run = ResearchRun::sole();
        $this->assertSame('success', $run->status, (string) $run->error_message);
        $this->assertSame(1, CompanyOpportunity::count());
        $this->assertSame(1, \App\Domain\Signals\Opportunities\ResearchSource::count());
    }

    public function test_no_signals_code_uses_the_column_check_that_fails_on_the_live_database(): void
    {
        $roots = ['app/Domain/Signals', 'app/Http/Controllers/Api/Signals', 'app/Http/Controllers/Api/Portfolio', 'app/Jobs', 'database/migrations'];
        $offenders = [];
        foreach ($roots as $root) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($root), \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $text = file_get_contents($file->getPathname());
                // Of the migrations, only those written for this work are held to it (older ones have already run everywhere).
                if (str_contains($file->getPathname(), 'migrations') && ! preg_match('/2026_(11_29|12_01)/', $file->getFilename())) {
                    continue;
                }
                if (preg_match('/Schema::(hasColumn|hasColumns|getColumnListing|getColumns|getColumnType)\(/', $text)) {
                    $offenders[] = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file->getPathname());
                }
            }
        }
        $this->assertSame([], $offenders, 'use App\Support\ColumnExists (SHOW COLUMNS) instead');
    }

    public function test_column_exists_answers_correctly(): void
    {
        $this->assertTrue(\App\Support\ColumnExists::has('g2g_research_runs', 'product_key'));
        $this->assertFalse(\App\Support\ColumnExists::has('g2g_research_runs', 'no_such_column'));
    }

    public function test_a_database_failure_is_named_safely_instead_of_a_generic_message(): void
    {
        $pdo = new \PDOException("SQLSTATE[42S22]: Column not found: 1054 Unknown column 'generation_expression' in 'field list'");
        $pdo->errorInfo = ['42S22', 1054, "Unknown column 'generation_expression' in 'field list'"];
        $e = new \Illuminate\Database\QueryException('mysql', 'select secret_column from g2g_research_runs where token = ?', ['abc-token'], $pdo);

        $f = \App\Domain\Signals\AiFailureClassifier::classify($e);

        $this->assertSame('database_error', $f['code']);
        $this->assertStringContainsString('42S22', $f['message']);
        $this->assertStringContainsString('generation_expression', $f['message']);
        $this->assertStringContainsString('migrations', $f['message']);
        foreach (['select', 'secret_column', 'abc-token', 'mysql', 'Host'] as $leak) {
            $this->assertStringNotContainsString($leak, $f['message']);
        }
        $this->assertSame('generation_failed', \App\Domain\Signals\AiFailureClassifier::classify(new \RuntimeException('boom'))['code']);
    }

    public function test_nothing_in_the_schedule_runs_signal_generation_automatically(): void
    {
        $this->artisan('schedule:list')->doesntExpectOutputToContain('signals:research')->assertExitCode(0);
    }

    // ── a run that finds nothing says why; the school list moves between environments ──

    public function test_a_general_run_with_no_target_schools_says_so_and_the_history_carries_what_it_did(): void
    {
        $this->seedPortfolio();
        $this->fake([]);   // the model finds no lead

        $this->runFor('scholarclone');

        $run = ResearchRun::sole();
        $this->assertSame('success', $run->status);
        $this->assertStringContainsString('No target schools are loaded for ScholarClone', (string) $run->stage_message);
        $day = $this->dates('scholarclone')->assertOk()->json('data.dates.0');
        $this->assertSame(0, $day['qualified']);
        $this->assertGreaterThan(0, $day['searches']);
        $this->assertStringContainsString('No target schools', (string) $day['note']);

        // With schools loaded the note is not shown.
        $this->account('Podar World School', 'Vadodara', 1);
        Cache::flush();
        $this->travel(11)->minutes();
        $this->fake([]);
        $this->runFor('scholarclone');
        $this->assertStringNotContainsString('No target schools', (string) ResearchRun::orderByDesc('id')->first()->stage_message);
    }

    public function test_target_accounts_export_and_load_into_another_organisation_without_touching_what_is_there(): void
    {
        $a = $this->account('Podar World School', 'Vadodara', 1, 'candidate', 'CRM-1');
        $a->update(['contact_name' => 'The Principal', 'attributes' => ['website_verification' => ['url' => 'https://school.example.org', 'scope' => 'campus', 'status' => 'verified']]]);
        $this->account('Nirma Vidya Vihar', 'Ahmedabad', 2);
        $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'accounts_' . uniqid() . '.json';

        $this->artisan('signals:export-accounts', ['--tenant' => self::T, '--pack' => 'scholar_k12', '--file' => $file])->assertExitCode(0);
        $data = json_decode((string) file_get_contents($file), true);
        $this->assertCount(2, $data['accounts']);
        $this->assertArrayNotHasKey('id', $data['accounts'][0]);
        $this->assertArrayNotHasKey('sub_institute_id', $data['accounts'][0]);

        // dry run writes nothing
        $this->artisan('signals:load-accounts', ['file' => $file, '--tenant' => self::T2])->expectsOutputToContain('2 to add')->assertExitCode(0);
        $this->assertSame(0, TargetAccount::where('sub_institute_id', self::T2)->count());

        $this->artisan('signals:load-accounts', ['file' => $file, '--tenant' => self::T2, '--apply' => true])->assertExitCode(0);
        $loaded = TargetAccount::where('sub_institute_id', self::T2)->orderBy('id')->get();
        $this->assertCount(2, $loaded);
        $this->assertSame('scholar_k12', $loaded[0]->pack);
        $this->assertSame('verified', $loaded[0]->attributes['website_verification']['status'], 'the verified website travels with the account');
        $this->assertSame('The Principal', $loaded[0]->contact_name);

        // an existing account is kept as it is; loading twice adds nothing
        $loaded[0]->update(['city' => 'Changed locally']);
        $this->artisan('signals:load-accounts', ['file' => $file, '--tenant' => self::T2, '--apply' => true])->expectsOutputToContain('0 to add')->assertExitCode(0);
        $this->assertSame('Changed locally', $loaded[0]->refresh()->city);
        $this->assertSame(2, TargetAccount::where('sub_institute_id', self::T2)->count());
        $this->assertSame(2, TargetAccount::where('sub_institute_id', self::T)->count(), 'the source organisation is untouched');
        @unlink($file);
    }
}
