<?php

namespace Tests\Feature;

use App\Domain\Signals\Opportunities\OpportunityResearcher;
use App\Domain\Signals\Opportunities\ProductProfile;
use App\Domain\Signals\Opportunities\ResearchRun;
use App\Domain\Signals\Opportunities\ResearchRunReaper;
use App\Jobs\RunOpportunityResearchJob;
use App\Models\auth\tbluserModel;
use App\Support\RoleKey;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Generate Signals must never hang. A click returns at once with a persisted run; the run reports real steps; a run that cannot finish is closed
 * with a reason (no worker, a timeout, a lock held elsewhere); a slow provider cannot hold it for long. All provider traffic is mocked.
 */
class ResearchRunProgressTest extends TestCase
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
            'ai.provider.gemini.api_key' => 'test-key', 'signals.ai_attempts' => 2,
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
        Carbon::setTestNow();
        parent::tearDown();
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

    private int $aiCalls = 0;

    private function fakeSearchOkAiTimeout(): void
    {
        $this->aiCalls = 0;
        $this->app->forgetInstance(\Illuminate\Http\Client\Factory::class);
        Http::clearResolvedInstance(\Illuminate\Http\Client\Factory::class);
        Http::fake([
            'api.tavily.com/*' => Http::response(['results' => [['url' => 'https://news.example.com/x', 'title' => 'Podar opens campus',
                'content' => 'Podar World School announced a new campus in Vadodara and is hiring an ERP administrator ahead of admissions 2027.', 'published_date' => '2026-10-15']]], 200),
            'generativelanguage.googleapis.com/*' => function () {
                $this->aiCalls++;

                throw new ConnectionException('cURL error 28: Operation timed out after 90001 milliseconds');
            },
            '*/robots.txt' => Http::response('', 404),
            '*' => Http::response('<html><body><p>Podar World School announced a new campus in Vadodara.</p></body></html>', 200, ['Content-Type' => 'text/html']),
        ]);
    }

    // ── a click returns promptly, with a persisted run ───────────────────────

    public function test_generate_is_accepted_at_once_with_a_queued_persisted_run_and_does_no_work_itself(): void
    {
        Queue::fake();
        Http::fake();

        $res = $this->postJson('/api/signals/research/run', ['product' => 'scholarclone'], $this->h())->assertStatus(202);

        $id = $res->json('data.run_id');
        $this->assertSame('queued', $res->json('data.run.stage'));
        $this->assertSame('running', $res->json('data.run.status'));
        $this->assertNotNull($res->json('data.run.updated_at'));
        Queue::assertPushed(RunOpportunityResearchJob::class, 1);
        Http::assertNothingSent();   // no provider call happens inside the request

        // The progress is persisted and fetchable by id.
        $this->getJson("/api/signals/research/runs/{$id}", $this->h())->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.stage', 'queued');
    }

    public function test_a_second_click_does_not_create_a_second_run_or_job(): void
    {
        Queue::fake();
        $h = $this->h();

        $this->postJson('/api/signals/research/run', ['product' => 'scholarclone'], $h)->assertStatus(202);
        $this->postJson('/api/signals/research/run', ['product' => 'scholarclone'], $h)->assertStatus(409)->assertJsonPath('code', 'already_running');

        Queue::assertPushed(RunOpportunityResearchJob::class, 1);
        $this->assertSame(1, ResearchRun::count());
    }

    public function test_a_failed_run_can_be_retried_at_once_by_the_person_but_nothing_retries_by_itself(): void
    {
        Queue::fake();
        $this->run_(['status' => 'failed', 'stage' => 'failed', 'error_code' => 'timed_out', 'completed_at' => now()]);

        $this->postJson('/api/signals/research/run', ['product' => 'scholarclone'], $this->h())->assertStatus(202);   // no cool-down after a failure

        Queue::assertPushed(RunOpportunityResearchJob::class, 1);   // exactly the one the person asked for
    }

    // ── a run that cannot finish is closed with a reason ─────────────────────

    public function test_a_queued_run_that_no_worker_picked_up_is_failed_with_the_fix_and_never_spins(): void
    {
        $stuck = $this->run_(['updated_at' => now()->subSeconds(400), 'started_at' => now()->subSeconds(400)]);
        $fresh = $this->run_(['sub_institute_id' => self::T, 'product_key' => 'enterprise_brain']);   // just queued: left alone
        $other = $this->run_(['sub_institute_id' => self::T2, 'updated_at' => now()->subSeconds(400)]);

        $data = $this->getJson("/api/signals/research/runs/{$stuck->id}", $this->h())->assertOk()->json('data');

        $this->assertSame('failed', $data['status']);
        $this->assertSame('failed', $data['stage']);
        $this->assertSame('worker_not_running', $data['error_code']);
        $this->assertStringContainsString('queue:work', (string) $data['stage_message']);
        $this->assertSame('running', $fresh->fresh()->status);
        $this->assertSame('running', $other->fresh()->status, 'another organisation\'s run is never touched');
    }

    public function test_a_run_that_stopped_sending_progress_is_closed_as_timed_out(): void
    {
        $silent = $this->run_(['stage' => 'collecting', 'updated_at' => now()->subMinutes(20), 'started_at' => now()->subMinutes(25)]);
        $alive = $this->run_(['stage' => 'collecting', 'updated_at' => now()->subMinutes(2), 'started_at' => now()->subMinutes(25), 'product_key' => 'g2g']);

        $this->assertSame(1, ResearchRunReaper::sweep(self::T));

        $this->assertSame('failed', $silent->fresh()->status);
        $this->assertSame('timed_out', $silent->fresh()->error_code);
        $this->assertSame('running', $alive->fresh()->status, 'a run still sending progress is not closed, however long it has been going');
    }

    public function test_the_reaper_never_changes_a_run_that_has_finished(): void
    {
        $done = $this->run_(['status' => 'success', 'stage' => 'completed', 'updated_at' => now()->subHours(3), 'completed_at' => now()->subHours(3)]);

        $this->assertSame(0, ResearchRunReaper::sweep(self::T));
        $this->assertSame('success', $done->fresh()->status);
    }

    public function test_a_late_worker_does_not_bring_a_closed_run_back_to_life(): void
    {
        Http::fake();
        $dead = $this->run_(['status' => 'failed', 'stage' => 'failed', 'error_code' => 'worker_not_running', 'completed_at' => now()]);

        app(OpportunityResearcher::class)->run(self::T, 'manual', null, null, $dead->id, null, 'scholarclone');

        $this->assertSame('failed', $dead->fresh()->status);
        $this->assertSame('worker_not_running', $dead->fresh()->error_code);
        Http::assertNothingSent();
    }

    public function test_a_refused_lock_ends_the_watched_run_instead_of_leaving_it_running(): void
    {
        Http::fake();
        $run = $this->run_();
        $held = Cache::lock('signals:research:' . self::T, 60);
        $this->assertTrue($held->get());

        app(OpportunityResearcher::class)->run(self::T, 'manual', null, null, $run->id, null, 'scholarclone');

        $this->assertSame('skipped', $run->fresh()->status);
        $this->assertSame('already_running', $run->fresh()->error_code);
        $this->assertNotNull($run->fresh()->completed_at);
        $this->assertSame(1, ResearchRun::count(), 'no second row');
        Http::assertNothingSent();
    }

    // ── a slow provider cannot hold a run ────────────────────────────────────

    public function test_an_ai_timeout_fails_the_run_at_once_with_one_request_and_a_clear_reason(): void
    {
        $this->fakeSearchOkAiTimeout();
        $run = $this->run_();

        app(OpportunityResearcher::class)->run(self::T, 'manual', null, null, $run->id, null, 'scholarclone');

        $run = $run->fresh();
        $this->assertSame('failed', $run->status);
        $this->assertSame('failed', $run->stage);
        $this->assertSame('ai_timeout', $run->error_code);
        $this->assertNotNull($run->completed_at);
        $this->assertSame(1, $this->aiCalls, 'a timed-out request is not retried (signals.ai_attempts is 2 here)');
    }

    public function test_every_ai_request_carries_a_finite_timeout_below_the_providers_ceiling(): void
    {
        config(['signals.ai_timeout' => 30, 'ai.provider.gemini.timeout' => 600]);
        $m = new \ReflectionMethod(\App\Domain\AI\Support\AiModelClient::class, 'timeout');
        $m->setAccessible(true);
        $client = app(\App\Domain\AI\Support\AiModelClient::class);

        $this->assertSame(30, $m->invoke($client, 'gemini', ['timeout' => 30]));
        $this->assertSame(600, $m->invoke($client, 'gemini', []), 'callers that set no cap keep the provider setting');
        $this->assertSame(45, $m->invoke($client, 'gemini', ['timeout' => 45]) , 'a cap never raises the timeout');
        config(['ai.provider.gemini.timeout' => 20]);
        $this->assertSame(20, $m->invoke($client, 'gemini', ['timeout' => 90]), 'a cap never raises the timeout');
    }

    public function test_a_run_that_uses_its_time_budget_stops_optional_work_and_finishes_as_partial_with_a_note(): void
    {
        config(['signals.run_budget_seconds' => 1]);
        $this->app->forgetInstance(\Illuminate\Http\Client\Factory::class);
        Http::clearResolvedInstance(\Illuminate\Http\Client\Factory::class);
        Http::fake([
            'api.tavily.com/*' => function () {
                usleep(1_200_000);   // the first search alone uses the whole budget

                return Http::response(['results' => []], 200);
            },
            '*' => Http::response('', 404),
        ]);
        $run = $this->run_();

        app(OpportunityResearcher::class)->run(self::T, 'manual', null, null, $run->id, null, 'scholarclone');

        $run = $run->fresh();
        $this->assertNotSame('running', $run->status, 'the run ended');
        $this->assertSame('partial', $run->status);
        $this->assertStringContainsString('time limit', (string) $run->stage_message);
        $searches = collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), 'api.tavily.com'))->count();
        $this->assertSame(1, $searches, 'the remaining searches were skipped, not waited for');
    }

    public function test_a_run_records_each_real_step_as_it_goes(): void
    {
        $this->fakeSearchOkAiTimeout();
        $run = $this->run_();
        $seen = [];
        ResearchRun::saved(function (ResearchRun $r) use (&$seen) {
            if ($r->stage && end($seen) !== $r->stage) {
                $seen[] = $r->stage;
            }
        });

        app(OpportunityResearcher::class)->run(self::T, 'manual', null, null, $run->id, null, 'scholarclone');

        $this->assertSame('preparing', $seen[0], 'the worker says it started');
        $this->assertContains('searching', $seen);
        $this->assertSame('failed', end($seen));
        $this->assertSame(array_values(array_unique($seen)), $seen, 'steps only move forward');
    }

    // ── product isolation and history ────────────────────────────────────────

    public function test_status_follows_the_selected_product_and_reports_when_another_product_is_busy(): void
    {
        $this->run_(['product_key' => 'enterprise_brain', 'stage' => 'searching']);
        $h = $this->h();

        $mine = $this->getJson('/api/signals/research/status?product=scholarclone', $h)->assertOk();
        $mine->assertJsonPath('data.running', false)->assertJsonPath('data.busy_elsewhere', true)->assertJsonPath('data.latest_run', null);

        $theirs = $this->getJson('/api/signals/research/status?product=enterprise_brain', $h)->assertOk();
        $theirs->assertJsonPath('data.running', true)->assertJsonPath('data.busy_elsewhere', false)->assertJsonPath('data.latest_run.product_key', 'enterprise_brain');
    }

    public function test_one_organisation_cannot_read_anothers_run(): void
    {
        $run = $this->run_(['sub_institute_id' => self::T2]);

        $this->getJson("/api/signals/research/runs/{$run->id}", $this->h())->assertStatus(404);
    }

    public function test_looking_at_a_past_date_or_the_history_never_starts_research(): void
    {
        Queue::fake();
        Http::fake();
        $this->run_(['status' => 'success', 'stage' => 'completed', 'completed_at' => now()]);
        $h = $this->h();

        $this->getJson('/api/signals/research/dates?product=scholarclone', $h)->assertOk();
        $this->getJson('/api/signals/opportunities?product=scholarclone&run_date=2026-10-20', $h)->assertOk();
        $this->getJson('/api/signals/research/runs', $h)->assertOk();

        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertSame(1, ResearchRun::count());
    }

    public function test_the_worker_command_bounds_the_worker_and_never_retries_a_job(): void
    {
        $cmd = \App\Support\QueueWorkerLauncher::command('signals', 'Linux', '/usr/bin/php', '/var/www/g2gb');

        $this->assertStringContainsString('--tries=1', $cmd);
        $this->assertStringContainsString('--timeout=900', $cmd);
        $this->assertStringContainsString('--max-time=', $cmd);
    }

    // ── a zero is explained, and is not confused with a failure or a save ────

    public function test_a_run_with_no_qualifying_candidate_finishes_as_a_truthful_zero_not_a_failure_and_says_what_it_read(): void
    {
        $this->app->forgetInstance(\Illuminate\Http\Client\Factory::class);
        Http::clearResolvedInstance(\Illuminate\Http\Client\Factory::class);
        Http::fake([
            'api.tavily.com/*' => Http::response(['results' => [
                ['url' => 'https://www.instagram.com/p/abc', 'title' => 'Instagram', 'content' => 'We love our school community and sports day photos.', 'published_date' => '2026-10-15'],
                ['url' => 'https://www.facebook.com/someschool/posts/1', 'title' => 'Garba night', 'content' => 'Garba Mahotsava at our school this weekend.', 'published_date' => '2026-10-15'],
                ['url' => 'https://www.shine.com/jobs/academic-coordinator-surat', 'title' => 'Academic Coordinator Jobs in Surat', 'content' => 'Academic coordinator jobs listing page.', 'published_date' => '2026-10-15'],
            ]], 200),
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode(['opportunities' => []])]]], 'finishReason' => 'STOP']],
                'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
            ], 200),
            '*/robots.txt' => Http::response('', 404),
            '*' => Http::response('<html><body><p>Nothing about systems here.</p></body></html>', 200, ['Content-Type' => 'text/html']),
        ]);
        $run = $this->run_();

        app(OpportunityResearcher::class)->run(self::T, 'manual', null, null, $run->id, null, 'scholarclone');

        $run = $run->fresh();
        $this->assertContains($run->status, ['success', 'partial'], 'no qualifying candidate is not a technical failure');
        $this->assertSame('completed', $run->stage);
        $this->assertSame(0, (int) $run->opportunities_qualified);
        $this->assertSame(0, (int) $run->opportunities_new);
        $this->assertNull($run->error_code);
        $this->assertStringContainsString('no school showed a clear reason to buy', (string) $run->stage_message);
        $this->assertStringContainsString('Pages:', (string) $run->stage_message);
        $this->assertStringContainsString('social post', (string) $run->stage_message);
        $this->assertSame(0, DB::table('g2g_company_opportunities')->count(), 'nothing was invented to fill the list');
    }

    public function test_the_page_mix_counts_kinds_of_pages_without_judging_them(): void
    {
        $urls = ['https://www.instagram.com/p/1', 'https://facebook.com/x', 'https://www.shine.com/jobs/1', 'https://news.example.com/a', 'https://www.nytimes.com/b'];

        $mix = \App\Domain\Signals\Scholar\SourceScreen::mix($urls);

        $this->assertSame(['official' => 0, 'job_post' => 1, 'social_post' => 2, 'third_party' => 2], $mix);
        $this->assertSame('', \App\Domain\Signals\Scholar\SourceScreen::describeMix([]));
        $this->assertSame('Pages: 1 job post, 2 social posts, 2 other pages.', \App\Domain\Signals\Scholar\SourceScreen::describeMix($urls));
    }
}
