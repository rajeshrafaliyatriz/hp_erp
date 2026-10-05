<?php

namespace App\Domain\AI\Support;

use App\Domain\AI\Configuration\AiConfigurationResolver;
use App\Domain\AI\Configuration\AiModuleRegistry;
use App\Domain\AI\Configuration\ProviderCatalog;
use App\Domain\AI\Configuration\ResolvedAiConfiguration;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The one place this application calls a model.
 *
 * WHY THIS EXISTS
 *
 * G2G already had three ways to reach a provider — `DeepSeekService`, the Gemini
 * controllers, and the frontend's own chat route — each holding its own key, its own
 * base URL and its own idea of what a failure looks like. Adding Conversational AI
 * and AI Evaluation on top of that would have made five. This is the single caller
 * the new capabilities use, and it is what makes the AI Providers screen do
 * something: every call resolves its provider, model and credential through
 * `AiConfigurationResolver`, so saving a configuration changes what the next call
 * does rather than merely being recorded.
 *
 * TWO WIRE SHAPES, SELECTED BY THE CATALOGUE
 *
 * `ProviderCatalog::shape()` answers `gemini` or `openai_compatible`, and this class
 * has one method for each. A provider with no shape is refused before a request is
 * made — reaching the network to discover that a provider cannot be called is a
 * slower way to learn something the catalogue already knows.
 *
 * NOT CONFIGURED IS NOT AN ERROR
 *
 * An organisation with no credential gets `AiNotConfiguredException`, whose message
 * names the screen that fixes it. That is a different outcome from a provider
 * returning 500, and collapsing the two would tell an administrator to investigate
 * an outage when what they actually need to do is paste a key.
 *
 * SCOPE COMES FROM THE CALLER'S TOKEN
 *
 * `$institute` is `AiRequestScope::selectedInstituteId`. An organisation calls on its
 * own credential or on the platform's, never on another organisation's.
 */
final class AiModelClient
{
    public function __construct(
        private readonly AiConfigurationResolver $configuration,
        private readonly ProviderCatalog $providers,
        private readonly AiModuleRegistry $modules,
        private readonly AiUsageMeter $meter,
        private readonly CredentialHealth $health,
        private readonly ProviderFailureClassifier $failures,
    ) {
    }

    /**
     * Send a conversation and return what came back.
     *
     * @param  string  $moduleKey  An `AiModuleRegistry` key — decides which saved
     *                             configuration this call resolves through.
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array{max_tokens?: int, temperature?: float, json?: bool}  $options
     */
    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array{max_tokens?: int, temperature?: float, json?: bool, images?: array<int, array{mime_type: string, data: string}>}  $options
     *         `images` is Gemini-only (OCR of a scan/photo via inline base64 data,
     *         `mime_type` + base64-encoded `data`) - see `callGemini()`. An
     *         OpenAI-compatible provider ignores it rather than failing the
     *         call, because text classification (the other caller of this
     *         module) never sends one.
     */
    public function complete(
        string $moduleKey,
        array $messages,
        array $options = [],
        int|string|null $institute = null,
        // The `ai_modules` key the call is made from, when there is one. Only lets that
        // module's own AI Stack model choice apply; omitted, resolution is unchanged.
        ?string $productModule = null
    ): AiCompletion {
        $config = $this->configuration->resolve($moduleKey, $institute, $productModule);

        if (! $this->providers->isDriveable($config->provider)) {
            throw AiNotConfiguredException::providerNotDriveable(
                $this->providers->label($config->provider)
            );
        }

        if (! $config->hasKey()) {
            throw AiNotConfiguredException::forModule(
                $this->modules->label($moduleKey),
                $this->providers->label($config->provider)
            );
        }

        // BEFORE the request reaches the network. A quota checked afterwards is an
        // accounting entry, not a limit — see AiUsageMeter.
        $refusal = $this->meter->guard($moduleKey, $institute);

        if ($refusal !== null) {
            // Recorded with zero tokens and outcome "refused", because that is what
            // happened: nothing was sent. A refusal that leaves no trace makes a
            // quota indistinguishable from an outage.
            $this->meter->record($moduleKey, $config, $institute, 0, 0, null, [
                'outcome' => AiUsageMeter::OUTCOME_REFUSED,
                'error' => $refusal,
            ] + $this->meta($options));

            throw new AiQuotaExceededException($refusal);
        }

        // The saved per-key ceiling wins over the caller's request, because it is the
        // administrator's statement about spend and the caller's is a preference.
        $maxTokens = $config->maxOutputTokens
            ?: (int) ($options['max_tokens'] ?? 2048);

        $credentials = $this->credentials($config);
        $rotating = count($credentials) > 1;
        $maxAttempts = max(1, (int) config('ai.failover.max_attempts', 20));
        $maxTransient = max(1, (int) config('ai.failover.max_transient_attempts', 2));

        // Health-aware order. With a single credential there is nothing to rotate to,
        // so it is always attempted (a stale cooldown must not block the only key).
        // With several, cooling credentials are skipped; all cooling = nothing to try.
        $eligible = $rotating
            ? array_values(array_filter($credentials, fn ($c) => $this->health->isAvailable($c['fingerprint'])))
            : $credentials;

        if ($eligible === []) {
            throw AiCredentialsExhaustedException::forProvider($this->providers->label($config->provider));
        }

        $attempts = 0;
        $transient = 0;
        $capacityFailure = false;
        $last = null;
        $completion = null;
        $attemptConfig = $config;
        $started = microtime(true);

        // Each credential is tried at most once per request; the loop is bounded by the
        // eligible list and `max_attempts`. Nothing here re-enters complete().
        foreach ($eligible as $credential) {
            if ($attempts >= $maxAttempts) {
                break;
            }

            $attempts++;
            $attemptConfig = $credential['config'];
            $started = microtime(true);

            try {
                $completion = match ($this->providers->shape($config->provider)) {
                    'gemini' => $this->callGemini($attemptConfig, $messages, $options, $maxTokens),
                    default => $this->callOpenAiCompatible($attemptConfig, $messages, $options, $maxTokens),
                };

                if ($rotating) {
                    $this->health->recordSuccess($credential['fingerprint']);
                }

                break;
            } catch (Throwable $exception) {
                // Metered on the way out. A provider that rejects a request has usually
                // still charged for the prompt, and a meter that counts only successes
                // under-reports exactly when something is going wrong.
                $this->meter->record(
                    $moduleKey,
                    $attemptConfig,
                    $institute,
                    0,
                    0,
                    (int) round((microtime(true) - $started) * 1000),
                    [
                        'outcome' => AiUsageMeter::OUTCOME_FAILED,
                        'error' => $exception->getMessage(),
                    ] + $this->meta($options)
                );

                $verdict = $this->failures->classify($exception);

                // Not a credential problem (bad request, bad model, network): another
                // key cannot fix it, and trying 20 would only multiply the damage.
                if (! $rotating || $verdict['action'] !== 'rotate') {
                    if ($verdict['action'] === 'rotate') {
                        $this->health->recordFailure($credential['fingerprint'], $verdict['type'], $verdict['cooldown']);
                    }

                    throw $exception;
                }

                $this->health->recordFailure($credential['fingerprint'], $verdict['type'], $verdict['cooldown']);

                Log::warning('AI credential failed; trying the next one.', [
                    'provider' => $config->provider,
                    'credential' => $credential['fingerprint'], // row id or key hash — never the key
                    'failure' => $verdict['type'],
                    'http_status' => $exception instanceof AiProviderHttpException ? $exception->httpStatus : null,
                    'cooldown_seconds' => $verdict['cooldown'],
                    'attempt' => $attempts,
                    'institute' => $institute,
                ]);

                $last = $exception;

                if ($verdict['type'] === 'transient' && ++$transient >= $maxTransient) {
                    break;
                }

                if ($verdict['type'] !== 'invalid_credential') {
                    $capacityFailure = true;
                }
            }
        }

        if ($completion === null) {
            // Quota/outage on any attempted credential → controlled, user-safe error.
            // Only invalid keys across the board → the real cause (a bad key) is the
            // more useful thing to surface to the administrator.
            if ($capacityFailure && $last !== null) {
                throw AiCredentialsExhaustedException::forProvider($this->providers->label($config->provider));
            }

            throw $last ?? AiCredentialsExhaustedException::forProvider($this->providers->label($config->provider));
        }

        $config = $attemptConfig;
        $latencyMs = (int) round((microtime(true) - $started) * 1000);

        $this->meter->record(
            $moduleKey,
            $config,
            $institute,
            $completion['input_tokens'],
            $completion['output_tokens'],
            $latencyMs,
            ['finish_reason' => $completion['finish_reason']] + $this->meta($options)
        );
        return new AiCompletion(
            text: $completion['text'],
            provider: $config->provider,
            model: $config->model,
            inputTokens: $completion['input_tokens'],
            outputTokens: $completion['output_tokens'],
            latencyMs: $latencyMs,
            finishReason: $completion['finish_reason'],
        );
    }

    /**
     * The primary credential followed by its failover alternates, each as a ready-to-call
     * configuration plus its health fingerprint. Duplicate keys collapse to one.
     *
     * @return list<array{config: ResolvedAiConfiguration, fingerprint: string}>
     */
    private function credentials(ResolvedAiConfiguration $config): array
    {
        $out = [['config' => $config, 'fingerprint' => CredentialHealth::fingerprint($config->keyId, (string) $config->apiKey)]];
        $seen = [(string) $config->apiKey => true];

        foreach ($config->alternates as $alternate) {
            $key = (string) ($alternate['api_key'] ?? '');

            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $out[] = [
                'config' => $config->withCredential($key, $alternate['id'] ?? null),
                'fingerprint' => CredentialHealth::fingerprint($alternate['id'] ?? null, $key),
            ];
        }

        return $out;
    }

    /**
     * The caller's own attribution, passed through to the meter.
     *
     * A whitelist rather than forwarding `$options` wholesale: that array also carries
     * `temperature` and `max_tokens`, and a meter row is not the place for a request
     * parameter.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function meta(array $options): array
    {
        return array_filter(
            [
                'related_type' => $options['related_type'] ?? null,
                'related_id' => $options['related_id'] ?? null,
                'user_id' => $options['user_id'] ?? null,
            ],
            fn ($value) => $value !== null
        );
    }

    /**
     * Google's REST shape.
     *
     * The system instruction is a field of its own here rather than a message with a
     * role, which is the main reason this cannot share a method with the
     * OpenAI-compatible path. Gemini also names the assistant role `model`.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     * @return array{text: string, input_tokens: int, output_tokens: int, finish_reason: ?string}
     */
    private function callGemini(
        $config,
        array $messages,
        array $options,
        int $maxTokens
    ): array {
        $contents = [];
        $system = null;

        foreach ($messages as $message) {
            $role = (string) ($message['role'] ?? 'user');
            $text = (string) ($message['content'] ?? '');

            if ($text === '') {
                continue;
            }

            if ($role === 'system') {
                // Concatenated rather than overwritten: a caller that sends two system
                // messages means both, and silently keeping the last would drop a
                // safety rule without saying so.
                $system = $system === null ? $text : $system . "\n\n" . $text;

                continue;
            }

            $contents[] = [
                'role' => $role === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $text]],
            ];
        }

        /*
         * Images ride as inline base64 parts on the LAST user turn - OCR is
         * always "here is the page, read it", asked once, not a multi-turn
         * conversation about an image. Appended to that turn's existing
         * `parts` (rather than a separate content entry) so Gemini sees the
         * instruction text and the page together, which is what makes it
         * transcribe the text in the image instead of describing the image.
         */
        if (! empty($options['images'])) {
            $lastUserIndex = null;

            foreach ($contents as $index => $entry) {
                if ($entry['role'] === 'user') {
                    $lastUserIndex = $index;
                }
            }

            $imageParts = array_map(
                fn (array $image) => [
                    'inlineData' => [
                        'mimeType' => (string) $image['mime_type'],
                        'data' => (string) $image['data'],
                    ],
                ],
                $options['images']
            );

            if ($lastUserIndex !== null) {
                $contents[$lastUserIndex]['parts'] = [...$contents[$lastUserIndex]['parts'], ...$imageParts];
            } else {
                $contents[] = ['role' => 'user', 'parts' => $imageParts];
            }
        }

        $payload = [
            'contents' => $contents,
            'generationConfig' => array_filter([
                'maxOutputTokens' => $maxTokens,
                'temperature' => $options['temperature'] ?? null,
                'responseMimeType' => ! empty($options['json']) ? 'application/json' : null,
            ], fn ($value) => $value !== null),
        ];

        // Opt-in: Gemini's internal "thinking" tokens count against maxOutputTokens, so a
        // structured-JSON caller can lose its whole budget to thinking and get truncated JSON.
        if (isset($options['thinking_budget'])) {
            $payload['generationConfig']['thinkingConfig'] = ['thinkingBudget' => (int) $options['thinking_budget']];
        }

        if ($system !== null) {
            $payload['systemInstruction'] = ['parts' => [['text' => $system]]];
        }

        $base = rtrim((string) $this->providers->baseUrl($config->provider), '/');
        $model = $config->model ?: 'gemini-3.6-flash';

        $send = fn (array $body) => Http::timeout($this->timeout($config->provider))
            ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
            ->acceptJson()
            ->asJson()
            // The key rides in a header, not the query string. A URL reaches access
            // logs, proxy logs and the Referer header; a header does not.
            ->withHeaders(['x-goog-api-key' => $config->apiKey])
            ->post("{$base}/models/{$model}:generateContent", $body);

        $response = $send($payload);

        // Some Gemini models cannot switch thinking off and answer `thinkingBudget: 0` with a
        // 400 or 5xx. That is a statement about the optional setting, not about the model, so
        // ask once more without it before reporting the provider as unavailable.
        if (! $response->successful()
            && ($response->status() === 400 || $response->status() >= 500)
            && isset($payload['generationConfig']['thinkingConfig'])) {
            unset($payload['generationConfig']['thinkingConfig']);
            $response = $send($payload);
        }

        $this->guard($response, $this->providers->label($config->provider), $config->apiKey);

        $parts = $response->json('candidates.0.content.parts') ?? [];
        $text = implode('', array_map(fn ($part) => (string) ($part['text'] ?? ''), $parts));

        return [
            'text' => trim($text),
            'input_tokens' => (int) ($response->json('usageMetadata.promptTokenCount') ?? 0),
            'output_tokens' => (int) ($response->json('usageMetadata.candidatesTokenCount') ?? 0),
            'finish_reason' => $response->json('candidates.0.finishReason'),
        ];
    }

    /**
     * The OpenAI chat-completions shape — DeepSeek, OpenRouter, OpenAI, Groq, Mistral.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     * @return array{text: string, input_tokens: int, output_tokens: int, finish_reason: ?string}
     */
    private function callOpenAiCompatible(
        $config,
        array $messages,
        array $options,
        int $maxTokens
    ): array {
        $payload = array_filter([
            'model' => $config->model,
            'messages' => array_values(array_filter(
                $messages,
                fn ($message) => trim((string) ($message['content'] ?? '')) !== ''
            )),
            'max_tokens' => $maxTokens,
            'temperature' => $options['temperature'] ?? null,
            'stream' => false,
        ], fn ($value) => $value !== null);

        if (! empty($options['json'])) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $base = rtrim((string) $this->providers->baseUrl($config->provider), '/');

        $response = Http::timeout($this->timeout($config->provider))
            ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
            ->withToken($config->apiKey)
            ->acceptJson()
            ->asJson()
            ->post("{$base}/chat/completions", $payload);

        $this->guard($response, $this->providers->label($config->provider), $config->apiKey);

        return [
            'text' => trim((string) ($response->json('choices.0.message.content') ?? '')),
            'input_tokens' => (int) ($response->json('usage.prompt_tokens') ?? 0),
            'output_tokens' => (int) ($response->json('usage.completion_tokens') ?? 0),
            'finish_reason' => $response->json('choices.0.finish_reason'),
        ];
    }

    /**
     * Turn a failed response into something a caller can act on.
     *
     * The provider's own message is surfaced because it is usually the diagnosis —
     * "API key not valid", "model not found", "insufficient quota" each point at a
     * different fix, and replacing all three with "the request failed" throws that
     * away. The response body is not returned wholesale: it can echo the prompt, and
     * the prompt can contain the organisation's data.
     */
    private function guard(Response $response, string $providerLabel, ?string $apiKey = null): void
    {
        if ($response->successful()) {
            return;
        }

        $message = $response->json('error.message')
            ?? $response->json('message')
            ?? $response->json('error');
        $message = is_string($message) ? $message : null;

        $quotaIds = [];
        $reason = null;
        $retryAfter = null;

        foreach ((array) ($response->json('error.details') ?? []) as $detail) {
            $type = (string) ($detail['@type'] ?? '');

            foreach ((array) ($detail['violations'] ?? []) as $violation) {
                $quotaIds[] = (string) ($violation['quotaId'] ?? '');
            }

            if (str_ends_with($type, 'ErrorInfo') && isset($detail['reason'])) {
                $reason = (string) $detail['reason'];
            }

            if (str_ends_with($type, 'RetryInfo') && isset($detail['retryDelay'])) {
                $retryAfter = (int) ceil((float) $detail['retryDelay']); // "34s" / "34.5s"
            }
        }

        $header = $response->header('Retry-After');

        if ($retryAfter === null && is_numeric($header)) {
            $retryAfter = (int) $header;
        }

        // A provider message should never contain the credential, but this class is the
        // last stop before a message is stored in the usage table and shown to users.
        if ($message !== null && $apiKey !== null && $apiKey !== '') {
            $message = str_replace($apiKey, '[redacted]', $message);
        }

        throw new AiProviderHttpException(
            sprintf(
                '%s refused the request (HTTP %d)%s',
                $providerLabel,
                $response->status(),
                $message !== null && $message !== '' ? ': ' . $message : '.'
            ),
            $response->status(),
            is_string($response->json('error.status')) ? $response->json('error.status') : null,
            $reason,
            array_values(array_filter($quotaIds)),
            $retryAfter,
            $message,
        );
    }
    private function timeout(string $provider): int
    {
        return ((int) config("ai.provider.{$provider}.timeout")) ?: 45;
    }
}
