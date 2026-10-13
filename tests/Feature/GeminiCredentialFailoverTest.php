<?php

namespace Tests\Feature;

use App\Domain\AI\Support\AiCredentialsExhaustedException;
use App\Domain\AI\Support\AiModelClient;
use App\Domain\AI\Support\AiProviderHttpException;
use App\Domain\AI\Support\CredentialHealth;
use App\Domain\Signals\AiFailureClassifier;
use App\Domain\Signals\Support\StructuredAi;
use App\Jobs\RunOpportunityResearchJob;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Gemini credential failover. Every provider call is mocked with Http::fake — no real
 * quota is consumed. Keys are distinct recognisable strings so a leak is detectable.
 */
class GeminiCredentialFailoverTest extends TestCase
{
    private const KEY_A = 'AIzaSecretKeyAAAAAAAAAAAAAAAAAAAAAA';
    private const KEY_B = 'AIzaSecretKeyBBBBBBBBBBBBBBBBBBBBBB';
    private const KEY_C = 'AIzaSecretKeyCCCCCCCCCCCCCCCCCCCCCC';
    private const KEY_T2 = 'AIzaSecretKeyTENANT2222222222222222';

    /** @var array<string, int> calls per key */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Carbon::setTestNow();

        Schema::create('ai_api_keys', function ($t) {
            $t->bigIncrements('id');
            $t->string('account_email')->nullable();
            $t->string('api_type')->nullable();
            $t->string('ai_module')->nullable();
            $t->string('model')->nullable();
            $t->text('api_key');
            $t->string('api_limit')->nullable();
            $t->integer('status')->default(1);
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->timestamps();
        });

        // SchemaCache probes MySQL's information_schema; on the sqlite test database it
        // would report every column missing, so tenant scoping would silently switch off.
        $this->app->instance(\App\Domain\AI\Support\SchemaCache::class, new class extends \App\Domain\AI\Support\SchemaCache {
            public function hasTable(string $table): bool
            {
                return \Illuminate\Support\Facades\Schema::hasTable($table);
            }

            public function hasColumn(string $table, string $column): bool
            {
                return \Illuminate\Support\Facades\Schema::hasColumn($table, $column);
            }
        });

        config([
            'ai.provider.driver' => 'gemini',
            'ai.provider.gemini.api_key' => null,
            'ai.provider.gemini.extra_api_keys' => [],
            'ai.failover.quota_cooldown_seconds' => 60,
            'signals.ai_attempts' => 2,
        ]);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function addKey(string $key, ?int $tenant = 1, string $type = 'gemini'): int
    {
        return DB::table('ai_api_keys')->insertGetId([
            'api_type' => $type, 'api_key' => $key, 'status' => 1, 'sub_institute_id' => $tenant,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param array<string, array{0:int,1:array}|string> $byKey key → [status, body] | 'ok' */
    private function fakeGemini(array $byKey): void
    {
        $this->calls = [];
        // Http::fake() stacks stubs (first match wins), so re-faking needs a clean factory.
        $this->app->forgetInstance(\Illuminate\Http\Client\Factory::class);
        Http::clearResolvedInstance(\Illuminate\Http\Client\Factory::class);
        Http::fake(function (Request $request) use ($byKey) {
            $key = $request->header('x-goog-api-key')[0] ?? '';
            $this->calls[$key] = ($this->calls[$key] ?? 0) + 1;
            $plan = $byKey[$key] ?? [500, []];

            if ($plan === 'ok') {
                return Http::response([
                    'candidates' => [['content' => ['parts' => [['text' => '{"ok":true}']]], 'finishReason' => 'STOP']],
                    'usageMetadata' => ['promptTokenCount' => 3, 'candidatesTokenCount' => 2],
                ], 200);
            }

            return Http::response($plan[1], $plan[0]);
        });
    }

    private function quota(string $quotaId = 'GenerateRequestsPerMinutePerProjectPerModel-FreeTier', string $retry = '34s'): array
    {
        return [429, ['error' => [
            'code' => 429, 'status' => 'RESOURCE_EXHAUSTED', 'message' => 'You exceeded your current quota.',
            'details' => [
                ['@type' => 'type.googleapis.com/google.rpc.QuotaFailure', 'violations' => [['quotaId' => $quotaId]]],
                ['@type' => 'type.googleapis.com/google.rpc.RetryInfo', 'retryDelay' => $retry],
            ],
        ]]];
    }

    private function invalidKey(): array
    {
        return [400, ['error' => ['code' => 400, 'status' => 'INVALID_ARGUMENT', 'message' => 'API key not valid. Please pass a valid API key.',
            'details' => [['@type' => 'type.googleapis.com/google.rpc.ErrorInfo', 'reason' => 'API_KEY_INVALID']]]]];
    }

    private function badRequest(): array
    {
        return [400, ['error' => ['code' => 400, 'status' => 'INVALID_ARGUMENT', 'message' => 'Invalid JSON payload received.']]];
    }

    private function complete(?int $tenant = 1): \App\Domain\AI\Support\AiCompletion
    {
        return app(AiModelClient::class)->complete(
            'analytics_ai',
            [['role' => 'user', 'content' => 'hi']],
            ['json' => true],
            $tenant
        );
    }

    private function fp(int $rowId): string
    {
        return CredentialHealth::fingerprint($rowId, '');
    }

    // ── 1-4: success and rotation ────────────────────────────────────────────

    public function test_single_credential_succeeds(): void
    {
        $this->addKey(self::KEY_A);
        $this->fakeGemini([self::KEY_A => 'ok']);

        $this->assertSame('{"ok":true}', $this->complete()->text);
        $this->assertSame([self::KEY_A => 1], $this->calls);
    }

    public function test_quota_on_first_key_moves_to_second(): void
    {
        $a = $this->addKey(self::KEY_A);
        $b = $this->addKey(self::KEY_B); // newest wins first
        $this->fakeGemini([self::KEY_B => $this->quota(), self::KEY_A => 'ok']);

        $this->assertSame('{"ok":true}', $this->complete()->text);
        $this->assertSame([self::KEY_B => 1, self::KEY_A => 1], $this->calls);
        $this->assertSame('cooldown', app(CredentialHealth::class)->status($this->fp($b)));
        $this->assertSame('healthy', app(CredentialHealth::class)->status($this->fp($a)));
    }

    public function test_several_failures_before_a_later_key_succeeds(): void
    {
        $this->addKey(self::KEY_A);
        $this->addKey(self::KEY_B);
        $this->addKey(self::KEY_C);
        $this->fakeGemini([self::KEY_C => $this->quota(), self::KEY_B => $this->quota(), self::KEY_A => 'ok']);

        $this->assertSame('{"ok":true}', $this->complete()->text);
        $this->assertSame([self::KEY_C => 1, self::KEY_B => 1, self::KEY_A => 1], $this->calls);
    }

    // ── 5: all fail ──────────────────────────────────────────────────────────

    public function test_all_credentials_exhausted_gives_a_clean_error_and_each_key_is_tried_once(): void
    {
        $this->addKey(self::KEY_A);
        $this->addKey(self::KEY_B);
        $this->fakeGemini([self::KEY_A => $this->quota(), self::KEY_B => $this->quota()]);

        try {
            $this->complete();
            $this->fail('expected exhaustion');
        } catch (AiCredentialsExhaustedException $e) {
            $this->assertSame('All configured Google Gemini credentials are temporarily unavailable. Please try again later.', $e->getMessage());
            $this->assertStringNotContainsString('AIza', $e->getMessage());
        }

        $this->assertSame([self::KEY_B => 1, self::KEY_A => 1], $this->calls);

        // A second request does not hit the network at all while both are cooling down.
        $before = $this->calls;
        $this->expectException(AiCredentialsExhaustedException::class);
        try {
            $this->complete();
        } finally {
            $this->assertSame($before, $this->calls);
        }
    }

    // ── 6-7: invalid credential / non-retryable ──────────────────────────────

    public function test_invalid_credential_rotates_and_is_marked_invalid(): void
    {
        $a = $this->addKey(self::KEY_A);
        $b = $this->addKey(self::KEY_B);
        $this->fakeGemini([self::KEY_B => $this->invalidKey(), self::KEY_A => 'ok']);

        $this->assertSame('{"ok":true}', $this->complete()->text);
        $this->assertSame('invalid', app(CredentialHealth::class)->status($this->fp($b)));
        $this->assertSame('healthy', app(CredentialHealth::class)->status($this->fp($a)));
    }

    public function test_all_invalid_surfaces_the_credential_problem_not_a_quota_message(): void
    {
        $this->addKey(self::KEY_A);
        $this->addKey(self::KEY_B);
        $this->fakeGemini([self::KEY_A => $this->invalidKey(), self::KEY_B => $this->invalidKey()]);

        try {
            $this->complete();
            $this->fail('expected failure');
        } catch (AiProviderHttpException $e) {
            $this->assertSame('ai_invalid_credentials', AiFailureClassifier::classify($e)['code']);
        }
    }

    public function test_malformed_request_does_not_rotate(): void
    {
        $this->addKey(self::KEY_A);
        $this->addKey(self::KEY_B);
        $c = $this->addKey(self::KEY_C);
        $this->fakeGemini([self::KEY_C => $this->badRequest(), self::KEY_B => 'ok', self::KEY_A => 'ok']);

        try {
            $this->complete();
            $this->fail('expected failure');
        } catch (AiProviderHttpException $e) {
            $this->assertSame(400, $e->httpStatus);
        }

        $this->assertSame([self::KEY_C => 1], $this->calls);
        $this->assertSame('healthy', app(CredentialHealth::class)->status($this->fp($c)));
    }

    public function test_server_errors_rotate_but_are_capped(): void
    {
        foreach ([self::KEY_A, self::KEY_B, self::KEY_C] as $k) {
            $this->addKey($k);
        }
        config(['ai.failover.max_transient_attempts' => 2]);
        $down = [503, ['error' => ['code' => 503, 'status' => 'UNAVAILABLE', 'message' => 'overloaded']]];
        $this->fakeGemini([self::KEY_A => $down, self::KEY_B => $down, self::KEY_C => $down]);

        $this->expectException(AiCredentialsExhaustedException::class);
        try {
            $this->complete();
        } finally {
            $this->assertSame(2, array_sum($this->calls), 'an outage must not walk the whole pool');
        }
    }

    // ── 8-9: cooldown ────────────────────────────────────────────────────────

    public function test_cooldown_is_respected_then_expires(): void
    {
        $a = $this->addKey(self::KEY_A);
        $b = $this->addKey(self::KEY_B);
        $this->fakeGemini([self::KEY_B => $this->quota(retry: '30s'), self::KEY_A => 'ok']);

        $this->complete();
        $this->assertSame([self::KEY_B => 1, self::KEY_A => 1], $this->calls);

        // While B cools down it is skipped entirely.
        $this->fakeGemini([self::KEY_B => 'ok', self::KEY_A => 'ok']);
        $this->complete();
        $this->assertSame([self::KEY_A => 1], $this->calls);

        // After the provider-supplied delay B is eligible again, and newest-first picks it.
        $this->travelTo(now()->addSeconds(31));
        $this->fakeGemini([self::KEY_B => 'ok', self::KEY_A => 'ok']);
        $this->complete();
        $this->assertSame([self::KEY_B => 1], $this->calls);
        $this->assertSame('healthy', app(CredentialHealth::class)->status($this->fp($b)));
    }

    public function test_per_day_quota_cools_down_much_longer_than_per_minute(): void
    {
        $a = $this->addKey(self::KEY_A);
        $this->addKey(self::KEY_B);
        $this->fakeGemini([self::KEY_B => $this->quota('GenerateRequestsPerDayPerProjectPerModel-FreeTier', '20s'), self::KEY_A => 'ok']);
        $this->complete();

        $state = app(CredentialHealth::class)->state($this->fp($a + 1));
        $this->assertGreaterThan(now()->getTimestamp() + 60, $state['cooldown_until']);
        $this->assertSame('quota', $state['last_error_type']);
        $this->assertSame(1, $state['failure_count']);
    }

    // ── 10: tenant isolation ─────────────────────────────────────────────────

    public function test_tenant_never_uses_another_tenants_credentials(): void
    {
        $this->addKey(self::KEY_A, 1);
        $this->addKey(self::KEY_T2, 2);
        $this->fakeGemini([self::KEY_A => $this->quota(), self::KEY_T2 => 'ok']);

        try {
            $this->complete(1);
            $this->fail('tenant 1 has only its own key, which is exhausted');
        } catch (\Throwable $e) {
            $this->assertArrayNotHasKey(self::KEY_T2, $this->calls);
        }

        $this->calls = [];
        $this->assertSame('{"ok":true}', $this->complete(2)->text);
        $this->assertSame([self::KEY_T2 => 1], $this->calls);
    }

    public function test_tenant_keys_win_over_platform_and_platform_is_not_mixed_in(): void
    {
        $this->addKey(self::KEY_A, 1);
        $this->addKey(self::KEY_B, null); // platform
        $this->fakeGemini([self::KEY_A => $this->quota(), self::KEY_B => 'ok']);

        // Tenant 1 owns a key, so exhausting it must not silently spend the platform's.
        try {
            $this->complete(1);
            $this->fail('expected failure');
        } catch (\Throwable) {
            $this->assertArrayNotHasKey(self::KEY_B, $this->calls);
        }

        // A tenant with no keys of its own uses the platform pool.
        $this->calls = [];
        $this->assertSame('{"ok":true}', $this->complete(3)->text);
        $this->assertSame([self::KEY_B => 1], $this->calls);
    }

    // ── env-based pool ───────────────────────────────────────────────────────

    public function test_environment_keys_fail_over_when_the_pool_is_empty(): void
    {
        config([
            'ai.provider.gemini.api_key' => self::KEY_A,
            'ai.provider.gemini.extra_api_keys' => [self::KEY_B, self::KEY_C],
        ]);
        $this->fakeGemini([self::KEY_A => $this->quota(), self::KEY_B => $this->quota(), self::KEY_C => 'ok']);

        $this->assertSame('{"ok":true}', $this->complete()->text);
        $this->assertSame([self::KEY_A => 1, self::KEY_B => 1, self::KEY_C => 1], $this->calls);
    }

    // ── 11: no key leakage ───────────────────────────────────────────────────

    public function test_keys_never_reach_logs_or_usage_rows(): void
    {
        $this->addKey(self::KEY_A);
        $this->addKey(self::KEY_B);
        Schema::create('ai_usage_events', function ($t) {
            $t->bigIncrements('id');
            foreach (['ai_module', 'provider', 'model', 'source', 'outcome', 'finish_reason', 'error', 'related_type'] as $c) {
                $t->string($c, 2000)->nullable();
            }
            foreach (['input_tokens', 'output_tokens', 'latency_ms', 'related_id', 'user_id'] as $c) {
                $t->integer($c)->nullable();
            }
            $t->decimal('estimated_cost_usd', 12, 6)->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->timestamps();
        });

        // A provider that (wrongly) echoes the key in its error message.
        $echo = [429, ['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED', 'message' => 'quota for key ' . self::KEY_B]]];
        $this->fakeGemini([self::KEY_B => $echo, self::KEY_A => 'ok']);
        Log::spy();

        $this->complete();

        Log::shouldHaveReceived('warning')->withArgs(function ($message, $context = []) {
            $dump = $message . json_encode($context);

            return ! str_contains($dump, 'AIza') && ($context['failure'] ?? null) === 'quota';
        })->atLeast()->once();

        $this->assertStringNotContainsString('AIzaSecret', json_encode(DB::table('ai_usage_events')->get()));
    }

    // ── Signals-only keys ────────────────────────────────────────────────────

    private function addModuleKey(string $key, string $module, ?int $tenant = 1): int
    {
        $id = $this->addKey($key, $tenant);
        DB::table('ai_api_keys')->where('id', $id)->update(['ai_module' => $module]);

        return $id;
    }

    private function completeAs(string $module, ?int $tenant = 1): \App\Domain\AI\Support\AiCompletion
    {
        return app(AiModelClient::class)->complete($module, [['role' => 'user', 'content' => 'hi']], ['json' => true], $tenant);
    }

    public function test_signals_keys_fail_over_among_themselves_only(): void
    {
        $this->addModuleKey(self::KEY_A, 'signals');
        $this->addModuleKey(self::KEY_B, 'signals');
        $this->addModuleKey(self::KEY_C, 'analytics_ai'); // another module's key, same organisation
        $this->fakeGemini([self::KEY_B => $this->quota(), self::KEY_A => $this->quota(), self::KEY_C => 'ok']);

        try {
            $this->completeAs('signals');
            $this->fail('both Signals keys are exhausted; another module key must not be used');
        } catch (AiCredentialsExhaustedException) {
            $this->assertSame([self::KEY_B => 1, self::KEY_A => 1], $this->calls);
        }
    }

    public function test_other_modules_never_use_signals_keys(): void
    {
        $this->addModuleKey(self::KEY_A, 'signals');
        $this->addModuleKey(self::KEY_B, 'signals');
        $this->fakeGemini([self::KEY_A => 'ok', self::KEY_B => 'ok']);

        // 'analytics_ai' has no key of its own; it must not fall back to the Signals keys.
        try {
            $this->completeAs('analytics_ai');
            $this->fail('expected not configured');
        } catch (\App\Domain\AI\Support\AiNotConfiguredException) {
            $this->assertSame([], $this->calls);
        }
    }
    // ── 12: retry safety ─────────────────────────────────────────────────────

    public function test_research_retry_layer_does_not_multiply_calls(): void
    {
        $this->addKey(self::KEY_A);
        $this->addKey(self::KEY_B);
        $this->fakeGemini([self::KEY_A => $this->quota(), self::KEY_B => $this->quota()]);

        // StructuredAi would otherwise retry `signals.ai_attempts` (=2) times.
        try {
            app(StructuredAi::class)->json(1, [['role' => 'user', 'content' => 'x']]);
            $this->fail('expected exhaustion');
        } catch (AiCredentialsExhaustedException) {
        }

        $this->assertSame(2, array_sum($this->calls), 'two keys, one attempt each, no second retry round');

        $failure = AiFailureClassifier::classify(AiCredentialsExhaustedException::forProvider('Gemini'));
        $this->assertSame('ai_rate_limited', $failure['code']);
    }

    public function test_research_job_is_not_queue_retried(): void
    {
        $this->assertSame(1, (new RunOpportunityResearchJob(1, null))->tries);
    }

    public function test_deepseek_flash_is_called_with_thinking_disabled_but_deepseek_chat_is_not(): void
    {
        config(['ai.provider.driver' => 'deepseek', 'ai.provider.deepseek.api_key' => 'ds-key-111111111']);

        foreach (['deepseek-flash' => true, 'deepseek-v4-flash' => true, 'deepseek-chat' => false] as $model => $expectsThinkingOff) {
            config(['ai.provider.deepseek.model' => $model]);
            $this->app->forgetInstance(\Illuminate\Http\Client\Factory::class);
            Http::clearResolvedInstance(\Illuminate\Http\Client\Factory::class);
            $body = null;
            Http::fake(function (Request $request) use (&$body) {
                $body = $request->data();

                return Http::response(['choices' => [['message' => ['content' => '{"ok":true}'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1]], 200);
            });

            $this->complete();

            $this->assertSame($model, $body['model']);
            $this->assertSame($expectsThinkingOff ? ['type' => 'disabled'] : null, $body['thinking'] ?? null, $model);
        }
    }
    // ── 13: DeepSeek unchanged ───────────────────────────────────────────────

    public function test_deepseek_is_not_rotated_and_still_works(): void
    {
        config(['ai.provider.driver' => 'deepseek', 'ai.provider.deepseek.api_key' => 'ds-key-111111111']);
        $this->addKey('ds-key-222222222', 1, 'DEEPSEEK_API_KEY');
        $this->addKey('ds-key-333333333', 1, 'DEEPSEEK_API_KEY');

        $seen = [];
        Http::fake(function (Request $request) use (&$seen) {
            $seen[] = $request->header('Authorization')[0] ?? '';

            return Http::response(['choices' => [['message' => ['content' => 'hello'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1]], 200);
        });

        $this->assertSame('hello', $this->complete()->text);
        $this->assertSame(['Bearer ds-key-333333333'], $seen); // newest pool key, exactly as before

        // A 429 from DeepSeek is surfaced as before, not rotated.
        $seen = [];
        $this->app->forgetInstance(\Illuminate\Http\Client\Factory::class);
        Http::clearResolvedInstance(\Illuminate\Http\Client\Factory::class);
        Http::fake(function (Request $request) use (&$seen) {
            $seen[] = 1;

            return Http::response(['error' => ['message' => 'rate']], 429);
        });
        try {
            $this->complete();
            $this->fail('expected failure');
        } catch (AiProviderHttpException $e) {
            $this->assertSame(429, $e->httpStatus);
        }
        $this->assertCount(1, $seen);
    }
}
