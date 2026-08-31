<?php

namespace App\Services;

use App\Exceptions\DeepSeekBudgetException;
use App\Exceptions\DeepSeekTruncatedException;
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
 *
 * ── THREE THINGS THIS CLASS DOES THAT COST NOTHING AND SAVE MONEY ───────────
 *
 * This account is small and shared by four features. Every one of them called
 * blind: no call recorded what it cost, a truncated answer was billed and
 * silently discarded, and nothing stopped a run from spending the balance to
 * zero and taking the other three features down with it.
 *
 *   lastUsage()   the token counts DeepSeek already returns, kept instead of
 *                 dropped - see the note on that method
 *   truncation    finish_reason === 'length' raises its own exception rather
 *                 than surfacing as an unparseable answer
 *   balance floor refuses BEFORE sending when the account is nearly empty
 *
 * All three live here rather than in a caller so they apply to every call site
 * at once, including ones written later that never thought about it.
 */
class DeepSeekService
{
    /**
     * Token counts from the most recent call on THIS instance.
     *
     * @var array{prompt_tokens:int, completion_tokens:int, total_tokens:int,
     *            prompt_cache_hit_tokens:int, prompt_cache_miss_tokens:int}|null
     */
    private ?array $lastUsage = null;

    public function isConfigured(): bool
    {
        return !empty(config('deepseek.api_key'));
    }

    public function model(): string
    {
        return (string) config('deepseek.model');
    }

    /**
     * What the last call actually cost, in tokens.
     *
     * DeepSeek returns a `usage` object on every 200 and this class used to
     * throw it away, reading only the message content. That left no way to
     * answer "what did that cost" other than guessing from character counts -
     * and no way to hold a spend limit, because a limit you cannot measure is
     * a wish.
     *
     * Instance state rather than a return value because `chat()` is typed
     * `string`. Read it immediately after the call that produced it.
     *
     * `prompt_cache_hit_tokens` matters: cached input is roughly a thirtieth of
     * the price of fresh input, so a caller repeating a long shared prefix is
     * far cheaper than the raw prompt_tokens figure suggests.
     */
    public function lastUsage(): ?array
    {
        return $this->lastUsage;
    }

    /**
     * The account's remaining balance, straight from DeepSeek.
     *
     * `GET /user/balance` is free, so this is the honest way to answer "how much
     * is left" - better than pricing tokens locally against a published rate
     * card. That rate card is not stable: model names and prices have already
     * changed under this codebase once, and a hardcoded price table would have
     * gone stale without a single test failing.
     *
     * Returns null when the endpoint cannot be reached. Null means UNKNOWN and
     * callers must treat it as such - refusing every call because a balance
     * check timed out would be its own kind of outage.
     *
     * @return array{available:bool, currency:string, total:float}|null
     */
    public function balance(): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        try {
            $response = Http::withToken(config('deepseek.api_key'))
                ->acceptJson()
                ->timeout(15)
                ->get($this->url('/user/balance'));

            if ($response->failed()) {
                return null;
            }

            // USD first; the account may also carry a CNY balance.
            $infos = $response->json('balance_infos') ?? [];
            $chosen = collect($infos)->firstWhere('currency', 'USD') ?? ($infos[0] ?? null);

            if (!$chosen) {
                return null;
            }

            return [
                'available' => (bool) ($response->json('is_available') ?? false),
                'currency'  => (string) ($chosen['currency'] ?? 'USD'),
                'total'     => (float) ($chosen['total_balance'] ?? 0),
            ];
        } catch (\Throwable $e) {
            Log::warning('DeepSeek balance check failed', ['error' => $e->getMessage()]);
            return null;
        }
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

        // A new call, so the previous call's counts are no longer current. Cleared
        // FIRST so a caller reading lastUsage() after a failure gets null rather
        // than the last successful call's numbers, which would misreport a
        // failure as free.
        $this->lastUsage = null;

        $this->guardBalance($options);

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
            ->post($this->url('/chat/completions'), $payload);

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

        // CAPTURED BEFORE ANY THROW BELOW. A truncated or empty answer still
        // costs money, and the caller cannot report that unless the counts
        // survive the failure.
        $usage = $response->json('usage') ?? [];
        $this->lastUsage = [
            'prompt_tokens'           => (int) ($usage['prompt_tokens'] ?? 0),
            'completion_tokens'       => (int) ($usage['completion_tokens'] ?? 0),
            'total_tokens'            => (int) ($usage['total_tokens'] ?? 0),
            'prompt_cache_hit_tokens' => (int) ($usage['prompt_cache_hit_tokens'] ?? 0),
            'prompt_cache_miss_tokens'=> (int) ($usage['prompt_cache_miss_tokens'] ?? 0),

            /*
             * WHICH MODEL ANSWERED, AND WHAT CEILING IT HAD.
             *
             * These are not statistics, they are the diagnosis. `deepseek-chat`
             * is an ALIAS that resolves to whatever the account currently serves,
             * and config/deepseek.php records the measurement that matters: the
             * v4 models consume their entire allowance and return nothing
             * parseable, every time, while deepseek-chat answers in 252 tokens.
             *
             * Those two failures are indistinguishable from the counts alone —
             * both end at finish_reason=length. Only the model name separates
             * "the alias moved under us" from "the model genuinely rambled", and
             * without it a caller on a server we cannot shell into has no way to
             * tell which fix applies.
             */
            'model'            => (string) $payload['model'],
            'max_tokens'       => (int) $payload['max_tokens'],
            'finish_reason'    => (string) ($response->json('choices.0.finish_reason') ?? ''),
        ];

        /*
         * TRUNCATION IS ITS OWN FAILURE, NOT A PARSE ERROR.
         *
         * `finish_reason === 'length'` means the model was still writing when it
         * hit max_tokens. In JSON mode the result is a cut-off object that fails
         * json_decode, and before this check that arrived at the caller as
         * "could not be parsed" - indistinguishable from the model returning
         * nonsense, and pointing at entirely the wrong fix.
         *
         * Measured on this account: asking deepseek-v4-flash for 3 task
         * classifications returned finish_reason=length with the FULL 3,000-token
         * allowance consumed and nothing usable in it. That is what this costs
         * when it goes unnoticed.
         */
        if ($response->json('choices.0.finish_reason') === 'length') {
            Log::warning('DeepSeek answer truncated at max_tokens', [
                'model'      => $payload['model'],
                'max_tokens' => $payload['max_tokens'],
                'usage'      => $this->lastUsage,
            ]);

            throw new DeepSeekTruncatedException(
                $this->lastUsage['prompt_tokens'],
                $this->lastUsage['completion_tokens'],
                (int) $payload['max_tokens'],
            );
        }

        $content = $response->json('choices.0.message.content');

        if (!is_string($content) || trim($content) === '') {
            throw new RuntimeException('DeepSeek returned an empty completion.');
        }

        return trim($content);
    }

    /**
     * Refuse before sending when the account is nearly empty.
     *
     * Pass `skip_balance_check` to bypass it - the balance probe itself, and any
     * call where a stale-by-seconds balance is not worth a second round trip.
     *
     * An UNREACHABLE balance endpoint does not block the call. Refusing to work
     * because a check timed out would turn a DeepSeek hiccup into an outage of
     * four features, which is a worse failure than the one being prevented.
     */
    private function guardBalance(array $options): void
    {
        $floor = (float) config('deepseek.min_balance_usd');

        if ($floor <= 0 || !empty($options['skip_balance_check'])) {
            return;
        }

        $balance = $this->balance();

        if ($balance === null) {
            return;
        }

        if ($balance['total'] <= $floor) {
            throw new DeepSeekBudgetException($balance['total'], $floor, $balance['currency']);
        }
    }

    /** Base URL joined to a path, with the slash handling in exactly one place. */
    private function url(string $path): string
    {
        return rtrim((string) config('deepseek.base_url'), '/') . '/' . ltrim($path, '/');
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
