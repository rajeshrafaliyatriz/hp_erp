<?php

namespace App\Domain\Signals\Providers;

use App\Domain\Signals\Support\SignalsAiModule;
use App\Domain\AI\Configuration\AiConfigurationResolver;
use App\Domain\AI\Configuration\ProviderCatalog;
use App\Domain\AI\Support\AiModelClient;
use App\Domain\AI\Support\AiNotConfiguredException;
use App\Domain\AI\Support\AiQuotaExceededException;
use App\Domain\Signals\AiFailureClassifier;
use App\Domain\Signals\Opportunities\SearchProviderFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * What the Signals engine is connected to, and whether that has actually been PROVEN.
 *
 * "Configured" (a key exists) and "connected" (a real request succeeded) are different
 * facts and are reported separately. A provider is only ever shown as connected because
 * a real test call succeeded, and that result is remembered with its time so the UI can
 * say when it was last proven and by what.
 *
 * NO SECRET LEAVES THIS CLASS: keys are only ever exposed masked, and test results carry
 * a fixed category + fixed wording, never the provider's own message.
 */
class ProviderStatus
{
    public function __construct(
        private readonly AiConfigurationResolver $configuration,
        private readonly ProviderCatalog $providers,
        private readonly AiModelClient $client,
    ) {
    }

    // ── status ───────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function ai(int $tenantId): array
    {
        try {
            $config = $this->configuration->resolve(app(SignalsAiModule::class)->key($tenantId), $tenantId);
            $driveable = $this->providers->isDriveable($config->provider);
            $configured = $config->hasKey() && $driveable;

            return [
                'provider' => $config->provider,
                'provider_label' => $this->providers->label($config->provider),
                'model' => $config->model,
                'credential_source' => $config->source,
                'key_masked' => $configured ? 'Configured' : null,
                'key_masked' => $configured ? self::mask($config->apiKey) : null,
                'configured' => $configured,
                'last_test' => $this->lastCheck($tenantId, 'ai', $configured),
            ];
        } catch (\Throwable) {
            return ['provider' => null, 'provider_label' => null, 'model' => null, 'credential_source' => null, 'key_masked' => null, 'configured' => false, 'last_test' => $this->lastCheck($tenantId, 'ai', false)];
        }
    }

    /** @return array<string, mixed> */
    public function search(int $tenantId): array
    {
        $driver = strtolower((string) config('signals.web_search.driver'));
        $provider = SearchProviderFactory::make();
        $configured = $provider !== null && $provider->isConfigured();
        $rawKey = $provider instanceof \App\Domain\Signals\Opportunities\TavilySearchProvider
            ? $provider->key()
            : (string) config("signals.web_search.{$driver}.api_key");

        return [
            'driver' => $driver !== '' ? $driver : 'none',
            'env_keys' => ['tavily' => 'TAVILY_API_KEY', 'brave' => 'BRAVE_SEARCH_API_KEY'][$driver] ?? 'TAVILY_API_KEY',
            'key_masked' => $configured ? 'Configured' : null,
            'key_masked' => $configured ? self::mask($rawKey) : null,
            'configured' => $configured,
            'last_test' => $this->lastCheck($tenantId, 'search', $configured),
        ];
    }

    /**
     * The scheduler / worker health a person needs in order to trust "it runs daily".
     * Derived from real evidence: the scheduler stamps a heartbeat on every tick, and a
     * job that has waited on the Signals queue for minutes means no worker is consuming it.
     *
     * @return array<string, mixed>
     */
    public function runtime(): array
    {
        $tick = Cache::get('signals:scheduler:last_tick');
        $tickAt = $tick ? \Illuminate\Support\Carbon::parse($tick) : null;

        $waiting = 0;
        try {
            $waiting = DB::table('jobs')->where('queue', (string) config('signals.queue', 'signals'))
                ->whereNull('reserved_at')->where('created_at', '<', now()->subMinutes(2)->getTimestamp())->count();
        } catch (\Throwable) {
            // the jobs table may not exist on a sync-queue install
        }

        return [
            'scheduler_last_tick_at' => $tickAt?->toIso8601String(),
            'scheduler_running' => $tickAt !== null && $tickAt->gt(now()->subMinutes(35)),
            'queue_connection' => (string) config('queue.default'),
            'queue_name' => (string) config('signals.queue', 'signals'),
            'queue_jobs_waiting_over_2_min' => $waiting,
            'queue_worker_suspected_down' => config('queue.default') !== 'sync' && $waiting > 0,
        ];
    }

    // ── real tests ───────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function testAi(int $tenantId, ?int $userId): array
    {
        $started = microtime(true);
        $status = $this->ai($tenantId);

        try {
            $completion = $this->client->complete(
                app(SignalsAiModule::class)->key($tenantId),
                [['role' => 'user', 'content' => 'Reply with exactly this JSON and nothing else: {"ok":true}']],
                ['json' => true, 'temperature' => 0, 'max_tokens' => 20],
                $tenantId,
            );
            $decoded = json_decode(preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($completion->text)) ?? '', true);
            $usable = is_array($decoded) && ($decoded['ok'] ?? null) === true;

            $result = $usable
                ? ['ok' => true, 'category' => 'ok', 'message' => 'The AI provider answered a real request with valid structured JSON.']
                : ['ok' => false, 'category' => 'invalid_response', 'message' => 'The AI provider answered, but not with the structured JSON the Signals engine requires. Try a different model.'];
            $result += ['provider' => $completion->provider, 'model' => $completion->model, 'http_status' => null];
        } catch (AiNotConfiguredException) {
            $result = ['ok' => false, 'category' => 'not_configured', 'message' => 'No usable AI key is configured. Set a valid key in AI Providers (or GEMINI_API_KEY in the backend environment).', 'provider' => $status['provider'], 'model' => $status['model'], 'http_status' => null];
        } catch (AiQuotaExceededException) {
            $result = ['ok' => false, 'category' => 'quota', 'message' => 'The AI usage quota has been reached.', 'provider' => $status['provider'], 'model' => $status['model'], 'http_status' => null];
        } catch (\Throwable $e) {
            $failure = AiFailureClassifier::classify($e);
            $result = ['ok' => false, 'category' => $failure['code'], 'message' => $failure['message'], 'provider' => $status['provider'], 'model' => $status['model'], 'http_status' => $failure['http']];
        }

        return $this->remember($tenantId, 'ai', $result + ['latency_ms' => (int) round((microtime(true) - $started) * 1000)], $userId);
    }

    /** @return array<string, mixed> */
    public function testSearch(int $tenantId, ?int $userId): array
    {
        $started = microtime(true);
        $provider = SearchProviderFactory::make();
        $driver = strtolower((string) config('signals.web_search.driver')) ?: 'none';

        if ($provider === null) {
            $result = ['ok' => false, 'category' => 'not_configured', 'message' => 'No web search provider is selected. Set SIGNALS_SEARCH_DRIVER (tavily or brave) in the backend environment.', 'driver' => $driver, 'results' => 0];
        } elseif (! $provider->isConfigured()) {
            $result = ['ok' => false, 'category' => 'not_configured', 'message' => "The {$provider->name()} API key is missing. Set it in the backend environment (never in source code).", 'driver' => $provider->name(), 'results' => 0];
        } else {
            try {
                $found = $provider->search('technology industry news', 30, 3);
                $valid = array_values(array_filter($found, fn ($r) => preg_match('#^https?://#i', $r['url'] ?? '') && trim($r['title'] ?? '') !== ''));

                $result = $valid !== []
                    ? ['ok' => true, 'category' => 'ok', 'message' => 'A real search request returned valid results.', 'driver' => $provider->name(), 'results' => count($valid), 'sample_domain' => parse_url($valid[0]['url'], PHP_URL_HOST)]
                    : ['ok' => false, 'category' => 'no_results', 'message' => 'The search provider accepted the request but returned no usable results.', 'driver' => $provider->name(), 'results' => 0];
            } catch (\Throwable $e) {
                [$category, $message] = self::searchFailure($e->getMessage());
                $result = ['ok' => false, 'category' => $category, 'message' => $message, 'driver' => $provider->name(), 'results' => 0];
            }
        }

        return $this->remember($tenantId, 'search', $result + ['latency_ms' => (int) round((microtime(true) - $started) * 1000)], $userId);
    }

    /**
     * Provider error -> our category + fixed wording. Only the HTTP status is read from
     * the message; the provider's own text never reaches a user, a log or a response.
     *
     * @return array{0: string, 1: string}
     */
    public static function searchFailure(?string $error): array
    {
        $http = $error && preg_match('/HTTP (\d{3})/', $error, $m) ? (int) $m[1] : null;

        return match (true) {
            in_array($http, [401, 403], true) => ['research_invalid_credentials', 'The web search provider rejected its API key. An administrator must update it.'],
            $http === 429 => ['research_rate_limited', 'The web search provider is rate limiting requests. Try again later.'],
            $http === 402 => ['research_billing', 'The web search provider reports a billing or quota problem. An administrator must resolve it.'],
            $http !== null && $http >= 500 => ['research_provider_failed', 'The web search provider had a temporary failure. Please try again later.'],
            default => ['research_provider_failed', 'The web search provider could not be reached. Please try again later.'],
        };
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    public static function mask(?string $key): ?string
    {
        $key = trim((string) $key);
        if ($key === '') {
            return null;
        }

        return strlen($key) >= 12 ? substr($key, 0, 4) . '••••••••' . substr($key, -4) : '••••••••';
    }

    /** @return array<string, mixed>|null */
    private function lastCheck(int $tenantId, string $kind, bool $isConfigured = false): ?array
    {
        $check = Cache::get($this->cacheKey($tenantId, $kind));
        if (! is_array($check)) {
            return null;
        }

        // If the provider is currently configured, discard stale "not_configured" failure from cache
        if ($isConfigured && ($check['category'] ?? null) === 'not_configured') {
            Cache::forget($this->cacheKey($tenantId, $kind));

            return null;
        }

        return $check;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function remember(int $tenantId, string $kind, array $result, ?int $userId): array
    {
        $previous = $this->lastCheck($tenantId, $kind);
        $result['tested_at'] = now()->toIso8601String();
        // Keep the last SUCCESS visible even after a later failure: "last proven working".
        $result['last_success_at'] = $result['ok'] ? $result['tested_at'] : ($previous['last_success_at'] ?? null);

        Cache::forever($this->cacheKey($tenantId, $kind), $result);

        return $result;
    }

    private function cacheKey(int $tenantId, string $kind): string
    {
        return "signals:provider-check:{$tenantId}:{$kind}";
    }
}
