<?php

namespace Tests\Feature;

use App\Domain\Signals\Icp\TargetAccount;
use App\Domain\Signals\Opportunities\CompanyOpportunity;
use App\Domain\Signals\Opportunities\ProductProfile;
use App\Domain\Signals\Opportunities\ResearchRun;
use App\Domain\Signals\Opportunities\TavilySearchProvider;
use App\Domain\Signals\Support\SafeUrlFetcher;
use App\Domain\Signals\Support\StructuredAi;
use App\Models\auth\tbluserModel;
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
 * Researching with the Scholar K-12 ICP pack: buyer-worded searches with their own topic and domains,
 * one search per named school, hard rules, closing dates and decay, and the lead pointing back at the
 * CRM school. All web and AI traffic is mocked (Http::fake); runs on the in-memory sqlite database.
 */
class IcpPackResearchTest extends TestCase
{
    private const T = 1;

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
            '2026_10_08_100000_extend_g2g_signals_for_market_import', '2026_10_13_100000_create_g2g_target_accounts_table',
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
            'what_we_could_sell' => 'school management system for the new Vadodara campus covering admissions, fees and attendance', 'offer_id' => null,
            'entry_point' => 'discovery_meeting', 'entry_point_reason' => null,
            'soft_marketing_angle' => 'Ask for the principal or the trustee. Open with running the new campus from one system.',
            'likely_stakeholder' => null,
            'scores' => ['buying_signal' => 4, 'problem_fit' => 4, 'product_fit' => 4, 'accessibility' => 3, 'urgency' => 4, 'potential_value' => 3, 'evidence_quality' => 3, 'reasoning' => 'A new campus and an ERP hire.'],
            'inference_reasoning' => null, 'event_date' => null, 'source_ids' => ['S1'],
            'evidence_quote' => 'Podar World School announced a new campus in Vadodara and is hiring an ERP administrator',
        ];
    }

    private function fake(array $leads, ?string $content = null): void
    {
        $this->app->forgetInstance(\Illuminate\Http\Client\Factory::class);
        Http::clearResolvedInstance(\Illuminate\Http\Client\Factory::class);
        $content ??= self::SNIPPET;
        Http::fake([
            'api.tavily.com/*' => Http::response(['results' => [['url' => 'https://news.example.com/podar-vadodara', 'title' => 'Podar opens campus', 'content' => $content, 'published_date' => '2026-10-15']]], 200),
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

    private function research(): void
    {
        $this->artisan('signals:research', ['--tenant' => self::T, '--trigger' => 'manual'])->assertExitCode(0);
    }

    // ── the search provider ──────────────────────────────────────────────────

    public function test_tavily_sends_news_options_by_default_and_general_options_when_asked(): void
    {
        Http::fake(['api.tavily.com/*' => Http::response(['results' => []], 200)]);
        $p = new TavilySearchProvider();

        $p->search('x', 30, 5);
        $p->search('tender "school management system"', 90, 5, ['topic' => 'general', 'include_domains' => ['gem.gov.in', 'eprocure.gov.in']]);

        [$news, $general] = $this->tavilyPayloads();
        $this->assertSame('news', $news['topic']);
        $this->assertSame(30, $news['days']);
        $this->assertArrayNotHasKey('include_domains', $news);

        $this->assertSame('general', $general['topic']);
        $this->assertSame('year', $general['time_range'], 'general searches take time_range, not days');
        $this->assertArrayNotHasKey('days', $general);
        $this->assertSame(['gem.gov.in', 'eprocure.gov.in'], $general['include_domains']);
    }

    // ── the account list import ──────────────────────────────────────────────

    private function csv(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'leads');
        $rows = [
            ['Lead Number', 'Last Name', 'Company', 'Designation', 'City', 'State', 'Description', 'Street', 'Website', 'Assigned To', 'Primary Phone', 'Primary Email'],
            ['LEA35', 'MRS SUNITA SEHGAL', 'NIRMA VIDYA VIHAR', '', 'AHMEDABAD', 'Gujarat', 'Senior Secondary Level', 'Chharodi Campus', 'www.nirmavidya.example', 'Marketing Group', '9999999999', 'x@example.com'],
            ['LEA31338', 'TAPOVAN U B SANSKAR TIRTH', '', 'GSEB', 'Aravali', 'GUJARAT', '', '', '', 'kalpesh@triz.co.in', '', 'y@example.com'],
            ['LEA31339', 'TAPOVAN U B SANSKAR TIRTH', '', 'GSEB', 'Aravali', 'Gujarat', '', '', '', 'kalpesh@triz.co.in', '', 'z@example.com'],
            ['LEA77', 'V. T. COLLEGE P. S.', '', 'GSEB', 'Anand', 'Gujarat', '', '', '', 'kalpesh@triz.co.in', '', 'q@example.com'],
        ];
        $h = fopen($path, 'w');
        foreach ($rows as $r) {
            fputcsv($h, $r);
        }
        fclose($h);

        return $path;
    }

    public function test_the_crm_export_is_read_in_both_shapes_scored_deduplicated_and_without_contact_details(): void
    {
        $this->artisan('signals:import-accounts', ['file' => $this->csv(), '--tenant' => self::T])->assertExitCode(0);

        $this->assertSame(3, TargetAccount::count(), 'the repeated school is one row');
        $nirma = TargetAccount::where('crm_ref', 'LEA35')->first();
        $this->assertSame('Nirma Vidya Vihar', $nirma->name);
        $this->assertSame('Ahmedabad', $nirma->city);
        $this->assertSame('Mrs Sunita Sehgal', $nirma->contact_name, 'when the school is in Company, Last Name is the contact');
        $this->assertSame('Senior Secondary Level', $nirma->level);

        $tapovan = TargetAccount::where('crm_ref', 'LEA31338')->first();
        $this->assertSame('Tapovan U B Sanskar Tirth', $tapovan->name, 'when Company is empty, Last Name is the school');
        $this->assertNull($tapovan->contact_name);
        $this->assertSame('GSEB', $tapovan->board);
        $this->assertSame('Gujarat', $tapovan->state, 'GUJARAT and Gujarat are one state');
        $this->assertSame('unscored', $tapovan->icp_status);

        $college = TargetAccount::where('crm_ref', 'LEA77')->first();
        $this->assertSame('disqualified', $college->icp_status);
        $this->assertSame(9, (int) $college->watch_priority);

        $this->assertSame(1, (int) $nirma->watch_priority, 'a private-sounding name is watched first');

        // Contact phone numbers and emails never reach the table.
        $columns = array_map('strtolower', Schema::getColumnListing('g2g_target_accounts'));
        $this->assertSame([], array_values(array_filter($columns, fn ($c) => str_contains($c, 'phone') || str_contains($c, 'email') || str_contains($c, 'mobile'))));
        $this->assertStringNotContainsString('9999999999', json_encode(TargetAccount::all()->toArray()));
        $this->assertStringNotContainsString('example.com', json_encode(TargetAccount::all()->toArray()));
    }

    public function test_importing_twice_updates_the_same_schools_and_keeps_hand_added_facts_and_history(): void
    {
        $this->artisan('signals:import-accounts', ['file' => $this->csv(), '--tenant' => self::T])->assertExitCode(0);
        $a = TargetAccount::where('crm_ref', 'LEA35')->first();
        $a->forceFill(['attributes' => ['campuses' => 3, 'students' => 2400], 'last_checked_at' => now()])->save();

        $this->artisan('signals:import-accounts', ['file' => $this->csv(), '--tenant' => self::T])->assertExitCode(0);

        $this->assertSame(3, TargetAccount::count());
        $a->refresh();
        $this->assertSame(3, $a->attributes['campuses']);
        $this->assertNotNull($a->last_checked_at);
    }

    public function test_a_dry_run_shows_the_column_mapping_and_every_row_it_would_not_import(): void
    {
        $this->artisan('signals:import-accounts', ['file' => $this->csv(), '--dry-run' => true])
            ->expectsOutputToContain('Column mapping (vtiger preset):')
            ->expectsOutputToContain('Company, else Last Name')
            ->expectsOutputToContain('NOT imported on purpose: Primary Phone, Mobile Phone, Primary Email')
            ->expectsOutputToContain('Rows NOT imported (1):')
            ->expectsOutputToContain('repeats line 3 (same name and city)')
            ->expectsOutputToContain('Imported but outside the ICP, so never searched (1):')
            ->expectsOutputToContain('college or university')
            ->assertExitCode(0);
        $this->assertSame(0, TargetAccount::count());
    }

    public function test_a_dry_run_reports_the_generic_mapping_the_user_gave(): void
    {
        $this->artisan('signals:import-accounts', ['file' => $this->csv(), '--dry-run' => true, '--preset' => 'generic', '--map' => 'name=Company;city=City'])
            ->expectsOutputToContain('Column mapping (generic preset):')
            ->expectsOutputToContain('Company')
            ->assertExitCode(0);
    }

    public function test_a_dry_run_reads_and_scores_but_writes_nothing(): void
    {
        $this->artisan('signals:import-accounts', ['file' => $this->csv(), '--dry-run' => true])->assertExitCode(0);

        $this->assertSame(0, TargetAccount::count());
    }

    public function test_school_facts_are_added_from_a_sheet_and_the_schools_are_scored_again(): void
    {
        $this->artisan('signals:import-accounts', ['file' => $this->csv(), '--tenant' => self::T])->assertExitCode(0);

        $path = tempnam(sys_get_temp_dir(), 'facts');
        $h = fopen($path, 'w');
        foreach ([
            ['crm_ref', 'name', 'city', 'students', 'campuses', 'fee_inr', 'management', 'medium', 'board', 'tier', 'flags'],
            ['LEA35', '', '', '3,200', '3', '60000', 'private', '', 'CBSE', 'A', ''],                       // passes every must-have
            ['LEA31338', '', '', '900', '1', '', '', 'english', '', '', ''],                                 // too few students
            ['', 'Nobody School', 'Nowhere', '5000', '', '', '', '', '', '', ''],                            // not on the list
        ] as $r) {
            fputcsv($h, $r);
        }
        fclose($h);

        $this->artisan('signals:import-account-facts', ['file' => $path, '--tenant' => self::T])->assertExitCode(0);

        $ok = TargetAccount::where('crm_ref', 'LEA35')->first();
        $this->assertSame(3200, $ok->attributes['students'], 'a number with a comma is read as a number');
        $this->assertSame('A', $ok->attributes['tier'], 'the entry tier is judged by a person, stored but never computed');
        $this->assertSame('candidate', $ok->icp_status);
        $this->assertSame('CBSE', $ok->board);

        $small = TargetAccount::where('crm_ref', 'LEA31338')->first();
        $this->assertSame('disqualified', $small->icp_status);
        $this->assertSame('under 2,000 students', $small->icp_notes['disqualified_by']);
        $this->assertSame(9, (int) $small->watch_priority, 'a school that fails a must-have is never searched again');
        $this->assertSame('GSEB', $small->board, 'an empty cell never erases what is stored');

        // a dry run writes nothing
        $this->artisan('signals:import-account-facts', ['file' => $path, '--tenant' => self::T, '--dry-run' => true])->assertExitCode(0);
    }

    // ── a research run through the pack ──────────────────────────────────────

    public function test_a_pack_run_searches_buyer_queries_with_their_own_topic_and_domains_and_one_search_per_named_school(): void
    {
        $this->account('Podar World School', 'Vadodara', 1, crm: 'LEA59');
        $this->account('Ryan International School', 'Rajkot', 1);
        $this->account('Shree Auro English School', 'Rajkot', 2);
        $this->account('Tapovan U B Sanskar Tirth', 'Aravali', 3);
        $this->account('Little Stars Play School', 'Surat', 9, 'disqualified');
        $this->fake([$this->lead()]);

        $this->research();

        $payloads = $this->tavilyPayloads();
        $this->assertLessThanOrEqual(6, count($payloads));
        $queries = array_column($payloads, 'query');
        foreach (['Podar World School', 'Ryan International School', 'Shree Auro English School'] as $school) {
            $this->assertNotEmpty(array_filter($queries, fn ($q) => str_contains($q, '"' . $school . '"')), "{$school} is searched by name");
        }
        $this->assertEmpty(array_filter($queries, fn ($q) => str_contains($q, 'Tapovan')), 'lower priority schools wait their turn');
        $this->assertEmpty(array_filter($queries, fn ($q) => str_contains($q, 'Little Stars')), 'a disqualified school is never searched');
        $this->assertNotEmpty(array_filter($payloads, fn ($p) => $p['topic'] === 'general'), 'tender, job and school searches use the general topic');
        $this->assertEmpty(array_filter($queries, fn ($q) => preg_match('/AI solutions|enterprise AI|business intelligence/i', $q)), 'never our product words');

        $checked = TargetAccount::whereNotNull('last_checked_at')->pluck('name')->all();
        $this->assertEqualsCanonicalizing(['Podar World School', 'Ryan International School', 'Shree Auro English School'], $checked);

        // The next run moves on to the schools not yet checked.
        $this->fake([]);
        Cache::flush();
        $this->travel(11 * 60)->minutes(); // clear the manual cooldown
        $this->research();
        $second = array_column($this->tavilyPayloads(), 'query');
        $this->assertNotEmpty(array_filter($second, fn ($q) => str_contains($q, '"Tapovan U B Sanskar Tirth"')));
    }

    public function test_the_lead_is_written_as_who_needs_what_belongs_to_the_scholar_line_decays_and_points_at_the_crm_school(): void
    {
        $this->account('Podar World School', 'Vadodara', 1, crm: 'LEA59');
        $this->fake([$this->lead()]);

        $this->research();

        $opp = CompanyOpportunity::sole();
        $this->assertSame('Podar World School is opening a new campus and needs a school system running from day one.', $opp->title);
        $this->assertSame('scholar', $opp->business_fit);
        $this->assertSame('web-research', $opp->import_source);
        $this->assertSame(Carbon::parse('2026-10-20')->addDays(90)->toDateString(), $opp->expires_at->toDateString(), 'an expansion stays fresh for 90 days');

        $account = TargetAccount::where('crm_ref', 'LEA59')->first();
        $this->assertSame($opp->company_id, $account->company_id, 'the lead and the CRM school are linked');
        $this->assertNotNull($account->last_signal_at);

        $id = DB::table('tbluser')->insertGetId(['user_name' => 'u', 'sub_institute_id' => self::T, 'user_profile_id' => 1]);
        $token = tbluserModel::find($id)->createToken('t')->plainTextToken;
        $card = $this->getJson('/api/signals/opportunities', ['Authorization' => 'Bearer ' . $token])->assertOk()->json('data.0.card');

        $this->assertSame($opp->title, $card['needs_line']);
        $this->assertSame('LEA59', $card['account']['crm_ref']);
        $this->assertSame('kalpesh@triz.co.in', $card['account']['crm_owner']);
        $this->assertArrayNotHasKey('phone', $card['account']);
        $this->assertArrayNotHasKey('email', $card['account']);
    }

    public function test_a_coaching_centre_is_rejected_as_outside_the_icp_with_a_recorded_reason(): void
    {
        $content = 'Bright Future Coaching Classes announced a new branch in Surat and is hiring an ERP administrator for admissions.';
        $this->fake([$this->lead(['company_name' => 'Bright Future Coaching Classes', 'location' => 'Surat',
            'needs_statement' => 'Bright Future Coaching Classes is opening a branch and needs a system.',
            'evidence_quote' => 'Bright Future Coaching Classes announced a new branch in Surat and is hiring an ERP administrator'])], $content);

        $this->research();

        $this->assertSame(0, CompanyOpportunity::count());
        $this->assertSame(['outside_icp'], \App\Domain\Signals\Market\ImportRejection::pluck('reason_code')->all());
        $this->assertSame(1, ResearchRun::orderByDesc('id')->first()->opportunities_rejected);
    }

    public function test_a_tender_closing_date_is_kept_only_when_the_source_states_it(): void
    {
        $stated = 'The Education Department invited a tender for a school management system. Bids close on 30 November 2026 at 3 pm.';
        $this->fake([$this->lead([
            'company_name' => 'The Education Department', 'location' => null, 'buyer_type' => 'government', 'trigger_type' => 'tender',
            'closing_date' => '2026-11-30', 'needs_statement' => 'The Education Department wants a school management system and bids close on 30 November.',
            'what_happened' => 'The Education Department invited a tender for a school management system. Bids close on 30 November 2026.',
            'evidence_quote' => 'The Education Department invited a tender for a school management system',
        ])], $stated);
        $this->research();
        $this->assertSame('2026-11-30', CompanyOpportunity::sole()->expires_at->toDateString());

        CompanyOpportunity::query()->delete();
        DB::table('g2g_companies')->delete();
        ResearchRun::query()->delete();
        Cache::flush();

        // The model claims a date the source never states: it is dropped, not invented.
        $unstated = 'The Education Department invited a tender for a school management system. Details are on the portal.';
        $this->fake([$this->lead([
            'company_name' => 'The Education Department', 'location' => null, 'buyer_type' => 'government', 'trigger_type' => 'tender',
            'closing_date' => '2026-12-15', 'needs_statement' => 'The Education Department wants a school management system through an open tender.',
            'what_happened' => 'The Education Department invited a tender for a school management system. Details are on the portal.',
            'evidence_quote' => 'The Education Department invited a tender for a school management system',
        ])], $unstated);
        $this->research();
        $this->assertNull(CompanyOpportunity::sole()->expires_at);
    }

    public function test_a_tenant_without_the_pack_keeps_the_generic_research_untouched(): void
    {
        config(['signals.icp.scholar_k12_tenants' => []]);
        $this->account('Podar World School', 'Vadodara', 1);
        $this->fake([]);

        $this->research();

        $queries = array_column($this->tavilyPayloads(), 'query');
        $this->assertNotEmpty($queries);
        $this->assertEmpty(array_filter($queries, fn ($q) => str_contains($q, '"Podar World School"')), 'no school searches without the pack');
        $this->assertSame(['news'], array_values(array_unique(array_column($this->tavilyPayloads(), 'topic'))), 'generic research still searches news only');
        $this->assertNull(TargetAccount::first()->last_checked_at);
    }
}
