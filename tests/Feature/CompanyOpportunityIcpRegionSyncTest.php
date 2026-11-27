<?php

namespace Tests\Feature;

use App\Domain\Portfolio\ProductOffer;
use App\Domain\Signals\Icp\IcpProfile;
use App\Domain\Signals\Icp\IcpProfileVersion;
use App\Domain\Signals\Opportunities\ProductProfile;
use App\Domain\Signals\Opportunities\ResearchRun;
use App\Domain\Signals\Products\ProductCatalog;
use App\Domain\Signals\Products\RegionSyncRejected;
use App\Domain\Signals\Products\SubProductIcp;
use App\Domain\Signals\Products\SubProductIcpRegionSync;
use App\Domain\Signals\Support\RegionScope;
use App\Models\auth\tbluserModel;
use App\Support\QueueWorkerLauncher;
use App\Support\RoleKey;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Company Opportunities: the Region chosen with a SPECIFIC sub-product and customer type is saved to that sub-product's ICP for that customer type
 * (created if it does not exist); Whole Product and All Regions never touch an ICP.
 *
 * Everything runs through the real POST /api/signals/research/run endpoint on in-memory sqlite with a fake queue and a fake worker launcher: no
 * provider is called and no process is started. It proves the backend rules and their transaction behaviour, not a live database or live traffic.
 *
 * The application's model of "the matching ICP" is organisation + product + sub-product (portfolio offer) + customer model; the product itself has
 * no ICP of its own (see SubProductIcpRegionSync).
 */
class CompanyOpportunityIcpRegionSyncTest extends TestCase
{
    private const T = 1;
    private const T2 = 2;

    protected function setUp(): void
    {
        parent::setUp();
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
            '2026_10_08_100000_extend_g2g_signals_for_market_import', '2026_10_08_120000_add_readiness_confirmation_to_g2g_product_offers',
            '2026_11_29_090000_add_assessment_to_g2g_company_opportunities', '2026_12_01_090000_add_product_key_to_signals_runs_and_sources',
            '2026_10_31_090000_add_research_run_configuration_scope', '2026_10_14_100000_create_g2g_icp_profiles_tables',
            '2026_11_29_100000_add_date_provenance_to_g2g_research_sources', '2026_11_29_110000_add_modified_date_to_g2g_research_sources',
            '2026_10_13_100000_create_g2g_target_accounts_table', '2026_10_27_200000_add_icp_config_to_g2g_product_offers_table',
        ] as $migration) {
            (require base_path("database/migrations/{$migration}.php"))->up();
        }

        RoleKey::flushCache();
        Cache::flush();
        Storage::fake('local');
        Carbon::setTestNow('2026-10-20 10:00:00');
        config([
            'ai.provider.gemini.api_key' => 'test-key', 'signals.ai_attempts' => 2,
            'signals.web_search.driver' => 'tavily', 'signals.web_search.tavily_key' => 'tvly-test-key',
            'signals.web_search.min_interval_ms' => 0, 'signals.icp.scholar_k12_tenants' => [self::T, self::T2],
            'signals.queue_pickup_seconds' => 180, 'signals.stale_run_minutes' => 15,
        ]);
        DB::table('tbluserprofilemaster')->insert([['id' => 1, 'name' => 'Administrator', 'role_key' => 'administrator']]);
        foreach ([self::T, self::T2] as $tenant) {
            ProductProfile::create([
                'sub_institute_id' => $tenant, 'product_name' => 'ScholarClone K-12 Bundle', 'description' => 'School ERP and LMS.',
                'problems_solved' => 'Disconnected school systems', 'features' => 'ERP, LMS', 'target_industries' => ['Education'],
                'target_markets' => ['Gujarat'], 'keywords' => ['school erp'], 'excluded' => [], 'research_enabled' => true, 'research_frequency' => 'daily', 'recency_days' => 30,
            ]);
        }
    }

    protected function tearDown(): void
    {
        QueueWorkerLauncher::fake(null);
        ResearchRun::flushEventListeners();
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function h(int $tenant = self::T): array
    {
        $id = DB::table('tbluser')->insertGetId(['user_name' => 'u' . uniqid(), 'sub_institute_id' => $tenant, 'user_profile_id' => 1]);

        return ['Authorization' => 'Bearer ' . tbluserModel::find($id)->createToken('t')->plainTextToken];
    }

    private function offer(string $id, string $name = 'Fee Suite', int|null $tenant = self::T, string $parent = 'Scholar K-12', array $o = []): ProductOffer
    {
        return ProductOffer::create($o + [
            'sub_institute_id' => $tenant, 'offer_id' => $id, 'name' => $name, 'parent_product' => $parent, 'readiness_status' => 'In development',
            'what_it_does' => 'Online fee collection, receipts and parent reminders for schools.', 'modules_components' => 'Fee engine; receipts',
            'needs_solved' => ['N13'], 'primary_segments' => ['K12-PVT'], 'trigger_signals' => ['expansion'], 'is_user_edited' => true,
        ]);
    }

    /** Makes the next Generate Signals request possible: the previous run is over, the cool-down has passed. */
    private function previousRunIsOver(): void
    {
        DB::table('g2g_research_runs')->update(['status' => 'success', 'started_at' => now()->subDay()]);
        Cache::flush();
    }

    private function generate(array $body, int $tenant = self::T)
    {
        return $this->postJson('/api/signals/research/run', $body + ['product' => 'scholarclone'], $this->h($tenant));
    }

    private function place(string $country, ?string $state = null): array
    {
        return array_filter(['country' => $country, 'state' => $state]);
    }

    private function icps(int $tenant = self::T)
    {
        return IcpProfile::where('sub_institute_id', $tenant)->orderBy('id')->get();
    }

    private function icp(string $offerId, string $model, int $tenant = self::T): ?IcpProfile
    {
        return IcpProfile::where('sub_institute_id', $tenant)->where('key', SubProductIcp::key($offerId, $model))->first();
    }

    // ── specific product: created or updated, per customer type ──────────────

    public function test_a_specific_product_with_b2b_and_india_creates_the_matching_b2b_icp_with_india(): void
    {
        $this->offer('FEE-1');

        $res = $this->generate(['offer_id' => 'FEE-1', 'customer_model' => 'b2b', 'regions' => [$this->place('India')]])->assertStatus(202);

        $res->assertJsonPath('data.icp_sync.status', 'created');
        $this->assertStringContainsString('India', (string) $res->json('data.icp_sync.message'));
        $icp = $this->icp('FEE-1', 'b2b');
        $this->assertNotNull($icp);
        $this->assertSame(['India'], $icp->config['regions']);
        $this->assertSame('b2b', $icp->config['customer_model']);
        $this->assertSame(['FEE-1'], $icp->config['offer_ids']);
        $this->assertSame([], app(\App\Domain\Signals\Icp\IcpProfileValidator::class)->validate($icp->config), 'a valid ICP, not a placeholder');
        $this->assertSame(['India'], SubProductIcp::configFor(self::T, ProductOffer::where('offer_id', 'FEE-1')->first(), 'b2b')['regions'], "the offer's own map agrees");
        $this->assertSame(1, IcpProfileVersion::where('icp_profile_id', $icp->id)->count());
        $this->assertSame(1, ResearchRun::count(), 'and the run was created as before');
        $this->assertSame('b2b', ResearchRun::first()->customer_model);
        $this->assertSame('FEE-1', ResearchRun::first()->offer_id);
    }

    public function test_b2c_with_usa_and_b2g_with_uk_each_create_only_their_own_icp(): void
    {
        $this->offer('FEE-1');

        $this->generate(['offer_id' => 'FEE-1', 'customer_model' => 'b2c', 'regions' => [$this->place('United States')]])->assertStatus(202)->assertJsonPath('data.icp_sync.status', 'created');
        $this->previousRunIsOver();
        $this->generate(['offer_id' => 'FEE-1', 'customer_model' => 'b2g', 'regions' => [$this->place('United Kingdom')]])->assertStatus(202)->assertJsonPath('data.icp_sync.status', 'created');

        $this->assertSame(['United States'], $this->icp('FEE-1', 'b2c')->config['regions']);
        $this->assertSame(['United Kingdom'], $this->icp('FEE-1', 'b2g')->config['regions']);
        $this->assertNull($this->icp('FEE-1', 'b2b'), 'B2B was never asked for');
        $this->assertSame(2, $this->icps()->count());
        $this->assertSame('b2c', $this->icp('FEE-1', 'b2c')->config['customer_model']);
        $this->assertSame('b2g', $this->icp('FEE-1', 'b2g')->config['customer_model']);
    }

    public function test_a_changed_region_updates_the_same_icp_and_keeps_everything_else(): void
    {
        $offer = $this->offer('FEE-1');
        $this->generate(['offer_id' => 'FEE-1', 'customer_model' => 'b2b', 'regions' => [$this->place('India')]])->assertStatus(202);
        $first = $this->icp('FEE-1', 'b2b');
        // a person later edits the ICP (a new pitch): the region sync must not undo it
        $edited = $first->config;
        $edited['pitch'] = 'A pitch a person wrote';
        $first->update(['config' => $edited, 'version' => 2]);
        $offerRow = ProductOffer::where('offer_id', 'FEE-1')->first();
        $map = SubProductIcp::normalizeStoredConfig($offerRow->icp_config);
        $map['b2b'] = $edited;
        $offerRow->icp_config = $map;
        $offerRow->save();
        $this->previousRunIsOver();

        $res = $this->generate(['offer_id' => 'FEE-1', 'customer_model' => 'b2b', 'regions' => [$this->place('United States')]])->assertStatus(202);

        $res->assertJsonPath('data.icp_sync.status', 'updated');
        $this->assertSame(1, $this->icps()->count(), 'no second ICP');
        $second = $this->icp('FEE-1', 'b2b');
        $this->assertSame($first->id, $second->id, 'the same ICP');
        $this->assertSame(['United States'], $second->config['regions']);
        $this->assertSame('A pitch a person wrote', $second->config['pitch'], 'other fields kept');
        $this->assertSame($edited['triggers'], $second->config['triggers']);
        $this->assertSame(3, (int) $second->version, 'a new version');
        $this->assertSame('Region set from Market Signals: United States', IcpProfileVersion::where('icp_profile_id', $second->id)->orderByDesc('version')->first()->note, 'with history');
        $this->assertSame(['United States'], SubProductIcp::configFor(self::T, ProductOffer::where('offer_id', 'FEE-1')->first(), 'b2b')['regions']);
        $this->assertSame($second->status, $first->status);
    }

    public function test_the_updated_region_is_what_the_next_generation_works_from(): void
    {
        $this->offer('FEE-1');
        $this->generate(['offer_id' => 'FEE-1', 'customer_model' => 'b2b', 'regions' => [$this->place('India', 'Gujarat')]])->assertStatus(202);

        $context = (new ProductCatalog())->resolveSub(self::T, 'scholarclone', 'FEE-1', 'b2b');

        $this->assertSame(['Gujarat'], $context->pack->config()['regions'], 'the most specific place of the selection, the way a run names it');
    }

    public function test_repeating_the_same_request_changes_nothing(): void
    {
        $this->offer('FEE-1');
        $body = ['offer_id' => 'FEE-1', 'customer_model' => 'b2b', 'regions' => [$this->place('India')]];
        $this->generate($body)->assertStatus(202)->assertJsonPath('data.icp_sync.status', 'created');
        $icp = $this->icp('FEE-1', 'b2b');
        $before = [$icp->version, IcpProfileVersion::count(), $icp->updated_at?->toIso8601String()];
        $this->previousRunIsOver();

        $this->generate($body)->assertStatus(202)->assertJsonPath('data.icp_sync.status', 'unchanged')->assertJsonPath('data.icp_sync.message', null);
        $this->previousRunIsOver();
        $this->generate($body)->assertStatus(202)->assertJsonPath('data.icp_sync.status', 'unchanged');

        $this->assertSame(1, $this->icps()->count());
        $icp->refresh();
        $this->assertSame($before, [$icp->version, IcpProfileVersion::count(), $icp->updated_at?->toIso8601String()]);
    }

    public function test_a_second_state_of_the_same_places_in_another_order_or_case_is_the_same_region(): void
    {
        $this->offer('FEE-1');
        $this->generate(['offer_id' => 'FEE-1', 'customer_model' => 'b2b', 'regions' => [$this->place('India'), $this->place('United States')]])->assertStatus(202);
        $this->previousRunIsOver();

        $this->generate(['offer_id' => 'FEE-1', 'customer_model' => 'b2b', 'regions' => [$this->place('united states'), $this->place('INDIA')]])
            ->assertStatus(202)->assertJsonPath('data.icp_sync.status', 'unchanged');
    }

    // ── nothing unrelated is touched ─────────────────────────────────────────

    public function test_icps_of_another_sub_product_another_customer_type_and_another_organisation_are_never_changed(): void
    {
        $this->offer('FEE-1');
        $this->offer('LMS-1', 'LMS Suite');
        $this->offer('FEE-1', 'Fee Suite (other organisation)', self::T2);
        // existing ICPs for each neighbour, all with Gujarat
        foreach ([['FEE-1', 'b2c', self::T], ['LMS-1', 'b2b', self::T], ['FEE-1', 'b2b', self::T2]] as [$offerId, $model, $tenant]) {
            $this->previousRunIsOver();
            $this->generate(['offer_id' => $offerId, 'customer_model' => $model, 'regions' => [$this->place('India', 'Gujarat')]], $tenant)->assertStatus(202);
        }
        $snapshot = fn () => IcpProfile::orderBy('id')->get(['id', 'sub_institute_id', 'key', 'version', 'config'])->map(fn ($p) => [$p->id, $p->sub_institute_id, $p->key, $p->version, json_encode($p->config)])->all();
        $this->assertCount(3, $snapshot());
        $before = $snapshot();
        $this->previousRunIsOver();

        // the request: tenant 1, FEE-1, B2B, USA: the B2B ICP of FEE-1 does not exist yet, so it is created; the three neighbours stay as they were
        $this->generate(['offer_id' => 'FEE-1', 'customer_model' => 'b2b', 'regions' => [$this->place('United States')]])->assertStatus(202)->assertJsonPath('data.icp_sync.status', 'created');

        $after = $snapshot();
        $this->assertSame($before, array_slice($after, 0, 3), 'every neighbour is byte-for-byte unchanged');
        $this->assertCount(4, $after);
        $this->assertSame(['United States'], $this->icp('FEE-1', 'b2b', self::T)->config['regions']);
        $this->assertSame(['Gujarat'], $this->icp('FEE-1', 'b2b', self::T2)->config['regions'], "the other organisation's ICP for the same offer id");
        $this->assertSame(['Gujarat'], $this->icp('FEE-1', 'b2c')->config['regions'], 'the same sub-product, another customer type');
        $this->assertSame(['Gujarat'], $this->icp('LMS-1', 'b2b')->config['regions'], 'another sub-product');
    }

    public function test_switching_product_or_customer_type_affects_only_the_one_now_chosen(): void
    {
        $this->offer('FEE-1');
        $this->offer('LMS-1', 'LMS Suite');
        $this->generate(['offer_id' => 'FEE-1', 'customer_model' => 'b2b', 'regions' => [$this->place('India')]])->assertStatus(202);
        $this->previousRunIsOver();

        // A to B
        $this->generate(['offer_id' => 'LMS-1', 'customer_model' => 'b2b', 'regions' => [$this->place('United States')]])->assertStatus(202);
        $this->assertSame(['India'], $this->icp('FEE-1', 'b2b')->config['regions'], 'A is as it was');
        $this->assertSame(['United States'], $this->icp('LMS-1', 'b2b')->config['regions']);
        $this->previousRunIsOver();

        // B2B to B2C on the same sub-product
        $this->generate(['offer_id' => 'LMS-1', 'customer_model' => 'b2c', 'regions' => [$this->place('United Kingdom')]])->assertStatus(202);
        $this->assertSame(['United States'], $this->icp('LMS-1', 'b2b')->config['regions'], 'B2B is as it was');
        $this->assertSame(['United Kingdom'], $this->icp('LMS-1', 'b2c')->config['regions']);
    }

    // ── Whole Product and All Regions never touch an ICP ─────────────────────

    public function test_whole_product_never_creates_or_changes_an_icp_for_any_customer_type(): void
    {
        $this->offer('FEE-1');
        $this->generate(['offer_id' => 'FEE-1', 'customer_model' => 'b2b', 'regions' => [$this->place('India')]])->assertStatus(202);
        $before = IcpProfile::orderBy('id')->get()->map(fn ($p) => [$p->id, $p->version, json_encode($p->config)])->all();
        $offerMap = ProductOffer::where('offer_id', 'FEE-1')->value('icp_config');

        foreach (['b2b' => 'India', 'b2c' => 'United States', 'b2g' => 'United Kingdom'] as $model => $country) {
            $this->previousRunIsOver();
            $res = $this->generate(['customer_model' => $model, 'regions' => [$this->place($country)]])->assertStatus(202);
            $res->assertJsonPath('data.icp_sync.status', 'skipped')->assertJsonPath('data.icp_sync.reason', 'whole_product')->assertJsonPath('data.icp_sync.message', null);
            $this->assertNull($res->json('data.icp_sync.icp'));
            $this->assertNull(ResearchRun::latest('id')->first()->offer_id, 'the run itself still uses the Region');
            $this->assertStringContainsString($country, (string) ResearchRun::latest('id')->first()->region_scope, 'for the opportunity generation only');
        }

        $this->assertSame($before, IcpProfile::orderBy('id')->get()->map(fn ($p) => [$p->id, $p->version, json_encode($p->config)])->all(), 'no ICP created or changed');
        $this->assertSame(1, $this->icps()->count());
        $this->assertEquals($offerMap, ProductOffer::where('offer_id', 'FEE-1')->value('icp_config'));
        $this->assertSame(1, IcpProfileVersion::count());
    }

    public function test_whole_product_with_no_icps_at_all_creates_none(): void
    {
        foreach (['b2b', 'b2c', 'b2g'] as $model) {
            $this->previousRunIsOver();
            $this->generate(['customer_model' => $model, 'regions' => [$this->place('India')]])->assertStatus(202)->assertJsonPath('data.icp_sync.reason', 'whole_product');
        }

        $this->assertSame(0, IcpProfile::count());
        $this->assertSame(0, IcpProfileVersion::count());
    }

    public function test_switching_from_a_specific_product_to_whole_product_changes_no_further_icp(): void
    {
        $this->offer('FEE-1');
        $this->generate(['offer_id' => 'FEE-1', 'customer_model' => 'b2b', 'regions' => [$this->place('India')]])->assertStatus(202);
        $this->previousRunIsOver();

        $this->generate(['customer_model' => 'b2b', 'regions' => [$this->place('United States')]])->assertStatus(202)->assertJsonPath('data.icp_sync.status', 'skipped');

        $this->assertSame(['India'], $this->icp('FEE-1', 'b2b')->config['regions'], 'the specific product keeps what it had');
        $this->assertSame(1, (int) $this->icp('FEE-1', 'b2b')->version);
    }

    public function test_all_regions_with_a_specific_product_synchronises_nothing(): void
    {
        $this->offer('FEE-1');
        $this->generate(['offer_id' => 'FEE-1', 'customer_model' => 'b2b', 'regions' => [$this->place('India')]])->assertStatus(202);
        $this->previousRunIsOver();

        $this->generate(['offer_id' => 'FEE-1', 'customer_model' => 'b2b'])->assertStatus(202)
            ->assertJsonPath('data.icp_sync.status', 'skipped')->assertJsonPath('data.icp_sync.reason', 'all_regions');

        $this->assertSame(['India'], $this->icp('FEE-1', 'b2b')->config['regions']);
    }

    public function test_an_offer_of_no_organisation_is_never_found_and_so_never_written(): void
    {
        $this->offer('SHARED-1', 'Shared Product', null);

        // CHANGED ON PURPOSE (S15 follow-up, 24 Nov 2026): an About with no organisation is nobody's, so the run does not even find it (it used to be found and skipped as `shared_sub_product`).
        $this->generate(['offer_id' => 'SHARED-1', 'customer_model' => 'b2b', 'regions' => [$this->place('India')]])->assertStatus(422);

        $this->assertSame(0, IcpProfile::count());
        $this->assertNull(ProductOffer::where('offer_id', 'SHARED-1')->value('icp_config'), 'a shared offer\'s map would be shared by every organisation');
    }

    // ── rejections write nothing ─────────────────────────────────────────────

    public function test_an_invalid_region_is_rejected_before_anything_is_saved(): void
    {
        $this->offer('FEE-1');

        $this->generate(['offer_id' => 'FEE-1', 'customer_model' => 'b2b', 'regions' => [['country' => '<script>']]])
            ->assertStatus(422)->assertJsonPath('code', 'invalid_region');
        $this->generate(['offer_id' => 'FEE-1', 'customer_model' => 'b2b', 'regions' => [['state' => 'Gujarat']]])->assertStatus(422)->assertJsonPath('code', 'invalid_region');

        $this->assertSame(0, IcpProfile::count());
        $this->assertSame(0, ResearchRun::count());
        $this->assertNull(ProductOffer::where('offer_id', 'FEE-1')->value('icp_config'));
    }

    public function test_a_sub_product_of_another_product_is_rejected(): void
    {
        $this->offer('EB-9', 'Brain Thing', self::T, 'EB');

        $this->generate(['offer_id' => 'EB-9', 'customer_model' => 'b2b', 'regions' => [$this->place('India')]])->assertStatus(422)->assertJsonPath('code', 'unknown_sub_product');
        $this->generate(['offer_id' => 'NOPE-1', 'customer_model' => 'b2b', 'regions' => [$this->place('India')]])->assertStatus(422)->assertJsonPath('code', 'unknown_sub_product');

        $this->assertSame(0, IcpProfile::count());
        $this->assertNull(ProductOffer::where('offer_id', 'EB-9')->value('icp_config'));
    }

    public function test_another_organisation_cannot_synchronise_an_icp_it_does_not_own(): void
    {
        $this->offer('FEE-1');   // tenant 1 only
        $this->generate(['offer_id' => 'FEE-1', 'customer_model' => 'b2b', 'regions' => [$this->place('India')]])->assertStatus(202);
        $this->previousRunIsOver();
        $before = IcpProfile::orderBy('id')->get()->map(fn ($p) => [$p->id, $p->version, json_encode($p->config)])->all();

        $this->generate(['offer_id' => 'FEE-1', 'customer_model' => 'b2b', 'regions' => [$this->place('United States')]], self::T2)
            ->assertStatus(422)->assertJsonPath('code', 'unknown_sub_product');

        $this->assertSame($before, IcpProfile::orderBy('id')->get()->map(fn ($p) => [$p->id, $p->version, json_encode($p->config)])->all());
        $this->assertSame(0, IcpProfile::where('sub_institute_id', self::T2)->count());
    }

    public function test_an_icp_that_cannot_be_created_validly_fails_clearly_and_leaves_no_broken_icp_and_no_run(): void
    {
        // The ICP's label is "<name> B2B ICP" and an ICP label may be 100 characters: this offer cannot get a valid one.
        $this->offer('LONG-1', str_repeat('Very long product name ', 5));

        $res = $this->generate(['offer_id' => 'LONG-1', 'customer_model' => 'b2b', 'regions' => [$this->place('India')]])->assertStatus(422);

        $res->assertJsonPath('code', 'icp_region_sync_failed')->assertJsonPath('reason', 'icp_incomplete');
        $this->assertStringContainsString('Nothing was changed', (string) $res->json('message'));
        $this->assertStringContainsString('label', implode(' ', $res->json('problems')));
        $this->assertSame(0, IcpProfile::count());
        $this->assertSame(0, ResearchRun::count());
        $this->assertNull(ProductOffer::where('offer_id', 'LONG-1')->value('icp_config'));
        // and the next click is not told "already running"
        $this->offer('FEE-1');
        $this->generate(['offer_id' => 'FEE-1', 'customer_model' => 'b2b', 'regions' => [$this->place('India')]])->assertStatus(202);
    }

    public function test_an_older_icp_with_problems_of_its_own_still_gets_its_region_and_gains_no_new_problem(): void
    {
        $this->offer('FEE-1');
        $this->generate(['offer_id' => 'FEE-1', 'customer_model' => 'b2b', 'regions' => [$this->place('India')]])->assertStatus(202);
        $icp = $this->icp('FEE-1', 'b2b');
        $legacy = $icp->config;
        $legacy['legacy_flag'] = true;   // an unknown setting: the validator already complains about it
        $icp->update(['config' => $legacy]);
        $offerRow = ProductOffer::where('offer_id', 'FEE-1')->first();
        $map = SubProductIcp::normalizeStoredConfig($offerRow->icp_config);
        $map['b2b'] = $legacy;   // the offer's own copy is what the application reads first, so it carries the same older ICP
        $offerRow->icp_config = $map;
        $offerRow->save();
        $this->previousRunIsOver();

        $this->generate(['offer_id' => 'FEE-1', 'customer_model' => 'b2b', 'regions' => [$this->place('United States')]])->assertStatus(202)->assertJsonPath('data.icp_sync.status', 'updated');

        $this->assertSame(['United States'], $this->icp('FEE-1', 'b2b')->config['regions']);
        $this->assertTrue($this->icp('FEE-1', 'b2b')->config['legacy_flag'], 'left exactly as it was');
    }

    public function test_a_failure_while_saving_the_run_rolls_the_icp_change_back(): void
    {
        $this->offer('FEE-1');
        $this->generate(['offer_id' => 'FEE-1', 'customer_model' => 'b2b', 'regions' => [$this->place('India')]])->assertStatus(202);
        $this->previousRunIsOver();
        $before = [$this->icp('FEE-1', 'b2b')->version, json_encode($this->icp('FEE-1', 'b2b')->config), IcpProfileVersion::count(), json_encode(ProductOffer::where('offer_id', 'FEE-1')->value('icp_config'))];
        $runsBefore = ResearchRun::count();
        ResearchRun::creating(function () {
            throw new \RuntimeException('the run could not be saved');
        });

        $this->generate(['offer_id' => 'FEE-1', 'customer_model' => 'b2b', 'regions' => [$this->place('United States')]])->assertStatus(500);

        $this->assertSame($before, [$this->icp('FEE-1', 'b2b')->version, json_encode($this->icp('FEE-1', 'b2b')->config), IcpProfileVersion::count(), json_encode(ProductOffer::where('offer_id', 'FEE-1')->value('icp_config'))], 'the Region is still India everywhere');
        $this->assertSame($runsBefore, ResearchRun::count());
        ResearchRun::flushEventListeners();
        // and nothing is left locked: the next request is accepted
        $this->generate(['offer_id' => 'FEE-1', 'customer_model' => 'b2b', 'regions' => [$this->place('United States')]])->assertStatus(202)->assertJsonPath('data.icp_sync.status', 'updated');
    }

    public function test_a_failure_while_creating_the_run_leaves_no_icp_behind(): void
    {
        $this->offer('FEE-1');
        ResearchRun::creating(function () {
            throw new \RuntimeException('the run could not be saved');
        });

        $this->generate(['offer_id' => 'FEE-1', 'customer_model' => 'b2b', 'regions' => [$this->place('India')]])->assertStatus(500);

        $this->assertSame(0, IcpProfile::count());
        $this->assertSame(0, IcpProfileVersion::count());
        $this->assertNull(ProductOffer::where('offer_id', 'FEE-1')->value('icp_config'));
    }

    public function test_requests_that_are_refused_before_the_run_starts_do_not_touch_the_icp(): void
    {
        $this->offer('FEE-1');
        $this->generate(['offer_id' => 'FEE-1', 'customer_model' => 'b2b', 'regions' => [$this->place('India')]])->assertStatus(202);   // leaves a run "running"

        // already running: 409, before the sync
        $this->generate(['offer_id' => 'FEE-1', 'customer_model' => 'b2b', 'regions' => [$this->place('United States')]])->assertStatus(409);
        $this->assertSame(['India'], $this->icp('FEE-1', 'b2b')->config['regions'], 'refused before any sync');

        // cool-down (same scope as the run a minute ago): 429, before the sync. The ICP was changed by hand meanwhile, so a sync would show.
        DB::table('g2g_research_runs')->update(['status' => 'success', 'started_at' => now()->subMinute()]);
        Cache::flush();
        $icp = $this->icp('FEE-1', 'b2b');
        $moved = $icp->config;
        $moved['regions'] = ['Gujarat'];
        $icp->update(['config' => $moved]);
        $offerRow = ProductOffer::where('offer_id', 'FEE-1')->first();
        $map = SubProductIcp::normalizeStoredConfig($offerRow->icp_config);
        $map['b2b'] = $moved;
        $offerRow->icp_config = $map;
        $offerRow->save();
        $this->generate(['offer_id' => 'FEE-1', 'customer_model' => 'b2b', 'regions' => [$this->place('India')]])->assertStatus(429);
        $this->assertSame(['Gujarat'], $this->icp('FEE-1', 'b2b')->config['regions'], 'refused before any sync');

        // no search provider: 422, before the sync
        $this->previousRunIsOver();
        config(['signals.web_search.driver' => 'none']);
        $this->generate(['offer_id' => 'FEE-1', 'customer_model' => 'b2b', 'regions' => [$this->place('United States')]])->assertStatus(422);
        $this->assertSame(['Gujarat'], $this->icp('FEE-1', 'b2b')->config['regions']);
    }

    // ── the service itself ───────────────────────────────────────────────────

    public function test_the_service_runs_no_icp_query_at_all_for_whole_product(): void
    {
        DB::enableQueryLog();

        $result = app(SubProductIcpRegionSync::class)->sync(self::T, 1, 'scholarclone', null, 'b2b', RegionScope::fromInput([$this->place('India')]));

        $this->assertSame(['status' => 'skipped', 'reason' => 'whole_product', 'icp' => null, 'message' => null], $result);
        $this->assertSame([], DB::getQueryLog(), 'not even a read');
        DB::disableQueryLog();
    }

    public function test_the_service_rejects_an_invalid_customer_model_and_a_foreign_sub_product(): void
    {
        $this->offer('FEE-1');
        $service = app(SubProductIcpRegionSync::class);
        $region = RegionScope::fromInput([$this->place('India')]);

        foreach ([['FEE-1', 'b2x', 'invalid_customer_model'], ['NOPE', 'b2b', 'unknown_sub_product']] as [$offerId, $model, $reason]) {
            try {
                $service->sync(self::T, 1, 'scholarclone', $offerId, $model, $region);
                $this->fail('expected a rejection');
            } catch (RegionSyncRejected $e) {
                $this->assertSame($reason, $e->codeName);
            }
        }
        $this->assertSame(0, IcpProfile::count());
    }

    public function test_two_requests_for_the_same_new_icp_end_with_one_icp(): void
    {
        $this->offer('FEE-1');
        $service = app(SubProductIcpRegionSync::class);
        $region = RegionScope::fromInput([$this->place('India')]);

        $first = $service->sync(self::T, 1, 'scholarclone', 'FEE-1', 'b2b', $region);
        // a second writer that read "no ICP yet" before the first finished: its insert hits the unique key and is retried as an update/no-op
        $offer = ProductOffer::where('offer_id', 'FEE-1')->first();
        $offer->icp_config = null;
        $offer->saveQuietly();
        $second = $service->sync(self::T, 1, 'scholarclone', 'FEE-1', 'b2b', $region);

        $this->assertSame('created', $first['status']);
        $this->assertContains($second['status'], ['unchanged', 'updated']);
        $this->assertSame(1, $this->icps()->count(), 'never two ICPs for one sub-product and customer type');
    }

    // ── Country > State > City: the location API ─────────────────────────────

    public function test_the_countries_list_is_complete_searchable_and_needs_a_signed_in_user(): void
    {
        $res = $this->getJson('/api/signals/locations/countries', $this->h())->assertOk();

        $names = array_column($res->json('data.countries'), 'name');
        $this->assertGreaterThanOrEqual(240, count($names));
        foreach (['India', 'Singapore', 'United States', 'Japan', 'Brazil'] as $n) {
            $this->assertContains($n, $names, $n);
        }
        $this->assertSame('IN', $res->json('data.default_country_code'));
        $this->assertNotEmpty($res->json('data.data_version'));

        $found = $this->getJson('/api/signals/locations/countries?q=SINGA', $this->h())->assertOk()->json('data.countries');
        $this->assertSame(['Singapore'], array_column($found, 'name'), 'case-insensitive');
        $this->getJson('/api/signals/locations/countries')->assertStatus(401);
    }

    public function test_states_come_only_from_the_data_with_the_right_label_and_an_honest_answer_for_a_country_without_any(): void
    {
        $india = $this->getJson('/api/signals/locations/states?country=IN', $this->h())->assertOk();
        $this->assertContains('Gujarat', array_column($india->json('data.states'), 'name'));
        $this->assertSame('GJ', collect($india->json('data.states'))->firstWhere('name', 'Gujarat')['code']);
        $this->assertSame('State', $india->json('data.label'));

        $sg = $this->getJson('/api/signals/locations/states?country=Singapore', $this->h())->assertOk();
        $this->assertSame(['Central Singapore', 'North East', 'North West', 'South East', 'South West'], array_column($sg->json('data.states'), 'name'));
        $this->assertSame('District', $sg->json('data.label'));

        $none = $this->getJson('/api/signals/locations/states?country=Antarctica', $this->h())->assertOk();
        $this->assertSame([], $none->json('data.states'));
        $this->assertFalse($none->json('data.has_states'));
        $this->assertStringContainsString('no states or provinces', (string) $none->json('data.message'));

        $this->getJson('/api/signals/locations/states?country=Atlantis', $this->h())->assertStatus(422)->assertJsonPath('code', 'unknown_country');
        $this->assertSame(['Gujarat'], array_column($this->getJson('/api/signals/locations/states?country=IN&q=guj', $this->h())->json('data.states'), 'name'));
    }

    public function test_cities_are_the_chosen_states_only_searched_on_the_server_and_bounded(): void
    {
        $gj = $this->getJson('/api/signals/locations/cities?country=IN&state=GJ&q=sur&limit=5', $this->h())->assertOk();
        $this->assertSame('Surat', $gj->json('data.cities.0.name'));
        $this->assertLessThanOrEqual(5, count($gj->json('data.cities')));
        $this->assertIsInt($gj->json('data.cities.0.id'));

        $mh = $this->getJson('/api/signals/locations/cities?country=IN&state=Maharashtra&q=surat', $this->h())->assertOk();
        $this->assertNotContains('Surat', array_column($mh->json('data.cities'), 'name'), "Surat is not in Maharashtra");

        $page = $this->getJson('/api/signals/locations/cities?country=US&state=CA&limit=10', $this->h())->assertOk();
        $this->assertCount(10, $page->json('data.cities'));
        $this->assertTrue($page->json('data.truncated'), 'never the whole state at once');

        $this->getJson('/api/signals/locations/cities?country=IN', $this->h())->assertStatus(422)->assertJsonPath('code', 'state_required');
        $this->getJson('/api/signals/locations/cities?country=IN&state=Texas', $this->h())->assertStatus(422)->assertJsonPath('code', 'unknown_state');
        $this->getJson('/api/signals/locations/cities?country=Atlantis&state=X', $this->h())->assertStatus(422)->assertJsonPath('code', 'unknown_country');
    }

    public function test_validating_a_place_returns_it_with_codes_and_takes_typed_state_and_city_as_manual_text(): void
    {
        $ok = $this->postJson('/api/signals/locations/validate', ['country' => 'India', 'state' => 'Gujarat', 'city' => 'Surat'], $this->h())->assertOk();
        $this->assertSame(['IN', 'GJ', true], [$ok->json('data.country_code'), $ok->json('data.state_code'), $ok->json('data.verified')]);
        $this->assertIsInt($ok->json('data.city_id'));

        $manual = $this->postJson('/api/signals/locations/validate', ['country' => 'Singapore', 'state' => 'My Region', 'city' => 'Anywhere'], $this->h())->assertOk();
        $this->assertSame(['My Region', 'Anywhere', 'manual', false], [$manual->json('data.state'), $manual->json('data.city'), $manual->json('data.source'), $manual->json('data.verified')]);
        $this->postJson('/api/signals/locations/validate', ['country_code' => 'IN', 'state_code' => 'CA'], $this->h())->assertStatus(422)->assertJsonPath('code', 'invalid_region');
        $this->postJson('/api/signals/locations/validate', ['state' => 'Gujarat'], $this->h())->assertStatus(422);
    }

    public function test_the_older_name_only_listing_still_works_and_now_has_every_country_and_the_real_cities(): void
    {
        $countries = $this->getJson('/api/signals/locations', $this->h())->assertOk()->json('data.countries');
        $this->assertSame('India', $countries[0]);
        $this->assertContains('Singapore', $countries);
        $this->assertGreaterThanOrEqual(240, count($countries));
        $this->assertContains('Gujarat', $this->getJson('/api/signals/locations?country=India', $this->h())->json('data.states'));
        $this->assertContains('Surat', $this->getJson('/api/signals/locations?country=India&state=Gujarat', $this->h())->json('data.cities'));
    }

    // ── Country > State > City through Generate Signals and the ICP ──────────

    private function surat(): array
    {
        return [['country' => 'India', 'state' => 'Gujarat', 'city' => 'Surat']];
    }

    public function test_india_gujarat_surat_is_saved_to_the_icp_with_codes_and_the_run_keeps_its_unchanged_stored_shape(): void
    {
        $this->offer('FEE-1');

        $res = $this->generate(['offer_id' => 'FEE-1', 'customer_model' => 'b2b', 'regions' => $this->surat()])->assertStatus(202);

        $res->assertJsonPath('data.icp_sync.status', 'created');
        $icp = $this->icp('FEE-1', 'b2b');
        $this->assertSame(['Surat'], $icp->config['regions'], 'the plain name research searches with');
        $location = $icp->config['locations'][0];
        $this->assertSame(['India', 'IN', 'Gujarat', 'GJ', 'Surat', true, 'dataset'], [
            $location['country'], $location['country_code'], $location['state'], $location['state_code'], $location['city'], $location['verified'], $location['source'],
        ]);
        $this->assertIsInt($location['city_id']);
        $this->assertSame([], app(\App\Domain\Signals\Icp\IcpProfileValidator::class)->validate($icp->config));
        // what runs and opportunities are matched by is exactly what it always was: canonical names, no codes
        $this->assertSame('[{"country":"India","state":"Gujarat","city":"Surat"}]', ResearchRun::first()->region_scope);
        Queue::assertPushed(\App\Jobs\RunOpportunityResearchJob::class, fn ($job) => $job->regions === [['country' => 'India', 'state' => 'Gujarat', 'city' => 'Surat']]
            && $job->offerId === 'FEE-1' && $job->customerModel === 'b2b');   // generation receives the selected place, canonical
    }

    public function test_the_saved_location_is_what_a_page_refresh_reads_back(): void
    {
        $offer = $this->offer('FEE-1');
        $this->generate(['offer_id' => 'FEE-1', 'customer_model' => 'b2b', 'regions' => $this->surat()])->assertStatus(202);

        $read = $this->getJson("/api/portfolio/offers/{$offer->id}", $this->h())->assertOk();

        $this->assertSame(['Surat'], $read->json('data.individual_icp.regions'));
        $this->assertSame('GJ', $read->json('data.individual_icp.locations.0.state_code'));
        $this->assertSame('IN', $read->json('data.individual_icp.locations.0.country_code'));
    }

    public function test_changing_india_to_singapore_replaces_the_whole_location_with_no_leftover_state_or_city(): void
    {
        $this->offer('FEE-1');
        $this->generate(['offer_id' => 'FEE-1', 'customer_model' => 'b2b', 'regions' => $this->surat()])->assertStatus(202);
        $first = $this->icp('FEE-1', 'b2b');
        $this->previousRunIsOver();

        $this->generate(['offer_id' => 'FEE-1', 'customer_model' => 'b2b', 'regions' => [['country' => 'Singapore']]])->assertStatus(202)->assertJsonPath('data.icp_sync.status', 'updated');

        $icp = $this->icp('FEE-1', 'b2b');
        $this->assertSame($first->id, $icp->id, 'the same ICP');
        $this->assertSame(['Singapore'], $icp->config['regions']);
        $this->assertCount(1, $icp->config['locations']);
        $this->assertSame(['Singapore', 'SG'], [$icp->config['locations'][0]['country'], $icp->config['locations'][0]['country_code']]);
        $this->assertArrayNotHasKey('state', $icp->config['locations'][0]);
        $this->assertArrayNotHasKey('city', $icp->config['locations'][0]);
        $this->assertStringNotContainsString('Gujarat', json_encode($icp->config['locations']));
        $this->assertStringNotContainsString('Surat', json_encode($icp->config));
        $this->assertSame('[{"country":"Singapore","state":null,"city":null}]', ResearchRun::latest('id')->first()->region_scope);
    }

    public function test_switching_states_and_singapores_districts_work_and_each_change_is_one_update_of_the_same_icp(): void
    {
        $this->offer('FEE-1');
        foreach ([
            [['country' => 'India', 'state' => 'Gujarat']], [['country' => 'India', 'state' => 'Maharashtra', 'city' => 'Pune']],
            [['country' => 'Singapore', 'state' => 'Central Singapore']], [['country' => 'India', 'state' => 'Gujarat', 'city' => 'Surat']],
        ] as $i => $regions) {
            $this->previousRunIsOver();
            $this->generate(['offer_id' => 'FEE-1', 'customer_model' => 'b2b', 'regions' => $regions])->assertStatus(202)->assertJsonPath('data.icp_sync.status', $i === 0 ? 'created' : 'updated');
        }

        $this->assertSame(1, $this->icps()->count());
        $this->assertSame(['Surat'], $this->icp('FEE-1', 'b2b')->config['regions']);
        $this->assertSame('Gujarat', $this->icp('FEE-1', 'b2b')->config['locations'][0]['state']);
        $this->assertSame(4, (int) $this->icp('FEE-1', 'b2b')->version);
    }

    public function test_manually_typed_state_and_city_are_saved_and_only_conflicting_codes_are_refused(): void
    {
        $this->offer('FEE-1');
        $this->generate(['offer_id' => 'FEE-1', 'customer_model' => 'b2b', 'regions' => [['country' => 'India', 'state' => 'Maharashtra', 'city' => 'Mumbai']]])->assertStatus(202)->assertJsonPath('data.icp_sync.status', 'created');
        $loc = $this->icp('FEE-1', 'b2b')->config['locations'][0];
        $this->assertSame(['Maharashtra', 'Mumbai'], [$loc['state'], $loc['city']]);
        $this->assertSame(['Mumbai'], $this->icp('FEE-1', 'b2b')->config['regions']);
        $this->previousRunIsOver();

        $this->generate(['offer_id' => 'FEE-1', 'customer_model' => 'b2b', 'regions' => [['country' => 'Singapore', 'state' => 'My Region', 'city' => 'Anywhere']]])->assertStatus(202)->assertJsonPath('data.icp_sync.status', 'updated');
        $loc = $this->icp('FEE-1', 'b2b')->config['locations'][0];
        $this->assertSame(['Singapore', 'My Region', 'Anywhere', 'manual'], [$loc['country'], $loc['state'], $loc['city'], $loc['source']]);
        $this->assertSame('[{"country":"Singapore","state":"My Region","city":"Anywhere"}]', ResearchRun::latest('id')->first()->region_scope);
        $this->previousRunIsOver();

        $before = json_encode($this->icp('FEE-1', 'b2b')->config);
        $this->generate(['offer_id' => 'FEE-1', 'customer_model' => 'b2b', 'regions' => [['country_code' => 'IN', 'state_code' => 'CA']]])->assertStatus(422)->assertJsonPath('code', 'invalid_region');
        $this->assertSame($before, json_encode($this->icp('FEE-1', 'b2b')->config));
    }

    public function test_whole_product_uses_the_structured_location_for_the_run_only_and_never_touches_an_icp(): void
    {
        $this->offer('FEE-1');
        $this->generate(['offer_id' => 'FEE-1', 'customer_model' => 'b2b', 'regions' => [['country' => 'Singapore']]])->assertStatus(202);
        $before = json_encode($this->icp('FEE-1', 'b2b')->config) . $this->icp('FEE-1', 'b2b')->version;
        $this->previousRunIsOver();

        $res = $this->generate(['customer_model' => 'b2b', 'regions' => $this->surat()])->assertStatus(202);

        $res->assertJsonPath('data.icp_sync.status', 'skipped')->assertJsonPath('data.icp_sync.reason', 'whole_product');
        $this->assertSame('[{"country":"India","state":"Gujarat","city":"Surat"}]', ResearchRun::latest('id')->first()->region_scope, 'the location is used for the run');
        $this->assertSame($before, json_encode($this->icp('FEE-1', 'b2b')->config) . $this->icp('FEE-1', 'b2b')->version, 'and no ICP changed');
        $this->assertSame(1, $this->icps()->count());
    }

    public function test_an_older_icp_with_only_region_names_gets_the_structured_location_once_and_keeps_its_other_settings(): void
    {
        $this->offer('FEE-1');
        $this->generate(['offer_id' => 'FEE-1', 'customer_model' => 'b2b', 'regions' => [['country' => 'India', 'state' => 'Gujarat']]])->assertStatus(202);
        // make it an older ICP: names only, no structured location
        $icp = $this->icp('FEE-1', 'b2b');
        $legacy = $icp->config;
        unset($legacy['locations']);
        $legacy['pitch'] = 'kept as is';
        $icp->update(['config' => $legacy]);
        $row = ProductOffer::where('offer_id', 'FEE-1')->first();
        $map = SubProductIcp::normalizeStoredConfig($row->icp_config);
        $map['b2b'] = $legacy;
        $row->icp_config = $map;
        $row->save();
        $this->previousRunIsOver();

        $this->generate(['offer_id' => 'FEE-1', 'customer_model' => 'b2b', 'regions' => [['country' => 'India', 'state' => 'Gujarat']]])->assertStatus(202)->assertJsonPath('data.icp_sync.status', 'updated');
        $this->previousRunIsOver();
        $this->generate(['offer_id' => 'FEE-1', 'customer_model' => 'b2b', 'regions' => [['country' => 'India', 'state' => 'Gujarat']]])->assertStatus(202)->assertJsonPath('data.icp_sync.status', 'unchanged');

        $this->assertSame('GJ', $this->icp('FEE-1', 'b2b')->config['locations'][0]['state_code']);
        $this->assertSame('kept as is', $this->icp('FEE-1', 'b2b')->config['pitch']);
    }

    public function test_a_place_the_catalogue_does_not_know_is_kept_as_typed_flagged_unverified_and_never_becomes_india(): void
    {
        $this->offer('FEE-1');

        $this->generate(['offer_id' => 'FEE-1', 'customer_model' => 'b2b', 'regions' => [['country' => 'Atlantis', 'state' => 'Deep']]])->assertStatus(202);

        $this->assertSame('[{"country":"Atlantis","state":"Deep","city":null}]', ResearchRun::first()->region_scope);
        $location = $this->icp('FEE-1', 'b2b')->config['locations'][0];
        $this->assertSame(['Atlantis', 'Deep', false, 'unverified'], [$location['country'], $location['state'], $location['verified'], $location['source']]);
        $this->assertArrayNotHasKey('country_code', $location);
        $this->assertStringNotContainsString('India', json_encode($this->icp('FEE-1', 'b2b')->config['locations']));
    }

    public function test_b2c_and_b2g_save_their_own_structured_locations(): void
    {
        $this->offer('FEE-1');
        $this->generate(['offer_id' => 'FEE-1', 'customer_model' => 'b2c', 'regions' => [['country' => 'United States', 'state' => 'California', 'city' => 'Los Angeles']]])->assertStatus(202);
        $this->previousRunIsOver();
        $this->generate(['offer_id' => 'FEE-1', 'customer_model' => 'b2g', 'regions' => [['country' => 'United Kingdom', 'state' => 'Westminster', 'city' => 'London']]])->assertStatus(202);

        $this->assertSame(['US', 'CA'], [$this->icp('FEE-1', 'b2c')->config['locations'][0]['country_code'], $this->icp('FEE-1', 'b2c')->config['locations'][0]['state_code']]);
        $this->assertSame(['GB', 'WSM'], [$this->icp('FEE-1', 'b2g')->config['locations'][0]['country_code'], $this->icp('FEE-1', 'b2g')->config['locations'][0]['state_code']], 'the data source files London under Westminster');

        $this->assertNull($this->icp('FEE-1', 'b2b'));
    }

    public function test_another_organisation_cannot_use_a_location_to_reach_an_icp_it_does_not_own(): void
    {
        $this->offer('FEE-1');
        $this->generate(['offer_id' => 'FEE-1', 'customer_model' => 'b2b', 'regions' => $this->surat()])->assertStatus(202);
        $before = json_encode($this->icp('FEE-1', 'b2b')->config);
        $this->previousRunIsOver();

        $this->generate(['offer_id' => 'FEE-1', 'customer_model' => 'b2b', 'regions' => [['country' => 'Singapore']]], self::T2)->assertStatus(422)->assertJsonPath('code', 'unknown_sub_product');

        $this->assertSame($before, json_encode($this->icp('FEE-1', 'b2b')->config));
        $this->assertSame(0, IcpProfile::where('sub_institute_id', self::T2)->count());
    }
}
