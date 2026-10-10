<?php

namespace App\Http\Controllers\AI;

use App\Domain\AI\Configuration\AiConfigurationResolver;
use App\Domain\AI\Configuration\ResolvedAiConfiguration;
use App\Domain\AI\Support\AiUsageMeter;
use Illuminate\Http\Request;
use Throwable;

/**
 * The two things the frontend chat route (`g2gv0/app/api/ai/chat`) needs from the AI layer.
 *
 * That route calls Google directly with its own key, so until now its spend never reached
 * `ai_usage_events` and AI Model Setup could not influence it. Both gaps are closed here
 * without moving the chat itself:
 *
 *   POST /chat-usage   records what a chat call used, under `conversational_ai`.
 *   GET  /chat-model   says which Gemini model an administrator has explicitly chosen.
 *
 * TENANT AND USER COME FROM THE TOKEN
 *
 * Both are taken from `scope()`, which is built from the caller's Sanctum token, never
 * from the body. A caller can therefore only ever write usage against their own
 * organisation, and the provider recorded is fixed to the one the route can call.
 *
 * Open to any signed-in user, not just admins, because every chat user generates usage.
 * The rest of `/api/ai` stays admin-only.
 */
class AiChatUsageController extends AiController
{
    private const MODULE = 'conversational_ai';

    /** What the chat route can call. A different provider cannot be honoured there. */
    private const PROVIDER = 'gemini';

    /** Sources that mean an administrator chose this, as opposed to the env/pool default. */
    private const EXPLICIT_SOURCES = ['module', 'module_platform', 'module_binding', 'module_binding_platform'];

    public function __construct(
        private readonly AiUsageMeter $meter,
        private readonly AiConfigurationResolver $resolver,
    ) {
    }

    public function report(Request $request)
    {
        try {
            $scope = $this->scope($request);

            $data = $request->validate([
                'model' => ['required', 'string', 'max:120'],
                'input_tokens' => ['nullable', 'integer', 'min:0', 'max:5000000'],
                'output_tokens' => ['nullable', 'integer', 'min:0', 'max:5000000'],
                'latency_ms' => ['nullable', 'integer', 'min:0', 'max:3600000'],
                'outcome' => ['nullable', 'in:success,failed'],
                'error' => ['nullable', 'string', 'max:500'],
                'related_type' => ['nullable', 'in:chat,intent_classifier'],
            ]);

            $this->meter->record(
                self::MODULE,
                new ResolvedAiConfiguration(
                    provider: self::PROVIDER,
                    model: (string) $data['model'],
                    apiKey: null,
                    source: 'frontend_chat',
                    keyId: null,
                    scope: 'config',
                    maxOutputTokens: null,
                    alternates: [],
                ),
                $scope->selectedInstituteId,
                (int) ($data['input_tokens'] ?? 0),
                (int) ($data['output_tokens'] ?? 0),
                isset($data['latency_ms']) ? (int) $data['latency_ms'] : null,
                [
                    'outcome' => ($data['outcome'] ?? 'success') === 'failed'
                        ? AiUsageMeter::OUTCOME_FAILED
                        : AiUsageMeter::OUTCOME_SUCCESS,
                    'error' => $data['error'] ?? null,
                    'related_type' => $data['related_type'] ?? 'chat',
                    'user_id' => $scope->userId,
                ],
            );

            return $this->success('Recorded.');
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /**
     * The Gemini model an administrator has explicitly configured for the assistant, or null.
     *
     * Null is the normal answer and means "keep using the route's own default" — the
     * default resolution (env / pool) is deliberately not reported, so switching this on
     * changes nothing until somebody actually chooses a model.
     */
    public function model(Request $request)
    {
        try {
            $scope = $this->scope($request);

            $resolved = $this->resolver->resolve(self::MODULE, $scope->selectedInstituteId);

            $explicit = in_array($resolved->source, self::EXPLICIT_SOURCES, true)
                && $resolved->provider === self::PROVIDER
                && trim((string) $resolved->model) !== '';

            return $this->success('Resolved.', [
                'model' => $explicit ? trim((string) $resolved->model) : null,
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }
}
