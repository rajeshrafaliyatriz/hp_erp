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
     * Extra sends allowed when the answer comes back blank. Deliberately small:
     * a blank still bills prompt tokens, and the one blank fully diagnosed here
     * was a prompt fault that no number of retries would have cleared. See
     * perturb() before raising this.
     */
    public const BLANK_RETRIES = 2;

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

        $attempts = 1 + max(0, (int) ($options['blank_retries'] ?? self::BLANK_RETRIES));
        $billed = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $try = $attempt === 1 ? $options : $options + ['skip_balance_check' => true];

            /*
             * THE LAST ATTEMPT DROPS JSON MODE. This is the fallback that works.
             *
             * Measured: the blank only ever appears with
             * response_format=json_object. Resending the SAME bytes with it
             * removed returned usable content on every attempt. So rather than
             * spend the last try on a mode that is the thing failing, it is
             * spent on plain text - and chatJson() digs the object back out of
             * whatever prose comes with it.
             */
            if ($attempt === $attempts && !empty($options['json'])) {
                $try['json'] = false;
            }

            // The balance was checked once above. Re-probing per retry would add a
            // round trip to fix a problem that costs no tokens to begin with.
            $content = $this->sendOnce($this->perturb($messages, $attempt), $try);

            // A blank answer still consumed prompt tokens, so every attempt is
            // added up. Reporting only the last one would understate what the
            // caller was charged.
            foreach (array_keys($billed) as $k) {
                $billed[$k] += (int) ($this->lastUsage[$k] ?? 0);
            }
            $this->lastUsage = array_merge($this->lastUsage ?? [], $billed, ['attempts' => $attempt]);

            if ($content !== '') {
                return $content;
            }

            Log::warning('DeepSeek returned an empty completion; retrying with a perturbed prompt', [
                'attempt' => $attempt,
                'of'      => $attempts,
                'usage'   => $this->lastUsage,
            ]);
        }

        throw new RuntimeException(
            'DeepSeek returned an empty completion ' . $attempts . ' times, including with a '
            . 'perturbed prompt.'
        );
    }

    /*
     * A BLANK COMPLETION IS USUALLY YOUR PROMPT, NOT A GLITCH. READ THIS FIRST.
     *
     * In JSON mode deepseek-chat can return HTTP 200, finish_reason=stop, and
     * ~40 completion tokens of PURE WHITESPACE. It bills and it does not error,
     * so the caller sees only "the model was unavailable".
     *
     * TWO THINGS WERE MEASURED, 2026-09-04, marking written answers.
     *
     *   1. Prompt wording SHIFTS it. A system message ending "and nothing else -
     *      no prose, no markdown fences" blanked on every send; ending it at "a
     *      single valid JSON object." answered on both formats. That is why
     *      markingSystemPrompt() is worded the way it is.
     *
     *   2. Wording does NOT eliminate it. The corrected prompt marked correctly
     *      twice and then blanked three times in a row on the same bytes. So it
     *      is stochastic, and no phrasing can be trusted to be safe.
     *
     * What was never observed is a blank WITHOUT response_format=json_object.
     * The same bytes in plain text answered every time. Hence the shape of the
     * loop: perturb and retry for the cheap case, then spend the last attempt
     * outside JSON mode, which is the failure's only known precondition.
     *
     * The perturbation is why a retry is not a pure duplicate: an ignorable
     * marker on the LAST user message changes the sampled sequence while the
     * system message and earlier turns stay byte-identical and still hit the
     * prefix cache.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array<int, array{role: string, content: string}>
     */
    private function perturb(array $messages, int $attempt): array
    {
        if ($attempt <= 1 || $messages === []) {
            return $messages;
        }

        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? '') === 'user') {
                $messages[$i]['content'] .= "\n\n(request " . $attempt . ")";

                return $messages;
            }
        }

        return $messages;
    }

    /**
     * One request. Returns '' for an empty completion so the caller can retry;
     * every other failure still throws.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     */
    private function sendOnce(array $messages, array $options = []): string
    {
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

        // Blank is returned, not thrown, because chat() can clear it by retrying
        // with a perturbed prompt - see perturb() for what was measured.
        return is_string($content) ? trim($content) : '';
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

        // chat() spends its last attempt on PLAIN TEXT when JSON mode keeps
        // coming back blank, so the object can arrive wrapped in a sentence.
        // Digging it out here is what makes that fallback worth having.
        if (!is_array($decoded)) {
            $extracted = $this->extractJsonObject($cleaned);
            $decoded = $extracted === null ? null : json_decode($extracted, true);
        }

        if (!is_array($decoded)) {
            Log::warning('DeepSeek returned unparsable JSON', ['raw' => $raw]);
            throw new RuntimeException('DeepSeek returned a response that could not be parsed as JSON.');
        }

        return $decoded;
    }

    /**
     * The first complete top-level JSON object in a string, or null.
     *
     * Brace-counted rather than a regex between the first `{` and the last `}`:
     * that naive span breaks the moment the model writes anything containing a
     * brace after the object, and it silently returns a truncated string that
     * json_decode rejects for a reason nobody can see. Braces inside string
     * literals are skipped, with escapes honoured, so a `{` in a feedback
     * sentence cannot unbalance the count.
     */
    private function extractJsonObject(string $text): ?string
    {
        $start = strpos($text, '{');
        if ($start === false) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escaped = false;

        for ($i = $start, $len = strlen($text); $i < $len; $i++) {
            $ch = $text[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($ch === '\\') {
                    $escaped = true;
                } elseif ($ch === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($ch === '"') {
                $inString = true;
            } elseif ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($text, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }
}
