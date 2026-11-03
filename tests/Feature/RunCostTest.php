<?php

namespace Tests\Feature;

use App\Domain\Signals\Opportunities\OpportunityResearcher;
use App\Domain\Signals\Opportunities\ProductProfile;
use App\Domain\Signals\Opportunities\ResearchRun;
use App\Domain\Signals\Products\ProductCatalog;
use App\Domain\Signals\Run\RunContext;
use App\Domain\Signals\Run\RunCost;
use App\Domain\Signals\Support\StructuredAi;
use App\Models\auth\tbluserModel;
use App\Support\RoleKey;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Slice 2: what a research run cost. Every AI call a run makes is filed under the run (RunContext + StructuredAi), the run's totals are read back
 * when it finishes (RunCost), and the runs API shows them. The real migrations run on the in-memory sqlite database; every provider call is
 * mocked. The related_type literal is written out in full on purpose: a test that used the constant would agree with a wrong constant.
 */
class RunCostTest extends TestCase
{
    private const T = 1;
    private const T2 = 2;
    private const RUNS = '2026_12_08_090200_add_cost_and_product_columns_to_g2g_research_runs';
    private const RUN_COST = '2026_12_08_100200_add_ai_cost_to_g2g_research_runs';
    private const USAGE_COST = '2026_12_08_100100_add_cost_columns_to_ai_usage_events';
    private const MODEL_CURRENCY = '2026_12_08_100000_add_currency_to_ai_models';

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
            '2026_10_08_100000_extend_g2g_signals_for_market_import', '2026_11_29_090000_add_assessment_to_g2g_company_opportunities', '2026_12_01_090000_add_product_key_to_signals_runs_and_sources', '2026_10_31_090000_add_research_run_configuration_scope', '2026_10_14_100000_create_g2g_icp_profiles_tables', '2026_11_29_100000_add_date_provenance_to_g2g_research_sources', '2026_11_29_110000_add_modified_date_to_g2g_research_sources', '2026_10_13_100000_create_g2g_target_accounts_table',
            self::RUNS, self::RUN_COST, '2026_09_21_150000_create_ai_usage_tables', '2026_10_09_160000_add_product_to_ai_usage_events', self::USAGE_COST,
            '2026_12_07_090000_create_g2g_product_master_table',
        ] as $m) {
            $this->migration($m)->up();
        }

        RoleKey::flushCache();
        Cache::flush();
        Storage::fake('local');
        ProductCatalog::forgetCustom();
        RunContext::clear();
        Carbon::setTestNow('2026-10-20 10:00:00');

        config([
            'ai.provider.driver' => 'gemini', 'ai.provider.gemini.api_key' => 'test-key', 'signals.ai_attempts' => 2,
            'signals.web_search.driver' => 'tavily', 'signals.web_search.tavily_key' => 'tvly-test-key',
            'signals.web_search.min_interval_ms' => 0, 'signals.icp.scholar_k12_tenants' => [self::T],
            'signals.queue_pickup_seconds' => 180, 'signals.stale_run_minutes' => 15,
        ]);
        DB::table('tbluserprofilemaster')->insert([['id' => 1, 'name' => 'Administrator', 'role_key' => 'administrator']]);

        ProductProfile::create([
            'sub_institute_id' => self::T, 'product_name' => 'ScholarClone K-12 Bundle', 'description' => 'School ERP and LMS.',
            'problems_solved' => 'Disconnected school systems', 'features' => 'ERP, LMS', 'target_industries' => ['Education'],
            'target_markets' => ['Gujarat'], 'keywords' => ['school erp'], 'excluded' => [], 'research_enabled' => true, 'research_frequency' => 'daily', 'recency_days' => 30,
        ]);
    }

    protected function tearDown(): void
    {
        RunContext::clear();
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function migration(string $name): object
    {
        return require base_path("database/migrations/{$name}.php");
    }

    private function h(int $tenant = self::T): array
    {
        $id = DB::table('tbluser')->insertGetId(['user_name' => 'u' . uniqid(), 'sub_institute_id' => $tenant, 'user_profile_id' => 1]);

        return ['Authorization' => 'Bearer ' . tbluserModel::find($id)->createToken('t')->plainTextToken];
    }

    private function run_(array $o = []): ResearchRun
    {
        return ResearchRun::create($o + [
            'sub_institute_id' => self::T, 'report_date' => '2026-10-20', 'trigger' => 'manual', 'status' => 'running', 'stage' => 'queued',
            'stage_message' => 'Queued.', 'started_at' => now(), 'product_key' => 'scholarclone',
        ]);
    }

    /** One ai_usage_events row, as AiUsageMeter writes it: a priced call has estimated_cost + cost_currency, an unpriced one has neither. */
    private function event(array $o = []): void
    {
        DB::table('ai_usage_events')->insert($o + [
            'ai_module' => 'signals', 'provider' => 'gemini', 'model' => 'm', 'input_tokens' => 0, 'output_tokens' => 0, 'estimated_cost_usd' => null,
            'estimated_cost' => null, 'cost_currency' => null,
            'outcome' => 'success', 'related_type' => 'g2g_research_run', 'related_id' => null, 'sub_institute_id' => self::T,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A priced call of a run, in rupees unless said otherwise. */
    private function priced(ResearchRun $run, float $cost, string $currency = 'INR', array $o = []): void
    {
        $this->event($o + ['related_id' => $run->id, 'input_tokens' => 100, 'output_tokens' => 10, 'estimated_cost' => $cost, 'cost_currency' => $currency]);
    }

    private function fakeGemini(int $in = 3, int $out = 2): void
    {
        $this->app->forgetInstance(Factory::class);
        Http::clearResolvedInstance(Factory::class);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => '{"ok":true}']]], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => $in, 'candidatesTokenCount' => $out],
        ], 200)]);
    }

    private function ai(): void
    {
        app(StructuredAi::class)->json(self::T, [['role' => 'user', 'content' => 'x']]);
    }

    private int $aiCalls = 0;

    /** A whole research run's traffic: search finds one page, the page loads, and the AI either answers or times out. */
    private function fakeRunTraffic(bool $aiAnswers): void
    {
        $this->aiCalls = 0;
        $this->app->forgetInstance(Factory::class);
        Http::clearResolvedInstance(Factory::class);
        Http::fake([
            'api.tavily.com/*' => Http::response(['results' => [['url' => 'https://news.example.com/x', 'title' => 'Podar opens campus',
                'content' => 'Podar World School announced a new campus in Vadodara and is hiring an ERP administrator ahead of admissions 2027.', 'published_date' => '2026-10-15']]], 200),
            'generativelanguage.googleapis.com/*' => function () use ($aiAnswers) {
                $this->aiCalls++;   // counted here: a request that throws is not in Http::recorded()
                if (! $aiAnswers) {
                    throw new ConnectionException('cURL error 28: Operation timed out after 90001 milliseconds');
                }

                return Http::response([
                    'candidates' => [['content' => ['parts' => [['text' => '{"opportunities":[],"queries":[]}']]], 'finishReason' => 'STOP']],
                    'usageMetadata' => ['promptTokenCount' => 3, 'candidatesTokenCount' => 2],
                ], 200);
            },
            '*/robots.txt' => Http::response('', 404),
            '*' => Http::response('<html><body><p>Podar World School announced a new campus in Vadodara.</p></body></html>', 200, ['Content-Type' => 'text/html']),
        ]);
    }

    private function requestsTo(string $host): int
    {
        return collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), $host))->count();
    }

    // ── attribution: StructuredAi files a run's AI calls under the run ───────

    public function test_an_ai_call_made_during_a_run_is_filed_under_that_run_and_organisation(): void
    {
        $this->fakeGemini(11, 7);
        RunContext::set(77, self::T);

        $this->ai();

        $row = DB::table('ai_usage_events')->first();
        $this->assertSame('g2g_research_run', $row->related_type);
        $this->assertSame(77, (int) $row->related_id);
        $this->assertSame(self::T, (int) $row->sub_institute_id);
        $this->assertSame([11, 7], [(int) $row->input_tokens, (int) $row->output_tokens]);
    }

    public function test_with_no_active_run_the_call_carries_no_attribution_at_all(): void
    {
        $this->fakeGemini();

        $this->ai();
        RunContext::set(77, self::T);
        $this->ai();
        RunContext::clear();
        $this->ai();

        $rows = DB::table('ai_usage_events')->orderBy('id')->get();
        $this->assertCount(3, $rows);
        $this->assertNull($rows[0]->related_type);
        $this->assertNull($rows[0]->related_id);
        $this->assertSame(77, (int) $rows[1]->related_id);
        $this->assertNull($rows[2]->related_type, 'a cleared context leaves nothing behind for the next call');
        $this->assertNull($rows[2]->related_id);
    }

    public function test_a_call_for_another_organisation_is_not_claimed_by_the_active_run(): void
    {
        $this->fakeGemini();
        RunContext::set(77, self::T2);

        $this->ai();   // made for organisation 1, while organisation 2's run is active

        $row = DB::table('ai_usage_events')->first();
        $this->assertNull($row->related_type);
        $this->assertNull($row->related_id);
    }

    // ── RunCost::fill ────────────────────────────────────────────────────────

    public function test_the_cost_is_the_sum_of_this_runs_rows_of_this_organisation_and_nothing_else(): void
    {
        $run = $this->run_();
        $other = $this->run_();
        $this->event(['related_id' => $run->id, 'input_tokens' => 100, 'output_tokens' => 40, 'estimated_cost' => 12.345678, 'cost_currency' => 'INR']);
        $this->event(['related_id' => $run->id, 'input_tokens' => 20, 'output_tokens' => 5, 'estimated_cost' => 0.5, 'cost_currency' => 'INR']);
        // Everything below belongs to somebody else. Each is in dollars on purpose: if one were counted, the run would be "two currencies" and unknown.
        $this->event(['related_id' => $run->id, 'sub_institute_id' => self::T2, 'input_tokens' => 9000, 'output_tokens' => 9000, 'estimated_cost' => 9.5, 'cost_currency' => 'USD']);   // same id, another organisation
        $this->event(['related_id' => $run->id, 'related_type' => 'g2g_research_runs', 'input_tokens' => 5000, 'estimated_cost' => 4.0, 'cost_currency' => 'USD']);   // near miss on the type
        $this->event(['related_id' => $run->id, 'related_type' => null, 'input_tokens' => 5000, 'estimated_cost' => 4.0, 'cost_currency' => 'USD']);
        $this->event(['related_id' => $other->id, 'input_tokens' => 7000, 'output_tokens' => 7000, 'estimated_cost' => 3.0, 'cost_currency' => 'USD']);   // another run
        $this->event(['related_id' => $other->id, 'input_tokens' => 70, 'output_tokens' => 0]);   // another run's unpriced call

        RunCost::fill($run);

        $fresh = $run->fresh();
        $this->assertSame(120, $fresh->ai_input_tokens);
        $this->assertSame(45, $fresh->ai_output_tokens);
        $this->assertEqualsWithDelta(12.8457, (float) $fresh->ai_cost, 0.000000001, 'the rupees of the two calls, to four decimals');
        $this->assertSame('INR', $fresh->ai_cost_currency);
        $this->assertNull($other->fresh()->ai_input_tokens, 'a run that was not filled is untouched');
    }

    public function test_calls_priced_in_dollars_give_a_dollar_total(): void
    {
        $run = $this->run_();
        $this->priced($run, 0.0018, 'USD');
        $this->priced($run, 0.0004, 'USD');

        RunCost::fill($run);

        $this->assertEqualsWithDelta(0.0022, (float) $run->fresh()->ai_cost, 0.000000001);
        $this->assertSame('USD', $run->fresh()->ai_cost_currency);
    }

    public function test_calls_priced_by_the_older_meter_in_dollars_alone_still_count_as_dollars(): void
    {
        $run = $this->run_();
        $this->event(['related_id' => $run->id, 'input_tokens' => 100, 'output_tokens' => 10, 'estimated_cost_usd' => 0.25]);   // no estimated_cost: priced before the pricing columns
        $this->priced($run, 0.5, 'USD');

        RunCost::fill($run);

        $this->assertEqualsWithDelta(0.75, (float) $run->fresh()->ai_cost, 0.000000001);
        $this->assertSame('USD', $run->fresh()->ai_cost_currency);

        $this->priced($run, 9.0, 'INR');
        RunCost::fill($run);
        $this->assertNull($run->fresh()->ai_cost, 'and a rupee call beside a legacy dollar one is two currencies');
    }

    public function test_calls_in_two_currencies_make_the_cost_unknown_not_a_sum_of_unlike_things(): void
    {
        $run = $this->run_();
        $this->priced($run, 5.0, 'INR');
        $this->priced($run, 0.06, 'USD');

        RunCost::fill($run);

        $fresh = $run->fresh();
        $this->assertSame(220, $fresh->ai_input_tokens + $fresh->ai_output_tokens, 'the tokens are real and still counted');
        $this->assertNull($fresh->ai_cost, 'rupees and dollars are never added');
        $this->assertNull($fresh->ai_cost_currency, 'and no currency is claimed for a cost that is not known');
    }

    public function test_a_refused_call_priced_in_another_currency_does_not_make_the_currencies_clash(): void
    {
        $run = $this->run_();
        $this->priced($run, 5.0, 'INR');
        $this->event(['related_id' => $run->id, 'outcome' => 'refused', 'input_tokens' => 0, 'output_tokens' => 0, 'estimated_cost' => 0, 'cost_currency' => 'USD']);

        RunCost::fill($run);

        $this->assertEqualsWithDelta(5.0, (float) $run->fresh()->ai_cost, 0.000000001);
        $this->assertSame('INR', $run->fresh()->ai_cost_currency);
    }

    public function test_an_amount_with_no_currency_is_not_a_price(): void
    {
        $run = $this->run_();
        $this->event(['related_id' => $run->id, 'input_tokens' => 100, 'output_tokens' => 10, 'estimated_cost' => 4.0, 'cost_currency' => null]);

        RunCost::fill($run);

        $this->assertNull($run->fresh()->ai_cost);
        $this->assertNull($run->fresh()->ai_cost_currency);
    }

    public function test_a_run_that_used_no_ai_stores_zeros_not_unknown(): void
    {
        $run = $this->run_();
        $this->event(['related_id' => $run->id + 100, 'input_tokens' => 50, 'estimated_cost' => 1.0, 'cost_currency' => 'USD']);   // somebody else's

        RunCost::fill($run, 0);

        $fresh = $run->fresh();
        $this->assertSame(0, $fresh->ai_input_tokens);
        $this->assertSame(0, $fresh->ai_output_tokens);
        $this->assertNotNull($fresh->ai_cost, 'no AI is a cost of zero, not an unknown cost');
        $this->assertEquals(0, $fresh->ai_cost);
        $this->assertSame('INR', $fresh->ai_cost_currency, 'in the platform default currency');
        $this->assertSame(0, $fresh->search_requests);
    }

    public function test_the_currency_of_a_run_that_used_no_ai_is_the_configured_default_not_a_hard_coded_one(): void
    {
        config(['ai.default_currency' => 'USD']);
        $run = $this->run_();

        RunCost::fill($run, 0);

        $this->assertEquals(0, $run->fresh()->ai_cost);
        $this->assertSame('USD', $run->fresh()->ai_cost_currency);
    }

    public function test_a_call_that_used_tokens_but_has_no_price_makes_the_cost_unknown_not_zero_and_not_partial(): void
    {
        $run = $this->run_();
        $this->priced($run, 0.5);
        $this->event(['related_id' => $run->id, 'input_tokens' => 80, 'output_tokens' => 8, 'estimated_cost' => null, 'cost_currency' => null]);   // the model has no rate

        RunCost::fill($run);

        $fresh = $run->fresh();
        $this->assertSame(180, $fresh->ai_input_tokens, 'the tokens are real and still counted');
        $this->assertNull($fresh->ai_cost, 'a total that left a call out would read cheaper than it was');
        $this->assertNull($fresh->ai_cost_currency);
    }

    public function test_a_stale_cost_is_cleared_when_a_later_call_turns_out_to_be_unpriced(): void
    {
        $run = $this->run_();
        $this->priced($run, 0.5);
        RunCost::fill($run);
        $this->assertEqualsWithDelta(0.5, (float) $run->fresh()->ai_cost, 0.000000001);

        $this->event(['related_id' => $run->id, 'input_tokens' => 80, 'output_tokens' => 8]);
        RunCost::fill($run);

        $this->assertNull($run->fresh()->ai_cost, 're-read, never left as it was');
        $this->assertNull($run->fresh()->ai_cost_currency);
    }

    public function test_failed_and_refused_calls_with_no_tokens_do_not_make_the_cost_unknown(): void
    {
        $run = $this->run_();
        $this->event(['related_id' => $run->id, 'outcome' => 'failed', 'input_tokens' => 0, 'output_tokens' => 0, 'estimated_cost_usd' => null]);
        $this->event(['related_id' => $run->id, 'outcome' => 'refused', 'input_tokens' => 0, 'output_tokens' => 0, 'estimated_cost_usd' => null]);

        RunCost::fill($run);

        $fresh = $run->fresh();
        $this->assertSame(0, $fresh->ai_input_tokens);
        $this->assertEquals(0, $fresh->ai_cost);
        $this->assertSame('INR', $fresh->ai_cost_currency);
    }

    public function test_filling_twice_gives_the_same_numbers_and_a_later_call_is_picked_up(): void
    {
        $run = $this->run_();
        $this->priced($run, 0.25, 'INR', ['input_tokens' => 100, 'output_tokens' => 40]);

        RunCost::fill($run, 4);
        $once = $run->fresh()->only(['ai_input_tokens', 'ai_output_tokens', 'ai_cost', 'ai_cost_currency', 'search_requests']);
        RunCost::fill($run, 4);
        $twice = $run->fresh()->only(['ai_input_tokens', 'ai_output_tokens', 'ai_cost', 'ai_cost_currency', 'search_requests']);

        $this->assertEquals($once, $twice);
        $this->assertEquals(100, $twice['ai_input_tokens'], 'summed again, never added to what was there');
        $this->assertEquals(0.25, $twice['ai_cost'], 'the rupees too');

        $this->priced($run, 0.25, 'INR', ['input_tokens' => 1, 'output_tokens' => 1]);
        RunCost::fill($run);
        $this->assertEquals(0.5, $run->fresh()->ai_cost);
        $this->assertSame(101, $run->fresh()->ai_input_tokens);
        $this->assertSame(4, $run->fresh()->search_requests, 'no count given: the stored one is left as it was');
    }

    public function test_the_in_memory_run_shows_the_numbers_and_is_not_left_dirty(): void
    {
        $run = $this->run_();
        $this->priced($run, 0.1, 'INR', ['input_tokens' => 9, 'output_tokens' => 1]);

        RunCost::fill($run, 3);

        $this->assertSame(9, $run->ai_input_tokens);
        $this->assertSame(3, $run->search_requests);
        $this->assertFalse($run->isDirty(), 'a later save() of the run has nothing of ours to rewrite');
    }

    public function test_provider_credits_are_never_invented(): void
    {
        $run = $this->run_();

        RunCost::fill($run, 12);

        $this->assertNull($run->fresh()->search_credits);
        $this->assertSame(12, $run->fresh()->search_requests);
    }

    public function test_without_the_new_columns_fill_does_nothing_and_does_not_throw(): void
    {
        $run = $this->run_();
        $this->event(['related_id' => $run->id, 'input_tokens' => 100]);
        $this->migration(self::RUN_COST)->down();
        $this->migration(self::RUNS)->down();

        RunCost::fill($run, 5);

        $this->assertSame('running', $run->fresh()->status, 'the run itself is untouched');
        $this->assertFalse(Schema::hasColumn('g2g_research_runs', 'ai_input_tokens'));
        $this->assertFalse(Schema::hasColumn('g2g_research_runs', 'ai_cost'));
    }

    public function test_a_host_that_has_the_token_columns_but_not_the_pricing_ones_records_tokens_and_leaves_the_old_dollar_column_alone(): void
    {
        $run = $this->run_();
        $this->priced($run, 7.5);
        $this->migration(self::RUN_COST)->down();

        RunCost::fill($run, 5);

        $fresh = $run->fresh();
        $this->assertSame(100, $fresh->ai_input_tokens);
        $this->assertSame(5, $fresh->search_requests);
        $this->assertNull($fresh->ai_cost_usd, 'no longer written, and never a rupee figure in a dollar column');
        $this->assertFalse(Schema::hasColumn('g2g_research_runs', 'ai_cost'));
    }

    public function test_a_usage_table_without_the_pricing_columns_is_read_by_its_dollar_column(): void
    {
        $run = $this->run_();
        $this->event(['related_id' => $run->id, 'input_tokens' => 100, 'output_tokens' => 10, 'estimated_cost_usd' => 0.25]);
        $this->migration(self::USAGE_COST)->down();

        RunCost::fill($run);

        $fresh = $run->fresh();
        $this->assertEqualsWithDelta(0.25, (float) $fresh->ai_cost, 0.000000001);
        $this->assertSame('USD', $fresh->ai_cost_currency);

        DB::table('ai_usage_events')->insert([   // a call with no dollar price (it may be a rupee one this host has nowhere to store)
            'ai_module' => 'signals', 'provider' => 'gemini', 'model' => 'm', 'input_tokens' => 5, 'output_tokens' => 0, 'outcome' => 'success',
            'related_type' => 'g2g_research_run', 'related_id' => $run->id, 'sub_institute_id' => self::T, 'created_at' => now(), 'updated_at' => now(),
        ]);
        RunCost::fill($run);
        $this->assertNull($run->fresh()->ai_cost);
    }

    public function test_without_the_usage_table_the_ai_numbers_stay_unknown_but_the_search_count_is_kept(): void
    {
        $run = $this->run_();
        Schema::drop('ai_usage_events');

        RunCost::fill($run, 6);

        $fresh = $run->fresh();
        $this->assertNull($fresh->ai_input_tokens, 'nothing was metered, so nothing is known (not "zero tokens")');
        $this->assertNull($fresh->ai_cost);
        $this->assertNull($fresh->ai_cost_currency);
        $this->assertSame(6, $fresh->search_requests);
    }

    public function test_a_cost_read_that_fails_is_logged_and_never_thrown_and_the_run_is_untouched(): void
    {
        $run = $this->run_(['status' => 'success', 'stage' => 'completed', 'stage_message' => 'Done.']);
        Schema::drop('ai_usage_events');
        Schema::create('ai_usage_events', function ($t) {   // a table the sums cannot be read from
            $t->id();
            $t->string('related_type')->nullable();
            $t->unsignedBigInteger('related_id')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
        });
        Log::spy();

        RunCost::fill($run, 3);

        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => $message === 'signals.run_cost_not_recorded')->once();
        $fresh = $run->fresh();
        $this->assertSame('success', $fresh->status);
        $this->assertSame('Done.', $fresh->stage_message);
        $this->assertNull($fresh->ai_input_tokens);
        $this->assertNull($fresh->search_requests, 'nothing partial is written when the read fails');
    }

    // ── the run's product ────────────────────────────────────────────────────

    private function seedScholarMaster(): int
    {
        return (int) DB::table('g2g_product_master')->insertGetId([
            'sub_institute_id' => 0, 'product_key' => 'scholarclone', 'kind' => 'product', 'name' => 'ScholarClone', 'status' => 'active', 'origin' => 'manual',
        ]);
    }

    public function test_a_run_is_resolved_to_its_product_master_row(): void
    {
        $id = $this->seedScholarMaster();

        $this->assertSame($id, RunCost::productMasterId(self::T, 'scholarclone', null));
        $this->assertSame($id, RunCost::productMasterId(self::T2, 'scholar', null), 'the shared product is visible to every organisation');
        $this->assertNull(RunCost::productMasterId(self::T, 'no such product', null), 'never guessed');
        $this->assertNull(RunCost::productMasterId(self::T, null, null));
    }

    public function test_a_missing_master_table_or_column_never_stops_a_run(): void
    {
        $this->seedScholarMaster();

        Schema::drop('g2g_product_master');
        $this->assertNull(RunCost::productMasterId(self::T, 'scholarclone', null), 'no master table on this host');

        $this->migration('2026_12_07_090000_create_g2g_product_master_table')->up();
        $this->seedScholarMaster();
        $this->migration(self::RUNS)->down();
        $this->assertNull(RunCost::productMasterId(self::T, 'scholarclone', null), 'no column to hold it, so no lookup');
    }

    // ── the researcher: the whole path ───────────────────────────────────────

    public function test_a_run_whose_ai_call_fails_still_clears_the_context_and_records_what_it_spent(): void
    {
        $master = $this->seedScholarMaster();
        $this->fakeRunTraffic(false);
        $run = $this->run_();

        app(OpportunityResearcher::class)->run(self::T, 'manual', null, null, $run->id, null, 'scholarclone');

        $this->assertNull(RunContext::current(), 'the context does not outlive the run, even one that failed');
        $fresh = $run->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertSame('ai_timeout', $fresh->error_code);
        $this->assertSame($master, (int) $fresh->product_master_id);
        $this->assertGreaterThan(0, $this->requestsTo('api.tavily.com'));
        $this->assertSame($this->requestsTo('api.tavily.com'), $fresh->search_requests, 'one per request sent to the search provider');
        $rows = DB::table('ai_usage_events')->get();
        $this->assertGreaterThan(0, $this->aiCalls);
        $this->assertCount($this->aiCalls, $rows, 'every AI request left one usage row');
        foreach ($rows as $row) {
            $this->assertSame('g2g_research_run', $row->related_type);
            $this->assertSame($run->id, (int) $row->related_id);
            $this->assertSame('failed', $row->outcome);
        }
        $this->assertSame(0, $fresh->ai_input_tokens, 'failed calls carry no tokens');
        $this->assertEquals(0, $fresh->ai_cost);
        $this->assertSame('INR', $fresh->ai_cost_currency);
    }

    public function test_a_run_that_finishes_stores_the_tokens_its_calls_used_and_an_unpriced_cost_stays_unknown(): void
    {
        $this->fakeRunTraffic(true);
        $run = $this->run_();

        app(OpportunityResearcher::class)->run(self::T, 'manual', null, null, $run->id, null, 'scholarclone');

        $this->assertNull(RunContext::current());
        $fresh = $run->fresh();
        $this->assertContains($fresh->status, ['success', 'partial'], (string) $fresh->stage_message);
        $calls = $this->aiCalls;
        $this->assertGreaterThan(0, $calls, 'the run made at least one AI call');
        $this->assertSame($calls * 3, $fresh->ai_input_tokens);
        $this->assertSame($calls * 2, $fresh->ai_output_tokens);
        $this->assertNull($fresh->ai_cost, 'the test models have no rate, so the cost is unknown, not 0');
        $this->assertNull($fresh->ai_cost_currency);
        $this->assertSame($this->requestsTo('api.tavily.com'), $fresh->search_requests);
        $this->assertSame($calls, DB::table('ai_usage_events')->where('related_type', 'g2g_research_run')->where('related_id', $run->id)->count());
    }

    public function test_a_run_whose_model_has_a_rupee_price_is_priced_in_rupees_from_the_call_to_the_runs_api(): void
    {
        // The whole chain: rate card row -> AiUsageMeter prices each call as it is made -> RunCost sums them -> the runs API shows rupees.
        $this->migration('2026_09_19_120000_create_ai_intelligence_tables')->up();
        $this->migration(self::MODEL_CURRENCY)->up();
        DB::table('ai_models')->delete();
        $model = (string) config('ai.provider.gemini.model');
        DB::table('ai_models')->insert([
            'provider' => 'gemini', 'model_id' => $model, 'label' => $model, 'input_cost_per_1k' => 10.0, 'output_cost_per_1k' => 20.0, 'currency' => 'INR',
            'sub_institute_id' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->fakeRunTraffic(true);
        $run = $this->run_();

        app(OpportunityResearcher::class)->run(self::T, 'manual', null, null, $run->id, null, 'scholarclone');

        $calls = $this->aiCalls;
        $this->assertGreaterThan(0, $calls);
        $rows = DB::table('ai_usage_events')->where('related_id', $run->id)->get();
        $this->assertCount($calls, $rows);
        foreach ($rows as $row) {
            $this->assertEqualsWithDelta(0.07, (float) $row->estimated_cost, 0.000000001, '3 input and 2 output tokens at 10 and 20 rupees per 1,000');
            $this->assertSame('INR', $row->cost_currency);
            $this->assertNull($row->estimated_cost_usd);
        }
        $fresh = $run->fresh();
        $this->assertEqualsWithDelta(round(0.07 * $calls, 4), (float) $fresh->ai_cost, 0.000000001);
        $this->assertSame('INR', $fresh->ai_cost_currency);

        $data = $this->getJson("/api/signals/research/runs/{$run->id}", $this->h())->assertOk()->json('data');
        $this->assertEqualsWithDelta(round(0.07 * $calls, 4), $data['ai_cost'], 0.000000001);
        $this->assertSame('INR', $data['ai_cost_currency']);
    }

    public function test_a_product_already_on_the_run_is_never_overwritten(): void
    {
        $this->seedScholarMaster();
        $this->fakeRunTraffic(false);
        $run = $this->run_(['product_master_id' => 999]);

        app(OpportunityResearcher::class)->run(self::T, 'manual', null, null, $run->id, null, 'scholarclone');

        $this->assertSame(999, (int) $run->fresh()->product_master_id);
    }

    public function test_a_run_closed_at_the_lock_reports_zero_searches_not_the_previous_runs_count(): void
    {
        $this->fakeRunTraffic(false);
        $researcher = app(OpportunityResearcher::class);
        $first = $this->run_();
        $researcher->run(self::T, 'manual', null, null, $first->id, null, 'scholarclone');
        $this->assertGreaterThan(0, $first->fresh()->search_requests, 'the first run searched');

        $second = $this->run_();
        $held = Cache::lock('signals:research:' . self::T, 60);
        $this->assertTrue($held->get());
        $researcher->run(self::T, 'manual', null, null, $second->id, null, 'scholarclone');   // the same instance, still holding the first run's counters

        $fresh = $second->fresh();
        $this->assertSame('skipped', $fresh->status);
        $this->assertSame(0, $fresh->search_requests);
        $this->assertSame(0, $fresh->ai_input_tokens);
        $this->assertEquals(0, $fresh->ai_cost);
        $this->assertSame('INR', $fresh->ai_cost_currency);
    }

    public function test_a_run_recorded_without_ever_starting_stores_zeros(): void
    {
        $this->fakeRunTraffic(true);

        $row = app(OpportunityResearcher::class)->run(self::T, 'scheduled', null, null, null, null, 'no_such_product');   // refused before any run row exists

        $this->assertSame('skipped', $row->status);
        $this->assertSame('unknown_product', $row->error_code);
        $this->assertSame(0, $row->search_requests);
        $this->assertSame(0, $row->ai_input_tokens);
        $this->assertSame(0, ResearchRun::find($row->id)->search_requests);
        $this->assertNull(RunContext::current());
    }

    // ── the runs API ─────────────────────────────────────────────────────────

    public function test_the_run_endpoints_show_the_product_cost_search_counts_and_duration(): void
    {
        $run = $this->run_([
            'status' => 'success', 'stage' => 'completed', 'started_at' => '2026-10-20 09:00:00', 'completed_at' => '2026-10-20 09:03:20',
            'product_master_id' => 5, 'ai_input_tokens' => 1200, 'ai_output_tokens' => 340, 'ai_cost' => 12.8457, 'ai_cost_currency' => 'INR', 'search_requests' => 9,
        ]);

        foreach (["/api/signals/research/runs/{$run->id}" => 'data', '/api/signals/research/runs' => 'data.0'] as $url => $path) {
            $data = $this->getJson($url, $this->h())->assertOk()->json($path);
            $this->assertSame(5, $data['product_master_id']);
            $this->assertSame(1200, $data['ai_input_tokens']);
            $this->assertSame(340, $data['ai_output_tokens']);
            $this->assertEqualsWithDelta(12.8457, $data['ai_cost'], 0.0000001);
            $this->assertIsFloat($data['ai_cost'], 'a number, not a string');
            $this->assertSame('INR', $data['ai_cost_currency']);
            $this->assertArrayNotHasKey('ai_cost_usd', $data, 'the dollar-only field is gone from the API');
            $this->assertSame(9, $data['search_requests']);
            $this->assertNull($data['search_credits'], 'the provider reports none: unknown');
            $this->assertSame(200, $data['duration_seconds']);
        }
    }

    public function test_a_run_that_spent_nothing_shows_zero_and_a_run_with_unknown_cost_shows_null(): void
    {
        $zero = $this->run_(['status' => 'skipped', 'stage' => 'failed', 'completed_at' => now(), 'ai_input_tokens' => 0, 'ai_output_tokens' => 0, 'ai_cost' => 0, 'ai_cost_currency' => 'INR', 'search_requests' => 0]);
        $unknown = $this->run_(['status' => 'success', 'stage' => 'completed', 'completed_at' => now(), 'ai_input_tokens' => 50, 'ai_output_tokens' => 5, 'ai_cost' => null, 'ai_cost_currency' => null, 'search_requests' => null]);

        $a = $this->getJson("/api/signals/research/runs/{$zero->id}", $this->h())->assertOk()->json('data');
        $b = $this->getJson("/api/signals/research/runs/{$unknown->id}", $this->h())->assertOk()->json('data');

        $this->assertSame([0, 0, 0.0, 0], [$a['ai_input_tokens'], $a['ai_output_tokens'], (float) $a['ai_cost'], $a['search_requests']]);
        $this->assertNotNull($a['ai_cost']);
        $this->assertSame('INR', $a['ai_cost_currency']);
        $this->assertArrayHasKey('ai_cost', $b);
        $this->assertNull($b['ai_cost'], 'unknown stays null, never 0');
        $this->assertArrayHasKey('ai_cost_currency', $b);
        $this->assertNull($b['ai_cost_currency']);
        $this->assertArrayHasKey('search_requests', $b);
        $this->assertNull($b['search_requests']);
        $this->assertSame(50, $b['ai_input_tokens']);
    }

    public function test_a_running_run_has_no_duration_and_one_with_an_unknown_end_has_none_either(): void
    {
        $running = $this->run_();
        $noEnd = $this->run_(['status' => 'failed', 'stage' => 'failed', 'completed_at' => null]);
        $inconsistent = $this->run_(['status' => 'running', 'completed_at' => '2026-10-20 09:59:00']);

        foreach ([$running, $noEnd, $inconsistent] as $r) {
            $data = $this->getJson("/api/signals/research/runs/{$r->id}", $this->h())->assertOk()->json('data');
            $this->assertArrayHasKey('duration_seconds', $data);
            $this->assertNull($data['duration_seconds']);
        }
    }

    public function test_a_host_without_the_cost_columns_still_answers_with_nulls(): void
    {
        $run = $this->run_(['status' => 'success', 'stage' => 'completed', 'completed_at' => now()]);
        $this->migration(self::RUN_COST)->down();
        $this->migration(self::RUNS)->down();

        $data = $this->getJson("/api/signals/research/runs/{$run->id}", $this->h())->assertOk()->json('data');

        foreach (['product_master_id', 'ai_input_tokens', 'ai_output_tokens', 'ai_cost', 'ai_cost_currency', 'search_requests', 'search_credits'] as $key) {
            $this->assertArrayHasKey($key, $data);
            $this->assertNull($data[$key], $key);
        }
    }

    public function test_another_organisations_run_and_its_cost_are_not_visible(): void
    {
        $theirs = $this->run_(['sub_institute_id' => self::T2, 'ai_input_tokens' => 777, 'status' => 'success', 'completed_at' => now()]);

        $this->getJson("/api/signals/research/runs/{$theirs->id}", $this->h())->assertStatus(404);
        $list = $this->getJson('/api/signals/research/runs', $this->h())->assertOk()->json('data');
        $this->assertSame([], $list);
    }
}
