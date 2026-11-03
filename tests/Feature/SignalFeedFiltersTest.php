<?php

namespace Tests\Feature;

use App\Models\auth\tbluserModel;
use App\Support\RoleKey;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * GET /api/signals/opportunities, slice 2 additions: the date facets (freshness, date_status), the product facet, sort=recent, and the new item
 * and filter keys. The first test pins what the feed answered BEFORE these existed, so a request that sends none of the new parameters is
 * proven unchanged. Time is frozen at 2026-10-20, so the 30-day line is 2026-09-20 and the 90-day line is 2026-07-22. sqlite in memory.
 */
class SignalFeedFiltersTest extends TestCase
{
    private const T1 = 1;
    private const T2 = 2;

    /** Every key a feed item carried before slice 2 (presentOpportunity + presentMany). */
    private const OLD_ITEM_KEYS = [
        'id', 'company_id', 'company_name', 'website', 'industry', 'location', 'title', 'category', 'category_label', 'signal_kind', 'signal_kind_label',
        'observed_event', 'why_indicates_need', 'product_fit', 'product_name', 'signal_family', 'relevant_offer_id', 'relevant_offer_name', 'urgency',
        'feed_section', 'confirmed_facts', 'unverified_claims', 'recommended_action', 'priority', 'qualification', 'confidence', 'review_status',
        'review_notes', 'ingestion_source_id', 'primary_source', 'source_count', 'source_published_at', 'event_date', 'report_date', 'first_discovered_at',
        'last_verified_at', 'discovered_on_report_date', 'research_run_id', 'entered_manually', 'captured_on', 'card', 'strength', 'strength_reason',
        'buying_intent', 'assessment', 'pipeline_status', 'trigger_type', 'trigger_summary', 'reference_no', 'source_url', 'source_title', 'expires_at',
        'days_left', 'is_expired', 'claim_level', 'fetch_level', 'evidence_note', 'business_fit', 'product', 'candidate_need_codes', 'buyer_segment',
        'buyer_type', 'buyer_state', 'likely_problem', 'likely_stakeholder', 'what_we_could_sell', 'entry_point', 'scale', 'estimated_value',
        'is_government_track', 'partner_route_note', 'soft_marketing_angle', 'scores', 'score_reasoning', 'is_sample', 'matched_offers', 'account',
        'promoted_lead_id', 'promoted_lead_no', 'promoted_lead_status', 'crm_qualification',
    ];

    private const NEW_ITEM_KEYS = ['date_info', 'product_master_id', 'product_master_name'];

    private const OLD_SUMMARY_KEYS = ['total', 'new', 'high_priority', 'follow_up', 'immediate', 'watchlist', 'market_intelligence', 'competitor_intelligence', 'expired', 'samples'];

    private const OLD_FILTER_KEYS = ['claim_levels', 'fetch_levels', 'business_fits', 'products', 'pipeline_statuses', 'categories', 'kinds', 'report_dates'];

    private int $seq = 0;

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
            '2026_10_08_100000_extend_g2g_signals_for_market_import', '2026_11_29_090000_add_assessment_to_g2g_company_opportunities',
            '2026_12_01_090000_add_product_key_to_signals_runs_and_sources', '2026_12_07_090000_create_g2g_product_master_table',
            '2026_12_08_090100_add_review_and_product_columns_to_g2g_company_opportunities',
        ] as $m) {
            (require base_path("database/migrations/{$m}.php"))->up();
        }

        RoleKey::flushCache();
        Cache::flush();
        Carbon::setTestNow('2026-10-20 10:00:00');
        DB::table('tbluserprofilemaster')->insert([['id' => 1, 'name' => 'Administrator', 'role_key' => 'administrator']]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function h(int $tenant = self::T1): array
    {
        $id = DB::table('tbluser')->insertGetId(['user_name' => 'u' . uniqid(), 'sub_institute_id' => $tenant, 'user_profile_id' => 1]);

        return ['Authorization' => 'Bearer ' . tbluserModel::find($id)->createToken('t')->plainTextToken];
    }

    /** One signal. The title is the label the tests assert on. */
    private function sig(string $title, array $o = []): int
    {
        $this->seq++;

        return (int) DB::table('g2g_company_opportunities')->insertGetId($o + [
            'sub_institute_id' => self::T1, 'company_id' => 1, 'research_run_id' => null, 'title' => $title, 'category' => 'hiring',
            'observed_event' => 'e', 'why_indicates_need' => 'w', 'product_fit' => 'f', 'recommended_action' => 'a', 'priority' => 'Medium',
            'qualification' => 'Monitoring', 'confidence' => 'Medium', 'review_status' => 'New', 'sources' => '[]', 'fingerprint' => md5('fp' . $this->seq),
            'first_discovered_at' => '2026-10-01 09:00:00', 'last_verified_at' => '2026-10-01 09:00:00', 'created_at' => '2026-10-01 09:00:00', 'updated_at' => '2026-10-01 09:00:00',
        ]);
    }

    /** @return list<string> the titles of a request's page, in order */
    private function titles(string $query, int $tenant = self::T1): array
    {
        return collect($this->getJson('/api/signals/opportunities?per_page=50&' . $query, $this->h($tenant))->assertOk()->json('data'))->pluck('title')->all();
    }

    private function assertSameSet(array $expected, array $actual, string $message = ''): void
    {
        $this->assertEqualsCanonicalizing($expected, $actual, $message);
    }

    /**
     * Eighteen dated and undated signals around the 30 and 90 day lines. Insertion order is id order, which the ordering tests rely on.
     * Effective date = event_date, else source_published_at.
     */
    private function seedDates(): void
    {
        $this->sig('ev_today', ['event_date' => '2026-10-20']);
        $this->sig('ev_d30', ['event_date' => '2026-09-20']);                                   // exactly 30 days ago
        $this->sig('ev_d31', ['event_date' => '2026-09-19']);
        $this->sig('ev_d90', ['event_date' => '2026-07-22']);                                   // exactly 90 days ago
        $this->sig('ev_d91', ['event_date' => '2026-07-21']);
        $this->sig('src_recent', ['source_published_at' => '2026-10-10']);
        $this->sig('src_d45', ['source_published_at' => '2026-09-05']);
        $this->sig('src_old', ['source_published_at' => '2026-01-02']);
        $this->sig('future_event', ['event_date' => '2027-01-15']);
        $this->sig('event_wins', ['event_date' => '2026-01-01', 'source_published_at' => '2026-10-15']);   // the event date beats a newer source date
        $this->sig('zero_event', ['event_date' => '0000-00-00', 'source_published_at' => '2026-10-12']);   // a zero date is no date
        // The stored status says undated, the columns say an event date: the feed goes by the columns.
        $this->sig('stored_lies', ['event_date' => '2026-10-18', 'date_status' => 'undated']);
        $this->sig('tie_a', ['event_date' => '2026-10-18', 'first_discovered_at' => '2026-10-19 09:00:00']);
        $this->sig('tie_b', ['event_date' => '2026-10-18', 'first_discovered_at' => '2026-10-02 09:00:00']);
        $this->sig('both_null', ['first_discovered_at' => '2026-10-10 09:00:00']);
        $this->sig('u_b', ['first_discovered_at' => '2026-10-01 09:00:00']);
        $this->sig('u_c', ['first_discovered_at' => '2026-10-05 09:00:00']);
        $this->sig('u_d', ['first_discovered_at' => '2026-10-05 09:00:00']);
        // Another organisation's signals, dated inside every window: never returned.
        $this->sig('other_tenant_fresh', ['sub_institute_id' => self::T2, 'event_date' => '2026-10-19']);
        $this->sig('other_tenant_undated', ['sub_institute_id' => self::T2]);
    }

    // ── nothing changes when no new parameter is sent ────────────────────────

    public function test_a_request_without_the_new_parameters_keeps_its_order_total_and_keys(): void
    {
        // [expires_at, score_total, priority, first_discovered_at]
        $this->sig('A', ['expires_at' => '2026-11-30', 'score_total' => 50, 'priority' => 'High']);
        $this->sig('B', ['expires_at' => '2026-10-25', 'score_total' => 10, 'priority' => 'Low']);
        $this->sig('C', ['score_total' => 90, 'priority' => 'High']);
        $this->sig('D', ['score_total' => 90, 'priority' => 'Medium', 'first_discovered_at' => '2026-10-02 09:00:00']);
        $this->sig('E', ['score_total' => 90, 'priority' => 'Medium', 'first_discovered_at' => '2026-10-03 09:00:00']);
        $this->sig('F', ['score_total' => 20, 'priority' => 'High']);
        $this->sig('G', ['expires_at' => '2026-10-25', 'score_total' => 40, 'priority' => 'Medium']);
        $this->sig('H_expired', ['expires_at' => '2026-10-10', 'score_total' => 99, 'priority' => 'High']);
        $this->sig('J_sample', ['is_sample' => 1, 'score_total' => 99]);
        $this->sig('K_other_tenant', ['sub_institute_id' => self::T2, 'score_total' => 99]);
        // Dates must not move anything when no date parameter is sent.
        DB::table('g2g_company_opportunities')->where('title', 'F')->update(['event_date' => '2026-10-19']);
        DB::table('g2g_company_opportunities')->where('title', 'D')->update(['source_published_at' => '2020-01-01']);

        // Soonest expiry first (none last), then score, then priority, then first seen, then id.
        $default = $this->getJson('/api/signals/opportunities?per_page=50', $this->h())->assertOk();
        $this->assertSame(['G', 'B', 'A', 'C', 'E', 'D', 'F'], collect($default->json('data'))->pluck('title')->all());
        $default->assertJsonPath('meta.total', 7)->assertJsonPath('summary.total', 7);

        $this->assertSame(['G', 'B', 'A', 'C', 'E', 'D', 'F'], $this->titles('sort=default'));
        $this->assertSame(['A', 'C', 'F', 'G', 'E', 'D', 'B'], $this->titles('sort=priority'));
        $this->assertSame(['H_expired'], $this->titles('expired=only'));
        $this->assertSame(['H_expired', 'G', 'B', 'A', 'C', 'E', 'D', 'F'], $this->titles('expired=include'));

        $body = $default->json();
        $this->assertEqualsCanonicalizing(['status', 'data', 'meta', 'summary', 'filters'], array_keys($body));
        $this->assertEqualsCanonicalizing(self::OLD_SUMMARY_KEYS, array_keys($body['summary']));
        $this->assertEqualsCanonicalizing(array_merge(self::OLD_FILTER_KEYS, ['review_reasons', 'date_statuses']), array_keys($body['filters']));
        foreach ($body['data'] as $item) {
            $this->assertEqualsCanonicalizing(array_merge(self::OLD_ITEM_KEYS, self::NEW_ITEM_KEYS), array_keys($item));
        }
    }

    // ── freshness and the effective date ─────────────────────────────────────

    public function test_freshness_windows_split_on_the_exact_30th_and_90th_day(): void
    {
        $this->seedDates();

        $last30 = ['ev_today', 'ev_d30', 'src_recent', 'future_event', 'zero_event', 'stored_lies', 'tie_a', 'tie_b'];
        $this->assertSameSet($last30, $this->titles('freshness=last_30_days'));
        $this->assertSameSet(array_merge($last30, ['ev_d31', 'ev_d90', 'src_d45']), $this->titles('freshness=last_90_days'));
        $this->assertSameSet(['ev_d91', 'src_old', 'event_wins'], $this->titles('freshness=older'));
        $this->assertSameSet(['both_null', 'u_b', 'u_c', 'u_d'], $this->titles('freshness=undated'));

        // The four answers split the tenant's 18 signals with no overlap and nothing left over.
        $parts = array_merge($this->titles('freshness=last_30_days'), array_diff($this->titles('freshness=last_90_days'), $last30), $this->titles('freshness=older'), $this->titles('freshness=undated'));
        $this->assertCount(18, $parts);
        $this->assertCount(18, array_unique($parts));
    }

    public function test_a_future_event_date_counts_as_fresh_and_is_never_older(): void
    {
        $this->seedDates();

        $this->assertContains('future_event', $this->titles('freshness=last_30_days'));
        $this->assertContains('future_event', $this->titles('freshness=last_90_days'));
        $this->assertNotContains('future_event', $this->titles('freshness=older'));
        $this->assertNotContains('future_event', $this->titles('freshness=undated'));
    }

    public function test_the_effective_date_is_the_event_date_then_the_source_date_and_a_zero_date_is_none(): void
    {
        $this->seedDates();

        // event 2026-01-01 with a source date of last week: the event date wins, so it is old.
        $this->assertContains('event_wins', $this->titles('freshness=older'));
        $this->assertNotContains('event_wins', $this->titles('freshness=last_90_days'));
        // only a source date: that is the effective date.
        $this->assertContains('src_recent', $this->titles('freshness=last_30_days'));
        $this->assertContains('src_old', $this->titles('freshness=older'));
        // a zero event date falls through to the source date, it is not "very old".
        $this->assertContains('zero_event', $this->titles('freshness=last_30_days'));
        $this->assertNotContains('zero_event', $this->titles('freshness=older'));
        // both absent: no age, so never "older".
        $this->assertNotContains('both_null', $this->titles('freshness=older'));
    }

    public function test_the_date_status_filter_goes_by_the_date_columns_and_agrees_with_freshness_undated(): void
    {
        $this->seedDates();

        $this->assertSameSet(
            ['ev_today', 'ev_d30', 'ev_d31', 'ev_d90', 'ev_d91', 'future_event', 'event_wins', 'stored_lies', 'tie_a', 'tie_b'],
            $this->titles('date_status=event_date_stated')
        );
        $this->assertSameSet(['src_recent', 'src_d45', 'src_old', 'zero_event'], $this->titles('date_status=source_date_only'));
        $this->assertSameSet($this->titles('freshness=undated'), $this->titles('date_status=undated'));
        $this->assertSameSet(['both_null', 'u_b', 'u_c', 'u_d'], $this->titles('date_status=undated'));
        // The two facets together are an AND.
        $this->assertSameSet(['src_recent', 'zero_event'], $this->titles('date_status=source_date_only&freshness=last_30_days'));
    }

    public function test_date_info_comes_from_the_columns_not_the_stored_status(): void
    {
        $this->seedDates();

        $info = collect($this->getJson('/api/signals/opportunities?per_page=50', $this->h())->assertOk()->json('data'))->pluck('date_info', 'title');

        $this->assertSame(['status' => 'event_date_stated', 'date' => '2026-10-20'], $info['ev_today']);
        $this->assertSame(['status' => 'event_date_stated', 'date' => '2026-01-01'], $info['event_wins']);
        $this->assertSame(['status' => 'event_date_stated', 'date' => '2026-10-18'], $info['stored_lies']);
        $this->assertSame(['status' => 'source_date_only', 'date' => '2026-10-10'], $info['src_recent']);
        $this->assertSame(['status' => 'source_date_only', 'date' => '2026-10-12'], $info['zero_event']);
        $this->assertSame(['status' => 'undated', 'date' => null], $info['both_null']);
    }

    // ── sort=recent ──────────────────────────────────────────────────────────

    public function test_sort_recent_orders_by_effective_date_then_first_seen_for_undated_then_id(): void
    {
        $this->seedDates();

        $this->assertSame([
            'future_event', 'ev_today',
            'tie_b', 'tie_a', 'stored_lies',            // 2026-10-18 three ways: the newest id first, whatever was first discovered when
            'zero_event', 'src_recent', 'ev_d30', 'ev_d31', 'src_d45', 'ev_d90', 'ev_d91', 'src_old', 'event_wins',
            'both_null', 'u_d', 'u_c', 'u_b',           // no date: after every dated signal, by first seen (u_d and u_c tie on it: newest id first)
        ], $this->titles('sort=recent'));
    }

    public function test_sort_recent_pages_in_the_same_order_and_combines_with_the_facets(): void
    {
        $this->seedDates();
        $h = $this->h();

        $page1 = $this->getJson('/api/signals/opportunities?sort=recent&per_page=5&page=1', $h)->assertOk();
        $page2 = $this->getJson('/api/signals/opportunities?sort=recent&per_page=5&page=2', $h)->assertOk();
        $this->assertSame(18, $page1->json('meta.total'));
        $this->assertSame(['future_event', 'ev_today', 'tie_b', 'tie_a', 'stored_lies'], collect($page1->json('data'))->pluck('title')->all());
        $this->assertSame(['zero_event', 'src_recent', 'ev_d30', 'ev_d31', 'src_d45'], collect($page2->json('data'))->pluck('title')->all());

        $this->assertSame(
            ['future_event', 'ev_today', 'tie_b', 'tie_a', 'stored_lies', 'ev_d30', 'ev_d31', 'ev_d90'],
            $this->titles('sort=recent&freshness=last_90_days&date_status=event_date_stated')
        );
        $this->assertSame(['ev_d91', 'src_old', 'event_wins'], $this->titles('sort=recent&freshness=older'));
    }

    // ── product facet and names ──────────────────────────────────────────────

    private function product(int $tenant, string $key, string $name): int
    {
        return (int) DB::table('g2g_product_master')->insertGetId(['sub_institute_id' => $tenant, 'product_key' => $key, 'kind' => 'product', 'name' => $name, 'status' => 'active']);
    }

    public function test_the_product_filter_and_names_are_tenant_scoped_and_looked_up_once_per_page(): void
    {
        $shared = $this->product(0, 'shared_one', 'Shared Product');
        $own = $this->product(self::T1, 'own_one', 'Own Product');
        $foreign = $this->product(self::T2, 'secret_one', 'Secret Foreign Product');

        $this->sig('on_shared', ['product_master_id' => $shared]);
        $this->sig('on_own', ['product_master_id' => $own]);
        $this->sig('on_own_too', ['product_master_id' => $own]);
        $this->sig('points_at_foreign', ['product_master_id' => $foreign]);   // must never gain the other organisation's product name
        $this->sig('no_product');
        $this->sig('t2_on_foreign', ['sub_institute_id' => self::T2, 'product_master_id' => $foreign]);

        $h = $this->h();
        $lookups = fn () => collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from "g2g_product_master"'))->count();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $all = $this->getJson('/api/signals/opportunities?per_page=50', $h)->assertOk();
        $nameQueries = $lookups();
        DB::disableQueryLog();

        $this->assertSame(1, $nameQueries, 'one product lookup for the whole page, not one per row');
        $byTitle = collect($all->json('data'))->keyBy('title');
        $this->assertSame($shared, $byTitle['on_shared']['product_master_id']);
        $this->assertSame('Shared Product', $byTitle['on_shared']['product_master_name']);
        $this->assertSame('Own Product', $byTitle['on_own']['product_master_name']);
        $this->assertSame($foreign, $byTitle['points_at_foreign']['product_master_id']);
        $this->assertNull($byTitle['points_at_foreign']['product_master_name']);
        $this->assertNull($byTitle['no_product']['product_master_id']);
        $this->assertNull($byTitle['no_product']['product_master_name']);
        $this->assertStringNotContainsString('Secret Foreign Product', $all->getContent());

        $this->assertSameSet(['on_own', 'on_own_too'], $this->titles("product_master_id={$own}"));
        $this->assertSameSet(['on_shared'], $this->titles("product_master_id={$shared}"));
        // Asking for the other organisation's product id finds only this organisation's own rows that carry it, and still names nothing.
        $foreignAsk = $this->getJson("/api/signals/opportunities?product_master_id={$foreign}", $h)->assertOk();
        $this->assertSame(['points_at_foreign'], collect($foreignAsk->json('data'))->pluck('title')->all());
        $this->assertStringNotContainsString('Secret Foreign Product', $foreignAsk->getContent());
        // The other organisation sees its own name on its own row.
        $t2 = $this->getJson('/api/signals/opportunities', $this->h(self::T2))->assertOk();
        $this->assertSame('Secret Foreign Product', $t2->json('data.0.product_master_name'));
        // A page with no product on any row makes no product lookup at all.
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/signals/opportunities?product_master_id=999999', $h)->assertOk()->assertJsonPath('meta.total', 0);
        $this->assertSame(0, $lookups());
        DB::disableQueryLog();
    }

    public function test_the_signal_detail_also_names_its_product(): void
    {
        $own = $this->product(self::T1, 'own_one', 'Own Product');
        $id = $this->sig('on_own', ['product_master_id' => $own]);

        $this->getJson("/api/signals/opportunities/{$id}", $this->h())->assertOk()
            ->assertJsonPath('data.product_master_id', $own)->assertJsonPath('data.product_master_name', 'Own Product')
            ->assertJsonPath('data.date_info.status', 'undated');
    }

    // ── validation, filters block, old databases ─────────────────────────────

    public function test_unknown_values_for_the_new_parameters_are_refused(): void
    {
        $h = $this->h();

        foreach (['freshness=yesterday', 'freshness=last_7_days', 'date_status=stale', 'date_status=Undated', 'product_master_id=abc', 'product_master_id=0', 'product_master_id=-3', 'product_master_id=1.5', 'sort=oldest'] as $query) {
            $this->getJson('/api/signals/opportunities?' . $query, $h)->assertStatus(422)->assertJsonPath('status', 0)->assertJsonPath('message', 'Validation failed');
        }
        foreach (['freshness=last_30_days', 'freshness=last_90_days', 'freshness=older', 'freshness=undated', 'date_status=event_date_stated', 'date_status=source_date_only', 'date_status=undated', 'sort=recent', 'product_master_id=7'] as $query) {
            $this->getJson('/api/signals/opportunities?' . $query, $h)->assertOk();
        }
        // An empty value is "not sent".
        $this->getJson('/api/signals/opportunities?freshness=&date_status=&product_master_id=&sort=', $h)->assertOk();
    }

    public function test_the_filters_block_lists_the_review_reasons_and_date_statuses(): void
    {
        $filters = $this->getJson('/api/signals/opportunities', $this->h())->assertOk()->json('filters');

        $reasons = config('signals.review.reasons');
        $this->assertSame(array_map(fn ($code, $label) => ['value' => $code, 'label' => $label], array_keys($reasons), array_values($reasons)), $filters['review_reasons']);
        $this->assertContains('not_a_buyer', array_column($filters['review_reasons'], 'value'));
        $this->assertSame(['event_date_stated', 'source_date_only', 'undated'], array_column($filters['date_statuses'], 'value'));
        foreach ($filters['date_statuses'] as $status) {
            $this->assertNotSame('', $status['label']);
        }
    }

    public function test_the_summary_follows_the_date_and_product_facets(): void
    {
        $this->seedDates();

        $r = $this->getJson('/api/signals/opportunities?freshness=undated&per_page=2', $this->h())->assertOk();
        $this->assertSame(4, $r->json('meta.total'));
        $this->assertSame(4, $r->json('summary.total'));
        $this->assertSame(4, $r->json('summary.new'));
    }

    public function test_a_database_without_the_new_columns_still_answers_and_refuses_only_the_product_facet(): void
    {
        $this->seedDates();
        (require base_path('database/migrations/2026_12_08_090100_add_review_and_product_columns_to_g2g_company_opportunities.php'))->down();
        $this->assertFalse(Schema::hasColumn('g2g_company_opportunities', 'product_master_id'));
        $this->assertFalse(Schema::hasColumn('g2g_company_opportunities', 'date_status'));
        $h = $this->h();

        $this->getJson('/api/signals/opportunities?product_master_id=5', $h)->assertStatus(422)->assertJsonPath('status', 0)->assertJsonPath('code', 'product_filter_unavailable');

        $ok = $this->getJson('/api/signals/opportunities?per_page=50&freshness=last_30_days&sort=recent', $h)->assertOk();
        $this->assertSame(['future_event', 'ev_today', 'tie_b', 'tie_a', 'stored_lies', 'zero_event', 'src_recent', 'ev_d30'], collect($ok->json('data'))->pluck('title')->all());
        $first = $ok->json('data.0');
        $this->assertSame(['status' => 'event_date_stated', 'date' => '2027-01-15'], $first['date_info']);
        $this->assertNull($first['product_master_id']);
        $this->assertNull($first['product_master_name']);
        $this->assertSameSet(['both_null', 'u_b', 'u_c', 'u_d'], $this->titles('date_status=undated'));
    }
}
