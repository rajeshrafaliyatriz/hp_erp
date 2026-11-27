<?php

namespace Tests\Feature;

use App\Domain\Signals\AiSignals\AiDelivery;
use App\Domain\Signals\AiSignals\AiDeliveryToken;
use App\Domain\Signals\AiSignals\AiSignalNormalizer;
use App\Domain\Signals\AiSignals\AiSignalProviders;
use App\Domain\Signals\Connectors\DataConnectorRegistry;
use App\Domain\Signals\Ingestion\DataConnector;
use App\Domain\Signals\Ingestion\DataConnectorSync;
use App\Domain\Signals\Ingestion\IngestionAnalyzer;
use App\Domain\Signals\Ingestion\IngestionService;
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
 * AI signal connectors (Claude, ChatGPT / GPT, DeepSeek, Gemini): the organisation's own scheduled tasks DELIVER finished signals, G2G imports them.
 * In-memory sqlite, every external request blocked: nothing here can reach a provider or a model.
 */
class AiSignalConnectorsTest extends TestCase
{
    private const T1 = 1;
    private const T2 = 2;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Carbon::setTestNow('2026-11-27 10:00:00');
        Cache::flush();
        config(['signals.legacy_product_icp' => true, 'signals.web_search.driver' => 'none']);
        QueueWorkerLauncher::fake(fn () => null);

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
        foreach ([self::T1, self::T2] as $tenant) {
            ProductProfile::create([
                'sub_institute_id' => $tenant, 'product_name' => 'ScholarClone', 'description' => 'School ERP system', 'problems_solved' => 'Manual school operations',
                'features' => 'Fee collection', 'target_industries' => ['Education'], 'target_markets' => ['India'], 'keywords' => ['school erp'],
                'excluded' => [], 'research_enabled' => true, 'research_frequency' => 'daily', 'recency_days' => 30,
            ]);
        }
    }

    protected function tearDown(): void
    {
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

    /** Sets a source up through the real endpoint; returns [connector id, the secret shown once]. */
    private function setUpSource(string $provider, int $tenant = self::T1): array
    {
        $r = $this->postJson("/api/signals/ingestion/ai-signals/{$provider}/enable", [], $this->h($tenant))->assertStatus(201);

        return [(int) $r->json('data.connector_id'), (string) $r->json('secret')];
    }

    private function deliver(string $secret, array|string $body, array $extra = []): \Illuminate\Testing\TestResponse
    {
        $raw = is_array($body) ? json_encode($body) : $body;

        return $this->call('POST', '/api/signals/ai-delivery', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $secret, 'HTTP_ACCEPT' => 'application/json'] + $extra, $raw);
    }

    private function signal(array $o = []): array
    {
        return $o + [
            'buyer' => ['name' => 'Sunrise Public School', 'type' => 'school', 'state' => 'Maharashtra'], 'trigger_type' => 'vendor_search',
            'trigger_summary' => 'The school is comparing school ERP vendors for the next academic year.',
            'what_happened' => 'Sunrise Public School published a notice inviting school ERP vendors to demonstrate.', 'event_date' => '2026-11-25',
            'source_url' => 'https://news.example.com/sunrise-erp', 'source_title' => 'Sunrise ERP notice', 'claim_level' => 'inference', 'fetch_level' => 'search_snippet',
        ];
    }

    private function runSync(int $connectorId, int $tenant = self::T1): DataConnectorSync
    {
        $sync = DataConnectorSync::where('sub_institute_id', $tenant)->where('connector_id', $connectorId)->where('status', 'queued')->orderByDesc('id')->firstOrFail();
        (new SyncDataConnectorJob($tenant, $connectorId, (int) $sync->id, null))->handle(app(DataConnectorRegistry::class), app(IngestionService::class), app(IngestionAnalyzer::class));

        return $sync->refresh();
    }

    // ── the normalizer: rearranges, never invents ────────────────────────────

    public function test_the_normalizer_reads_the_common_envelopes_and_a_fenced_block(): void
    {
        $rec = ['company' => 'Sunrise School', 'event' => 'Wants an ERP', 'url' => 'https://x.test/a', 'date' => '2026-11-20', 'claim_level' => 'Confirmed', 'fetch_level' => 'Search Snippet'];
        foreach ([
            json_encode([$rec]), json_encode(['signals' => [$rec]]), json_encode(['opportunities' => [$rec]]), json_encode(['data' => ['records' => [$rec]]]),
            "Here is the feed:\n```json\n" . json_encode(['signals' => [$rec]]) . "\n```\nThanks",
        ] as $raw) {
            $p = AiSignalNormalizer::parse($raw);
            $this->assertTrue($p['ok'], $raw);
            $this->assertCount(1, $p['records']);
            $r = $p['records'][0];
            $this->assertSame('Sunrise School', $r['buyer']['name']);
            $this->assertSame('Wants an ERP', $r['trigger_summary']);
            $this->assertSame('https://x.test/a', $r['source_url']);
            $this->assertSame('2026-11-20', $r['event_date']);
            $this->assertSame('confirmed', $r['claim_level']);
            $this->assertSame('search_snippet', $r['fetch_level']);
        }
        $this->assertFalse(AiSignalNormalizer::parse('not json at all')['ok']);
        $this->assertFalse(AiSignalNormalizer::parse(json_encode(['hello' => 'world']))['ok']);
    }

    public function test_the_normalizer_never_overwrites_a_canonical_field_and_never_invents_a_missing_one(): void
    {
        $r = AiSignalNormalizer::normaliseRecord(['buyer' => ['name' => 'Real School'], 'company' => 'Other Name', 'trigger_summary' => 'Canonical', 'event' => 'Alias', 'event_date' => '2026-11-01', 'date' => '2026-01-01']);
        $this->assertSame('Real School', $r['buyer']['name']);
        $this->assertSame('Canonical', $r['trigger_summary']);
        $this->assertSame('2026-11-01', $r['event_date']);

        $bare = AiSignalNormalizer::normaliseRecord(['company' => 'Only A Name']);
        $this->assertArrayNotHasKey('event_date', $bare, 'no date is made up');
        $this->assertArrayNotHasKey('source_url', $bare);
        $this->assertArrayNotHasKey('claim_level', $bare);
        $this->assertArrayNotHasKey('fetch_level', $bare);
    }

    // ── the door: authentication ─────────────────────────────────────────────

    public function test_a_wrong_missing_or_foreign_token_is_refused_the_same_way(): void
    {
        [$claudeId, $claudeSecret] = $this->setUpSource('claude');
        [, $gptSecret] = $this->setUpSource('gpt');
        $body = ['signals' => [$this->signal()]];

        $answers = [];
        foreach ([
            'none' => $this->call('POST', '/api/signals/ai-delivery', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode($body)),
            'garbage' => $this->deliver('not-a-token', $body),
            'right id, wrong secret' => $this->deliver('g2gai_' . $claudeId . '_' . str_repeat('a', 48), $body),
            'unknown connector' => $this->deliver('g2gai_999999_' . str_repeat('b', 48), $body),
        ] as $label => $res) {
            $res->assertStatus(401);
            $answers[$label] = $res->json('message');
        }
        $this->assertCount(1, array_unique($answers), 'unknown connector, wrong secret and no token all say the same thing');
        // the GPT secret does not open the Claude connector's door (and the delivery lands, if at all, on GPT's own connector)
        $this->assertSame(0, AiDelivery::count());
        $this->deliver($gptSecret, ['provider' => 'claude', 'signals' => [$this->signal()]])->assertStatus(422)->assertJson(['code' => 'provider_mismatch']);
        $this->assertSame(0, AiDelivery::where('provider', 'claude')->count());
        $this->assertNotSame($claudeSecret, $gptSecret);
    }

    public function test_the_secret_is_shown_once_stored_only_as_a_hash_and_never_returned_again(): void
    {
        [$id, $secret] = $this->setUpSource('claude');
        $this->assertMatchesRegularExpression('/^g2gai_' . $id . '_[a-f0-9]{48}$/', $secret);

        $stored = DataConnector::find($id)->credentials;
        $this->assertSame(hash('sha256', $secret), $stored['delivery_token_hash']);
        $this->assertStringNotContainsString($secret, json_encode(DB::table('g2g_data_connectors')->get()), 'the secret is not stored in readable form');

        $index = $this->getJson('/api/signals/ingestion/ai-signals', $this->h())->assertOk();
        $this->assertStringNotContainsString($secret, $index->getContent());
        $this->assertStringNotContainsString($stored['delivery_token_hash'], $index->getContent());
        $this->assertSame('…' . substr($secret, -4), $index->json('data.0.token_hint'));

        // enabling again does not reveal it again
        $again = $this->postJson('/api/signals/ingestion/ai-signals/claude/enable', [], $this->h())->assertOk();
        $this->assertNull($again->json('secret'));
    }

    public function test_rotating_the_secret_stops_the_old_one_at_once(): void
    {
        [, $old] = $this->setUpSource('claude');
        $new = $this->postJson('/api/signals/ingestion/ai-signals/claude/token', [], $this->h())->assertOk()->json('secret');
        $this->assertNotSame($old, $new);
        Queue::fake();
        $this->deliver($old, ['signals' => [$this->signal()]])->assertStatus(401);
        $this->deliver($new, ['signals' => [$this->signal()]])->assertStatus(202);
    }

    public function test_a_switched_off_connector_refuses_deliveries(): void
    {
        [, $secret] = $this->setUpSource('claude');
        $this->putJson('/api/signals/ingestion/ai-signals/claude', ['enabled' => false], $this->h())->assertOk()->assertJsonPath('data.enabled', false);
        $this->deliver($secret, ['signals' => [$this->signal()]])->assertStatus(403);
        $this->assertSame(0, AiDelivery::count());
    }

    // ── a delivery: stored as received, idempotent, tenant from the connector ─

    public function test_a_delivery_is_stored_as_received_under_the_connectors_own_organisation(): void
    {
        Queue::fake();
        [$id, $secret] = $this->setUpSource('claude');
        $raw = json_encode(['delivery_id' => 'claude-morning-2026-11-27', 'task' => 'Scholar Clone - Morning Opportunity Feed (10:00 IST)', 'sub_institute_id' => 2, 'tenant' => 2, 'signals' => [$this->signal()]]);

        $res = $this->deliver($secret, $raw)->assertStatus(202)->assertJsonPath('data.duplicate', false)->assertJsonPath('data.records', 1);
        $d = AiDelivery::findOrFail($res->json('data.delivery_id'));
        $this->assertSame($raw, $d->payload, 'kept exactly as received');
        $this->assertSame(self::T1, (int) $d->sub_institute_id, 'the organisation comes from the connector, never from the payload');
        $this->assertSame($id, (int) $d->connector_id);
        $this->assertSame('claude-morning-2026-11-27', $d->delivery_ref);
        $this->assertSame('received', $d->status);
        Queue::assertPushed(SyncDataConnectorJob::class, 1);   // daily automatic ingestion: a delivery starts its own import
    }

    public function test_the_same_delivery_twice_is_stored_once_even_when_keys_and_spacing_differ(): void
    {
        Queue::fake();
        [, $secret] = $this->setUpSource('claude');
        $a = ['signals' => [$this->signal()], 'delivery_id' => 'd1'];
        $first = $this->deliver($secret, json_encode($a))->assertStatus(202);
        $reordered = '{ "delivery_id": "d1",   "signals": [ ' . json_encode($this->signal()) . ' ] }';
        $second = $this->deliver($secret, $reordered)->assertOk()->assertJsonPath('data.duplicate', true);

        $this->assertSame(1, AiDelivery::count());
        $this->assertSame($first->json('data.delivery_id'), $second->json('data.delivery_id'));
    }

    public function test_unusable_deliveries_are_refused_with_a_reason_and_the_bad_ones_are_kept_visible(): void
    {
        Queue::fake();
        [, $secret] = $this->setUpSource('claude');

        $this->deliver($secret, '   ')->assertStatus(422)->assertJson(['code' => 'empty']);
        $this->deliver($secret, 'this is not json')->assertStatus(422)->assertJson(['code' => 'invalid']);
        $this->assertSame(1, AiDelivery::where('status', 'failed')->count(), 'a body that cannot be read is recorded as a failed delivery');
        $this->assertStringContainsString('not valid JSON', (string) AiDelivery::first()->error_message);

        config(['signals_connectors.ai_delivery.max_records' => 2]);
        $this->deliver($secret, ['signals' => [$this->signal(), $this->signal(), $this->signal()]])->assertStatus(422)->assertJson(['code' => 'too_many_records']);
        config(['signals_connectors.ai_delivery.max_kb' => 1]);
        $this->deliver($secret, ['signals' => [$this->signal(['what_happened' => str_repeat('x', 3000)])]])->assertStatus(413)->assertJson(['code' => 'too_large']);
        Queue::assertNothingPushed();
    }

    // ── import: the existing importer, the existing gate, counts ─────────────

    public function test_syncing_imports_through_the_existing_gate_with_attribution_and_honest_counts(): void
    {
        Queue::fake();
        [$id, $secret] = $this->setUpSource('claude');
        $good = $this->signal();
        $sameAgain = $this->signal();                                                       // the same event again in the same delivery
        $noDate = $this->signal(['buyer' => ['name' => 'Moonlight Academy'], 'source_url' => 'https://news.example.com/moonlight']);
        unset($noDate['event_date']);                                                       // fails the gate: no date
        $aliased = ['company' => 'Riverdale School', 'event' => 'Hiring an ERP administrator', 'url' => 'https://jobs.example.com/riverdale', 'date' => '2026-11-26', 'claim_level' => 'Hypothesis', 'fetch_level' => 'Search Snippet', 'trigger_type' => 'hiring'];

        $this->deliver($secret, ['delivery_id' => 'd1', 'signals' => [$good, $sameAgain, $noDate, $aliased]])->assertStatus(202);
        $sync = $this->runSync($id);

        $this->assertSame('partial', $sync->status);
        $d = AiDelivery::first();
        $this->assertSame('partial', $d->status);
        $this->assertSame([4, 2, 0, 1, 1], [$d->records_total, $d->records_accepted, $d->records_updated, $d->records_duplicate, $d->records_rejected]);
        $this->assertNotNull($d->scan_log_id);
        $this->assertSame(['accepted', 'duplicate', 'rejected', 'accepted'], array_column(json_decode($d->results, true), 'outcome'));
        $this->assertSame('missing_date', json_decode($d->results, true)[2]['reason_code'], 'the existing gate gave the reason; nothing was defaulted');

        $this->assertSame(2, CompanyOpportunity::count());
        $this->assertSame(['ai:claude'], CompanyOpportunity::pluck('import_source')->unique()->values()->all());
        $this->assertSame(self::T1, (int) CompanyOpportunity::first()->sub_institute_id);
        $this->assertSame([2, 2, 1, 1], [(int) $sync->records_created, (int) $sync->records_processed, (int) $sync->records_duplicate, (int) $sync->records_failed]);
        $this->assertSame(4, (int) $sync->records_fetched);

        // the connector's own numbers (what the card shows)
        $slot = collect($this->getJson('/api/signals/ingestion/ai-signals', $this->h())->assertOk()->json('data'))->firstWhere('provider', 'claude');
        $this->assertSame(['deliveries' => 1, 'imported' => 2, 'updated' => 0, 'duplicate' => 1, 'failed' => 1, 'failed_deliveries' => 0, 'waiting' => 0], $slot['totals']);
        $this->assertNotNull($slot['last_received_at']);
        $this->assertNotNull($slot['last_sync_at']);
        $this->assertSame('partial', $slot['last_sync_status']);
        Http::assertNothingSent();   // no model, no search, no scraping
    }

    public function test_imported_signals_show_in_the_existing_list_with_their_source_and_under_today(): void
    {
        Queue::fake();
        [$id, $secret] = $this->setUpSource('gemini');
        $this->deliver($secret, ['signals' => [$this->signal()]])->assertStatus(202);
        $this->runSync($id);

        $rows = $this->getJson('/api/signals/opportunities?per_page=50&run_date=2026-11-27', $this->h())->assertOk()->json('data');
        $this->assertCount(1, $rows, 'it is in the existing Signals list, under the day it was delivered');
        $this->assertSame('ai_gemini', $rows[0]['found_via']['platform']);
        $this->assertSame('Gemini', $rows[0]['found_via']['label']);
        $this->assertSame('connector', $rows[0]['found_via']['kind']);
        $this->assertSame('gemini', $rows[0]['ai_source']);
        $this->assertFalse($rows[0]['entered_manually']);
        $this->assertSame('Sunrise Public School', $rows[0]['company_name']);
        $this->assertSame('https://news.example.com/sunrise-erp', $rows[0]['source_url']);
        $this->assertCount(0, $this->getJson('/api/signals/opportunities?per_page=50', $this->h(self::T2))->assertOk()->json('data'), 'another organisation sees nothing');
    }

    public function test_a_new_delivery_of_a_known_signal_is_a_duplicate_not_a_second_signal(): void
    {
        Queue::fake();
        [$id, $secret] = $this->setUpSource('claude');
        $this->deliver($secret, ['delivery_id' => 'monday', 'signals' => [$this->signal()]])->assertStatus(202);
        $this->runSync($id);
        $this->deliver($secret, ['delivery_id' => 'tuesday', 'signals' => [$this->signal()]])->assertStatus(202);   // a different delivery, the same signal
        $sync = $this->runSync($id);

        $this->assertSame(1, CompanyOpportunity::count());
        $this->assertSame(2, AiDelivery::count());
        $this->assertSame(1, (int) AiDelivery::orderByDesc('id')->first()->records_duplicate);
        $this->assertSame('completed', $sync->status);
    }

    public function test_a_sync_with_nothing_waiting_completes_and_says_so(): void
    {
        Queue::fake();
        [$id] = $this->setUpSource('claude');
        $this->postJson('/api/signals/ingestion/ai-signals/claude/sync', [], $this->h())->assertStatus(202);
        $sync = $this->runSync($id);
        $this->assertSame('completed', $sync->status);
        $this->assertStringContainsString('No new deliveries', (string) $sync->error_message);
        $this->assertSame(0, CompanyOpportunity::count());
    }

    public function test_a_delivery_that_errors_is_retried_by_the_next_sync_and_then_given_up_on(): void
    {
        Queue::fake();
        [$id, $secret] = $this->setUpSource('claude');
        $this->deliver($secret, ['signals' => [$this->signal()]])->assertStatus(202);
        $d = AiDelivery::first();
        $d->forceFill(['status' => 'failed', 'attempts' => 1, 'error_message' => 'boom'])->save();   // as if the import had thrown once
        $this->runSync($id);
        $this->assertSame('imported', $d->refresh()->status);
        $this->assertSame(1, CompanyOpportunity::count());

        $stuck = AiDelivery::create(['sub_institute_id' => self::T1, 'connector_id' => $id, 'provider' => 'claude', 'idempotency_key' => str_repeat('c', 64), 'status' => 'failed', 'attempts' => AiDelivery::MAX_ATTEMPTS,
            'payload' => '{}', 'received_at' => now(), 'error_message' => 'gave up']);
        $this->postJson('/api/signals/ingestion/ai-signals/claude/sync', [], $this->h())->assertStatus(202);
        $this->runSync($id);
        $this->assertSame(AiDelivery::MAX_ATTEMPTS, (int) $stuck->refresh()->attempts, 'an exhausted delivery is not tried again');
    }

    // ── Sync, Sync All, paste ────────────────────────────────────────────────

    public function test_sync_all_starts_every_switched_on_source_and_skips_the_rest(): void
    {
        Queue::fake();
        $this->setUpSource('claude');
        $this->setUpSource('gpt');
        $this->setUpSource('deepseek');
        $this->putJson('/api/signals/ingestion/ai-signals/deepseek', ['enabled' => false], $this->h())->assertOk();

        $r = $this->postJson('/api/signals/ingestion/ai-signals/sync-all', [], $this->h())->assertStatus(202);
        $this->assertSame(['claude' => 'started', 'gpt' => 'started'], $r->json('results'));
        Queue::assertPushed(SyncDataConnectorJob::class, 2);
        $this->assertSame(2, DataConnectorSync::where('status', 'queued')->count());

        $again = $this->postJson('/api/signals/ingestion/ai-signals/sync-all', [], $this->h())->assertStatus(202);
        $this->assertSame(['claude' => 'already_running', 'gpt' => 'already_running'], $again->json('results'));
        $this->postJson('/api/signals/ingestion/ai-signals/claude/sync', [], $this->h())->assertStatus(409);
    }

    public function test_sync_all_with_nothing_set_up_says_so(): void
    {
        $this->postJson('/api/signals/ingestion/ai-signals/sync-all', [], $this->h())->assertStatus(409);
        $this->postJson('/api/signals/ingestion/ai-signals/claude/sync', [], $this->h())->assertStatus(404);
    }

    public function test_pasting_an_output_goes_through_the_same_storage_and_import(): void
    {
        Queue::fake();
        [$id] = $this->setUpSource('gpt');
        $paste = "```json\n" . json_encode(['signals' => [$this->signal()]]) . "\n```";
        $this->postJson('/api/signals/ingestion/ai-signals/gpt/deliveries', ['payload' => $paste], $this->h())->assertStatus(202);
        $this->assertSame('manual', AiDelivery::first()->via);
        $this->runSync($id);
        $this->assertSame(1, CompanyOpportunity::count());
        $this->assertSame('ai:gpt', CompanyOpportunity::first()->import_source);

        $this->postJson('/api/signals/ingestion/ai-signals/gpt/deliveries', ['payload' => $paste], $this->h())->assertOk();   // the same paste again
        $this->assertSame(1, AiDelivery::count());
        $this->postJson('/api/signals/ingestion/ai-signals/gpt/deliveries', ['payload' => 'nonsense'], $this->h())->assertStatus(422);
    }

    // ── isolation, and the existing connectors are untouched ─────────────────

    public function test_organisations_never_see_or_drive_each_others_sources(): void
    {
        Queue::fake();
        [$id1, $secret1] = $this->setUpSource('claude', self::T1);
        $this->deliver($secret1, ['signals' => [$this->signal()]])->assertStatus(202);

        $two = collect($this->getJson('/api/signals/ingestion/ai-signals', $this->h(self::T2))->assertOk()->json('data'))->firstWhere('provider', 'claude');
        $this->assertFalse($two['configured']);
        $this->assertNull($two['connector_id']);
        $this->assertSame(0, $two['totals']['deliveries']);
        $this->postJson('/api/signals/ingestion/ai-signals/claude/sync', [], $this->h(self::T2))->assertStatus(404);
        $this->getJson('/api/signals/ingestion/ai-signals/claude/deliveries', $this->h(self::T2))->assertStatus(404);
        $this->postJson('/api/signals/ingestion/ai-signals/claude/token', [], $this->h(self::T2))->assertStatus(404);

        // organisation 2 sets up its own: a different connector, a different secret, and organisation 1's secret does not belong to it
        [$id2, $secret2] = $this->setUpSource('claude', self::T2);
        $this->assertNotSame($id1, $id2);
        $this->deliver($secret2, ['signals' => [$this->signal(['buyer' => ['name' => 'Other Org School'], 'source_url' => 'https://news.example.com/other'])]])->assertStatus(202);
        $this->assertSame(1, AiDelivery::where('sub_institute_id', self::T1)->count());
        $this->assertSame(1, AiDelivery::where('sub_institute_id', self::T2)->count());
    }

    public function test_the_existing_connector_list_and_creation_are_unchanged(): void
    {
        $this->setUpSource('claude');
        DataConnector::create(['sub_institute_id' => self::T1, 'type' => 'facebook_page', 'name' => 'Our Page', 'url' => 'https://www.facebook.com/ourpage', 'external_account_id' => 'P1',
            'product_key' => 'scholarclone', 'connection_status' => 'connected', 'sync_enabled' => true]);

        $list = $this->getJson('/api/signals/ingestion/connectors', $this->h())->assertOk()->json('data');
        $this->assertSame(['facebook_page'], array_column($list, 'type'), 'the existing panel never receives the AI sources');
        $this->assertNotContains('ai_claude', array_column($this->getJson('/api/signals/ingestion/connectors', $this->h())->json('available'), 'key'));
        // the existing "add connector" endpoint still refuses an AI type: AI sources are set up only through their own endpoint
        $this->postJson('/api/signals/ingestion/connectors', ['type' => 'ai_claude', 'name' => 'x', 'product_key' => 'scholarclone'], $this->h())->assertStatus(422);
    }

    public function test_the_four_sources_are_listed_in_order_with_their_labels(): void
    {
        $slots = $this->getJson('/api/signals/ingestion/ai-signals', $this->h())->assertOk()->json('data');
        $this->assertSame(['claude', 'gpt', 'deepseek', 'gemini'], array_column($slots, 'provider'));
        $this->assertSame(['Claude', 'ChatGPT / GPT', 'DeepSeek', 'Gemini'], array_column($slots, 'label'));
        $this->assertSame(['not_configured', 'not_configured', 'not_configured', 'not_configured'], array_column($slots, 'state'));
        $this->assertStringEndsWith('/api/signals/ai-delivery', $slots[0]['delivery_url']);
    }

    public function test_a_server_without_the_table_says_so_instead_of_failing(): void
    {
        Schema::drop('g2g_ai_deliveries');
        $r = $this->getJson('/api/signals/ingestion/ai-signals', $this->h())->assertOk();
        $this->assertFalse($r->json('tables_migrated'));
        $this->assertCount(4, $r->json('data'));
        $this->postJson('/api/signals/ingestion/ai-signals/claude/enable', [], $this->h())->assertStatus(503);
        $this->call('POST', '/api/signals/ai-delivery', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . 'g2gai_1_' . str_repeat('a', 48), 'HTTP_ACCEPT' => 'application/json'], '{}')->assertStatus(401);
    }

    public function test_the_switch_off_setting_stops_the_door(): void
    {
        [, $secret] = $this->setUpSource('claude');
        config(['signals_connectors.ai_delivery.enabled' => false]);
        $this->deliver($secret, ['signals' => [$this->signal()]])->assertStatus(503);
    }

    public function test_no_secret_reaches_the_log(): void
    {
        Queue::fake();
        [, $secret] = $this->setUpSource('claude');
        $logged = [];
        \Illuminate\Support\Facades\Log::listen(function ($m) use (&$logged) {
            $logged[] = $m->message . json_encode($m->context);
        });
        $this->deliver($secret, ['signals' => [$this->signal()]])->assertStatus(202);
        $this->deliver('g2gai_1_' . str_repeat('d', 48), ['signals' => []])->assertStatus(401);
        $this->assertNotEmpty($logged);
        foreach ($logged as $line) {
            $this->assertStringNotContainsString($secret, $line);
            $this->assertStringNotContainsString(substr($secret, -20), $line);
            $this->assertStringNotContainsString('Sunrise Public School', $line, 'the delivered text is not logged either');
        }
    }
}
