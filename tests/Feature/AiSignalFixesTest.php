<?php

namespace Tests\Feature;

use App\Domain\Signals\AiSignals\AiDelivery;
use App\Domain\Signals\AiSignals\AiSignalScope;
use App\Domain\Signals\Connectors\DataConnectorRegistry;
use App\Domain\Signals\Ingestion\DataConnectorSync;
use App\Domain\Signals\Ingestion\IngestionAnalyzer;
use App\Domain\Signals\Ingestion\IngestionService;
use App\Domain\Signals\Market\MarketImporter;
use App\Domain\Signals\Opportunities\Company;
use App\Domain\Signals\Opportunities\CompanyOpportunity;
use App\Domain\Signals\Opportunities\ProductProfile;
use App\Jobs\SyncDataConnectorJob;
use App\Models\auth\tbluserModel;
use App\Support\QueueWorkerLauncher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The pre-production fixes to the AI signal connectors: where a delivered signal shows up under the Signals screen's selectors, stuck deliveries,
 * rejection visibility, strict dates and look-alike organisations. In-memory sqlite, every external request blocked.
 */
class AiSignalFixesTest extends TestCase
{
    private const T1 = 1;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Carbon::setTestNow('2026-11-27 10:00:00');
        Cache::flush();
        config(['signals.legacy_product_icp' => true, 'signals.web_search.driver' => 'none']);
        QueueWorkerLauncher::fake(fn () => null);
        Queue::fake();

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
            '2026_10_08_100000_extend_g2g_signals_for_market_import', '2026_11_29_090000_add_assessment_to_g2g_company_opportunities',
            '2026_12_01_090000_add_product_key_to_signals_runs_and_sources', '2026_10_14_100000_create_g2g_icp_profiles_tables',
            '2026_12_07_090000_add_scope_to_g2g_company_opportunities', '2026_11_04_120000_create_g2g_data_connector_tables',
            '2026_11_04_140000_extend_g2g_data_connectors_for_social_sync', '2026_12_10_100000_create_g2g_connector_provider_settings_tables',
            '2026_12_20_100000_add_sync_report_and_monitoring_columns', '2026_12_28_100000_create_g2g_signal_contacts_table',
            '2026_12_29_100000_create_g2g_ai_deliveries_table',
        ] as $m) {
            (require base_path("database/migrations/{$m}.php"))->up();
        }
        DB::table('tbluserprofilemaster')->insert([['id' => 1, 'name' => 'Administrator', 'role_key' => 'administrator'], ['id' => 2, 'name' => 'Employee', 'role_key' => 'employee']]);
        ProductProfile::create([
            'sub_institute_id' => self::T1, 'product_name' => 'ScholarClone', 'description' => 'School ERP system', 'problems_solved' => 'Manual school operations',
            'features' => 'Fee collection', 'target_industries' => ['Education'], 'target_markets' => ['India'], 'keywords' => ['school erp'],
            'excluded' => [], 'research_enabled' => true, 'research_frequency' => 'daily', 'recency_days' => 30,
        ]);
    }

    protected function tearDown(): void
    {
        QueueWorkerLauncher::fake(null);
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function h(): array
    {
        $id = DB::table('tbluser')->insertGetId(['user_name' => 'u' . uniqid(), 'sub_institute_id' => self::T1, 'user_profile_id' => 1]);

        return ['Authorization' => 'Bearer ' . tbluserModel::find($id)->createToken('t')->plainTextToken];
    }

    /** @return array{0: int, 1: string} */
    private function source(): array
    {
        $r = $this->postJson('/api/signals/ingestion/ai-signals/claude/enable', [], $this->h())->assertStatus(201);

        return [(int) $r->json('data.connector_id'), (string) $r->json('secret')];
    }

    private function deliver(string $secret, array $body)
    {
        return $this->call('POST', '/api/signals/ai-delivery', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $secret, 'HTTP_ACCEPT' => 'application/json'], json_encode($body));
    }

    private function sync(int $connectorId): DataConnectorSync
    {
        DataConnectorSync::where('connector_id', $connectorId)->whereIn('status', ['queued', 'running'])->delete();
        $this->postJson('/api/signals/ingestion/ai-signals/claude/sync', [], $this->h())->assertStatus(202);
        $sync = DataConnectorSync::where('connector_id', $connectorId)->where('status', 'queued')->orderByDesc('id')->firstOrFail();
        (new SyncDataConnectorJob(self::T1, $connectorId, (int) $sync->id, null))->handle(app(DataConnectorRegistry::class), app(IngestionService::class), app(IngestionAnalyzer::class));

        return $sync->refresh();
    }

    /** One delivery, imported. */
    private function importSignals(array $signals, string $ref = 'd'): array
    {
        [$id, $secret] = $this->source();
        $this->deliver($secret, ['delivery_id' => $ref, 'signals' => $signals])->assertStatus(202);
        $this->sync($id);

        return [$id, $secret];
    }

    private function signal(array $o = []): array
    {
        return $o + [
            'buyer' => ['name' => 'Sunrise Public School'], 'trigger_type' => 'vendor_search',
            'trigger_summary' => 'The school has invited school-ERP vendors to demonstrate for the 2027-28 academic year.',
            'what_happened' => 'A notice invites ERP vendors to register for demonstrations by 10 Dec.', 'source_url' => 'https://www.sunriseschool.in/notices/erp',
            'event_date' => '2026-11-24', 'claim_level' => 'inference', 'fetch_level' => 'search_snippet',
        ];
    }

    /** The documentation's sample: a school in Maharashtra, for ScholarClone. */
    private function schoolSample(): array
    {
        return $this->signal(['buyer' => ['name' => 'Sunrise Public School', 'type' => 'school', 'state' => 'Maharashtra', 'website' => 'https://www.sunriseschool.in'], 'business_fit' => 'scholar']);
    }

    /** A government body in Delhi. */
    private function governmentSample(): array
    {
        return $this->signal([
            'buyer' => ['name' => 'Delhi Directorate of Education', 'type' => 'government', 'state' => 'Delhi'], 'trigger_type' => 'tender', 'reference_no' => 'DoE/ERP/2026/41',
            'trigger_summary' => 'The Directorate has floated a tender for a school management system for its schools.', 'what_happened' => 'Tender notice published on the Directorate website.',
            'source_url' => 'https://edudel.example.gov.in/tenders/erp-2026', 'expires_at' => '2026-12-20', 'claim_level' => 'confirmed', 'fetch_level' => 'page_text', 'business_fit' => 'g2g',
        ]);
    }

    /** How many signals the Signals screen lists for these selectors, on the day of delivery. */
    private function listed(array $selectors): int
    {
        $q = ['run_date' => '2026-11-27', 'per_page' => 50, 'expired' => 'include', 'scope_filter' => 1] + $selectors;

        return count($this->getJson('/api/signals/opportunities?' . http_build_query($q), $this->h())->assertOk()->json('data'));
    }

    private function india(): array
    {
        return ['regions' => [['country' => 'India']]];
    }

    // ── contacts that come with a delivered signal ───────────────────────────

    public function test_contacts_sent_with_a_signal_are_stored_unverified_with_their_source_and_anything_unsourced_or_invalid_is_dropped(): void
    {
        [$id, $secret] = $this->importSignals([$this->signal([
            'buyer' => ['name' => 'Example Partner Pvt Ltd', 'type' => 'enterprise', 'state' => 'Maharashtra', 'country' => 'India'], 'business_fit' => 'g2g',
            'contacts' => [
                ['full_name' => 'Asha Rao', 'designation' => 'Chief Executive Officer', 'source_url' => 'https://example.org/leadership', 'linkedin_url' => 'https://www.linkedin.com/in/asha-rao-example'],
                ['designation' => 'Organisation contact (published)', 'phone' => '+91 22 3989 6000', 'email' => 'info@partnerco.in', 'source_url' => 'https://example.org/contact'],
                ['full_name' => 'No Source Person', 'email' => 'nosource@partnerco.in'],
                ['full_name' => 'Bad Email Person', 'email' => 'not-an-email', 'source_url' => 'https://example.org/team', 'linkedin_url' => 'https://evil.example.com/profile'],
            ],
        ])]);

        $rows = DB::table('g2g_signal_contacts')->orderBy('id')->get();
        $this->assertCount(3, $rows, 'the contact with no source page is dropped');
        $this->assertSame(['Asha Rao', null, 'Bad Email Person'], $rows->pluck('full_name')->all());
        $this->assertSame(['unverified'], $rows->pluck('verification_status')->unique()->values()->all(), 'nothing delivered is marked verified');
        $this->assertSame('https://www.linkedin.com/in/asha-rao-example', $rows[0]->linkedin_url);
        $this->assertSame('https://example.org/contact', $rows[1]->email_source_url, 'an email carries the page that printed it');
        $this->assertSame('info@partnerco.in', $rows[1]->email);
        $this->assertNull($rows[2]->email, 'an invalid email is not stored');
        $this->assertNull($rows[2]->linkedin_url, 'a link that is not LinkedIn is not stored as a LinkedIn link');
        $this->assertNotContains('No Source Person', $rows->pluck('full_name')->all());
        $this->assertSame('+912239896000', $rows[1]->phone, 'a published landline with +91 and its STD code is stored');

        // The same delivery content again stores nothing twice.
        $this->deliver($secret, ['delivery_id' => 'd2', 'signals' => [$this->signal([
            'buyer' => ['name' => 'Example Partner Pvt Ltd', 'type' => 'enterprise', 'state' => 'Maharashtra', 'country' => 'India'], 'business_fit' => 'g2g',
            'contacts' => [['full_name' => 'Asha Rao', 'designation' => 'Chief Executive Officer', 'source_url' => 'https://example.org/leadership']],
        ])]])->assertStatus(202);
        $this->sync($id);
        $this->assertSame(3, DB::table('g2g_signal_contacts')->count());
    }

    public function test_the_import_command_saves_a_file_through_the_same_checks_and_never_twice_or_into_a_missing_source(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'g2gai');
        file_put_contents($file, json_encode(['schema' => 'g2g.ai-signals.v1', 'delivery_id' => 'file-1', 'provider' => 'claude', 'signals' => [
            $this->signal(['buyer' => ['name' => 'File Partner Pvt Ltd', 'type' => 'enterprise', 'state' => 'Karnataka', 'country' => 'India'], 'business_fit' => 'g2g',
                'contacts' => [['full_name' => 'Ravi Menon', 'designation' => 'Chief Executive Officer', 'source_url' => 'https://filepartner.in/team']]]),
            $this->signal(['buyer' => ['name' => 'Undated Co'], 'event_date' => 'last Tuesday']),
        ]]));

        // No source set up for this organisation yet: it refuses and creates nothing.
        $this->artisan('signals:import-ai-file', ['file' => $file, '--tenant' => self::T1])->assertExitCode(1);
        $this->assertSame(0, CompanyOpportunity::count());
        $this->assertSame(0, DB::table('g2g_data_connectors')->count(), 'the command never creates a connector');

        // A dry run reads the file and saves nothing.
        $this->artisan('signals:import-ai-file', ['file' => $file, '--dry-run' => true])->assertExitCode(0);
        $this->assertSame(0, CompanyOpportunity::count());

        $this->source();
        $this->artisan('signals:import-ai-file', ['file' => $file, '--tenant' => self::T1])->assertExitCode(0);
        $this->assertSame(1, CompanyOpportunity::count(), 'the dated signal is stored; the one with a bad date is rejected by the same gate');
        $row = CompanyOpportunity::sole();
        $this->assertSame('b2b', $row->customer_model);
        $this->assertSame('[{"country":"India","state":"Karnataka","city":null}]', $row->region_scope);
        $this->assertSame(['Ravi Menon'], DB::table('g2g_signal_contacts')->pluck('full_name')->all());
        $this->assertStringStartsWith('ai:claude', (string) $row->import_source);

        // The same file again imports nothing twice.
        $this->artisan('signals:import-ai-file', ['file' => $file, '--tenant' => self::T1])->assertExitCode(0);
        $this->assertSame(1, CompanyOpportunity::count());
        $this->assertSame(1, DB::table('g2g_signal_contacts')->count());

        // Another organisation has no source, so it cannot be written to by naming it.
        $this->artisan('signals:import-ai-file', ['file' => $file, '--tenant' => 2])->assertExitCode(1);
        $this->assertSame(1, CompanyOpportunity::count());
        @unlink($file);
    }

    public function test_a_signal_can_state_its_icp_type_so_a_partner_prospect_lands_under_b2b_partner_acquisition(): void
    {
        $partner = fn (array $o = []) => $this->signal($o + ['buyer' => ['name' => 'Example Systems Integrator', 'type' => 'enterprise', 'state' => 'Maharashtra', 'country' => 'India'], 'business_fit' => 'g2g']);
        $this->importSignals([$partner(['customer_model' => 'b2c']), $partner(['buyer' => ['name' => 'Second Partner Ltd', 'type' => 'enterprise', 'state' => 'Karnataka', 'country' => 'India'], 'icp_type' => 'B2B Partner Acquisition', 'source_url' => 'https://second.example.in/news', 'trigger_summary' => 'Second Partner Ltd announced a new reseller alliance for HR software in Karnataka.', 'what_happened' => 'Press note on its own site dated 24 Nov 2026.']),
            $partner(['buyer' => ['name' => 'Third Buyer Ltd', 'type' => 'enterprise', 'state' => 'Goa', 'country' => 'India'], 'customer_model' => 'nonsense', 'source_url' => 'https://third.example.in/news', 'trigger_summary' => 'Third Buyer Ltd is expanding its HR operations in Goa and is hiring a payroll partner.', 'what_happened' => 'Careers page notice dated 23 Nov 2026.'])]);

        $models = CompanyOpportunity::orderBy('id')->pluck('customer_model')->all();
        $this->assertSame(['b2c', 'b2c', 'b2b'], $models, 'a stated type wins; an unknown one is ignored and the buyer type decides, as before');
        $this->assertSame(2, $this->listed(['customer_model' => 'b2c'] + $this->india()), 'both are under B2B Partner Acquisition + India');
        $this->assertSame(1, $this->listed(['customer_model' => 'b2b'] + $this->india()), 'only the third is under B2B Customer Acquisition');
        $this->assertSame(3, $this->listed(['customer_model' => '']), 'All ICP types shows all three');
    }

    public function test_a_signal_without_contacts_is_imported_exactly_as_before(): void
    {
        $this->importSignals([$this->schoolSample()]);
        $this->assertSame(1, CompanyOpportunity::count());
        $this->assertSame(0, DB::table('g2g_signal_contacts')->count());
    }

    // ── 1. visibility under the selectors ────────────────────────────────────

    public function test_a_government_signal_shows_in_the_default_b2g_india_view_and_nowhere_it_does_not_belong(): void
    {
        $this->importSignals([$this->governmentSample()]);
        $row = CompanyOpportunity::sole();
        $this->assertSame('b2g', $row->customer_model);
        $this->assertSame('[{"country":"India","state":"Delhi","city":null}]', $row->region_scope);

        $this->assertSame(1, $this->listed(['customer_model' => 'b2g'] + $this->india()), 'B2G + India: shown');
        $this->assertSame(1, $this->listed(['customer_model' => 'b2g', 'regions' => [['country' => 'India', 'state' => 'Delhi']]]), 'B2G + Delhi: shown');
        $this->assertSame(0, $this->listed(['customer_model' => 'b2g', 'regions' => [['country' => 'India', 'state' => 'Maharashtra']]]), 'another state: not shown');
        $this->assertSame(0, $this->listed(['customer_model' => 'b2b'] + $this->india()), 'B2B: not shown, it is not a B2B buyer');
        $this->assertSame(0, $this->listed(['customer_model' => 'b2g', 'regions' => [['country' => 'United States']]]), 'another country: not shown');
        $this->assertSame(1, $this->listed(['customer_model' => '']), 'All ICP types: shown');
    }

    public function test_the_government_sample_printed_in_the_documentation_is_accepted_and_lands_in_b2g_india(): void
    {
        $doc = (string) file_get_contents(base_path('docs/signals-ai-delivery.md'));
        $this->assertSame(1, preg_match('/Government sample: `(\{.*\})`/', $doc, $m), 'the documentation has no government sample');
        $sample = json_decode($m[1], true);
        $this->assertIsArray($sample, 'the documented government sample is not valid JSON');
        $this->importSignals([$sample]);

        $row = CompanyOpportunity::sole();
        $this->assertSame(['b2g', '[{"country":"India","state":"Delhi","city":null}]'], [$row->customer_model, $row->region_scope]);
        $this->assertSame(1, $this->listed(['customer_model' => 'b2g'] + $this->india()), 'it is in the B2G + India view');
        $this->assertSame(0, $this->listed(['customer_model' => 'b2b'] + $this->india()));
    }

    public function test_the_documentation_sample_school_is_b2b_india_so_it_is_not_in_the_b2g_view_and_the_reason_is_exact(): void
    {
        $this->importSignals([$this->schoolSample()]);
        $row = CompanyOpportunity::sole();
        $this->assertSame('b2b', $row->customer_model, 'the product catalogue defines School as a B2B buyer');
        $this->assertSame('[{"country":"India","state":"Maharashtra","city":null}]', $row->region_scope);
        $this->assertSame('scholar', $row->business_fit);

        $this->assertSame(1, $this->listed(['customer_model' => 'b2b'] + $this->india()), 'B2B + India');
        $this->assertSame(1, $this->listed(['customer_model' => 'b2b', 'regions' => [['country' => 'India', 'state' => 'Maharashtra']]]), 'B2B + Maharashtra');
        $this->assertSame(0, $this->listed(['customer_model' => 'b2g'] + $this->india()), 'B2G + India: NOT shown, because it is not a government buyer');
        $this->assertSame(1, $this->listed($this->india()), 'All ICP types + India');
        $this->assertSame(1, $this->listed([]), 'All ICP types + All regions');
        $this->assertSame(1, $this->listed(['product' => 'scholarclone']), 'by product');
    }

    public function test_an_unclassified_signal_matches_no_selector_it_was_not_classified_for_but_is_always_found_under_all(): void
    {
        $this->importSignals([$this->signal()]);   // no buyer type, no state, no business fit
        $row = CompanyOpportunity::sole();
        $this->assertNull($row->customer_model);
        $this->assertNull($row->region_scope);

        $this->assertSame(0, $this->listed(['customer_model' => 'b2b']), 'not guessed into B2B');
        $this->assertSame(0, $this->listed(['customer_model' => 'b2g']), 'not guessed into B2G');
        $this->assertSame(0, $this->listed($this->india()), 'not guessed into India');
        $this->assertSame(1, $this->listed([]), 'found under All ICP types + All regions');
        $api = $this->getJson('/api/signals/opportunities?run_date=2026-11-27&scope_filter=1&per_page=5', $this->h())->json('data.0');
        $this->assertSame('claude', $api['ai_source']);
        $this->assertNull($api['customer_model'], 'the screen can tell it is unclassified');
    }

    public function test_nothing_is_invented_an_ambiguous_state_an_unlisted_type_or_a_wrong_pair_stays_empty(): void
    {
        $this->assertNull(AiSignalScope::customerModel('hospital'));
        $this->assertNull(AiSignalScope::customerModel('other'));
        $this->assertNull(AiSignalScope::customerModel('Private School'));
        $this->assertNull(AiSignalScope::region(['state' => 'Punjab']), 'India and Pakistan both have a Punjab');
        $this->assertNull(AiSignalScope::region(['state' => 'Atlantis']));
        $this->assertNull(AiSignalScope::region(['country' => 'Atlantis']));
        $this->assertSame('[{"country":"United States","state":null,"city":null}]', AiSignalScope::region(['state' => 'Karnataka', 'country' => 'United States']), 'a state that is not in that country is dropped, the stated country is kept');
        $this->assertSame('[{"country":"India","state":"Maharashtra","city":null}]', AiSignalScope::region(['state' => 'maharashtra']), 'a state unique to one country resolves to it');
        $this->assertNull(AiSignalScope::productKey(null, self::T1));
        $this->assertNull(AiSignalScope::productKey('multiple', self::T1), 'no single product has that business fit');
    }

    public function test_classification_fills_empty_fields_only_and_never_changes_what_is_a_duplicate(): void
    {
        [$id, $secret] = $this->source();
        // Day 1: no type, no state. Day 2: the same event, now with a type and a state.
        $this->deliver($secret, ['delivery_id' => 'day-1', 'signals' => [$this->signal()]])->assertStatus(202);
        $this->sync($id);
        $this->assertNull(CompanyOpportunity::sole()->customer_model);
        $this->deliver($secret, ['delivery_id' => 'day-2', 'signals' => [$this->signal(['buyer' => ['name' => 'Sunrise Public School', 'type' => 'school', 'state' => 'Maharashtra']])]])->assertStatus(202);
        $this->sync($id);

        $this->assertSame(1, CompanyOpportunity::count(), 'adding a type later does not make a second signal');
        $row = CompanyOpportunity::sole();
        $this->assertSame('b2b', $row->customer_model, 'the empty field was filled');
        $this->assertSame(1, $this->listed(['customer_model' => 'b2b'] + $this->india()));

        // Day 3: the producer now says "government": the stored classification is not overwritten.
        $this->deliver($secret, ['delivery_id' => 'day-3', 'signals' => [$this->signal(['buyer' => ['name' => 'Sunrise Public School', 'type' => 'government', 'state' => 'Delhi']])]])->assertStatus(202);
        $this->sync($id);
        $this->assertSame(1, CompanyOpportunity::count());
        $this->assertSame('b2b', CompanyOpportunity::sole()->customer_model);
        $this->assertStringContainsString('Maharashtra', (string) CompanyOpportunity::sole()->region_scope);

        // A classification a person set is never replaced either.
        CompanyOpportunity::query()->update(['customer_model' => 'b2c']);
        $this->deliver($secret, ['delivery_id' => 'day-4', 'signals' => [$this->signal(['buyer' => ['name' => 'Sunrise Public School', 'type' => 'school', 'state' => 'Maharashtra'], 'source_url' => 'https://www.sunriseschool.in/notices/erp'])]])->assertStatus(202);
        $this->sync($id);
        $this->assertSame('b2c', CompanyOpportunity::sole()->customer_model);
    }

    // ── 2. stuck deliveries ──────────────────────────────────────────────────

    private function stale(AiDelivery $d, int $minutesAgo, array $attrs = []): void
    {
        DB::table('g2g_ai_deliveries')->where('id', $d->id)->update($attrs + ['status' => 'importing', 'updated_at' => now()->subMinutes($minutesAgo)->toDateTimeString()]);
    }

    public function test_a_delivery_stuck_importing_for_over_30_minutes_is_imported_again_and_creates_each_signal_once(): void
    {
        [$id, $secret] = $this->source();
        $this->deliver($secret, ['delivery_id' => 'crash', 'signals' => [$this->signal(), $this->governmentSample()]])->assertStatus(202);
        $d = AiDelivery::sole();
        $this->stale($d, 31, ['attempts' => 1]);

        $sync = $this->sync($id);
        $d->refresh();
        $this->assertSame('imported', $d->status);
        $this->assertSame(2, CompanyOpportunity::count());
        $this->assertSame(2, (int) $d->records_accepted);
        $this->assertStringContainsString('Re-imported after an interrupted import', (string) $d->error_message);
        $this->assertSame('completed', $sync->status);
    }

    public function test_the_retry_is_idempotent_when_the_first_attempt_had_already_stored_the_signals(): void
    {
        [$id, $secret] = $this->source();
        $this->deliver($secret, ['delivery_id' => 'half', 'signals' => [$this->signal(), $this->governmentSample(), $this->signal(['buyer' => ['name' => 'Moonlight Academy'], 'source_url' => 'https://news.example.com/moonlight'])]])->assertStatus(202);
        $this->sync($id);
        $this->assertSame(3, CompanyOpportunity::count());
        $companies = Company::count();

        // The worker died after storing every signal but before it could mark the delivery done.
        $d = AiDelivery::sole();
        $this->stale($d, 45, ['attempts' => 1, 'records_accepted' => 0, 'records_duplicate' => 0, 'imported_at' => null]);
        $this->sync($id);

        $this->assertSame(3, CompanyOpportunity::count(), 'no signal was created twice');
        $this->assertSame($companies, Company::count(), 'and no organisation either');
        $d->refresh();
        $this->assertSame('imported', $d->status);
        $this->assertSame([0, 0, 3], [(int) $d->records_accepted, (int) $d->records_updated, (int) $d->records_duplicate], 'what was stored before the interruption is counted as already known');
    }

    public function test_a_delivery_a_worker_may_still_be_writing_is_left_alone(): void
    {
        [$id, $secret] = $this->source();
        $this->deliver($secret, ['delivery_id' => 'live', 'signals' => [$this->signal()]])->assertStatus(202);
        $d = AiDelivery::sole();
        $this->stale($d, 5, ['attempts' => 1]);   // silent for only 5 minutes
        $sync = $this->sync($id);
        $this->assertSame('importing', $d->refresh()->status);
        $this->assertSame(0, CompanyOpportunity::count());
        $this->assertStringContainsString('No new deliveries', (string) $sync->error_message);
    }

    public function test_a_delivery_that_keeps_getting_stuck_is_given_up_on_with_a_visible_reason(): void
    {
        [$id, $secret] = $this->source();
        $this->deliver($secret, ['delivery_id' => 'doomed', 'signals' => [$this->signal()]])->assertStatus(202);
        $d = AiDelivery::sole();
        $this->stale($d, 90, ['attempts' => AiDelivery::MAX_ATTEMPTS]);
        $this->sync($id);
        $d->refresh();
        $this->assertSame('failed', $d->status);
        $this->assertStringContainsString('tried ' . AiDelivery::MAX_ATTEMPTS . ' times', (string) $d->error_message);
        $this->assertSame(0, CompanyOpportunity::count());
        $slot = collect($this->getJson('/api/signals/ingestion/ai-signals', $this->h())->json('data'))->firstWhere('provider', 'claude');
        $this->assertSame(1, $slot['totals']['failed_deliveries']);
    }

    public function test_a_stuck_delivery_counts_as_waiting_on_the_card(): void
    {
        [$id, $secret] = $this->source();
        $this->deliver($secret, ['delivery_id' => 'x', 'signals' => [$this->signal()]])->assertStatus(202);
        $this->stale(AiDelivery::sole(), 5, ['attempts' => 1]);
        $slot = collect($this->getJson('/api/signals/ingestion/ai-signals', $this->h())->json('data'))->firstWhere('provider', 'claude');
        $this->assertSame(1, $slot['totals']['waiting']);
        $this->assertSame(1, $slot['recent'][0]['pending']);
    }

    // ── 3. rejection visibility ──────────────────────────────────────────────

    public function test_each_rejected_signal_is_listed_with_its_organisation_its_reason_and_how_to_fix_it(): void
    {
        [$id, $secret] = $this->source();
        $noDate = $this->signal(['buyer' => ['name' => 'No Date School'], 'source_url' => 'https://example.org/1']);
        unset($noDate['event_date']);
        $this->deliver($secret, ['delivery_id' => 'mixed', 'signals' => [
            $this->signal(), $this->governmentSample(), $noDate,
            $this->signal(['buyer' => ['name' => 'Odd Type School', 'type' => 'Private School'], 'source_url' => 'https://example.org/2']),
            $this->signal(['buyer' => ['name' => 'Relative Date School'], 'event_date' => 'last Tuesday', 'source_url' => 'https://example.org/3']),
        ]])->assertStatus(202);
        $this->sync($id);

        $d = collect($this->getJson('/api/signals/ingestion/ai-signals', $this->h())->json('data'))->firstWhere('provider', 'claude')['recent'][0];
        $this->assertSame([5, 2, 0, 0, 3, 0], [$d['records'], $d['imported'], $d['updated'], $d['duplicate'], $d['failed'], $d['pending']]);
        $this->assertSame('partial', $d['status']);
        $this->assertCount(3, $d['rejections']);
        $by = collect($d['rejections'])->keyBy('label');
        $this->assertSame('missing_date', $by['No Date School']['reason_code']);
        $this->assertStringContainsString('YYYY-MM-DD', $by['No Date School']['hint']);
        $this->assertSame('invalid_buyer_type', $by['Odd Type School']['reason_code']);
        $this->assertStringContainsString('government, institutional', $by['Odd Type School']['hint']);
        $this->assertSame('invalid_date', $by['Relative Date School']['reason_code']);
        foreach ($d['rejections'] as $r) {
            $this->assertNotEmpty($r['reason']);
            $this->assertNotEmpty($r['hint']);
        }
    }

    public function test_a_waiting_delivery_shows_its_signals_as_pending_and_a_long_rejection_list_is_capped_with_a_count(): void
    {
        [$id, $secret] = $this->source();
        $bad = [];
        for ($i = 1; $i <= 25; $i++) {
            $bad[] = $this->signal(['buyer' => ['name' => "Bad Date School {$i}"], 'source_url' => "https://example.org/bad-{$i}", 'event_date' => 'yesterday']);
        }
        $this->deliver($secret, ['delivery_id' => 'bad', 'signals' => $bad])->assertStatus(202);
        $before = collect($this->getJson('/api/signals/ingestion/ai-signals', $this->h())->json('data'))->firstWhere('provider', 'claude')['recent'][0];
        $this->assertSame([25, 25, 0], [$before['records'], $before['pending'], $before['failed']], 'before any import: all 25 are pending');

        $this->sync($id);
        $d = collect($this->getJson('/api/signals/ingestion/ai-signals', $this->h())->json('data'))->firstWhere('provider', 'claude')['recent'][0];
        $this->assertSame([25, 0, 25], [$d['records'], $d['pending'], $d['failed']]);
        $this->assertCount(20, $d['rejections']);
        $this->assertSame(5, $d['rejections_more']);
        $this->assertSame('failed', $d['status']);
    }

    // ── 4. strict dates ──────────────────────────────────────────────────────

    public function test_only_iso_dates_are_accepted_and_every_other_form_is_refused_with_a_clear_reason(): void
    {
        [$id, $secret] = $this->source();
        $cases = [
            'last Tuesday' => false, 'yesterday' => false, '24/11/2026' => false, '11/24/2026' => false, 'Nov 24' => false, '24 November 2026' => false,
            '2026-13-45' => false, '2026-02-30' => false, '2026-11-24T10:00:00+05:30' => false, '2026-11-24' => true,
        ];
        $records = [];
        $i = 0;
        foreach (array_keys($cases) as $date) {
            $records[] = $this->signal(['buyer' => ['name' => 'School ' . ++$i], 'event_date' => $date, 'source_url' => "https://example.org/date-{$i}"]);
        }
        $this->deliver($secret, ['delivery_id' => 'dates', 'signals' => $records])->assertStatus(202);
        $this->sync($id);
        $items = json_decode(AiDelivery::sole()->results, true);
        foreach (array_values($cases) as $n => $accepted) {
            $label = array_keys($cases)[$n];
            $this->assertSame($accepted ? 'accepted' : 'rejected', $items[$n]['outcome'], "event_date \"{$label}\"");
            if (! $accepted) {
                $this->assertSame('invalid_date', $items[$n]['reason_code']);
            }
        }
        $reason = $items[0]['reason'];
        $this->assertStringContainsString('event_date "last Tuesday"', $reason);
        $this->assertStringContainsString('YYYY-MM-DD', $reason);
        $this->assertStringContainsString('not the day the event happened', $reason);
        $this->assertSame(1, CompanyOpportunity::count(), 'only the ISO date made a signal');
    }

    public function test_observed_at_may_carry_a_time_but_must_still_be_iso_and_a_relative_value_is_refused(): void
    {
        [$id, $secret] = $this->source();
        $a = $this->signal(['buyer' => ['name' => 'Observed OK'], 'source_url' => 'https://example.org/o1']);
        unset($a['event_date']);
        $a['observed_at'] = '2026-11-26T18:30:00+05:30';
        $b = $this->signal(['buyer' => ['name' => 'Observed Bad'], 'source_url' => 'https://example.org/o2']);
        unset($b['event_date']);
        $b['observed_at'] = 'tomorrow';
        $c = $this->signal(['buyer' => ['name' => 'Expiry Bad'], 'trigger_type' => 'tender', 'reference_no' => 'T-1', 'expires_at' => 'next Friday', 'source_url' => 'https://example.org/o3']);
        $this->deliver($secret, ['delivery_id' => 'obs', 'signals' => [$a, $b, $c]])->assertStatus(202);
        $this->sync($id);
        $items = json_decode(AiDelivery::sole()->results, true);
        $this->assertSame(['accepted', 'rejected', 'rejected'], array_column($items, 'outcome'));
        $this->assertStringContainsString('observed_at "tomorrow"', $items[1]['reason']);
        $this->assertStringContainsString('expires_at "next Friday"', $items[2]['reason']);
    }

    public function test_the_strict_rule_is_only_for_the_ai_sources_the_other_import_paths_read_dates_as_before(): void
    {
        $rec = $this->signal(['event_date' => 'last Tuesday']);
        $lenient = app(MarketImporter::class)->import(self::T1, [$rec], ['source_label' => 'daily-agent']);
        $this->assertSame(1, $lenient['accepted'], 'the daily-agent import and the rest are unchanged');
        $strict = app(MarketImporter::class)->import(self::T1, [$this->signal(['buyer' => ['name' => 'Other School'], 'event_date' => 'last Tuesday', 'source_url' => 'https://example.org/s'])], ['source_label' => 'ai:claude', 'strict_dates' => true]);
        $this->assertSame(1, $strict['rejected']);
    }

    // ── 5. look-alike organisations ──────────────────────────────────────────

    public function test_a_spelling_variant_is_kept_as_its_own_organisation_and_the_look_alike_is_reported_not_merged(): void
    {
        [$id, $secret] = $this->source();
        $this->deliver($secret, ['delivery_id' => 'first', 'signals' => [$this->signal()]])->assertStatus(202);
        $this->sync($id);
        $this->deliver($secret, ['delivery_id' => 'second', 'signals' => [$this->signal(['buyer' => ['name' => 'Sunrise Public School, Pune'], 'source_url' => 'https://www.sunriseschool.in/pune/erp'])]])->assertStatus(202);
        $this->sync($id);

        $this->assertSame(2, Company::count(), 'two organisations: identity is uncertain, so nothing is merged');
        $this->assertSame(2, CompanyOpportunity::count());
        $d = collect($this->getJson('/api/signals/ingestion/ai-signals', $this->h())->json('data'))->firstWhere('provider', 'claude')['recent'][0];
        $this->assertCount(1, $d['look_alikes']);
        $this->assertSame('Sunrise Public School, Pune', $d['look_alikes'][0]['label']);
        $this->assertSame('Sunrise Public School', $d['look_alikes'][0]['name']);
        $this->assertGreaterThanOrEqual(85, $d['look_alikes'][0]['similarity']);
    }

    public function test_the_same_website_or_an_alias_in_the_delivery_does_not_merge_two_organisations(): void
    {
        [$id, $secret] = $this->source();
        $a = $this->signal(['buyer' => ['name' => 'Sunrise Public School', 'website' => 'https://www.sunriseschool.in']]);
        $b = $this->signal(['buyer' => ['name' => 'Sunrise Public School, Pune', 'website' => 'https://www.sunriseschool.in', 'aliases' => ['Sunrise Public School']], 'source_url' => 'https://www.sunriseschool.in/pune']);
        $c = $this->signal(['buyer' => ['name' => 'Sunrise Senior Wing', 'website' => 'https://sunriseschool.in'], 'source_url' => 'https://www.sunriseschool.in/senior']);
        $this->deliver($secret, ['delivery_id' => 'chain', 'signals' => [$a, $b, $c]])->assertStatus(202);
        $this->sync($id);
        $this->assertSame(3, Company::count(), 'one website does not make one organisation (a school group shares one site)');
        $items = json_decode(AiDelivery::sole()->results, true);
        $this->assertSame(['Sunrise Public School'], $items[1]['alias_conflicts'], 'the alias is left with the organisation that already has it, and the conflict is reported');
    }

    public function test_exactly_the_same_name_is_still_one_organisation_as_before(): void
    {
        [$id, $secret] = $this->source();
        $this->deliver($secret, ['delivery_id' => 'a', 'signals' => [$this->signal(), $this->signal(['buyer' => ['name' => 'sunrise public school'], 'trigger_type' => 'hiring', 'source_url' => 'https://example.org/h'])]])->assertStatus(202);
        $this->sync($id);
        $this->assertSame(1, Company::count());
        $this->assertSame(2, CompanyOpportunity::count());
    }

    // ── no worker available ──────────────────────────────────────────────────

    public function test_an_import_nobody_picks_up_is_reported_as_waiting_for_a_worker_not_as_importing_for_ever(): void
    {
        [$id, $secret] = $this->source();
        $this->deliver($secret, ['delivery_id' => 'w', 'signals' => [$this->signal()]])->assertStatus(202);   // queued; no worker runs it (Queue is faked)
        $slot = fn () => collect($this->getJson('/api/signals/ingestion/ai-signals', $this->h())->json('data'))->firstWhere('provider', 'claude');

        $this->assertSame('syncing', $slot()['state']);
        $this->assertNull($slot()['worker_hint'], 'not yet: a worker may simply be about to start');

        Carbon::setTestNow('2026-11-27 10:03:30');
        $hint = $slot()['worker_hint'];
        $this->assertNotNull($hint);
        $this->assertStringContainsString('no Signals queue worker has picked it up', $hint);
        $this->assertStringContainsString('queue:work --queue=signals', $hint);

        // Once a worker has run it, the hint is gone.
        DataConnectorSync::where('connector_id', $id)->where('status', 'queued')->update(['status' => 'completed']);
        $this->assertNull($slot()['worker_hint']);
    }
}
