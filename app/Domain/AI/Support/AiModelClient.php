<?php

namespace App\Domain\AI\Support;

use App\Domain\AI\Configuration\AiConfigurationResolver;
use App\Domain\AI\Configuration\AiModuleRegistry;
use App\Domain\AI\Configuration\ProviderCatalog;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
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
    public function complete(
        string $moduleKey,
        array $messages,
        array $options = [],
        int|string|null $institute = null
    ): AiCompletion {
        $config = $this->configuration->resolve($moduleKey, $institute);

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

        $started = microtime(true);

        try {
            $completion = match ($this->providers->shape($config->provider)) {
                'gemini' => $this->callGemini($config, $messages, $options, $maxTokens),
                default => $this->callOpenAiCompatible($config, $messages, $options, $maxTokens),
            };
        } catch (Throwable $exception) {
            // Metered on the way out. A provider that rejects a request has usually
            // still charged for the prompt, and a meter that counts only successes
            // under-reports exactly when something is going wrong.
            $this->meter->record(
                $moduleKey,
                $config,
                $institute,
                0,
                0,
                (int) round((microtime(true) - $started) * 1000),
                [
                    'outcome' => AiUsageMeter::OUTCOME_FAILED,
                    'error' => $exception->getMessage(),
                ] + $this->meta($options)
            );

            throw $exception;
        }

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

        $payload = [
            'contents' => $contents,
            'generationConfig' => array_filter([
                'maxOutputTokens' => $maxTokens,
                'temperature' => $options['temperature'] ?? null,
                'responseMimeType' => ! empty($options['json']) ? 'application/json' : null,
            ], fn ($value) => $value !== null),
        ];

        if ($system !== null) {
            $payload['systemInstruction'] = ['parts' => [['text' => $system]]];
        }

        $base = rtrim((string) $this->providers->baseUrl($config->provider), '/');
        $model = $config->model ?: 'gemini-3.6-flash';

        $response = Http::timeout($this->timeout($config->provider))
            ->acceptJson()
            ->asJson()
            // The key rides in a header, not the query string. A URL reaches access
            // logs, proxy logs and the Referer header; a header does not.
            ->withHeaders(['x-goog-api-key' => $config->apiKey])
            ->post("{$base}/models/{$model}:generateContent", $payload);

        $this->guard($response, $this->providers->label($config->provider));

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
            ->withToken($config->apiKey)
            ->acceptJson()
            ->asJson()
            ->post("{$base}/chat/completions", $payload);

        $this->guard($response, $this->providers->label($config->provider));

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
    private function guard(Response $response, string $providerLabel): void
    {
        if ($response->successful()) {
            return;
        }

        $message = $response->json('error.message')
            ?? $response->json('message')
            ?? $response->json('error');

        throw new RuntimeException(sprintf(
            '%s refused the request (HTTP %d)%s',
            $providerLabel,
            $response->status(),
            is_string($message) && $message !== '' ? ': ' . $message : '.'
        ));
    }

    private function timeout(string $provider): int
    {
        return ((int) config("ai.provider.{$provider}.timeout")) ?: 45;
    }
}
