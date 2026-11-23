<?php

namespace Tests\Feature;

use App\Domain\Signals\Connectors\DataConnectorRegistry;
use App\Domain\Signals\Ingestion\DataConnector;
use App\Domain\Signals\Ingestion\DataConnectorSync;
use App\Domain\Signals\Ingestion\DataConnectorSyncReaper;
use App\Domain\Signals\Ingestion\IngestionAnalyzer;
use App\Domain\Signals\Ingestion\IngestionService;
use App\Domain\Signals\Ingestion\IngestionSource;
use App\Domain\Signals\Ingestion\SyncNoLongerActiveException;
use App\Domain\Signals\Opportunities\ProductProfile;
use App\Domain\Signals\Support\SafeUrlFetcher;
use App\Domain\Signals\Support\StructuredAi;
use App\Jobs\SyncDataConnectorJob;
use App\Models\auth\tbluserModel;
use App\Support\QueueWorkerLauncher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\Support\RunsSlowHttpServer;
use Tests\TestCase;

/**
 * Manual Sync Now and stale-sync recovery.
 *
 * Everything runs on the in-memory sqlite database from phpunit.xml with a fake queue, a fake worker launcher and
 * mocked HTTP. It proves our handling of the sync lifecycle; it does not prove that any real worker or scheduler is
 * running anywhere.
 */
class DataConnectorSyncLifecycleTest extends TestCase
{
    use RunsSlowHttpServer;

    private const T1 = 1;
    private const T2 = 2;

    /** @var list<string> queues a worker was asked to start for */
    private array $workerStarts = [];

    protected function setUp(): void
    {
        parent::setUp();
        // The reaper reads retry_after from the ACTIVE queue connection, so tests that rely on the database queue's 960 s say so.
        config(['signals.legacy_product_icp' => true, 'queue.default' => 'database', 'queue.connections.database.retry_after' => 960]);
        Carbon::setTestNow('2026-11-20 10:00:00');
        Cache::flush();
        $this->workerStarts = [];
        QueueWorkerLauncher::fake(function (string $queue) {
            $this->workerStarts[] = $queue;
        });

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
            '2026_09_30_150000_create_g2g_opportunity_and_ingestion_tables',
            '2026_09_30_180000_add_signal_fields_to_ingestion_findings',
            '2026_09_30_200000_add_research_sources_and_signal_kind',
            '2026_09_30_230000_add_claude_feed_and_linking_fields',
            '2026_10_08_100000_extend_g2g_signals_for_market_import',
            '2026_11_29_090000_add_assessment_to_g2g_company_opportunities',
            '2026_10_14_100000_create_g2g_icp_profiles_tables',
            '2026_12_01_090000_add_product_key_to_signals_runs_and_sources',
            '2026_12_07_090000_add_scope_to_g2g_company_opportunities',
            '2026_11_04_120000_create_g2g_data_connector_tables',
            '2026_11_04_140000_extend_g2g_data_connectors_for_social_sync',
            '2026_12_10_100000_create_g2g_connector_provider_settings_tables',
        ] as $migration) {
            (require base_path("database/migrations/{$migration}.php"))->up();
        }
        DB::table('tbluserprofilemaster')->insert([['id' => 1, 'name' => 'Administrator', 'role_key' => 'administrator'], ['id' => 2, 'name' => 'Employee', 'role_key' => 'employee'], ['id' => 3, 'name' => 'HR Manager', 'role_key' => 'hr_manager']]);

        $this->app->instance(SafeUrlFetcher::class, new SafeUrlFetcher(fn () => ['93.184.216.34']));
        foreach ([self::T1, self::T2] as $tenant) {
            ProductProfile::create([
                'sub_institute_id' => $tenant, 'product_name' => 'ScholarClone', 'description' => 'School ERP system',
                'problems_solved' => 'Manual school operations', 'features' => 'Fee collection, LMS, Attendance',
                'target_industries' => ['Education'], 'target_markets' => ['India'], 'keywords' => ['school erp'],
                'excluded' => [], 'research_enabled' => true, 'research_frequency' => 'daily', 'recency_days' => 30,
            ]);
        }
    }

    protected function tearDown(): void
    {
        $this->stopSlowServer();
        DataConnectorSync::flushEventListeners();
        QueueWorkerLauncher::fake(null);
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function h(int $tenant = self::T1): array
    {
        $id = DB::table('tbluser')->insertGetId(['user_name' => 'u' . uniqid(), 'sub_institute_id' => $tenant, 'user_profile_id' => 1]);

        return ['Authorization' => 'Bearer ' . tbluserModel::find($id)->createToken('t')->plainTextToken];
    }

    private function connector(array $o = []): DataConnector
    {
        return DataConnector::create($o + [
            'sub_institute_id' => self::T1, 'type' => 'public_web_page', 'name' => 'Acme News',
            'url' => 'https://news.example.com/acme', 'product_key' => 'scholarclone', 'connection_status' => 'connected',
            'sync_enabled' => true,
        ]);
    }

    /** A sync whose last write (`updated_at`, the heartbeat) was $ageSeconds ago. */
    private function sync(DataConnector $connector, string $status, int $ageSeconds, array $o = []): DataConnectorSync
    {
        $sync = DataConnectorSync::create($o + [
            'sub_institute_id' => $connector->sub_institute_id, 'connector_id' => $connector->id, 'status' => $status,
            'started_at' => now()->subSeconds($ageSeconds), 'created_by' => 1,
        ]);
        DB::table('g2g_data_connector_syncs')->where('id', $sync->id)->update(['updated_at' => now()->subSeconds($ageSeconds)]);

        return $sync->fresh();
    }

    private function threshold(): int
    {
        return DataConnectorSyncReaper::runningThresholdSeconds();
    }

    // ── Manual Sync Now ──────────────────────────────────────────────────────

    public function test_manual_sync_is_queued_on_the_signals_queue_and_reported_as_queued_never_as_done(): void
    {
        Queue::fake();
        config(['queue.default' => 'database']);
        $headers = $this->h();
        $connector = $this->connector();

        $response = $this->postJson("/api/signals/ingestion/connectors/{$connector->id}/sync", [], $headers)->assertStatus(202);

        $response->assertJsonPath('data.status', 'queued');
        $this->assertStringContainsString('queued', $response->json('message'));
        $this->assertStringNotContainsString('completed', strtolower($response->json('message')));
        $this->assertSame(0, (int) $response->json('data.records_processed'));
        $this->assertNull($response->json('data.completed_at'));
        Queue::assertPushed(SyncDataConnectorJob::class, 1);
        Queue::assertPushed(SyncDataConnectorJob::class, fn (SyncDataConnectorJob $job) => $job->queue === 'signals' && $job->tenantId === self::T1 && $job->connectorId === $connector->id);
        $this->assertSame('queued', DataConnectorSync::sole()->status);
    }

    public function test_manual_sync_asks_for_a_short_lived_worker_on_the_signals_queue_exactly_once(): void
    {
        Queue::fake();
        config(['queue.default' => 'database']);
        $connector = $this->connector();

        $this->postJson("/api/signals/ingestion/connectors/{$connector->id}/sync", [], $this->h())->assertStatus(202);

        $this->assertSame(['signals'], $this->workerStarts);
    }

    public function test_no_worker_is_started_when_the_queue_connection_is_sync_and_the_job_is_still_dispatched(): void
    {
        Queue::fake();
        config(['queue.default' => 'sync']);
        $connector = $this->connector();

        $this->postJson("/api/signals/ingestion/connectors/{$connector->id}/sync", [], $this->h())->assertStatus(202);

        $this->assertSame([], $this->workerStarts);
        Queue::assertPushed(SyncDataConnectorJob::class, 1);
    }

    public function test_a_second_click_while_a_sync_is_pending_is_refused_and_starts_nothing(): void
    {
        Queue::fake();
        config(['queue.default' => 'database']);
        $headers = $this->h();
        $connector = $this->connector();

        $this->postJson("/api/signals/ingestion/connectors/{$connector->id}/sync", [], $headers)->assertStatus(202);
        $this->postJson("/api/signals/ingestion/connectors/{$connector->id}/sync", [], $headers)
            ->assertStatus(409)->assertJsonPath('message', 'A sync is already running for this connector.');

        Queue::assertPushed(SyncDataConnectorJob::class, 1);
        $this->assertSame(1, DataConnectorSync::count());
        $this->assertCount(1, $this->workerStarts, 'the refused request must not start another worker');
    }

    public function test_a_connector_blocked_by_a_sync_nobody_picked_up_is_freed_by_the_next_sync_request(): void
    {
        Queue::fake();
        config(['queue.default' => 'database']);
        $connector = $this->connector();
        $lost = $this->sync($connector, 'queued', DataConnectorSyncReaper::QUEUED_GRACE_SECONDS + 5);

        $this->postJson("/api/signals/ingestion/connectors/{$connector->id}/sync", [], $this->h())->assertStatus(202);

        $this->assertSame('failed', $lost->fresh()->status);
        $this->assertSame(DataConnectorSyncReaper::WORKER_MESSAGE, $lost->fresh()->error_message);
        $this->assertSame(2, DataConnectorSync::count(), 'history is kept: the lost row stays, a new one is added');
        Queue::assertPushed(SyncDataConnectorJob::class, 1);
    }

    // ── Stale recovery: what is recovered ────────────────────────────────────

    public function test_the_running_threshold_follows_the_job_timeout_and_the_queue_retry_after(): void
    {
        $this->assertSame(900, (new SyncDataConnectorJob(1, 1, 1, null))->timeout);
        $this->assertSame(960 + 60, $this->threshold());

        config(['queue.connections.database.retry_after' => 100]);
        $this->assertSame(900 + 60, $this->threshold(), 'never below the job timeout');
    }

    public function test_a_genuinely_stale_running_sync_is_closed_with_a_reason_and_its_connector_is_unblocked(): void
    {
        Queue::fake();
        Http::fake();
        $connector = $this->connector(['schedule_enabled' => true, 'schedule_interval_minutes' => 60, 'schedule_timezone' => 'UTC', 'next_sync_at' => now()->subHour()]);
        $stale = $this->sync($connector, 'running', $this->threshold() + 30, ['records_fetched' => 5, 'records_failed' => 0]);
        // What a running job leaves behind when it dies: its lock, and the record of whose lock it is.
        $deadWorkersLock = Cache::lock(SyncDataConnectorJob::lockName((int) $connector->sub_institute_id, (int) $connector->id), 1800);
        $this->assertTrue($deadWorkersLock->get(), 'the dead worker left its lock behind');
        Cache::put(SyncDataConnectorJob::lockOwnerKey((int) $stale->id), $deadWorkersLock->owner(), 2100);

        $this->assertSame(1, DataConnectorSyncReaper::sweep());

        $fresh = $stale->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertSame(DataConnectorSyncReaper::INTERRUPTED_MESSAGE, $fresh->error_message);
        $this->assertNotNull($fresh->completed_at);
        $this->assertSame(5, (int) $fresh->records_fetched, 'counters survive');
        $this->assertSame(5, (int) $fresh->records_failed);
        $connector->refresh();
        $this->assertSame(DataConnectorSyncReaper::INTERRUPTED_MESSAGE, $connector->last_error);
        $this->assertSame('connected', $connector->connection_status, 'a dead worker is not a broken account');
        $this->assertNull($connector->last_successful_sync_at);
        $this->assertTrue($connector->next_sync_at->isFuture(), 'no instant retry loop');
        $this->assertTrue(Cache::lock("signals:connector-sync:{$connector->sub_institute_id}:{$connector->id}", 1800)->get(), 'the stale lock was released');
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_a_sync_that_had_processed_items_before_it_was_lost_is_partial_not_failed(): void
    {
        $connector = $this->connector();
        $stale = $this->sync($connector, 'running', $this->threshold() + 1, ['records_fetched' => 4, 'records_processed' => 2]);

        DataConnectorSyncReaper::sweep();

        $this->assertSame('partial', $stale->fresh()->status);
        $this->assertSame(2, (int) $stale->fresh()->records_processed);
    }

    public function test_a_queue_that_nobody_drains_closes_the_sync_with_the_worker_command(): void
    {
        $connector = $this->connector();
        $waiting = $this->sync($connector, 'queued', DataConnectorSyncReaper::QUEUED_GRACE_SECONDS + 1);

        $this->assertSame(1, DataConnectorSyncReaper::sweep());

        $this->assertSame('failed', $waiting->fresh()->status);
        $this->assertStringContainsString('queue:work --queue=signals --tries=4 --timeout=900', $waiting->fresh()->error_message);
    }

    // ── Stale recovery: what must NOT be recovered ───────────────────────────

    public function test_recent_and_just_inside_the_threshold_syncs_are_left_alone(): void
    {
        $connector = $this->connector();
        $recent = $this->sync($connector, 'running', 120);
        $edge = $this->sync($connector, 'running', $this->threshold() - 2);
        $queued = $this->sync($connector, 'queued', DataConnectorSyncReaper::QUEUED_GRACE_SECONDS - 5);

        $this->assertSame(0, DataConnectorSyncReaper::sweep());

        foreach ([$recent, $edge] as $sync) {
            $this->assertSame('running', $sync->fresh()->status);
        }
        $this->assertSame('queued', $queued->fresh()->status);
    }

    public function test_a_long_running_sync_that_keeps_writing_is_never_treated_as_stale(): void
    {
        $connector = $this->connector();
        // Started far longer ago than the threshold, but its job writes a heartbeat before each item.
        $active = $this->sync($connector, 'running', 5000);
        $active->touch();

        $this->assertSame(0, DataConnectorSyncReaper::sweep());
        $this->assertSame('running', $active->fresh()->status);

        // And a sync waiting to retry after a rate limit is not killed during its release delay.
        $retrying = $this->sync($connector, 'queued', DataConnectorSyncReaper::QUEUED_GRACE_SECONDS + 100, ['error_message' => 'The platform rate limit was reached.']);
        $this->assertSame(0, DataConnectorSyncReaper::sweep());
        $this->assertSame('queued', $retrying->fresh()->status);

        // ...but is closed once even the longest retry delay plus the grace has passed.
        DB::table('g2g_data_connector_syncs')->where('id', $retrying->id)->update(['updated_at' => now()->subSeconds(DataConnectorSyncReaper::QUEUED_GRACE_SECONDS + DataConnectorSyncReaper::MAX_RETRY_DELAY_SECONDS + 5)]);
        $this->assertSame(1, DataConnectorSyncReaper::sweep());
    }

    public function test_finished_syncs_are_never_touched_however_old_they_are(): void
    {
        $connector = $this->connector();
        $rows = [];
        foreach (['completed', 'partial', 'failed', 'skipped'] as $status) {
            $rows[$status] = $this->sync($connector, $status, 86400 * 30, ['error_message' => $status === 'failed' ? 'original reason' : null, 'completed_at' => now()->subDays(30)]);
        }
        $before = DB::table('g2g_data_connector_syncs')->orderBy('id')->get()->all();

        $this->assertSame(0, DataConnectorSyncReaper::sweep());

        $this->assertEquals($before, DB::table('g2g_data_connector_syncs')->orderBy('id')->get()->all());
        $this->assertSame('original reason', $rows['failed']->fresh()->error_message);
    }

    public function test_a_successful_sync_is_not_failed_by_a_later_recovery_run_even_days_later(): void
    {
        $connector = $this->connector();
        $sync = $this->sync($connector, 'running', 10);
        $this->invoke($this->jobFor($connector, $sync), 'finish', [$connector, $sync, 'completed', null]);
        $this->assertSame('completed', $sync->fresh()->status);

        Carbon::setTestNow(now()->addDays(3));
        $this->assertSame(0, DataConnectorSyncReaper::sweep());
        $this->assertSame('completed', $sync->fresh()->status);
        $this->assertNull($connector->fresh()->last_error);
    }

    // ── Idempotency, concurrency, isolation ──────────────────────────────────

    public function test_recovery_is_idempotent(): void
    {
        $connector = $this->connector();
        $stale = $this->sync($connector, 'running', $this->threshold() + 5);

        $this->assertSame(1, DataConnectorSyncReaper::sweep());
        $first = DB::table('g2g_data_connector_syncs')->where('id', $stale->id)->first();
        Carbon::setTestNow(now()->addMinutes(5));
        $this->assertSame(0, DataConnectorSyncReaper::sweep());
        $this->assertEquals($first, DB::table('g2g_data_connector_syncs')->where('id', $stale->id)->first(), 'a second sweep changes nothing');
    }

    public function test_two_sweeps_holding_the_same_candidate_close_it_only_once_and_never_a_row_that_finished_meanwhile(): void
    {
        $connector = $this->connector();
        $stale = $this->sync($connector, 'running', $this->threshold() + 5);
        $racing = $this->sync($connector, 'running', $this->threshold() + 5);
        $runningCutoff = now()->subSeconds($this->threshold());
        $queuedCutoff = now()->subSeconds(DataConnectorSyncReaper::QUEUED_GRACE_SECONDS);
        $retryCutoff = now()->subSeconds(DataConnectorSyncReaper::QUEUED_GRACE_SECONDS + DataConnectorSyncReaper::MAX_RETRY_DELAY_SECONDS);
        $recover = new ReflectionMethod(DataConnectorSyncReaper::class, 'recover');

        // Two scheduler instances read the same candidate; only one claim can succeed.
        $this->assertTrue($recover->invoke(null, $stale->fresh(), $runningCutoff, $queuedCutoff, $retryCutoff));
        $this->assertFalse($recover->invoke(null, $stale->fresh(), $runningCutoff, $queuedCutoff, $retryCutoff));
        $this->assertFalse($recover->invoke(null, $stale, $runningCutoff, $queuedCutoff, $retryCutoff), 'even from a stale in-memory copy');

        // The job finishes after the sweep read its candidate but before the sweep wrote.
        $candidate = $racing->fresh();
        DB::table('g2g_data_connector_syncs')->where('id', $racing->id)->update(['status' => 'completed', 'updated_at' => now()]);
        $this->assertFalse($recover->invoke(null, $candidate, $runningCutoff, $queuedCutoff, $retryCutoff));
        $this->assertSame('completed', $racing->fresh()->status);
    }

    public function test_recovery_keeps_every_history_row_and_stays_inside_its_tenant(): void
    {
        $mine = $this->connector();
        $other = $this->connector(['sub_institute_id' => self::T2]);
        $this->sync($mine, 'completed', 9000);
        $this->sync($mine, 'running', $this->threshold() + 5);
        $otherStale = $this->sync($other, 'running', $this->threshold() + 5);

        $this->assertSame(1, DataConnectorSyncReaper::sweep(self::T1), 'a tenant sweep only closes that tenant');
        $this->assertSame('running', $otherStale->fresh()->status);
        $this->assertSame(2, DataConnectorSync::where('sub_institute_id', self::T1)->count());

        $this->assertSame(1, DataConnectorSyncReaper::sweep(), 'the scheduler sweep covers everyone');
        $this->assertSame('failed', $otherStale->fresh()->status);
        $this->assertNull($mine->fresh()->last_successful_sync_at);
        $this->assertSame(DataConnectorSyncReaper::INTERRUPTED_MESSAGE, $other->fresh()->last_error);
        $this->assertNotSame(DataConnectorSyncReaper::INTERRUPTED_MESSAGE, $other->fresh()->name);
    }

    public function test_recovery_never_ingests_analyses_dispatches_or_calls_a_provider(): void
    {
        Queue::fake();
        Http::fake();
        $connector = $this->connector(['type' => 'youtube', 'credentials' => ['access_token' => 'tok'], 'external_account_id' => 'UC1']);
        $this->sync($connector, 'running', $this->threshold() + 5);
        $this->sync($connector, 'queued', 5000);

        $this->assertSame(2, DataConnectorSyncReaper::sweep());

        $this->assertSame(0, IngestionSource::count());
        $this->assertSame(0, DB::table('g2g_ingestion_analyses')->count());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    // ── Entry points: scheduler and API ──────────────────────────────────────

    public function test_the_scheduled_command_recovers_stale_syncs_even_when_scheduled_dispatch_is_switched_off(): void
    {
        Queue::fake();
        $connector = $this->connector();
        $stale = $this->sync($connector, 'running', $this->threshold() + 5);
        config(['signals_connectors.schedule_enabled' => false]);

        Artisan::call('signals:connectors:sync-scheduled');

        $this->assertSame('failed', $stale->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_the_connector_list_closes_a_lost_sync_so_the_screen_never_shows_running_forever(): void
    {
        $connector = $this->connector();
        $stale = $this->sync($connector, 'running', $this->threshold() + 5);

        $row = $this->getJson('/api/signals/ingestion/connectors', $this->h())->assertOk()->json('data.0');

        $this->assertSame('failed', $row['history'][0]['status']);
        $this->assertSame(DataConnectorSyncReaper::INTERRUPTED_MESSAGE, $row['history'][0]['error_message']);
        $this->assertSame(DataConnectorSyncReaper::INTERRUPTED_MESSAGE, $row['last_error']);
        $this->assertSame('failed', $stale->fresh()->status);
    }

    // ── Retry safety ─────────────────────────────────────────────────────────

    public function test_a_job_retried_after_its_sync_was_recovered_does_nothing_and_cannot_resurrect_it(): void
    {
        Http::fake();
        $connector = $this->connector();
        $sync = $this->sync($connector, 'running', $this->threshold() + 5);
        DataConnectorSyncReaper::sweep();
        $closed = DB::table('g2g_data_connector_syncs')->where('id', $sync->id)->first();

        // The queue now retries the original job.
        $this->runJob($connector, $sync);

        $this->assertEquals($closed, DB::table('g2g_data_connector_syncs')->where('id', $sync->id)->first());
        $this->assertSame(0, IngestionSource::count(), 'no duplicate ingestion');
        $this->assertSame(0, DB::table('g2g_ingestion_analyses')->count(), 'no duplicate analysis');
        Http::assertNothingSent();
    }

    public function test_a_slow_job_that_finishes_after_it_was_recovered_cannot_overwrite_the_recovery(): void
    {
        $connector = $this->connector();
        $sync = $this->sync($connector, 'running', $this->threshold() + 5);
        DataConnectorSyncReaper::sweep();
        $connector->refresh();
        $lastError = $connector->last_error;

        // The "lost" worker was only slow and now reports success from its stale in-memory copy.
        $this->invoke($this->jobFor($connector, $sync), 'finish', [$connector, $sync, 'completed', null]);

        $this->assertSame('failed', $sync->fresh()->status);
        $this->assertSame(DataConnectorSyncReaper::INTERRUPTED_MESSAGE, $sync->fresh()->error_message);
        $this->assertNull($connector->fresh()->last_successful_sync_at, 'the late writer did not touch the connector either');
        $this->assertSame($lastError, $connector->fresh()->last_error);
    }

    public function test_a_retry_re_queue_never_resurrects_a_closed_sync_but_does_requeue_an_open_one(): void
    {
        $connector = $this->connector();
        $closed = $this->sync($connector, 'failed', 10, ['error_message' => 'closed']);
        $open = $this->sync($connector, 'running', 10);
        $job = $this->jobFor($connector, $open);

        $this->invoke($job, 'requeue', [$closed, 'retry later']);
        $this->invoke($job, 'requeue', [$open, 'retry later']);

        $this->assertSame(['failed', 'closed'], [$closed->fresh()->status, $closed->fresh()->error_message]);
        $this->assertSame(['queued', 'retry later'], [$open->fresh()->status, $open->fresh()->error_message]);
    }

    public function test_two_workers_holding_the_same_job_cannot_both_run_it(): void
    {
        Http::fake();
        $connector = $this->connector(['type' => 'youtube', 'credentials' => ['access_token' => 'tok'], 'external_account_id' => 'UC1']);
        $sync = $this->sync($connector, 'running', 10);   // another worker already claimed it

        $this->runJob($connector, $sync);

        $this->assertSame('running', $sync->fresh()->status, 'the second worker left the first worker\'s sync alone');
        Http::assertNothingSent();
    }

    // ── Existing provider failure behaviour is intact ────────────────────────

    public function test_an_expired_token_still_ends_the_sync_as_failed_and_the_connector_as_token_expired(): void
    {
        Http::fake();
        $connector = $this->connector(['type' => 'youtube', 'external_account_id' => 'UC1', 'credentials' => [
            'access_token' => 'old', 'expires_at' => date(DATE_ATOM, time() - 60),   // the token manager reads the real clock, not Carbon's frozen one
        ]]);
        $sync = $this->sync($connector, 'queued', 5);

        $this->runJob($connector, $sync);

        $this->assertSame('failed', $sync->fresh()->status);
        $this->assertSame('token_expired', $connector->fresh()->connection_status);
        $this->assertStringContainsString('Reconnect', $sync->fresh()->error_message);
        Http::assertNothingSent();
    }

    public function test_a_rate_limit_still_requeues_the_sync_and_marks_the_connector_rate_limited(): void
    {
        Http::fake(['*' => Http::response('slow down', 429, ['Retry-After' => '120'])]);
        $connector = $this->connector(['type' => 'youtube', 'external_account_id' => 'UC1', 'credentials' => ['access_token' => 'tok']]);
        $sync = $this->sync($connector, 'queued', 5);

        $this->runJob($connector, $sync);

        $this->assertSame('queued', $sync->fresh()->status);
        $this->assertStringContainsString('rate limit', $sync->fresh()->error_message);
        $this->assertSame('rate_limited', $connector->fresh()->connection_status);
    }

    // ── A failing recovery sweep never blocks the operation it runs inside ───

    /** Makes the sweep's own candidate query throw, once: the first DataConnectorSync row loaded anywhere throws. */
    private function failTheNextSweep(): void
    {
        $thrown = false;
        DataConnectorSync::retrieved(function () use (&$thrown) {
            if (! $thrown) {
                $thrown = true;

                throw new \RuntimeException('simulated sweep failure');
            }
        });
    }

    private function assertSweepFailureWasLogged(): void
    {
        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context = []) => $message === 'signals.connector_sync_sweep_failed'
            && ($context['exception'] ?? null) === \RuntimeException::class
            && ($context['message'] ?? null) === 'simulated sweep failure')->once();
    }

    public function test_a_failing_sweep_does_not_break_the_connector_list_and_nothing_is_reported_recovered(): void
    {
        Log::spy();
        $connector = $this->connector();
        $stale = $this->sync($connector, 'running', $this->threshold() + 5);
        $this->failTheNextSweep();

        $row = $this->getJson('/api/signals/ingestion/connectors', $this->h())->assertOk()->json('data.0');

        $this->assertSame($connector->id, $row['id']);
        $this->assertSame('running', $row['history'][0]['status'], 'truthful: the sync was not recovered, so it is not shown as recovered');
        $this->assertSame('running', $stale->fresh()->status);
        $this->assertNull($connector->fresh()->last_error);
        $this->assertSweepFailureWasLogged();
        Log::shouldNotHaveReceived('warning', fn ($m) => $m === 'signals.connector_sync_recovered');
    }

    public function test_a_failing_sweep_does_not_stop_sync_now(): void
    {
        Queue::fake();
        Log::spy();
        config(['queue.default' => 'database']);
        $healthy = $this->connector();
        $other = $this->connector(['name' => 'Other']);
        $this->sync($other, 'running', $this->threshold() + 5);   // gives the sweep a candidate to fail on
        $this->failTheNextSweep();

        $this->postJson("/api/signals/ingestion/connectors/{$healthy->id}/sync", [], $this->h())
            ->assertStatus(202)->assertJsonPath('data.status', 'queued');

        Queue::assertPushed(SyncDataConnectorJob::class, 1);
        $this->assertSweepFailureWasLogged();
    }

    public function test_when_the_sweep_fails_a_genuinely_stale_sync_still_blocks_with_the_normal_answer_not_a_crash(): void
    {
        Queue::fake();
        Log::spy();
        $connector = $this->connector();
        $stale = $this->sync($connector, 'running', $this->threshold() + 5);
        $this->failTheNextSweep();

        $this->postJson("/api/signals/ingestion/connectors/{$connector->id}/sync", [], $this->h())
            ->assertStatus(409)->assertJsonPath('message', 'A sync is already running for this connector.');

        $this->assertSame('running', $stale->fresh()->status, 'not recovered, and not claimed to be');
        Queue::assertNothingPushed();
        $this->assertSweepFailureWasLogged();
    }

    public function test_a_failing_sweep_does_not_stop_scheduled_dispatch(): void
    {
        Queue::fake();
        Log::spy();
        $stale = $this->sync($this->connector(['name' => 'Stuck']), 'running', $this->threshold() + 5);
        $due = $this->connector(['name' => 'Due', 'schedule_enabled' => true, 'schedule_interval_minutes' => 60, 'schedule_timezone' => 'UTC', 'next_sync_at' => now()->subMinute()]);
        $this->failTheNextSweep();

        $exit = Artisan::call('signals:connectors:sync-scheduled');

        $this->assertSame(0, $exit);
        Queue::assertPushed(SyncDataConnectorJob::class, fn (SyncDataConnectorJob $job) => $job->connectorId === $due->id);
        $this->assertSame('running', $stale->fresh()->status);
        $this->assertSweepFailureWasLogged();
    }

    public function test_cleanup_that_fails_after_a_sync_was_closed_does_not_undo_or_hide_the_close(): void
    {
        Log::spy();
        $connector = $this->connector();
        $stale = $this->sync($connector, 'running', $this->threshold() + 5);
        // the lock store breaks while the sweep frees the lost worker's lock
        Cache::put(SyncDataConnectorJob::lockOwnerKey((int) $stale->id), 'some-owner', 600);
        Cache::shouldReceive('restoreLock')->andThrow(new \RuntimeException('cache down'));
        Cache::makePartial();

        $closed = DataConnectorSyncReaper::sweepSafely();

        $this->assertSame(1, $closed);
        $this->assertSame('failed', $stale->fresh()->status, 'the row really is closed, and says so');
        Log::shouldHaveReceived('error')->withArgs(fn (string $m) => $m === 'signals.connector_sync_recovery_cleanup_failed')->once();
    }

    // ── Lock ownership and zombie jobs ───────────────────────────────────────

    private function takeLock(DataConnector $connector, DataConnectorSync $sync): \Illuminate\Contracts\Cache\Lock
    {
        $lock = Cache::lock(SyncDataConnectorJob::lockName((int) $connector->sub_institute_id, (int) $connector->id), 1800);
        $this->assertTrue($lock->get());
        Cache::put(SyncDataConnectorJob::lockOwnerKey((int) $sync->id), $lock->owner(), 2100);

        return $lock;
    }

    private function lockIsFree(DataConnector $connector): bool
    {
        $probe = Cache::lock(SyncDataConnectorJob::lockName((int) $connector->sub_institute_id, (int) $connector->id), 1800);
        $free = $probe->get();
        if ($free) {
            $probe->release();
        }

        return $free;
    }

    public function test_the_sweep_frees_the_lost_syncs_own_lock_and_forgets_its_owner_record(): void
    {
        $connector = $this->connector();
        $lost = $this->sync($connector, 'running', $this->threshold() + 5);
        $this->takeLock($connector, $lost);

        DataConnectorSyncReaper::sweep();

        $this->assertTrue($this->lockIsFree($connector));
        $this->assertNull(Cache::get(SyncDataConnectorJob::lockOwnerKey((int) $lost->id)));
    }

    public function test_the_sweep_never_frees_a_lock_that_a_newer_sync_has_taken(): void
    {
        $connector = $this->connector();
        $lost = $this->sync($connector, 'running', $this->threshold() + 5);
        $oldLock = $this->takeLock($connector, $lost);
        // the old lock expired by TTL; a newer sync took the connector and its own lock
        Cache::lock(SyncDataConnectorJob::lockName((int) $connector->sub_institute_id, (int) $connector->id))->forceRelease();
        $newer = $this->sync($connector, 'running', 1);
        $newerLock = $this->takeLock($connector, $newer);

        DataConnectorSyncReaper::sweep();

        $this->assertSame('failed', $lost->fresh()->status);
        $this->assertFalse($this->lockIsFree($connector), 'the newer sync still holds its lock');
        $this->assertSame('running', $newer->fresh()->status);
        $this->assertNotNull(Cache::get(SyncDataConnectorJob::lockOwnerKey((int) $newer->id)), "the newer sync's record is untouched");
        $this->assertFalse($oldLock->release(), 'and the old job cannot release it either');
        $this->assertFalse($this->lockIsFree($connector));
        $this->assertTrue($newerLock->release(), 'only its owner can');
    }

    public function test_an_old_job_finishing_after_recovery_cannot_release_the_lock_of_the_sync_that_replaced_it(): void
    {
        $connector = $this->connector();
        $old = $this->sync($connector, 'running', $this->threshold() + 5);
        $oldLock = $this->takeLock($connector, $old);
        DataConnectorSyncReaper::sweep();                 // old sync recovered, lock freed
        $newer = $this->sync($connector, 'queued', 1);
        $newerLock = $this->takeLock($connector, $newer); // new sync acquires the lock after recovery

        $this->assertFalse($oldLock->release(), 'the zombie job releasing "its" lock is a no-op');

        $this->assertFalse($this->lockIsFree($connector));
        $this->assertTrue($newerLock->release());
    }

    public function test_repeated_sweeps_change_nothing_after_the_first_and_leave_a_newer_sync_alone(): void
    {
        $connector = $this->connector();
        $lost = $this->sync($connector, 'running', $this->threshold() + 5);
        $this->takeLock($connector, $lost);
        $this->assertSame(1, DataConnectorSyncReaper::sweep());
        $closedRow = DB::table('g2g_data_connector_syncs')->where('id', $lost->id)->first();
        $newer = $this->sync($connector, 'running', 3);
        $newerLock = $this->takeLock($connector, $newer);

        $this->assertSame(0, DataConnectorSyncReaper::sweep());
        $this->assertSame(0, DataConnectorSyncReaper::sweep());

        $this->assertEquals($closedRow, DB::table('g2g_data_connector_syncs')->where('id', $lost->id)->first());
        $this->assertSame('running', $newer->fresh()->status);
        $this->assertFalse($this->lockIsFree($connector));
        $this->assertTrue($newerLock->release());
    }

    public function test_a_zombie_job_stops_at_its_next_checkpoint_and_writes_nothing_over_the_sync_that_replaced_it(): void
    {
        Log::spy();
        $connector = $this->connector();
        $zombie = $this->sync($connector, 'queued', 5);
        $newer = null;
        $newerLock = null;
        $html = '<html><head><title>Acme</title></head><body><p>' . str_repeat('Acme builds school software for districts across the region. ', 5) . '</p></body></html>';
        Http::fake(function ($request) use ($connector, $zombie, $html, &$newer, &$newerLock) {
            if (str_ends_with($request->url(), '/robots.txt')) {
                return Http::response("User-agent: *\nAllow: /", 200, ['Content-Type' => 'text/plain']);
            }
            // While the page is being fetched, this job is presumed lost: its sync is closed, its lock lapsed, and a newer sync
            // took the connector and its lock.
            DB::table('g2g_data_connector_syncs')->where('id', $zombie->id)->update([
                'status' => 'failed', 'error_message' => DataConnectorSyncReaper::INTERRUPTED_MESSAGE, 'completed_at' => now(),
            ]);
            Cache::lock(SyncDataConnectorJob::lockName((int) $connector->sub_institute_id, (int) $connector->id))->forceRelease();
            $newer = $this->sync($connector, 'running', 1);
            $newerLock = $this->takeLock($connector, $newer);

            return Http::response($html, 200, ['Content-Type' => 'text/html']);
        });

        $this->runJob($connector, $zombie);

        $this->assertSame('failed', $zombie->fresh()->status);
        $this->assertSame(DataConnectorSyncReaper::INTERRUPTED_MESSAGE, $zombie->fresh()->error_message, 'not overwritten by the late job');
        $this->assertSame(0, DB::table('g2g_ingestion_analyses')->count(), 'it did not go on to analyse (and bill) anything');
        $this->assertSame('running', $newer->fresh()->status);
        $this->assertSame(0, (int) $newer->fresh()->records_processed);
        $this->assertNull($connector->fresh()->last_error, 'and did not write to the connector');
        $this->assertSame('connected', $connector->fresh()->connection_status);
        $this->assertFalse($this->lockIsFree($connector), "the newer sync's lock survived the zombie's finally block");
        $this->assertNotNull(Cache::get(SyncDataConnectorJob::lockOwnerKey((int) $newer->id)));
        Log::shouldHaveReceived('warning')->withArgs(fn (string $m) => $m === 'signals.connector_sync_abandoned')->once();
        $this->assertTrue($newerLock->release());
    }

    // ── Heartbeat ────────────────────────────────────────────────────────────

    public function test_a_heartbeat_refreshes_a_running_sync_and_is_throttled_unless_forced(): void
    {
        $connector = $this->connector();
        $sync = $this->sync($connector, 'running', 500);
        $job = $this->jobFor($connector, $sync);
        $stamp = fn () => DB::table('g2g_data_connector_syncs')->where('id', $sync->id)->value('updated_at');

        $this->invoke($job, 'heartbeat', [$sync]);
        $this->assertSame(now()->format('Y-m-d H:i:s'), substr((string) $stamp(), 0, 19), 'first beat writes');

        DB::table('g2g_data_connector_syncs')->where('id', $sync->id)->update(['updated_at' => now()->subSeconds(500)]);
        $this->invoke($job, 'heartbeat', [$sync]);
        $this->assertSame(now()->subSeconds(500)->format('Y-m-d H:i:s'), substr((string) $stamp(), 0, 19), 'a second beat inside the throttle window writes nothing');

        $this->invoke($job, 'heartbeat', [$sync, true]);
        $this->assertSame(now()->format('Y-m-d H:i:s'), substr((string) $stamp(), 0, 19), 'a forced beat always writes');
    }

    public function test_a_heartbeat_cannot_reopen_or_modify_a_closed_sync_and_tells_the_job_to_stop(): void
    {
        $connector = $this->connector();
        foreach (['failed', 'completed', 'partial', 'skipped', 'queued'] as $status) {
            $sync = $this->sync($connector, $status, 500, ['error_message' => 'closed row']);
            $before = DB::table('g2g_data_connector_syncs')->where('id', $sync->id)->first();

            try {
                $this->invoke($this->jobFor($connector, $sync), 'heartbeat', [$sync, true]);
                $this->fail("a {$status} sync accepted a heartbeat");
            } catch (SyncNoLongerActiveException) {
                // expected
            }

            $this->assertEquals($before, DB::table('g2g_data_connector_syncs')->where('id', $sync->id)->first(), "{$status}: untouched");
        }
    }

    public function test_the_analyzer_beats_before_each_ai_chunk_and_a_closed_sync_stops_it_without_an_ai_error(): void
    {
        $html = '<html><head><title>Acme</title></head><body><p>' . str_repeat('Acme builds school software for districts across the region. ', 5) . '</p></body></html>';
        // A fresh response per request: one shared Http::response() object has its body consumed by the robots.txt read.
        Http::fake(fn () => Http::response($html, 200, ['Content-Type' => 'text/html']));
        $source = app(IngestionService::class)->ingestUrl(self::T1, 1, 'https://news.example.com/acme');
        $ai = new class extends StructuredAi {
            public int $calls = 0;

            public function __construct()
            {
            }

            public function json(int $tenantId, array $messages, ?int $maxTokens = null, ?callable $beforeAttempt = null): array
            {
                if ($beforeAttempt !== null) {
                    $beforeAttempt();   // what the real StructuredAi does before each provider attempt
                }
                $this->calls++;

                throw new \RuntimeException('provider unavailable');
            }
        };

        // 1. a live sync: the beat is called, once per chunk
        $beats = 0;
        $analysis = (new IngestionAnalyzer($ai))->analyze($source, IngestionAnalyzer::begin($source, 1), function () use (&$beats) {
            $beats++;
        });
        $this->assertGreaterThanOrEqual(1, $beats);
        $this->assertSame($ai->calls * 2, $beats, 'one beat before each chunk and one before each provider attempt');
        $this->assertSame('failed', $analysis->status, 'the provider error is still reported as a provider error');

        // 2. a closed sync: the beat throws, nothing more is sent to the AI, the analysis row is closed (not left "running")
        $callsBefore = $ai->calls;
        $second = IngestionAnalyzer::begin($source, 1);
        try {
            (new IngestionAnalyzer($ai))->analyze($source, $second, function () {
                throw new SyncNoLongerActiveException();
            });
            $this->fail('the analyzer swallowed the stop signal');
        } catch (SyncNoLongerActiveException) {
            // expected
        }
        $this->assertSame($callsBefore, $ai->calls, 'no AI call was made after the stop signal');
        $this->assertSame('failed', $second->fresh()->status);
    }

    // ── Queue retry_after follows the ACTIVE connection ──────────────────────

    public function test_the_threshold_uses_the_active_database_queues_retry_after(): void
    {
        config(['queue.default' => 'database', 'queue.connections.database.retry_after' => 960, 'queue.connections.redis.retry_after' => 5000]);

        $this->assertSame(1020, $this->threshold());
        $this->assertSame(['connection' => 'database', 'driver' => 'database', 'retry_after' => 960, 'job_timeout' => 900, 'safe' => true], DataConnectorSyncReaper::queueDiagnostics());
    }

    public function test_the_threshold_does_not_silently_use_the_database_retry_after_when_another_driver_is_active(): void
    {
        config([
            'queue.default' => 'redis',
            'queue.connections.database.retry_after' => 5000,   // must be ignored
            'queue.connections.redis.driver' => 'redis',
            'queue.connections.redis.retry_after' => 1200,
        ]);

        $this->assertSame(1200 + 60, $this->threshold(), "redis's own retry_after, not the database one");
        $this->assertSame('redis', DataConnectorSyncReaper::queueDiagnostics()['connection']);

        config(['queue.connections.redis.retry_after' => 100]);
        $this->assertSame(900 + 60, $this->threshold(), 'never below the job timeout');
    }

    public function test_beanstalkd_is_read_from_its_own_connection(): void
    {
        config(['queue.default' => 'beanstalkd', 'queue.connections.beanstalkd.driver' => 'beanstalkd', 'queue.connections.beanstalkd.retry_after' => 2000]);

        $this->assertSame(2060, $this->threshold());
    }

    public function test_a_queue_without_a_retry_after_uses_the_job_timeout_plus_grace_only(): void
    {
        config(['queue.default' => 'sync', 'queue.connections.database.retry_after' => 5000]);
        $this->assertSame(960, $this->threshold());
        $this->assertNull(DataConnectorSyncReaper::queueDiagnostics()['retry_after']);
        $this->assertTrue(DataConnectorSyncReaper::queueDiagnostics()['safe']);

        config(['queue.default' => 'sqs', 'queue.connections.sqs.driver' => 'sqs']);
        $this->assertSame(960, $this->threshold(), 'sqs keeps its visibility timeout on the queue, not in this app: unknown, so only the job timeout is used');
        $this->assertNull(DataConnectorSyncReaper::queueDiagnostics()['retry_after']);
    }

    public function test_an_active_queue_that_would_redeliver_a_working_job_is_flagged_once_per_hour_not_silently_accepted(): void
    {
        Log::spy();
        config(['queue.default' => 'redis', 'queue.connections.redis.driver' => 'redis', 'queue.connections.redis.retry_after' => 90]);

        $this->assertFalse(DataConnectorSyncReaper::queueDiagnostics()['safe']);
        DataConnectorSyncReaper::sweep();
        DataConnectorSyncReaper::sweep();

        Log::shouldHaveReceived('warning')->withArgs(fn (string $m, array $c = []) => $m === 'signals.connector_queue_retry_after_unsafe' && $c['retry_after'] === 90)->once();
    }

    // ── Pagination bound ─────────────────────────────────────────────────────

    private function facebookConnector(): DataConnector
    {
        return $this->connector([
            'type' => 'facebook_page', 'name' => 'Our Page', 'url' => 'https://www.facebook.com/ourpage', 'external_account_id' => 'PAGE1',
            'credentials' => ['access_token' => 'tok'],
        ]);
    }

    public function test_a_facebook_feed_full_of_other_peoples_posts_is_paged_only_up_to_the_page_limit(): void
    {
        $requests = 0;
        Http::fake(function ($request) use (&$requests) {
            $requests++;

            return Http::response(['data' => [['id' => "x_{$requests}", 'message' => 'Not ours', 'permalink_url' => 'https://f.example/1', 'from' => ['id' => 'someone-else'], 'is_published' => true]],
                'paging' => ['cursors' => ['after' => "cursor-{$requests}"]]], 200);
        });
        $adapter = app(DataConnectorRegistry::class)->get('facebook_page');

        config(['signals_connectors.max_fetch_pages' => 3]);
        $this->assertSame([], $adapter->fetch($this->facebookConnector()));
        $this->assertSame(3, $requests, 'the configured limit');

        $requests = 0;
        config(['signals_connectors.max_fetch_pages' => 0]);   // blank/zero never means "unlimited"
        $adapter->fetch($this->facebookConnector());
        $this->assertSame(10, $requests, 'the default limit');
    }

    public function test_facebook_still_filters_and_stops_early_when_the_feed_ends(): void
    {
        $requests = 0;
        Http::fake(function () use (&$requests) {
            $requests++;

            return Http::response(['data' => [
                ['id' => 'PAGE1_1', 'message' => 'Our own published announcement about the new term and fees.', 'permalink_url' => 'https://f.example/1', 'from' => ['id' => 'PAGE1'], 'is_published' => true],
                ['id' => 'PAGE1_2', 'message' => 'Not ours', 'permalink_url' => 'https://f.example/2', 'from' => ['id' => 'someone-else'], 'is_published' => true],
                ['id' => 'PAGE1_3', 'message' => 'Our draft', 'permalink_url' => 'https://f.example/3', 'from' => ['id' => 'PAGE1'], 'is_published' => false],
            ]], 200);   // no paging cursor: the feed ends
        });

        $items = app(DataConnectorRegistry::class)->get('facebook_page')->fetch($this->facebookConnector());

        $this->assertSame(1, $requests);
        $this->assertCount(1, $items);
        $this->assertSame('PAGE1_1', $items[0]->externalId);
    }

    // ── Website fetch inside a sync (real transport, local server) ───────────

    public function test_a_website_that_never_finishes_ends_the_sync_failed_with_an_honest_message_inside_the_budget(): void
    {
        $this->startSlowServer();
        config(['signals.ingestion.min_host_interval_ms' => 0, 'signals.ingestion.url_timeout' => 2, 'signals.ingestion.url_operation_timeout' => 8]);
        $this->app->instance(SafeUrlFetcher::class, $this->pinnedFetcher());
        $connector = $this->connector(['url' => $this->slowUrl('/drip')]);
        $sync = $this->sync($connector, 'queued', 5);
        $started = microtime(true);

        $this->runJob($connector, $sync);

        $this->assertLessThan(6.0, microtime(true) - $started, 'the sync did not wait for the 20 s the server drips');
        $this->assertSame('failed', $sync->fresh()->status);
        $this->assertSame('The website did not respond in time.', $sync->fresh()->error_message);
        $this->assertGreaterThanOrEqual(1, (int) $sync->fresh()->records_failed);
        $this->assertNotNull($sync->fresh()->completed_at);
        $this->assertSame('The website did not respond in time.', $connector->fresh()->last_error);
        $this->assertSame('error', $connector->fresh()->connection_status);
        $this->assertSame(0, IngestionSource::count(), 'nothing was ingested');
        $this->assertTrue($this->lockIsFree($connector), 'the job released its own lock');
    }

    // ── Tenant isolation ─────────────────────────────────────────────────────

    public function test_another_tenants_user_cannot_act_on_a_connector_through_any_endpoint(): void
    {
        Queue::fake();
        Http::fake();
        $connector = $this->connector(['type' => 'youtube', 'external_account_id' => 'UC1', 'credentials' => ['access_token' => 'SECRET-TOKEN-1']]);
        $this->sync($connector, 'completed', 5000);
        $other = $this->h(self::T2);
        $before = DB::table('g2g_data_connectors')->where('id', $connector->id)->first();
        $syncsBefore = DB::table('g2g_data_connector_syncs')->count();
        $base = "/api/signals/ingestion/connectors/{$connector->id}";

        foreach ([
            ['putJson', $base, ['name' => 'Hijacked']],
            ['postJson', "{$base}/sync", []],
            ['postJson', "{$base}/oauth", []],
            ['postJson', "{$base}/connect", []],
            ['postJson', "{$base}/disconnect", []],
            ['postJson', "{$base}/schedule", ['schedule_enabled' => true, 'schedule_interval_minutes' => 60, 'schedule_timezone' => 'UTC']],
            ['putJson', "{$base}/account", ['external_account_id' => 'UC-OTHER']],
            ['postJson', "{$base}/accounts", ['account_id' => 'UC-OTHER']],
            ['deleteJson', $base, []],
        ] as [$method, $url, $body]) {
            $response = $this->{$method}($url, $body, $other);
            $this->assertContains($response->getStatusCode(), [404, 422], "{$method} {$url} answered {$response->getStatusCode()}");
            $this->assertStringNotContainsString('SECRET-TOKEN-1', $response->getContent());
        }

        $this->assertEquals($before, DB::table('g2g_data_connectors')->where('id', $connector->id)->first(), 'nothing about the connector or its credentials changed');
        $this->assertSame($syncsBefore, DB::table('g2g_data_connector_syncs')->count(), 'no sync was created for it');
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertSame([], $this->workerStarts);
    }

    public function test_the_list_never_shows_another_tenants_connectors_syncs_or_any_token(): void
    {
        $mine = $this->connector(['type' => 'youtube', 'external_account_id' => 'UC1', 'credentials' => ['access_token' => 'SECRET-TOKEN-1', 'refresh_token' => 'SECRET-REFRESH-1']]);
        $this->sync($mine, 'completed', 5000);

        $theirs = $this->getJson('/api/signals/ingestion/connectors', $this->h(self::T2));
        $ours = $this->getJson('/api/signals/ingestion/connectors', $this->h(self::T1));

        $this->assertSame([], $theirs->assertOk()->json('data'));
        foreach ([$theirs, $ours] as $response) {
            $this->assertStringNotContainsString('SECRET-TOKEN-1', $response->getContent());
            $this->assertStringNotContainsString('SECRET-REFRESH-1', $response->getContent());
        }
        $this->assertCount(1, $ours->json('data'));
    }

    public function test_a_job_cannot_run_a_connector_or_sync_that_belongs_to_another_tenant(): void
    {
        Http::fake();
        $connector = $this->connector(['type' => 'youtube', 'external_account_id' => 'UC1', 'credentials' => ['access_token' => 'tok']]);
        $sync = $this->sync($connector, 'queued', 5);

        // A job carrying the wrong tenant id for these rows finds neither of them.
        (new SyncDataConnectorJob(self::T2, (int) $connector->id, (int) $sync->id, 1))
            ->handle(app(DataConnectorRegistry::class), app(IngestionService::class), app(IngestionAnalyzer::class));

        $this->assertSame('queued', $sync->fresh()->status);
        Http::assertNothingSent();
        $this->assertTrue($this->lockIsFree($connector));
    }

    // ── Provider readiness is about structure, not just presence ─────────────

    private function setCredentials(array $google = [], array $linkedin = [], array $meta = []): void
    {
        config([
            'signals_connectors.google.client_id' => $google[0] ?? null, 'signals_connectors.google.client_secret' => $google[1] ?? null,
            'signals_connectors.linkedin.client_id' => $linkedin[0] ?? null, 'signals_connectors.linkedin.client_secret' => $linkedin[1] ?? null,
            'signals_connectors.meta.app_id' => $meta[0] ?? null, 'signals_connectors.meta.app_secret' => $meta[1] ?? null,
        ]);
    }

    public function test_a_provider_with_no_credentials_is_not_ready_and_names_only_the_missing_settings(): void
    {
        $this->setCredentials();
        $readiness = app(\App\Domain\Signals\Connectors\ConnectorProviderReadiness::class);

        $youtube = $readiness->check('youtube');
        $this->assertFalse($youtube['ready']);
        $this->assertSame('configuration_required', $youtube['status']);
        $this->assertSame(['YOUTUBE_OAUTH_CLIENT_ID', 'YOUTUBE_OAUTH_CLIENT_SECRET'], $youtube['missing_config']);
        $this->assertSame(['META_APP_ID', 'META_APP_SECRET'], $readiness->check('facebook_page')['missing_config']);
        $this->assertSame(['META_APP_ID', 'META_APP_SECRET'], $readiness->check('instagram_business')['missing_config']);
        $this->assertTrue($readiness->check('public_web_page')['ready'], 'the website connector needs no provider application');
    }

    public function test_a_setting_that_is_present_but_malformed_is_not_ready_and_its_value_is_never_returned(): void
    {
        $secret = 'not-a-valid-secret-value-xyz';
        $this->setCredentials(['12345-abc.apps.googleusercontent.com', 'tiny'], ['abc', $secret], ['abc', $secret]);
        $readiness = app(\App\Domain\Signals\Connectors\ConnectorProviderReadiness::class);

        foreach (['youtube' => ['YOUTUBE_OAUTH_CLIENT_SECRET'], 'linkedin' => ['LINKEDIN_CLIENT_ID'], 'facebook_page' => ['META_APP_ID', 'META_APP_SECRET']] as $type => $names) {
            $r = $readiness->check($type);
            $this->assertFalse($r['ready'], $type);
            $this->assertSame('configuration_invalid', $r['status'], $type);
            $this->assertSame($names, $r['invalid_config'], $type);
            $this->assertSame([], $r['missing_config'], $type);
            $this->assertStringNotContainsString($secret, json_encode($r), 'a value is never returned');
            $this->assertStringNotContainsString('tiny', json_encode($r));
        }
    }

    public function test_well_formed_settings_make_a_provider_ready_and_the_api_exposes_approval_notes_but_no_secret(): void
    {
        $secret = str_repeat('a', 32);
        $this->setCredentials(['1234567890-abcdef.apps.googleusercontent.com', 'GOCSPX-' . str_repeat('x', 20)], ['86abcdefghijkl', 'WPL_AP1.0123456789'], ['1234567890123456', $secret]);
        $readiness = app(\App\Domain\Signals\Connectors\ConnectorProviderReadiness::class);
        foreach (['youtube', 'linkedin', 'facebook_page', 'instagram_business'] as $type) {
            $this->assertTrue($readiness->check($type)['ready'], $type);
        }

        $body = $this->getJson('/api/signals/ingestion/connectors', $this->h())->assertOk();
        $available = collect($body->json('available'))->keyBy('key');
        $this->assertStringContainsString('Development tier', $available['linkedin']['approval']);
        $this->assertStringContainsString('Business Verification', $available['facebook_page']['approval']);
        $this->assertStringContainsString('Business or Creator', $available['instagram_business']['approval']);
        $this->assertStringNotContainsString($secret, $body->getContent());
        $this->assertStringNotContainsString('GOCSPX', $body->getContent());
    }

    // ── Disconnect asks the provider to revoke, and says honestly what happened ──

    public function test_disconnecting_youtube_revokes_the_token_at_google_only_and_removes_the_local_copy(): void
    {
        Http::fake(['oauth2.googleapis.com/*' => Http::response('', 200)]);
        $connector = $this->connector(['type' => 'youtube', 'external_account_id' => 'UC1', 'credentials' => ['access_token' => 'access-tok', 'refresh_token' => 'refresh-tok']]);

        $res = $this->postJson("/api/signals/ingestion/connectors/{$connector->id}/disconnect", [], $this->h())->assertOk();

        $res->assertJsonPath('revocation', 'revoked');
        $this->assertStringContainsString('revoked', $res->json('message'));
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->url() === 'https://oauth2.googleapis.com/revoke' && $r['token'] === 'refresh-tok');
        $this->assertNull(DB::table('g2g_data_connectors')->where('id', $connector->id)->value('credentials'));
        $this->assertSame('disconnected', $connector->fresh()->connection_status);
        $this->assertStringNotContainsString('refresh-tok', $res->getContent());
    }

    public function test_a_provider_that_refuses_or_is_down_does_not_leave_a_token_stored_and_the_answer_says_so(): void
    {
        Http::fake(['oauth2.googleapis.com/*' => Http::response('', 400)]);
        $connector = $this->connector(['type' => 'youtube', 'external_account_id' => 'UC1', 'credentials' => ['access_token' => 'access-tok']]);

        $res = $this->postJson("/api/signals/ingestion/connectors/{$connector->id}/disconnect", [], $this->h())->assertOk();

        $res->assertJsonPath('revocation', 'failed');
        $this->assertStringContainsString('could not be asked to revoke', $res->json('message'));
        $this->assertNull(DB::table('g2g_data_connectors')->where('id', $connector->id)->value('credentials'), 'the local copy is gone regardless');

        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('down'));
        $again = $this->connector(['type' => 'youtube', 'name' => 'Second', 'external_account_id' => 'UC2', 'credentials' => ['access_token' => 'tok-2']]);
        $this->postJson("/api/signals/ingestion/connectors/{$again->id}/disconnect", [], $this->h())->assertOk()->assertJsonPath('revocation', 'failed');
        $this->assertNull(DB::table('g2g_data_connectors')->where('id', $again->id)->value('credentials'));
    }

    public function test_linkedin_is_revoked_with_the_apps_credentials_and_meta_and_websites_make_no_provider_call(): void
    {
        $this->setCredentials([], ['86abcdefghijkl', 'WPL_AP1.0123456789']);
        Http::fake(['www.linkedin.com/*' => Http::response('', 200)]);
        $linkedin = $this->connector(['type' => 'linkedin', 'external_account_id' => '133390284', 'credentials' => ['access_token' => 'li-tok']]);
        $this->postJson("/api/signals/ingestion/connectors/{$linkedin->id}/disconnect", [], $this->h())->assertOk()->assertJsonPath('revocation', 'revoked');
        Http::assertSent(fn ($r) => $r->url() === 'https://www.linkedin.com/oauth/v2/revoke' && $r['token'] === 'li-tok' && $r['client_id'] === '86abcdefghijkl');

        Http::fake();
        $facebook = $this->connector(['type' => 'facebook_page', 'name' => 'FB', 'external_account_id' => 'PAGE1', 'credentials' => ['access_token' => 'fb-tok']]);
        $this->postJson("/api/signals/ingestion/connectors/{$facebook->id}/disconnect", [], $this->h())->assertOk()->assertJsonPath('revocation', 'not_supported');
        $website = $this->connector(['name' => 'Site']);
        $this->postJson("/api/signals/ingestion/connectors/{$website->id}/disconnect", [], $this->h())->assertOk()->assertJsonPath('revocation', 'not_attempted');
        Http::assertNothingSent();
        $this->assertNull(DB::table('g2g_data_connectors')->where('id', $facebook->id)->value('credentials'));
    }

    public function test_disconnecting_another_tenants_connector_revokes_nothing(): void
    {
        Http::fake();
        $connector = $this->connector(['type' => 'youtube', 'external_account_id' => 'UC1', 'credentials' => ['access_token' => 'tok']]);

        $this->postJson("/api/signals/ingestion/connectors/{$connector->id}/disconnect", [], $this->h(self::T2))->assertStatus(404);

        Http::assertNothingSent();
        $this->assertNotNull(DB::table('g2g_data_connectors')->where('id', $connector->id)->value('credentials'));
    }

    // ── Connector Settings: administrator-entered provider credentials ───────

    private const G_ID = '1234567890-abcdefgh.apps.googleusercontent.com';
    private const G_SECRET = 'GOCSPX-supersecretvalue123456';
    private const META_ID = '1234567890123456';
    private const META_SECRET = 'abcdef0123456789abcdef0123456789';

    /** A caller with another role (profile 2 is an ordinary employee, profile 3 an HR manager who may open Ingestion but is not an administrator). */
    private function employee(int $tenant = self::T1, int $profile = 2): array
    {
        $id = DB::table('tbluser')->insertGetId(['user_name' => 'e' . uniqid(), 'sub_institute_id' => $tenant, 'user_profile_id' => $profile]);

        return ['Authorization' => 'Bearer ' . tbluserModel::find($id)->createToken('t')->plainTextToken];
    }

    private function settingsUrl(string $suffix = ''): string
    {
        return '/api/signals/ingestion/connector-settings' . $suffix;
    }

    private function saveYoutube(?array $headers = null, array $body = []): \Illuminate\Testing\TestResponse
    {
        return $this->putJson($this->settingsUrl('/youtube'), $body + ['client_id' => self::G_ID, 'client_secret' => self::G_SECRET], $headers ?? $this->h());
    }

    private function noEnvCredentials(): void
    {
        $this->setCredentials();
    }

    public function test_only_administrators_can_read_or_change_connector_settings(): void
    {
        $this->noEnvCredentials();
        $employee = $this->employee();

        $this->getJson($this->settingsUrl(), $employee)->assertStatus(403);
        $this->putJson($this->settingsUrl('/youtube'), ['client_id' => self::G_ID, 'client_secret' => self::G_SECRET], $employee)->assertStatus(403);
        $this->deleteJson($this->settingsUrl('/youtube'), [], $employee)->assertStatus(403);
        $this->postJson($this->settingsUrl('/youtube/test'), [], $employee)->assertStatus(403);
        $this->getJson($this->settingsUrl())->assertStatus(401);
        $this->assertSame(0, DB::table('g2g_connector_provider_settings')->count(), 'nothing was written by a refused caller');

        $this->getJson($this->settingsUrl(), $this->h())->assertOk()->assertJsonCount(3, 'data.providers');
        $this->assertSame(['youtube', 'linkedin', 'meta'], array_column($this->getJson($this->settingsUrl(), $this->h())->json('data.providers'), 'provider'));
    }

    public function test_the_connector_list_tells_the_screen_whether_the_caller_may_open_settings(): void
    {
        $this->getJson('/api/signals/ingestion/connectors', $this->h())->assertOk()->assertJsonPath('can_manage_settings', true);
        $this->getJson('/api/signals/ingestion/connectors', $this->employee(self::T1, 3))->assertOk()->assertJsonPath('can_manage_settings', false);
        $this->getJson($this->settingsUrl(), $this->employee(self::T1, 3))->assertStatus(403);
    }

    public function test_saved_credentials_are_encrypted_at_rest_and_never_returned_or_logged(): void
    {
        $this->noEnvCredentials();
        Log::spy();

        $res = $this->saveYoutube()->assertOk();

        $raw = DB::table('g2g_connector_provider_settings')->first();
        $this->assertStringNotContainsString(self::G_SECRET, (string) $raw->client_secret, 'ciphertext, not the secret');
        $this->assertStringNotContainsString('GOCSPX', (string) $raw->client_secret);
        $this->assertStringNotContainsString('apps.googleusercontent.com', (string) $raw->client_id);
        $saved = \App\Domain\Signals\Connectors\ConnectorProviderSetting::first();
        $this->assertSame(self::G_SECRET, $saved->client_secret, 'decrypts with the application key');
        $this->assertArrayNotHasKey('client_secret', $saved->toArray(), 'hidden from serialisation');

        foreach ([$res->getContent(), $this->getJson($this->settingsUrl(), $this->h())->getContent(), $this->getJson('/api/signals/ingestion/connectors', $this->h())->getContent()] as $body) {
            $this->assertStringNotContainsString(self::G_SECRET, $body);
            $this->assertStringNotContainsString('GOCSPX', $body);
        }
        $youtube = collect($this->getJson($this->settingsUrl(), $this->h())->json('data.providers'))->firstWhere('provider', 'youtube');
        $this->assertSame(['database', true, '••••••••••••', self::G_ID], [$youtube['source'], $youtube['secret_saved'], $youtube['secret_mask'], $youtube['client_id']]);
        $this->assertArrayNotHasKey('client_secret', $youtube);

        $audit = DB::table('g2g_connector_setting_audits')->first();
        $this->assertSame(['created', 'youtube', 'client_id,client_secret'], [$audit->action, $audit->provider, $audit->fields]);
        $this->assertStringNotContainsString(self::G_SECRET, json_encode($audit));
        Log::shouldHaveReceived('info')->withArgs(function (string $m, array $c = []) {
            $this->assertStringNotContainsString(self::G_SECRET, json_encode($c));
            $this->assertStringNotContainsString(self::G_ID, json_encode($c));

            return true;
        })->atLeast()->once();
    }

    public function test_a_malformed_credential_is_refused_with_a_reason_and_nothing_is_saved(): void
    {
        $this->noEnvCredentials();

        $bad = $this->saveYoutube(null, ['client_id' => 'not-a-google-client-id'])->assertStatus(422)->assertJsonPath('code', 'configuration_invalid');
        $this->assertStringContainsString('apps.googleusercontent.com', $bad->json('errors.client_id.0'));
        $this->assertStringNotContainsString('not-a-google-client-id', $bad->getContent(), 'the refused value is not echoed back');
        $this->putJson($this->settingsUrl('/meta'), ['client_id' => 'abc', 'client_secret' => 'short'], $this->h())->assertStatus(422)->assertJsonStructure(['errors' => ['client_id', 'client_secret']]);
        $this->putJson($this->settingsUrl('/youtube'), ['client_id' => self::G_ID], $this->h())->assertStatus(422)->assertJsonPath('code', 'incomplete');
        $this->putJson($this->settingsUrl('/youtube'), [], $this->h())->assertStatus(422)->assertJsonPath('code', 'nothing_to_change');
        $this->putJson($this->settingsUrl('/tiktok'), ['client_id' => 'x'], $this->h())->assertStatus(404);
        $this->assertSame(0, DB::table('g2g_connector_provider_settings')->count());
        $this->assertSame(0, DB::table('g2g_connector_setting_audits')->count());
    }

    public function test_an_edit_keeps_any_field_left_empty_and_never_overwrites_a_working_secret_with_blank_or_the_mask(): void
    {
        $this->noEnvCredentials();
        $this->saveYoutube()->assertOk();
        $row = fn () => \App\Domain\Signals\Connectors\ConnectorProviderSetting::first();

        // only the client id: the secret stays
        $newId = '9999999999-zyxwvuts.apps.googleusercontent.com';
        $this->putJson($this->settingsUrl('/youtube'), ['client_id' => $newId, 'client_secret' => ''], $this->h())->assertOk();
        $this->assertSame([$newId, self::G_SECRET], [$row()->client_id, $row()->client_secret]);

        // the mask the form shows is not a new secret
        $this->putJson($this->settingsUrl('/youtube'), ['client_id' => '', 'client_secret' => '••••••••••••'], $this->h())->assertStatus(422)->assertJsonPath('code', 'nothing_to_change');
        $this->assertSame(self::G_SECRET, $row()->client_secret);

        // only the secret: the id stays; a real replacement works
        $this->putJson($this->settingsUrl('/youtube'), ['client_secret' => 'GOCSPX-anewsecretvalue9876543'], $this->h())->assertOk();
        $this->assertSame([$newId, 'GOCSPX-anewsecretvalue9876543'], [$row()->client_id, $row()->client_secret]);
        $this->assertSame(1, \App\Domain\Signals\Connectors\ConnectorProviderSetting::count(), 'one row, replaced in place');
        $this->assertSame(['created', 'updated', 'updated'], DB::table('g2g_connector_setting_audits')->orderBy('id')->pluck('action')->all());
        $this->assertSame('client_secret', DB::table('g2g_connector_setting_audits')->orderByDesc('id')->value('fields'));
    }

    public function test_changing_the_client_id_warns_when_accounts_are_connected_through_the_old_application(): void
    {
        $this->noEnvCredentials();
        $this->saveYoutube()->assertOk();
        $this->connector(['type' => 'youtube', 'external_account_id' => 'UC1', 'credentials' => ['access_token' => 'tok']]);

        $res = $this->putJson($this->settingsUrl('/youtube'), ['client_id' => '9999999999-zyxwvuts.apps.googleusercontent.com'], $this->h())->assertOk();

        $this->assertStringContainsString('reconnected', $res->json('warnings.0'));
        $rotate = $this->putJson($this->settingsUrl('/youtube'), ['client_secret' => 'GOCSPX-yetanothersecret123456'], $this->h())->assertOk();
        $this->assertSame([], $rotate->json('warnings'), 'rotating only the secret does not break connections');
    }

    public function test_saved_credentials_take_precedence_over_the_environment_and_removing_them_falls_back_to_it(): void
    {
        $this->setCredentials(['5555555555-envclient.apps.googleusercontent.com', 'GOCSPX-environmentsecret12345']);
        $readiness = app(\App\Domain\Signals\Connectors\ConnectorProviderReadiness::class);
        $resolver = app(\App\Domain\Signals\Connectors\ConnectorCredentialResolver::class);

        $this->assertSame('environment', $resolver->resolve('youtube', self::T1)['source']);
        $this->assertTrue($readiness->check('youtube', self::T1)['ready'], 'the environment alone still works');
        $env = collect($this->getJson($this->settingsUrl(), $this->h())->json('data.providers'))->firstWhere('provider', 'youtube');
        $this->assertSame(['environment', null, false], [$env['source'], $env['client_id'], $env['can_remove']], 'a server credential is not echoed and cannot be removed here');

        $this->saveYoutube()->assertOk();
        $this->assertSame(['database', self::G_ID, self::G_SECRET], [$resolver->resolve('youtube', self::T1)['source'], $resolver->clientId('youtube', self::T1), $resolver->clientSecret('youtube', self::T1)], 'the saved credentials win, and the memo was cleared by the save');
        $this->assertSame('environment', $resolver->resolve('youtube', self::T2)['source'], 'another organisation still uses the environment');

        $this->deleteJson($this->settingsUrl('/youtube'), [], $this->h())->assertOk()->assertJsonPath('data.source', 'environment');
        $this->assertSame('5555555555-envclient.apps.googleusercontent.com', $resolver->clientId('youtube', self::T1), 'the next call after removal uses the environment again');
        $this->assertSame(['removed'], array_slice(DB::table('g2g_connector_setting_audits')->orderBy('id')->pluck('action')->all(), -1));
        $this->deleteJson($this->settingsUrl('/youtube'), [], $this->h())->assertStatus(404)->assertJsonPath('code', 'nothing_saved');
    }

    public function test_a_half_saved_row_is_never_mixed_with_the_environment(): void
    {
        $this->setCredentials(['5555555555-envclient.apps.googleusercontent.com', 'GOCSPX-environmentsecret12345']);
        \App\Domain\Signals\Connectors\ConnectorProviderSetting::create(['sub_institute_id' => self::T1, 'provider' => 'youtube', 'client_id' => self::G_ID, 'client_secret' => '']);
        app(\App\Domain\Signals\Connectors\ConnectorCredentialResolver::class)->forget();

        $r = app(\App\Domain\Signals\Connectors\ConnectorCredentialResolver::class)->resolve('youtube', self::T1);

        $this->assertSame(['environment', '5555555555-envclient.apps.googleusercontent.com', 'GOCSPX-environmentsecret12345'], [$r['source'], $r['client_id'], $r['client_secret']]);
    }

    public function test_one_organisation_can_never_read_change_or_use_anothers_credentials(): void
    {
        $this->noEnvCredentials();
        $this->saveYoutube($this->h(self::T1))->assertOk();

        $other = collect($this->getJson($this->settingsUrl(), $this->h(self::T2))->json('data.providers'))->firstWhere('provider', 'youtube');
        $this->assertSame(['not_configured', 'none', null, false], [$other['status'], $other['source'], $other['client_id'], $other['secret_saved']]);
        $this->assertStringNotContainsString(self::G_SECRET, $this->getJson($this->settingsUrl(), $this->h(self::T2))->getContent());

        $this->deleteJson($this->settingsUrl('/youtube'), [], $this->h(self::T2))->assertStatus(404);
        $this->assertSame(1, \App\Domain\Signals\Connectors\ConnectorProviderSetting::where('sub_institute_id', self::T1)->count(), 'organisation 1 still has its credentials');

        // organisation 2 saves its own: organisation 1's are untouched
        $this->putJson($this->settingsUrl('/youtube'), ['client_id' => '7777777777-orgtwoclient.apps.googleusercontent.com', 'client_secret' => 'GOCSPX-organisationtwosecret1'], $this->h(self::T2))->assertOk();
        $this->assertSame(self::G_ID, \App\Domain\Signals\Connectors\ConnectorProviderSetting::where('sub_institute_id', self::T1)->first()->client_id);
        $this->assertSame(2, \App\Domain\Signals\Connectors\ConnectorProviderSetting::count());
    }

    public function test_connect_account_starts_the_existing_oauth_with_the_saved_credentials_and_a_provider_without_any_stays_blocked(): void
    {
        $this->noEnvCredentials();
        $connector = $this->connector(['type' => 'youtube', 'external_account_id' => '', 'credentials' => null, 'connection_status' => 'not_configured']);

        $this->postJson("/api/signals/ingestion/connectors/{$connector->id}/oauth", [], $this->h())->assertStatus(503)->assertJsonPath('code', 'provider_not_configured');

        $this->saveYoutube()->assertOk();
        $url = $this->postJson("/api/signals/ingestion/connectors/{$connector->id}/oauth", [], $this->h())->assertOk()->json('data.authorization_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame(self::G_ID, $query['client_id'], 'the saved client id is what the authorization URL carries');
        $this->assertStringNotContainsString(self::G_SECRET, $url);
        $this->assertSame('not_configured', $connector->fresh()->connection_status, 'starting OAuth does not mean connected');
    }

    public function test_the_token_refresh_uses_the_saved_credentials_of_the_connectors_own_organisation(): void
    {
        $this->noEnvCredentials();
        $this->saveYoutube($this->h(self::T1))->assertOk();
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'fresh-token', 'expires_in' => 3600], 200)]);
        $connector = $this->connector(['type' => 'youtube', 'external_account_id' => 'UC1', 'credentials' => ['access_token' => 'old', 'refresh_token' => 'refresh-tok', 'expires_at' => date(DATE_ATOM, time() - 60)]]);

        $token = app(\App\Domain\Signals\Connectors\SocialConnectorTokenManager::class)->accessToken($connector);

        $this->assertSame('fresh-token', $token);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'oauth2.googleapis.com/token') && $r['client_id'] === self::G_ID && $r['client_secret'] === self::G_SECRET && $r['refresh_token'] === 'refresh-tok');
    }

    // ── Test Configuration ───────────────────────────────────────────────────

    public function test_testing_with_nothing_configured_says_so_and_contacts_no_provider(): void
    {
        $this->noEnvCredentials();
        Http::fake();

        $res = $this->postJson($this->settingsUrl('/youtube/test'), [], $this->h())->assertOk();

        $this->assertSame(['not_configured', false, 'not_checked'], [$res->json('data.status'), $res->json('data.local.configured'), $res->json('data.provider_check.result')]);
        Http::assertNothingSent();
    }

    public function test_testing_separates_local_validity_from_provider_recognition_and_never_claims_oauth_success(): void
    {
        $this->noEnvCredentials();
        $this->saveYoutube()->assertOk();
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_grant'], 400)]);

        $ok = $this->postJson($this->settingsUrl('/youtube/test'), [], $this->h())->assertOk();

        $this->assertSame(['authorization_required', true, 'accepted', 0], [$ok->json('data.status'), $ok->json('data.local.valid'), $ok->json('data.provider_check.result'), $ok->json('data.connected_accounts')]);
        $this->assertStringContainsString('does not prove', $ok->json('data.provider_check.message'));
        $this->assertStringNotContainsString(self::G_SECRET, $ok->getContent());
        Http::assertSent(fn ($r) => $r['code'] === 'g2g-configuration-test', 'the probe never carries a real authorization code');

        Http::swap(new \Illuminate\Http\Client\Factory());   // stubs registered earlier would keep answering first
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_client'], 401)]);
        $bad = $this->postJson($this->settingsUrl('/youtube/test'), [], $this->h())->assertOk();
        $this->assertSame(['configuration_invalid', 'rejected'], [$bad->json('data.status'), $bad->json('data.provider_check.result')]);

        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('boom https://x?client_secret=' . self::G_SECRET));
        $down = $this->postJson($this->settingsUrl('/youtube/test'), [], $this->h())->assertOk();
        $this->assertSame('unreachable', $down->json('data.provider_check.result'));
        $this->assertStringNotContainsString(self::G_SECRET, $down->getContent(), 'an exception message is never returned');
    }

    public function test_testing_reports_connected_only_when_an_account_really_is_connected(): void
    {
        $this->noEnvCredentials();
        $this->putJson($this->settingsUrl('/meta'), ['client_id' => self::META_ID, 'client_secret' => self::META_SECRET], $this->h())->assertOk();
        Http::fake(['graph.facebook.com/*' => Http::response(['access_token' => 'app-token-do-not-return', 'token_type' => 'bearer'], 200)]);

        $before = $this->postJson($this->settingsUrl('/meta/test'), [], $this->h())->assertOk();
        $this->assertSame(['authorization_required', 'accepted'], [$before->json('data.status'), $before->json('data.provider_check.result')]);
        $this->assertStringNotContainsString('app-token-do-not-return', $before->getContent());

        $this->connector(['type' => 'facebook_page', 'external_account_id' => 'PAGE1', 'credentials' => ['access_token' => 'tok'], 'connection_status' => 'connected']);
        $after = $this->postJson($this->settingsUrl('/meta/test'), [], $this->h())->assertOk();
        $this->assertSame(['connected', 1], [$after->json('data.status'), $after->json('data.connected_accounts')]);
    }

    public function test_the_provider_cards_use_the_resolved_credentials_for_readiness_and_never_look_ready_from_credentials_alone(): void
    {
        $this->noEnvCredentials();
        $this->putJson($this->settingsUrl('/meta'), ['client_id' => self::META_ID, 'client_secret' => self::META_SECRET], $this->h())->assertOk();

        $available = collect($this->getJson('/api/signals/ingestion/connectors', $this->h())->assertOk()->json('available'))->keyBy('key');
        foreach (['facebook_page', 'instagram_business'] as $type) {
            $this->assertTrue($available[$type]['ready'], $type . ': one Meta application serves both');
        }
        $this->assertFalse($available['youtube']['ready']);
        $meta = collect($this->getJson($this->settingsUrl(), $this->h())->json('data.providers'))->firstWhere('provider', 'meta');
        $this->assertSame('authorization_required', $meta['status'], 'configured is not connected');
        $this->assertSame(['facebook_page', 'instagram_business'], $meta['connector_types']);
    }

    // ── Saved credentials drive the whole OAuth path (provider traffic mocked; no real provider is contacted) ──

    private const LI_ID = '86abcdefghijkl';
    private const LI_SECRET = 'WPL_AP1.0123456789';

    private function freshHttp(): \Illuminate\Http\Client\Factory
    {
        $factory = new \Illuminate\Http\Client\Factory();
        Http::swap($factory);

        return $factory;
    }

    /** Starts OAuth as the connector's tenant and returns [authorization query, state]. */
    private function oauthStart(DataConnector $connector, ?array $headers = null): array
    {
        $url = $this->postJson("/api/signals/ingestion/connectors/{$connector->id}/oauth", [], $headers ?? $this->h())->assertOk()->json('data.authorization_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return [$query, (string) $query['state']];
    }

    private function oauthCallback(string $provider, string $state, string $extra = 'code=auth-code'): array
    {
        $location = $this->get("/api/signals/ingestion/connectors/oauth/{$provider}/callback?state={$state}&{$extra}")->headers->get('Location');
        parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);

        return $query;
    }

    public function test_youtube_saved_credentials_are_used_for_the_authorization_url_and_the_token_exchange_and_tokens_are_stored_encrypted(): void
    {
        $this->noEnvCredentials();
        $this->saveYoutube()->assertOk();
        $connector = $this->connector(['type' => 'youtube']);
        $http = $this->freshHttp();
        $http->fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.NEW-ACCESS', 'refresh_token' => '1//NEW-REFRESH', 'expires_in' => 3600, 'scope' => 'https://www.googleapis.com/auth/youtube.readonly']),
            'www.googleapis.com/youtube/v3/channels*' => Http::response(['items' => [['id' => 'UC9', 'snippet' => ['title' => 'Saved Cred Channel']]]]),
        ]);

        [$auth, $state] = $this->oauthStart($connector);
        $this->assertSame(self::G_ID, $auth['client_id'], 'the authorization URL carries the saved client id');
        $this->assertStringNotContainsString(self::G_SECRET, http_build_query($auth), 'the secret is never in the browser URL');

        $query = $this->oauthCallback('youtube', $state);

        $this->assertSame('success', $query['connector_oauth']);
        $http->assertSent(fn ($r) => $r->url() === 'https://oauth2.googleapis.com/token'
            && $r['client_id'] === self::G_ID && $r['client_secret'] === self::G_SECRET
            && $r['code'] === 'auth-code' && $r['grant_type'] === 'authorization_code' && $r['redirect_uri'] === $auth['redirect_uri']);
        $raw = (string) DB::table('g2g_data_connectors')->where('id', $connector->id)->value('credentials');
        $this->assertStringNotContainsString('ya29.NEW-ACCESS', $raw);
        $this->assertStringNotContainsString('1//NEW-REFRESH', $raw);
        $this->assertSame('1//NEW-REFRESH', $connector->fresh()->credentials['refresh_token']);
        $this->assertSame('connected', $connector->fresh()->connection_status);
    }

    public function test_linkedin_saved_credentials_are_used_for_the_authorization_url_and_the_token_exchange(): void
    {
        $this->noEnvCredentials();
        $this->putJson($this->settingsUrl('/linkedin'), ['client_id' => self::LI_ID, 'client_secret' => self::LI_SECRET], $this->h())->assertOk();
        $connector = $this->connector(['type' => 'linkedin', 'external_account_id' => '133390284']);
        $http = $this->freshHttp();
        $http->fake([
            'www.linkedin.com/oauth/v2/accessToken' => Http::response(['access_token' => 'li-ACCESS', 'refresh_token' => 'li-REFRESH', 'expires_in' => 5184000]),
            'api.linkedin.com/*' => Http::response(['elements' => []]),
        ]);

        [$auth, $state] = $this->oauthStart($connector);
        $this->assertSame(self::LI_ID, $auth['client_id']);

        $query = $this->oauthCallback('linkedin', $state);

        $this->assertSame('success', $query['connector_oauth']);
        $http->assertSent(fn ($r) => $r->url() === 'https://www.linkedin.com/oauth/v2/accessToken'
            && $r['client_id'] === self::LI_ID && $r['client_secret'] === self::LI_SECRET && $r['code'] === 'auth-code');
        $raw = (string) DB::table('g2g_data_connectors')->where('id', $connector->id)->value('credentials');
        $this->assertStringNotContainsString('li-ACCESS', $raw);
        $this->assertSame('li-REFRESH', $connector->fresh()->credentials['refresh_token']);
    }

    public function test_meta_saved_credentials_are_used_for_both_token_exchange_steps_and_facebook_and_instagram_share_them(): void
    {
        $this->noEnvCredentials();
        $this->putJson($this->settingsUrl('/meta'), ['client_id' => self::META_ID, 'client_secret' => self::META_SECRET], $this->h())->assertOk();
        $connector = $this->connector(['type' => 'instagram_business']);
        $http = $this->freshHttp();
        $http->fake(function ($request) {
            $url = $request->url();

            return match (true) {
                str_contains($url, '/oauth/access_token') => Http::response(['access_token' => 'meta-token']),
                str_contains($url, '/me/permissions') => Http::response(['data' => array_map(fn ($p) => ['permission' => $p, 'status' => 'granted'], ['pages_show_list', 'pages_read_engagement', 'instagram_basic'])]),
                str_contains($url, '/me/accounts') => Http::response(['data' => [['id' => '555', 'name' => 'Acme Page', 'access_token' => 'PAGE-TOKEN', 'instagram_business_account' => ['id' => '777', 'username' => 'acme_ig']]]]),
                default => Http::response([], 404),
            };
        });

        [$auth, $state] = $this->oauthStart($connector);
        $this->assertSame(self::META_ID, $auth['client_id']);

        $query = $this->oauthCallback('meta', $state);

        $this->assertSame('pending', $query['connector_oauth'], 'the sign-in finished; an account still has to be chosen');
        $exchanges = $http->recorded(fn ($r) => str_contains($r->url(), '/oauth/access_token'));
        $this->assertCount(2, $exchanges, 'short-lived then long-lived exchange');
        foreach ($exchanges as [$request]) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $sent);
            $this->assertSame(self::META_ID, $sent['client_id']);
            $this->assertSame(self::META_SECRET, $sent['client_secret']);
        }
        $raw = (string) DB::table('g2g_data_connectors')->where('id', $connector->id)->value('credentials');
        $this->assertStringNotContainsString('meta-token', $raw);
        $this->assertStringNotContainsString('PAGE-TOKEN', $raw);
    }

    private function metaFake(array $accounts, ?array $debugToken): \Illuminate\Http\Client\Factory
    {
        $http = $this->freshHttp();
        $http->fake(function ($request) use ($accounts, $debugToken) {
            $url = $request->url();

            return match (true) {
                str_contains($url, '/oauth/access_token') => Http::response(['access_token' => 'meta-token']),
                str_contains($url, '/me/permissions') => Http::response(['data' => array_map(fn ($p) => ['permission' => $p, 'status' => 'granted'], ['pages_show_list', 'pages_read_engagement', 'instagram_basic'])]),
                str_contains($url, '/me/accounts') => Http::response(['data' => $accounts]),
                str_contains($url, '/debug_token') => $debugToken === null ? Http::response([], 400) : Http::response(['data' => $debugToken]),
                str_contains($url, '/110403501328427') => Http::response(['id' => '110403501328427', 'name' => 'ScholarClone', 'access_token' => 'PAGE-TOKEN-DIRECT', 'instagram_business_account' => ['id' => '17841465286645635', 'username' => 'scholarclone']]),
                default => Http::response([], 404),
            };
        });

        return $http;
    }

    public function test_meta_finds_the_chosen_page_from_the_token_when_the_simple_page_list_comes_back_empty(): void
    {
        $this->noEnvCredentials();
        $this->putJson($this->settingsUrl('/meta'), ['client_id' => self::META_ID, 'client_secret' => self::META_SECRET], $this->h())->assertOk();
        $connector = $this->connector(['type' => 'instagram_business']);
        $http = $this->metaFake([], ['granular_scopes' => [
            ['scope' => 'pages_show_list', 'target_ids' => ['110403501328427']],
            ['scope' => 'instagram_basic', 'target_ids' => ['17841465286645635']],
        ]]);

        [, $state] = $this->oauthStart($connector);
        $query = $this->oauthCallback('meta', $state);

        $this->assertSame('pending', $query['connector_oauth'], 'the Page chosen on the consent screen is found even though /me/accounts was empty');
        $fresh = $connector->fresh();
        $accounts = (array) ($fresh->credentials['available_accounts'] ?? []);
        $this->assertCount(1, $accounts);
        $this->assertSame('17841465286645635', $accounts[0]['instagram_id']);
        $this->assertSame('scholarclone', $accounts[0]['instagram_username']);

        // The app credentials used to read the token record travel in the Authorization header, not in the URL.
        $debug = $http->recorded(fn ($r) => str_contains($r->url(), '/debug_token'));
        $this->assertCount(1, $debug);
        $debugRequest = $debug->first()[0];
        $this->assertStringNotContainsString(self::META_SECRET, $debugRequest->url());
        $this->assertSame('Bearer ' . self::META_ID . '|' . self::META_SECRET, $debugRequest->header('Authorization')[0]);
        $raw = (string) DB::table('g2g_data_connectors')->where('id', $connector->id)->value('credentials');
        $this->assertStringNotContainsString('PAGE-TOKEN-DIRECT', $raw, 'tokens stay encrypted');
    }

    public function test_meta_still_reports_permission_required_when_neither_page_route_finds_anything(): void
    {
        $this->noEnvCredentials();
        $this->putJson($this->settingsUrl('/meta'), ['client_id' => self::META_ID, 'client_secret' => self::META_SECRET], $this->h())->assertOk();
        $connector = $this->connector(['type' => 'instagram_business']);
        $this->metaFake([], null);

        [, $state] = $this->oauthStart($connector);
        $query = $this->oauthCallback('meta', $state);

        $this->assertSame(['error', 'permission_required'], [$query['connector_oauth'], $query['reason']]);
    }

    public function test_without_a_saved_pair_the_environment_pair_is_used_for_the_whole_path(): void
    {
        $this->setCredentials([self::G_ID, self::G_SECRET]);
        $connector = $this->connector(['type' => 'youtube']);
        $http = $this->freshHttp();
        $http->fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 3600, 'scope' => 'https://www.googleapis.com/auth/youtube.readonly']),
            'www.googleapis.com/*' => Http::response(['items' => [['id' => 'UC1', 'snippet' => ['title' => 'T']]]]),
        ]);

        [$auth, $state] = $this->oauthStart($connector);
        $this->oauthCallback('youtube', $state);

        $this->assertSame(self::G_ID, $auth['client_id']);
        $http->assertSent(fn ($r) => $r->url() === 'https://oauth2.googleapis.com/token' && $r['client_id'] === self::G_ID && $r['client_secret'] === self::G_SECRET);
    }

    public function test_a_half_saved_row_is_never_mixed_with_the_environment_and_a_complete_saved_pair_beats_the_environment(): void
    {
        $this->setCredentials(['9999999999-envenvenv.apps.googleusercontent.com', 'GOCSPX-environmentsecret12345']);
        // A row whose secret is empty (the column is NOT NULL, so empty is how a half pair could exist) must not borrow the environment secret.
        DB::table('g2g_connector_provider_settings')->insert([
            'sub_institute_id' => self::T1, 'provider' => 'youtube', 'client_id' => \Illuminate\Support\Facades\Crypt::encryptString(self::G_ID), 'client_secret' => \Illuminate\Support\Facades\Crypt::encryptString(''),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $resolved = app(\App\Domain\Signals\Connectors\ConnectorCredentialResolver::class)->resolve('youtube', self::T1);
        $this->assertSame('environment', $resolved['source']);
        $this->assertSame('9999999999-envenvenv.apps.googleusercontent.com', $resolved['client_id']);
        $this->assertSame('GOCSPX-environmentsecret12345', $resolved['client_secret']);

        app(\App\Domain\Signals\Connectors\ConnectorCredentialResolver::class)->forget();
        $this->saveYoutube()->assertOk();
        $connector = $this->connector(['type' => 'youtube']);
        [$auth] = $this->oauthStart($connector);
        $this->assertSame(self::G_ID, $auth['client_id'], 'a complete saved pair wins over the environment');
    }

    public function test_rotating_the_saved_secret_is_used_by_the_very_next_exchange_and_a_new_client_id_by_the_next_authorization(): void
    {
        $this->noEnvCredentials();
        $this->saveYoutube()->assertOk();
        $connector = $this->connector(['type' => 'youtube']);
        $newSecret = 'GOCSPX-rotatedsecretvalue999999';
        $newId = '9876543210-zyxwvuts.apps.googleusercontent.com';
        $ok = ['oauth2.googleapis.com/token' => Http::response(['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 3600, 'scope' => 'https://www.googleapis.com/auth/youtube.readonly']), 'www.googleapis.com/*' => Http::response(['items' => [['id' => 'UC1', 'snippet' => ['title' => 'T']]]])];

        $this->saveYoutube(null, ['client_id' => self::G_ID, 'client_secret' => $newSecret])->assertOk();
        $http = $this->freshHttp();
        $http->fake($ok);
        [, $state] = $this->oauthStart($connector);
        $this->oauthCallback('youtube', $state);
        $http->assertSent(fn ($r) => $r->url() === 'https://oauth2.googleapis.com/token' && $r['client_secret'] === $newSecret && $r['client_id'] === self::G_ID);

        $this->saveYoutube(null, ['client_id' => $newId, 'client_secret' => $newSecret])->assertOk();
        [$auth] = $this->oauthStart($connector);
        $this->assertSame($newId, $auth['client_id']);
    }

    public function test_one_organisations_saved_credentials_are_never_used_for_another_organisation(): void
    {
        $this->noEnvCredentials();
        $this->saveYoutube()->assertOk();   // tenant 1 only
        $other = $this->connector(['type' => 'youtube', 'sub_institute_id' => self::T2, 'name' => 'Other']);
        $http = $this->freshHttp();
        $http->fake();

        $this->postJson("/api/signals/ingestion/connectors/{$other->id}/oauth", [], $this->h(self::T2))->assertStatus(503)->assertJsonPath('code', 'provider_not_configured');
        $http->assertNothingSent();
    }

    public function test_wrong_or_expired_credentials_give_safe_actionable_errors_that_never_contain_a_secret_or_reach_the_logs(): void
    {
        $this->noEnvCredentials();
        $this->saveYoutube()->assertOk();
        Log::spy();

        foreach ([
            ['invalid_client', 'application\'s credentials'],
            ['invalid_grant', 'expired or was already used'],
            ['redirect_uri_mismatch', 'redirect URI'],
        ] as [$providerError, $expected]) {
            $connector = $this->connector(['type' => 'youtube', 'name' => $providerError]);
            $http = $this->freshHttp();
            $http->fake(['oauth2.googleapis.com/token' => Http::response(['error' => $providerError, 'error_description' => 'client_secret ' . self::G_SECRET . ' echoed by a careless provider'], 401)]);

            [, $state] = $this->oauthStart($connector);
            $query = $this->oauthCallback('youtube', $state);

            $this->assertSame('error', $query['connector_oauth'], $providerError);
            $this->assertStringContainsString($expected, (string) $connector->fresh()->last_error, $providerError);
            $this->assertStringNotContainsString(self::G_SECRET, (string) $connector->fresh()->last_error);
            $this->assertStringNotContainsString(self::G_SECRET, json_encode($query));
            $this->assertNotSame('connected', $connector->fresh()->connection_status);
            $this->assertNull($connector->fresh()->credentials['access_token'] ?? null, 'no token is stored for a failed exchange');
        }
        Log::shouldHaveReceived('warning')->withArgs(function ($message, $context = []) {
            return ! str_contains(json_encode($context), self::G_SECRET);
        });

        // A provider outage, and a transport failure that throws, are reported without leaking either.
        $outage = $this->connector(['type' => 'youtube', 'name' => 'outage']);
        $this->freshHttp()->fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error: client_secret=' . self::G_SECRET));
        [, $state] = $this->oauthStart($outage);
        $query = $this->oauthCallback('youtube', $state);
        $this->assertSame('error', $query['connector_oauth']);
        $this->assertStringNotContainsString(self::G_SECRET, (string) $outage->fresh()->last_error);
        $this->assertStringNotContainsString(self::G_SECRET, json_encode($query));
    }

    public function test_the_callback_still_validates_state_when_credentials_are_saved_and_a_consumed_state_cannot_trigger_a_second_exchange(): void
    {
        $this->noEnvCredentials();
        $this->saveYoutube()->assertOk();
        $connector = $this->connector(['type' => 'youtube']);
        $http = $this->freshHttp();
        $http->fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 3600, 'scope' => 'https://www.googleapis.com/auth/youtube.readonly']), 'www.googleapis.com/*' => Http::response(['items' => [['id' => 'UC1', 'snippet' => ['title' => 'T']]]])]);

        $this->assertSame('invalid_state', $this->oauthCallback('youtube', 'forged')['reason']);
        [, $state] = $this->oauthStart($connector);
        $this->assertSame('success', $this->oauthCallback('youtube', $state)['connector_oauth']);
        $this->assertSame('invalid_state', $this->oauthCallback('youtube', $state)['reason'], 'replayed state');
        $http->assertSentCount(2);   // one token exchange + one channel lookup, nothing for the forged or replayed attempts
    }

    // ── plumbing ─────────────────────────────────────────────────────────────

    private function jobFor(DataConnector $connector, DataConnectorSync $sync): SyncDataConnectorJob
    {
        return new SyncDataConnectorJob((int) $connector->sub_institute_id, (int) $connector->id, (int) $sync->id, 1);
    }

    private function runJob(DataConnector $connector, DataConnectorSync $sync): void
    {
        $this->jobFor($connector, $sync)->handle(app(DataConnectorRegistry::class), app(IngestionService::class), app(IngestionAnalyzer::class));
    }

    /** @param list<mixed> $args */
    private function invoke(object $object, string $method, array $args): mixed
    {
        return (new ReflectionMethod($object, $method))->invoke($object, ...$args);
    }
}
