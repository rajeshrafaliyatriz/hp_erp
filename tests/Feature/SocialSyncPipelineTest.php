<?php

namespace Tests\Feature;

use App\Domain\Signals\Connectors\ConnectorMonitoring;
use App\Domain\Signals\Connectors\DataConnectorRegistry;
use App\Domain\Signals\Ingestion\DataConnector;
use App\Domain\Signals\Ingestion\DataConnectorSync;
use App\Domain\Signals\Ingestion\DataConnectorSyncReaper;
use App\Domain\Signals\Ingestion\IngestionAnalysis;
use App\Domain\Signals\Ingestion\IngestionAnalyzer;
use App\Domain\Signals\Ingestion\IngestionService;
use App\Domain\Signals\Ingestion\IngestionSource;
use App\Domain\Signals\Ingestion\SyncDecision;
use App\Domain\Signals\Opportunities\OpportunityResearcher;
use App\Domain\Signals\Opportunities\ProductProfile;
use App\Domain\Signals\SignalRunner;
use App\Domain\Signals\Support\SafeUrlFetcher;
use App\Jobs\SyncDataConnectorJob;
use App\Models\auth\tbluserModel;
use App\Support\QueueWorkerLauncher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The social-connector pipeline end to end: fetch -> store -> analyse in batches -> decide per post -> report.
 *
 * Runs on the in-memory sqlite database from phpunit.xml. Provider traffic is mocked (Http::fake) and the AI step is replaced by a
 * controlled double that answers like the real researcher's `leadsFromSources` does. These are FIXTURE posts: they prove our handling
 * of the pipeline, never that any real school or buying signal exists. The qualification gate itself is covered by its own tests.
 */
class SocialSyncPipelineTest extends TestCase
{
    private const T1 = 1;
    private const T2 = 2;

    protected function setUp(): void
    {
        parent::setUp();
        config(['signals.legacy_product_icp' => true, 'queue.default' => 'database', 'queue.connections.database.retry_after' => 960]);
        Carbon::setTestNow('2026-11-20 10:00:00');
        Cache::flush();
        QueueWorkerLauncher::fake(fn () => null);
        SyncDecision::forgetColumnCheck();

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
            '2026_12_20_100000_add_sync_report_and_monitoring_columns',
        ] as $migration) {
            (require base_path("database/migrations/{$migration}.php"))->up();
        }
        DB::table('tbluserprofilemaster')->insert([['id' => 1, 'name' => 'Administrator', 'role_key' => 'administrator'], ['id' => 2, 'name' => 'Employee', 'role_key' => 'employee']]);
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
        QueueWorkerLauncher::fake(null);
        SyncDecision::forgetColumnCheck();
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function h(int $tenant = self::T1): array
    {
        $id = DB::table('tbluser')->insertGetId(['user_name' => 'u' . uniqid(), 'sub_institute_id' => $tenant, 'user_profile_id' => 1]);

        return ['Authorization' => 'Bearer ' . tbluserModel::find($id)->createToken('t')->plainTextToken];
    }

    private function facebook(array $o = []): DataConnector
    {
        return DataConnector::create($o + [
            'sub_institute_id' => self::T1, 'type' => 'facebook_page', 'name' => 'Our Page', 'url' => 'https://www.facebook.com/ourpage',
            'external_account_id' => 'PAGE1', 'product_key' => 'scholarclone', 'connection_status' => 'connected', 'sync_enabled' => true,
            'credentials' => ['access_token' => 'tok'],
        ]);
    }

    private function queuedSync(DataConnector $connector): DataConnectorSync
    {
        return DataConnectorSync::create([
            'sub_institute_id' => $connector->sub_institute_id, 'connector_id' => $connector->id, 'status' => 'queued',
            'started_at' => now(), 'created_by' => 1,
        ]);
    }

    /** @param list<string> $messages one feed post each (own posts of the Page) */
    private function fakeFeed(array $messages, array $comments = []): void
    {
        $posts = [];
        foreach ($messages as $i => $message) {
            $posts[] = ['id' => 'PAGE1_' . ($i + 1), 'message' => $message, 'permalink_url' => 'https://www.facebook.com/ourpage/posts/' . ($i + 1),
                'from' => ['id' => 'PAGE1'], 'is_published' => true, 'created_time' => '2026-11-18T08:00:00+0000'];
        }
        Http::fake([
            '*/PAGE1/feed*' => Http::response(['data' => $posts], 200),
            '*/comments*' => Http::response(['data' => $comments], 200),
        ]);
    }

    private function runJob(DataConnector $connector, DataConnectorSync $sync): void
    {
        (new SyncDataConnectorJob((int) $connector->sub_institute_id, (int) $connector->id, (int) $sync->id, 1))
            ->handle(app(DataConnectorRegistry::class), app(IngestionService::class), app(IngestionAnalyzer::class));
    }

    /**
     * The AI step, replaced. `$answer` receives the batch of posts and the heartbeat callable and returns what leadsFromSources returns.
     *
     * @param  callable(array, ?callable): array  $answer
     */
    private function fakeResearcher(callable $answer): void
    {
        $this->mock(SignalRunner::class, fn ($m) => $m->shouldReceive('aiConfigured')->andReturn(true));
        $this->mock(OpportunityResearcher::class, fn ($m) => $m->shouldReceive('leadsFromSources')->andReturnUsing(fn (array $docs, $user = null, $beat = null) => $answer($docs, $beat)));
    }

    /** Every post is read and none qualifies: exactly what happens with a page of the account's own promotion. */
    private function nothingQualifies(array $docs): array
    {
        $per = [];
        foreach ($docs as $doc) {
            $per[(int) $doc->id] = ['code' => 'NO_BUYING_EVENT', 'detail' => 'Read by the AI: no named school with a stated need or buying event.', 'quote' => null, 'organisation' => null];
        }

        return ['qualified' => 0, 'new' => 0, 'rejected' => 0, 'candidates' => 0, 'per_source' => $per];
    }

    private function posts(int $n, string $prefix = 'Fixture post'): array
    {
        return array_map(fn ($i) => "{$prefix} {$i}: our ScholarClone team shared an update about fee collection and attendance for schools.", range(1, $n));
    }

    // ── 1. fetch, store, dispatch, analyse ───────────────────────────────────

    public function test_every_fetched_post_is_stored_and_analysed_in_batches_of_ten(): void
    {
        config(['signals_connectors.analysis_batch_size' => 10]);
        $this->fakeFeed($this->posts(25));
        $batches = [];
        $this->fakeResearcher(function (array $docs) use (&$batches) {
            $batches[] = count($docs);

            return $this->nothingQualifies($docs);
        });
        $connector = $this->facebook();
        $sync = $this->queuedSync($connector);

        $this->runJob($connector, $sync);

        $this->assertSame([10, 10, 5], $batches, 'the 10-post batch strategy is kept');
        $this->assertSame(25, IngestionSource::where('connector_id', $connector->id)->count(), 'all fetched posts persisted');
        $sync = $sync->fresh();
        $this->assertSame('completed', $sync->status);
        $this->assertSame([25, 25, 0], [(int) $sync->records_fetched, (int) $sync->records_processed, (int) $sync->records_failed]);
        $this->assertSame(25, IngestionAnalysis::where('connector_sync_id', $sync->id)->where('status', 'success')->count());
    }

    public function test_a_post_by_the_account_itself_with_no_outside_buyer_is_reported_as_own_brand_marketing(): void
    {
        $this->fakeFeed($this->posts(1));
        $this->fakeResearcher(fn (array $docs) => $this->nothingQualifies($docs));
        $connector = $this->facebook();
        $sync = $this->queuedSync($connector);

        $this->runJob($connector, $sync);

        $row = IngestionAnalysis::where('connector_sync_id', $sync->id)->first();
        $this->assertSame('OWN_BRAND_MARKETING', $row->decision_code);
        $this->assertNull($row->decision_quote);
    }

    public function test_a_qualified_post_keeps_its_exact_quote_and_an_other_peoples_comment_is_not_called_own_brand(): void
    {
        $comment = ['id' => 'C1', 'message' => 'We are Sunrise Public School and want a new ERP for fee collection.', 'permalink_url' => 'https://www.facebook.com/ourpage/posts/1?comment_id=C1',
            'from' => ['id' => 'USER9', 'name' => 'A Parent'], 'created_time' => '2026-11-19T08:00:00+0000'];
        $this->fakeFeed($this->posts(1), [$comment]);
        $this->fakeResearcher(function (array $docs) {
            $per = [];
            foreach ($docs as $doc) {
                $isComment = str_starts_with((string) $doc->external_content_id, 'fb-comment:');
                $per[(int) $doc->id] = $isComment
                    ? ['code' => 'QUALIFIED_SIGNAL', 'detail' => 'Passed the qualification gate.', 'quote' => 'want a new ERP for fee collection', 'organisation' => 'Sunrise Public School']
                    : ['code' => 'NO_BUYING_EVENT', 'detail' => 'none', 'quote' => null, 'organisation' => null];
            }

            return ['qualified' => 1, 'new' => 1, 'rejected' => 0, 'candidates' => 1, 'per_source' => $per];
        });
        $connector = $this->facebook();
        $sync = $this->queuedSync($connector);

        $this->runJob($connector, $sync);

        $this->assertSame(1, (int) $sync->fresh()->records_created);
        $rows = IngestionAnalysis::where('connector_sync_id', $sync->id)->get()->keyBy(fn ($a) => IngestionSource::find($a->source_id)->external_content_id);
        $this->assertSame('QUALIFIED_SIGNAL', $rows['fb-comment:C1']->decision_code);
        $this->assertSame('want a new ERP for fee collection', $rows['fb-comment:C1']->decision_quote);
        $this->assertSame('OWN_BRAND_MARKETING', $rows['PAGE1_1']->decision_code);
    }

    // ── 2. failure, retry, idempotence ───────────────────────────────────────

    public function test_a_failed_batch_is_explained_stays_pending_and_only_that_work_is_redone_on_the_next_sync(): void
    {
        config(['signals_connectors.analysis_batch_size' => 10]);
        $this->fakeFeed($this->posts(20));
        $calls = 0;
        $this->fakeResearcher(function (array $docs) use (&$calls) {
            $calls++;
            if ($calls === 2) {
                throw new \RuntimeException('AI response was not valid JSON.');
            }

            return $this->nothingQualifies($docs);
        });
        $connector = $this->facebook();
        $first = $this->queuedSync($connector);

        $this->runJob($connector, $first);

        $first = $first->fresh();
        $this->assertSame('partial', $first->status, 'half the work is done: reported as completed with errors, not as a clean success');
        $this->assertSame([10, 10], [(int) $first->records_processed, (int) $first->records_failed]);
        $failed = IngestionAnalysis::where('connector_sync_id', $first->id)->where('status', 'failed')->get();
        $this->assertCount(10, $failed);
        $this->assertSame('INVALID_AI_RESPONSE', $failed->first()->decision_code);
        $this->assertStringNotContainsString('RuntimeException', (string) $failed->first()->error_message, 'a plain reason, not a class dump');

        // Next sync: the provider returns the same 20 posts. Nothing is stored twice; only the 10 that failed are analysed again.
        $seen = [];
        $this->fakeResearcher(function (array $docs) use (&$seen) {
            $seen = array_merge($seen, array_map(fn ($d) => (int) $d->id, $docs));

            return $this->nothingQualifies($docs);
        });
        $this->fakeFeed($this->posts(20));
        $second = $this->queuedSync($connector);
        $this->runJob($connector, $second);

        $this->assertSame(20, IngestionSource::where('connector_id', $connector->id)->count(), 'no duplicate posts');
        $this->assertCount(10, $seen, 'only the unfinished posts are analysed again');
        $this->assertSame(10, (int) $second->fresh()->records_duplicate, 'the 10 already analysed are recognised, not redone');
        $this->assertSame(20, IngestionAnalysis::where('status', 'success')->distinct('source_id')->count('source_id'));
    }

    public function test_a_reload_of_the_same_posts_never_analyses_or_stores_anything_twice(): void
    {
        $this->fakeFeed($this->posts(5));
        $analysed = 0;
        $this->fakeResearcher(function (array $docs) use (&$analysed) {
            $analysed += count($docs);

            return $this->nothingQualifies($docs);
        });
        $connector = $this->facebook();
        $this->runJob($connector, $this->queuedSync($connector));
        $this->runJob($connector, $this->queuedSync($connector));

        $this->assertSame(5, $analysed);
        $this->assertSame(5, IngestionSource::where('connector_id', $connector->id)->count());
    }

    public function test_the_heartbeat_is_handed_to_the_ai_step_so_a_long_batch_is_never_mistaken_for_a_lost_worker(): void
    {
        $this->fakeFeed($this->posts(3));
        $syncId = null;
        $beatWrote = false;
        $this->fakeResearcher(function (array $docs, ?callable $beat) use (&$syncId, &$beatWrote) {
            $this->assertIsCallable($beat, 'the job must pass a heartbeat into the batch');
            DB::table('g2g_data_connector_syncs')->where('id', $syncId)->update(['updated_at' => now()->subSeconds(900)]);
            $beat();
            $beatWrote = DataConnectorSync::find($syncId)->updated_at->gte(now()->subSeconds(5));

            return $this->nothingQualifies($docs);
        });
        $connector = $this->facebook();
        $sync = $this->queuedSync($connector);
        $syncId = $sync->id;

        $this->runJob($connector, $sync);

        $this->assertTrue($beatWrote, 'beating inside the batch refreshes the sync the reaper watches');
    }

    // ── 3. the organisation's own monitoring rules ───────────────────────────

    public function test_excluded_keywords_and_switched_off_content_types_never_reach_the_ai(): void
    {
        $messages = ['Admission open at our ScholarClone partner schools this year, please share.', 'Fixture post about a coaching institute tender for JEE batches announced today.'];
        $comment = ['id' => 'C2', 'message' => 'Our school wants better attendance management software soon.', 'permalink_url' => 'https://f.example/c2', 'from' => ['id' => 'U1', 'name' => 'X']];
        $this->fakeFeed($messages, [$comment]);
        $sent = [];
        $this->fakeResearcher(function (array $docs) use (&$sent) {
            foreach ($docs as $d) {
                $sent[] = (string) $d->external_content_id;
            }

            return $this->nothingQualifies($docs);
        });
        $connector = $this->facebook(['monitoring_settings' => ['exclude_keywords' => ['coaching'], 'content_types' => ['own_posts']]]);
        $sync = $this->queuedSync($connector);

        $this->runJob($connector, $sync);

        $this->assertSame(['PAGE1_1'], $sent, 'the excluded post and the switched-off comments were never analysed');
    }

    public function test_the_monitoring_endpoint_clamps_and_sanitises_and_is_tenant_scoped(): void
    {
        $connector = $this->facebook();
        $this->putJson("/api/signals/ingestion/connectors/{$connector->id}/monitoring", [
            'search_keywords' => ['  school erp  ', 'School ERP', str_repeat('x', 200)],
            'exclude_keywords' => ['coaching', ''],
            'target_accounts' => ['@good.name_1', 'bad name;drop', 'x'],
            'max_items' => 9999, 'comment_videos' => 3, 'content_types' => ['own_posts', 'comments'],
        ], $this->h())->assertStatus(422);   // the 200-char keyword is refused outright

        $r = $this->putJson("/api/signals/ingestion/connectors/{$connector->id}/monitoring", [
            'search_keywords' => ['  school erp  ', 'School ERP'], 'exclude_keywords' => ['coaching'],
            'target_accounts' => ['@good.name_1'], 'max_items' => 150, 'comment_videos' => 3, 'content_types' => ['own_posts', 'comments'],
        ], $this->h())->assertOk();
        $m = $r->json('data.monitoring');
        $this->assertSame(['school erp'], $m['search_keywords'], 'trimmed and de-duplicated');
        $this->assertSame(['good.name_1'], $m['target_accounts']);
        $this->assertSame([150, 3], [$m['max_items'], $m['comment_videos']]);
        $this->assertSame(['own_posts', 'comments'], $m['content_types']);
        $this->assertSame(['good.name_1'], ConnectorMonitoring::sanitize(['target_accounts' => ['bad name;drop', '@good.name_1']])['target_accounts'], 'an unsafe handle can never reach a provider query');

        $this->putJson("/api/signals/ingestion/connectors/{$connector->id}/monitoring", ['exclude_keywords' => ['x']], $this->h(self::T2))->assertNotFound();
        $this->assertSame(['coaching'], $connector->fresh()->monitoring_settings['exclude_keywords'], 'another organisation changed nothing');
    }

    public function test_a_connector_without_saved_settings_falls_back_to_the_environment_defaults(): void
    {
        config(['signals_connectors.youtube_search_queries' => ['env query one', 'env query two'], 'signals_connectors.max_items' => 33]);
        $m = ConnectorMonitoring::for($this->facebook());

        $this->assertSame(['env query one', 'env query two'], $m->searchKeywords);
        $this->assertSame(33, $m->maxItems);

        $own = ConnectorMonitoring::for($this->facebook(['monitoring_settings' => ['search_keywords' => ['saved one']]]));
        $this->assertSame(['saved one'], $own->searchKeywords, 'the organisation\'s own setting wins');
    }

    public function test_youtube_searches_the_organisations_saved_keywords_not_the_environment_ones(): void
    {
        config(['signals_connectors.youtube_search_queries' => ['env only query']]);
        $searches = [];
        Http::fake(function ($request) use (&$searches) {
            $url = $request->url();
            if (str_contains($url, '/channels')) {
                return Http::response(['items' => [['contentDetails' => ['relatedPlaylists' => ['uploads' => 'UU1']]]]], 200);
            }
            if (str_contains($url, '/playlistItems')) {
                return Http::response(['items' => []], 200);
            }
            if (str_contains($url, '/search')) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
                $searches[] = $q['q'] ?? '';
                $this->assertSame('3', $q['maxResults'] ?? null, 'the saved search limit is used');

                return Http::response(['items' => []], 200);
            }

            return Http::response(['items' => []], 200);
        });
        $connector = DataConnector::create([
            'sub_institute_id' => self::T1, 'type' => 'youtube', 'name' => 'YT', 'external_account_id' => 'UC1', 'product_key' => 'scholarclone',
            'connection_status' => 'connected', 'sync_enabled' => true, 'credentials' => ['access_token' => 'tok'],
            'monitoring_settings' => ['search_keywords' => ['saved school erp query'], 'search_limit' => 3],
        ]);

        app(DataConnectorRegistry::class)->get('youtube')->fetch($connector);

        $this->assertSame(['saved school erp query'], $searches);
    }

    public function test_instagram_business_discovery_reads_only_the_configured_accounts_and_skips_one_the_platform_refuses(): void
    {
        $asked = [];
        Http::fake(function ($request) use (&$asked) {
            $url = urldecode($request->url());
            if (str_contains($url, '/IG1/media')) {
                return Http::response(['data' => []], 200);
            }
            if (str_contains($url, 'business_discovery.username(goodschool)')) {
                $asked[] = 'goodschool';

                return Http::response(['business_discovery' => ['username' => 'goodschool', 'name' => 'Good School', 'media' => ['data' => [
                    ['id' => 'M1', 'caption' => 'Good School is moving to a new campus and looking at admission software.', 'permalink' => 'https://www.instagram.com/p/M1/', 'timestamp' => '2026-11-10T08:00:00+0000'],
                ]]]], 200);
            }
            if (str_contains($url, 'business_discovery.username(privateone)')) {
                $asked[] = 'privateone';

                return Http::response(['error' => ['message' => 'Cannot find the account', 'code' => 110]], 400);
            }

            return Http::response([], 404);
        });
        $connector = DataConnector::create([
            'sub_institute_id' => self::T1, 'type' => 'instagram_business', 'name' => 'IG', 'external_account_id' => 'IG1', 'product_key' => 'scholarclone',
            'connection_status' => 'connected', 'sync_enabled' => true, 'credentials' => ['access_token' => 'tok'],
            'monitoring_settings' => ['target_accounts' => ['goodschool', 'privateone']],
        ]);

        $items = app(DataConnectorRegistry::class)->get('instagram_business')->fetch($connector);

        $this->assertSame(['goodschool', 'privateone'], $asked);
        $this->assertCount(1, $items);
        $this->assertSame('ig-discovery:M1', $items[0]->externalId);
        $this->assertSame('discovery', $items[0]->origin);
        $this->assertStringContainsString('Good School is moving to a new campus', $items[0]->text);
    }

    public function test_comments_the_platform_refuses_to_share_cost_one_request_and_never_fail_the_posts(): void
    {
        $commentRequests = 0;
        Http::fake(function ($request) use (&$commentRequests) {
            if (str_contains($request->url(), '/comments')) {
                $commentRequests++;

                return Http::response(['error' => ['message' => 'Permission denied', 'code' => 10]], 403);
            }

            return Http::response(['data' => array_map(fn ($i) => ['id' => "PAGE1_{$i}", 'message' => "Our own post number {$i} about the new academic year and fees.",
                'permalink_url' => "https://f.example/{$i}", 'from' => ['id' => 'PAGE1'], 'is_published' => true], range(1, 5))], 200);
        });

        $items = app(DataConnectorRegistry::class)->get('facebook_page')->fetch($this->facebook());

        $this->assertCount(5, $items, 'the posts are still returned');
        $this->assertSame(1, $commentRequests, 'one refusal ends the comment reading instead of repeating it for every post');
    }

    // ── 4. status, progress and the per-sync report ──────────────────────────

    public function test_each_state_of_a_sync_is_told_apart_and_an_interrupted_one_says_why(): void
    {
        $connector = $this->facebook();
        $mk = fn (string $status, array $o = []) => DataConnectorSync::create($o + ['sub_institute_id' => 1, 'connector_id' => $connector->id, 'status' => $status, 'started_at' => now()]);
        $rows = [
            'queued' => $mk('queued'), 'fetching' => $mk('running'), 'analysing' => $mk('running', ['records_fetched' => 40, 'records_processed' => 10]),
            'completed' => $mk('completed', ['records_fetched' => 3, 'records_processed' => 3, 'completed_at' => now()]),
            'partial' => $mk('partial', ['records_fetched' => 3, 'records_processed' => 1, 'records_failed' => 2, 'completed_at' => now()]),
            'failed' => $mk('failed', ['error_message' => 'The platform could not be reached after multiple attempts.', 'completed_at' => now()]),
            'interrupted' => $mk('failed', ['error_message' => DataConnectorSyncReaper::INTERRUPTED_MESSAGE, 'completed_at' => now()]),
        ];
        $expected = ['queued' => 'waiting_in_queue', 'fetching' => 'fetching', 'analysing' => 'analysing', 'completed' => 'completed',
            'partial' => 'completed_with_errors', 'failed' => 'failed', 'interrupted' => 'interrupted'];

        $history = collect($this->getJson('/api/signals/ingestion/connectors', $this->h())->assertOk()->json('data.0.history'))->keyBy('id');

        foreach ($rows as $name => $row) {
            $this->assertSame($expected[$name], $history[$row->id]['stage'], $name);
        }
        $this->assertSame(30, $history[$rows['analysing']->id]['records_pending']);
        $this->assertSame(100, $history[$rows['completed']->id]['progress']);
        $this->assertLessThan(100, $history[$rows['analysing']->id]['progress'], 'running is never shown as done');
        $this->assertNull($history[$rows['completed']->id]['records_pending']);
    }

    public function test_the_sync_report_counts_every_post_and_gives_each_one_its_decision(): void
    {
        config(['signals_connectors.analysis_batch_size' => 10, 'signals_connectors.sync_time_budget_seconds' => 30]);
        $this->fakeFeed($this->posts(4));
        $this->fakeResearcher(function (array $docs) {
            $per = [];
            foreach ($docs as $i => $doc) {
                $per[(int) $doc->id] = match ($i) {
                    0 => ['code' => 'QUALIFIED_SIGNAL', 'detail' => 'Passed the qualification gate.', 'quote' => 'fixture exact quote', 'organisation' => 'Fixture School'],
                    1 => ['code' => 'NO_SUPPORTING_QUOTE', 'detail' => 'The supporting quote is not in any cited source.', 'quote' => 'made up words', 'organisation' => 'Ghost School'],
                    default => ['code' => 'NO_BUYING_EVENT', 'detail' => 'none', 'quote' => null, 'organisation' => null],
                };
            }

            return ['qualified' => 1, 'new' => 1, 'rejected' => 1, 'candidates' => 2, 'per_source' => $per];
        });
        $connector = $this->facebook();
        $sync = $this->queuedSync($connector);
        $this->runJob($connector, $sync);

        $report = $this->getJson("/api/signals/ingestion/connectors/{$connector->id}/syncs/{$sync->id}/report", $this->h())->assertOk()->json('data');

        $t = $report['totals'];
        $this->assertSame([4, 4, 4, 0, 1, 3, 0], [$t['fetched'], $t['stored'], $t['analysed'], $t['pending'], $t['qualified'], $t['rejected'], $t['errors']]);
        $this->assertSame(1, $t['signals_created']);
        $this->assertIsInt($t['duration_seconds']);
        $this->assertSame('completed', $report['stage']);
        $this->assertSame('signals', $report['queue']['name']);
        $this->assertTrue($report['reasons_recorded']);
        $this->assertCount(4, $report['items']);
        $byCode = collect($report['items'])->groupBy('decision_code');
        $this->assertSame('fixture exact quote', $byCode['QUALIFIED_SIGNAL'][0]['quote']);
        $this->assertSame('Fixture School', $byCode['QUALIFIED_SIGNAL'][0]['organisation']);
        $this->assertSame(['QUALIFIED_SIGNAL' => 1, 'NO_SUPPORTING_QUOTE' => 1, 'OWN_BRAND_MARKETING' => 2], $report['by_reason']);
        foreach ($report['items'] as $item) {
            $this->assertStringStartsWith('https://www.facebook.com/ourpage/posts/', $item['url']);
            $this->assertSame('facebook_page', $item['connector']);
            $this->assertNotSame('', $item['content']);
        }
    }

    public function test_a_report_lists_posts_left_pending_and_posts_that_failed_with_their_error(): void
    {
        config(['signals_connectors.analysis_batch_size' => 10]);
        $this->fakeFeed($this->posts(12));
        $calls = 0;
        $this->fakeResearcher(function (array $docs) use (&$calls) {
            if (++$calls === 2) {
                throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out');
            }

            return $this->nothingQualifies($docs);
        });
        $connector = $this->facebook();
        $sync = $this->queuedSync($connector);
        $this->runJob($connector, $sync);

        $t = $this->getJson("/api/signals/ingestion/connectors/{$connector->id}/syncs/{$sync->id}/report", $this->h())->assertOk()->json('data');

        $this->assertSame([12, 10, 2, 2], [$t['totals']['stored'], $t['totals']['analysed'], $t['totals']['pending'], $t['totals']['errors']]);
        $failed = collect($t['items'])->where('status', 'failed')->first();
        $this->assertSame('AI_ANALYSIS_FAILED', $failed['decision_code']);
        $this->assertStringContainsString('did not answer in time', $failed['error']);
        $this->assertStringNotContainsString('cURL', $failed['error'], 'a plain reason, never the provider\'s raw message');
    }

    public function test_another_organisation_cannot_read_a_report_and_no_secret_is_in_one(): void
    {
        $this->fakeFeed($this->posts(1));
        $this->fakeResearcher(fn (array $docs) => $this->nothingQualifies($docs));
        $connector = $this->facebook(['credentials' => ['access_token' => 'SECRET-TOKEN-VALUE']]);
        $sync = $this->queuedSync($connector);
        $this->runJob($connector, $sync);
        $url = "/api/signals/ingestion/connectors/{$connector->id}/syncs/{$sync->id}/report";

        $this->getJson($url, $this->h(self::T2))->assertNotFound();
        $this->assertStringNotContainsString('SECRET-TOKEN-VALUE', $this->getJson($url, $this->h())->assertOk()->getContent());
        $this->getJson("/api/signals/ingestion/connectors/{$connector->id}/syncs/99999/report", $this->h())->assertNotFound();
    }

    public function test_an_ai_answer_without_an_opportunities_list_is_a_failure_not_a_clean_zero(): void
    {
        $this->mock(SignalRunner::class, fn ($m) => $m->shouldReceive('aiConfigured')->andReturn(true));
        $this->mock(\App\Domain\Signals\Support\StructuredAi::class, fn ($m) => $m->shouldReceive('json')->andReturn(['data' => ['note' => 'sorry'], 'completion' => null]));
        $this->fakeFeed($this->posts(2));
        $connector = $this->facebook();
        $sync = $this->queuedSync($connector);

        $this->runJob($connector, $sync);

        $this->assertSame(0, IngestionAnalysis::where('status', 'success')->count(), 'nothing is recorded as analysed');
        $this->assertSame(2, IngestionAnalysis::where('status', 'failed')->where('decision_code', 'INVALID_AI_RESPONSE')->count());
        $this->assertSame('failed', $sync->fresh()->status);
    }

    // ── 5. mapping the researcher's internal reasons to the structured codes ─

    public function test_the_researchers_internal_rejections_become_the_structured_reason_codes(): void
    {
        $researcher = app(OpportunityResearcher::class);
        $decide = fn (string $code, array $d = []) => (new ReflectionMethod($researcher, 'decisionFor'))->invoke($researcher, $code, $d);

        $this->assertSame('NO_NAMED_PROSPECT', $decide('incomplete_candidate', ['company' => null, 'quote' => 'x']));
        $this->assertSame('NO_SUPPORTING_QUOTE', $decide('incomplete_candidate', ['company' => 'A School', 'quote' => null]));
        $this->assertSame('NO_BUYING_EVENT', $decide('incomplete_candidate', ['company' => 'A School', 'quote' => 'a long enough quote here']));
        $this->assertSame('NO_SUPPORTING_QUOTE', $decide('quote_not_in_source'));
        $this->assertSame('NO_SUPPORTING_QUOTE', $decide('no_cited_source'));
        $this->assertSame('NO_BUYING_EVENT', $decide('no_product_fit'));
        $this->assertSame('IRRELEVANT_CONTENT', $decide('excluded_industry'));
        $this->assertSame('IRRELEVANT_CONTENT', $decide('stale_source'));
    }

    public function test_post_outcomes_follow_the_gate_not_the_models_claim(): void
    {
        $researcher = app(OpportunityResearcher::class);
        $docs = [new IngestionSource(['url' => 'https://a.example/1']), new IngestionSource(['url' => 'https://a.example/2']), new IngestionSource(['url' => 'https://a.example/3']), new IngestionSource(['url' => 'https://a.example/4'])];
        foreach ($docs as $i => $d) {
            $d->id = $i + 1;
        }
        $sidDoc = ['S1' => 1, 'S2' => 2, 'S3' => 3];   // post 4 produced no readable text: never shown to the AI
        $raw = [['source_ids' => ['S1']], ['source_ids' => ['S2']], ['source_ids' => ['S3']]];
        $dropped = [['code' => 'quote_not_in_source', 'source_ids' => ['S2'], 'company' => 'Ghost School', 'quote' => 'invented quote text here']];
        $cards = [0 => ['source_url' => 'https://a.example/1', 'source_excerpt' => 'real quote from post one', 'buyer' => ['name' => 'Real School']],
            1 => ['source_url' => 'https://a.example/3', 'source_excerpt' => 'quote three', 'buyer' => ['name' => 'Gate School']]];
        $results = [['index' => 0, 'outcome' => 'accepted'], ['index' => 1, 'outcome' => 'rejected', 'reason' => 'Evidence too weak for the gate.']];
        $urls = [1 => 'https://a.example/1', 2 => 'https://a.example/2', 3 => 'https://a.example/3', 4 => 'https://a.example/4'];

        $out = (new ReflectionMethod($researcher, 'outcomes'))->invoke($researcher, $docs, $sidDoc, ['S1', 'S2', 'S3'], $raw, $dropped, $cards, $results, $urls);

        $this->assertSame('QUALIFIED_SIGNAL', $out[1]['code']);
        $this->assertSame('real quote from post one', $out[1]['quote']);
        $this->assertSame('Real School', $out[1]['organisation']);
        $this->assertSame('NO_SUPPORTING_QUOTE', $out[2]['code'], 'a card whose quote is not in the post is never a signal');
        $this->assertSame('GATE_REJECTED', $out[3]['code']);
        $this->assertSame('Evidence too weak for the gate.', $out[3]['detail']);
        $this->assertSame('IRRELEVANT_CONTENT', $out[4]['code'], 'text that was never sent to the AI is not "no buying event"');
    }
}
