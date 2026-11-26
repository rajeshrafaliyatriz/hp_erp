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
        // These tests rely on an ICP stored against the product itself (the legacy path; a product's ICP now comes from its sub-products).
        config(['signals.legacy_product_icp' => true]);
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
            ['GET', '/api/portfolio/offers'], ['GET', '/api/portfolio/stats'],
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

    /**
     * The Partner Network is gone (26 Nov 2026), but a front end deployed before it may still send `partner_id` with a match decision: that must not be an error, and no partner is stored.
     */
    public function test_a_match_decision_that_still_carries_a_partner_id_is_accepted_and_stores_no_partner(): void
    {
        $body = ['signal_type' => 'signal', 'signal_id' => 41, 'offer_id' => 'OFF-1', 'action' => 'review', 'review_notes' => 'seen', 'partner_id' => 'PT-9'];

        $this->postJson('/api/signals/matches/action', $body, $this->h())->assertOk();

        $row = DB::table('g2g_opportunity_matches')->where('sub_institute_id', self::T1)->where('signal_id', 41)->where('offer_id', 'OFF-1')->first();
        $this->assertNotNull($row, 'the decision was saved');
        $this->assertSame('Reviewed', $row->match_status);
        $this->assertNull($row->partner_id, 'the partner the old front end sent was ignored');
    }

    // ── who may do what, through the real HTTP kernel ────────────────────────

    public function test_the_role_matrix_for_every_screen_endpoint_and_nobody_without_a_token_gets_in(): void
    {
        $admin = $this->h(self::T1, 1);
        $employee = $this->h(self::T1, 2);
        $manager = $this->h(self::T1, 3);

        // [method, url, body, admin, employee, manager]: expected HTTP status per role (null = anything but 401/403, i.e. it was let through)
        $matrix = [
            ['GET', '/api/signals/opportunities', [], 200, 403, 200],
            ['GET', '/api/signals/research/dates?product=scholarclone', [], 200, 403, 200],
            ['GET', '/api/signals/research/status', [], 200, 403, 200],
            ['POST', '/api/signals/research/run', ['product' => 'scholarclone'], null, 403, 403],
            ['GET', '/api/signals/ingestion/sources', [], 200, 403, 403],
            ['POST', '/api/signals/ingestion/url', ['url' => 'https://example.com', 'product' => 'scholarclone'], null, 403, 403],
            // (A product has no ICP of its own to read or save: those endpoints were removed. Creating a product is covered in CustomProductApiTest.)
            ['GET', '/api/portfolio/products', [], 200, 200, 200],
            ['GET', '/api/portfolio/offers', [], 200, 200, 200],
            ['POST', '/api/portfolio/offers', ['offer_id' => 'X-1', 'name' => 'X', 'parent_product' => 'G2G', 'readiness_status' => 'Live'], 'created', 403, 403],
        ];
        foreach ($matrix as [$method, $url, $body, $a, $e, $m]) {
            foreach ([['employee', $employee, $e], ['manager', $manager, $m]] as [$role, $headers, $expected]) {
                $this->assertSame($expected, $this->json($method, $url, $body, $headers)->getStatusCode(), "{$role} {$method} {$url}");
            }
            $this->json($method, $url, $body)->assertStatus(401);
            $status = $this->json($method, $url, $body, $admin)->getStatusCode();
            if ($a === null) {
                $this->assertNotContains($status, [401, 403], "admin {$method} {$url} should be let through, got {$status}");
            } elseif ($a === 'created') {
                $this->assertContains($status, [200, 201], "admin {$method} {$url}");
            } else {
                $this->assertSame($a, $status, "admin {$method} {$url}");
            }
        }
        // Only the administrator's creation exists: the employee's and manager's refused requests created nothing.
        $this->assertSame(1, DB::table('g2g_product_offers')->count());
    }

    public function test_an_opportunity_review_needs_admin_hr_or_manager_and_another_organisation_cannot_change_it(): void
    {
        $company = \App\Domain\Signals\Opportunities\Company::create(['sub_institute_id' => self::T1, 'name' => 'S', 'normalized_name' => 's']);
        $id = DB::table('g2g_company_opportunities')->insertGetId([
            'sub_institute_id' => self::T1, 'company_id' => $company->id, 'business_fit' => 'scholar', 'title' => 't', 'category' => 'x', 'observed_event' => 'e',
            'why_indicates_need' => 'w', 'product_fit' => 'p', 'recommended_action' => 'a', 'priority' => 'Low', 'qualification' => 'Monitoring', 'confidence' => 'Low',
            'sources' => '[]', 'confirmed_facts' => '[]', 'unverified_claims' => '[]', 'fingerprint' => 'f1', 'review_status' => 'New', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->patchJson("/api/signals/opportunities/{$id}/review", [], $this->h(self::T1, 2))->assertStatus(403);
        $this->patchJson("/api/signals/opportunities/{$id}/review", [], $this->h(self::T2, 1))->assertStatus(404);
        $this->assertSame('New', DB::table('g2g_company_opportunities')->where('id', $id)->value('review_status'));
        $this->patchJson("/api/signals/opportunities/{$id}/review", [], $this->h(self::T1, 3))->assertOk();
        $this->assertSame('Reviewed', DB::table('g2g_company_opportunities')->where('id', $id)->value('review_status'));
    }

    public function test_the_worker_command_uses_absolute_paths_so_it_works_whatever_directory_the_web_server_runs_in(): void
    {
        $unix = \App\Support\QueueWorkerLauncher::command('signals', 'Linux', '/usr/bin/php', '/var/www/g2gb');
        // (escapeshellarg quotes per host OS, so the expected text is built the same way)
        $this->assertSame(sprintf('cd %s && %s artisan queue:work --queue=%s --tries=1 --timeout=900 --max-time=1500 --stop-when-empty > /dev/null 2>&1 &', escapeshellarg('/var/www/g2gb'), escapeshellarg('/usr/bin/php'), escapeshellarg('signals,default')), $unix);
        $win = \App\Support\QueueWorkerLauncher::command('signals', 'WINNT', 'C:\\php\\php.exe', 'C:\\app\\g2gb');
        $this->assertSame('cd /d "C:\\app\\g2gb" && start /B "" "C:\\php\\php.exe" artisan queue:work --queue=signals,default --tries=1 --timeout=900 --max-time=1500 --stop-when-empty', $win);
        $this->assertStringNotContainsString(' php artisan', $unix, 'never a bare php');
        $this->assertStringContainsString(base_path(), \App\Support\QueueWorkerLauncher::command('signals'));
    }

    public function test_the_preflight_reads_and_reports_without_calling_a_provider_writing_or_printing_a_secret(): void
    {
        \Illuminate\Support\Facades\Http::fake();
        config(['signals.web_search.tavily_key' => 'tvly-SECRET-VALUE', 'ai.provider.gemini.api_key' => 'gem-SECRET-VALUE', 'cors.allowed_origins' => ['https://g2g.example.com']]);
        $before = DB::table('g2g_product_offers')->count() . '/' . DB::table('tbluser')->count();

        \Illuminate\Support\Facades\Artisan::call('signals:preflight', ['--origin' => 'https://g2g.example.com']);
        $out = \Illuminate\Support\Facades\Artisan::output();
        $this->assertStringContainsString('Nothing was written and no provider was called', $out);
        $this->assertStringContainsString('CORS for https://g2g.example.com', $out);
        $this->assertStringContainsString('allowed', $out);

        \Illuminate\Support\Facades\Artisan::call('signals:preflight', ['--origin' => 'https://evil.example.net']);
        $blocked = \Illuminate\Support\Facades\Artisan::output();
        $this->assertStringContainsString('NOT in config/cors.php', $blocked);

        \Illuminate\Support\Facades\Http::assertNothingSent();
        $this->assertSame($before, DB::table('g2g_product_offers')->count() . '/' . DB::table('tbluser')->count());
        $this->assertStringNotContainsString('SECRET-VALUE', $out . $blocked);
    }
}
