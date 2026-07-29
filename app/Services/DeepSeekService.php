<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * DeepSeek chat-completions client.
 *
 * Replaces the OpenRouter proxy the previous frontend used (it sent
 * `deepseek/deepseek-chat` through openrouter.ai). Calling DeepSeek directly
 * removes the middleman, and the key never leaves the server.
 *
 * DeepSeek's API is OpenAI-compatible, so the request/response shapes below are
 * the standard chat-completions ones.
 */
class DeepSeekService
{
    public function isConfigured(): bool
    {
        return !empty(config('deepseek.api_key'));
    }

    public function model(): string
    {
        return (string) config('deepseek.model');
    }

    /**
     * Send a chat completion and return the assistant's message content.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     */
    public function chat(array $messages, array $options = []): string
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException(
                'DeepSeek is not configured. Set DEEPSEEK_API_KEY in the environment.'
            );
        }

        $payload = [
            'model' => $options['model'] ?? $this->model(),
            'messages' => $messages,
            'max_tokens' => $options['max_tokens'] ?? config('deepseek.max_tokens'),
            'temperature' => $options['temperature'] ?? config('deepseek.temperature'),
            'top_p' => $options['top_p'] ?? config('deepseek.top_p'),
            'stream' => false,
        ];

        // DeepSeek supports OpenAI-style JSON mode. When asked for JSON we set
        // it so the model cannot wrap the object in prose.
        if (!empty($options['json'])) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $response = Http::withToken(config('deepseek.api_key'))
            ->timeout((int) config('deepseek.request_timeout'))
            ->acceptJson()
            ->asJson()
            ->post(rtrim((string) config('deepseek.base_url'), '/') . '/chat/completions', $payload);

        if ($response->failed()) {
            // The body carries DeepSeek's own error message; log it but do not
            // return it verbatim to the client - it can echo the request.
            Log::error('DeepSeek request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            $message = $response->json('error.message');

            throw new RuntimeException(
                $message
                    ? "DeepSeek error: {$message}"
                    : "DeepSeek request failed with status {$response->status()}."
            );
        }

        $content = $response->json('choices.0.message.content');

        if (!is_string($content) || trim($content) === '') {
            throw new RuntimeException('DeepSeek returned an empty completion.');
        }

        return trim($content);
    }

    /**
     * Chat completion that must return a JSON object.
     *
     * Even in JSON mode a model occasionally fences its output, so the fence is
     * stripped before decoding rather than failing the whole request.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array<string, mixed>
     */
    public function chatJson(array $messages, array $options = []): array
    {
        $raw = $this->chat($messages, $options + ['json' => true]);

        $cleaned = trim($raw);
        if (str_starts_with($cleaned, '```')) {
            $cleaned = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $cleaned) ?? $cleaned;
            $cleaned = trim($cleaned);
        }

        $decoded = json_decode($cleaned, true);

        if (!is_array($decoded)) {
            Log::warning('DeepSeek returned unparsable JSON', ['raw' => $raw]);
            throw new RuntimeException('DeepSeek returned a response that could not be parsed as JSON.');
        }

        return $decoded;
    }
}
