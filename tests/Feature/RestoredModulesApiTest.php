<?php

namespace Tests\Feature;

use App\Domain\Signals\Opportunities\ProductProfile;
use App\Domain\Signals\Opportunities\ResearchRun;
use App\Domain\Signals\Support\SafeUrlFetcher;
use App\Models\auth\tbluserModel;
use App\Support\RoleKey;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Regression: the Product Portfolio, Ingestion Engine and Company Opportunities API surface was removed while the
 * ScholarClone feed was built. These tests pin the routes, auth and tenant isolation that the existing screens call.
 */
class RestoredModulesApiTest extends TestCase
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


    private function offer(string $id = 'OFF-1'): array
    {
        return ['offer_id' => $id, 'name' => 'Test offer', 'parent_product' => 'ScholarClone', 'readiness_status' => 'Ready'];
    }

    public function test_the_modules_routes_exist_behind_the_token(): void
    {
        foreach ([
            ['GET', '/api/portfolio/offers'], ['GET', '/api/portfolio/partners'], ['GET', '/api/portfolio/stats'],
            ['GET', '/api/signals/product-profile'], ['GET', '/api/signals/research/status'], ['GET', '/api/signals/opportunities'],
            ['GET', '/api/signals/ingestion/sources'], ['GET', '/api/signals/ingestion/findings'], ['GET', '/api/signals/opportunity-matches'],
        ] as [$method, $url]) {
            $this->assertNotSame(404, $this->json($method, $url)->getStatusCode(), "{$method} {$url} must be routed");
            $this->json($method, $url)->assertStatus(401);
        }
    }

    public function test_portfolio_offers_are_saved_listed_per_tenant_and_need_admin_or_hr_to_change(): void
    {
        $this->postJson('/api/portfolio/offers', $this->offer(), $this->h())->assertSuccessful();
        $this->postJson('/api/portfolio/offers', $this->offer(), $this->h())->assertStatus(409);
        $this->postJson('/api/portfolio/offers', ['name' => 'x'], $this->h())->assertStatus(422);
        $this->postJson('/api/portfolio/offers', $this->offer('OFF-2'), $this->h(self::T1, 2))->assertStatus(403);

        $this->assertSame(1, DB::table('g2g_product_offers')->count());
        $this->getJson('/api/portfolio/offers', $this->h())->assertOk()->assertJsonFragment(['offer_id' => 'OFF-1']);
        $this->getJson('/api/portfolio/offers', $this->h(self::T1, 2))->assertOk()->assertJsonFragment(['offer_id' => 'OFF-1']);
        $this->getJson('/api/portfolio/offers', $this->h(self::T2))->assertOk()->assertJsonMissing(['offer_id' => 'OFF-1']);
    }

    public function test_product_profile_saves_validates_and_is_tenant_scoped(): void
    {
        $h = $this->h();
        $this->putJson('/api/signals/product-profile', [
            'product_name' => 'ScholarClone', 'description' => 'School ERP.', 'features' => 'Admissions',
            'keywords' => ['school erp', ' school erp ', ''], 'target_industries' => ['Education'], 'research_enabled' => true,
            'schedule_time' => '11:30', 'research_frequency' => 'weekdays',
        ], $h)->assertOk()->assertJsonPath('data.completeness.complete', true);

        $this->getJson('/api/signals/product-profile', $h)->assertOk()
            ->assertJsonPath('data.profile.product_name', 'ScholarClone')->assertJsonPath('data.profile.keywords', ['school erp']);
        $this->getJson('/api/signals/product-profile', $this->h(self::T2))->assertOk()->assertJsonPath('data.profile', null);

        $this->putJson('/api/signals/product-profile', ['schedule_time' => '25:99'], $h)->assertStatus(422);
        $this->putJson('/api/signals/product-profile', ['product_name' => 'X'], $this->h(self::T1, 2))->assertStatus(403);
    }

    public function test_research_status_keeps_the_original_and_the_scholarclone_fields(): void
    {
        $data = $this->getJson('/api/signals/research/status', $this->h())->assertOk()->json('data');

        foreach (['profile_complete', 'profile_missing', 'search_configured', 'search_driver', 'ai_configured', 'research_enabled'] as $k) {
            $this->assertArrayHasKey($k, $data['requirements'], $k);
        }
        foreach (['running', 'latest_run', 'last_successful_run', 'next_scheduled_at', 'timezone', 'product'] as $k) {
            $this->assertArrayHasKey($k, $data, $k);
        }
    }

    public function test_a_custom_topic_scan_is_accepted_and_carries_its_parameters_to_the_job(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $this->postJson('/api/signals/research/run', ['topic' => 'school ERP tender', 'geography' => 'Gujarat'], $this->h())->assertSuccessful();

        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\RunOpportunityResearchJob::class,
            fn ($job) => $job->custom === ['topic' => 'school ERP tender', 'geography' => 'Gujarat'] && $job->company === null);
        $this->assertSame(1, ResearchRun::count());
    }

    public function test_ingestion_findings_and_matches_endpoints_answer_for_admin_and_refuse_employees(): void
    {
        $this->getJson('/api/signals/ingestion/findings', $this->h())->assertOk();
        $this->getJson('/api/signals/ingestion/findings', $this->h(self::T1, 2))->assertStatus(403);
        $this->getJson('/api/signals/opportunity-matches', $this->h())->assertOk();
        $this->postJson('/api/signals/matches/action', [], $this->h())->assertStatus(422);
    }
}
