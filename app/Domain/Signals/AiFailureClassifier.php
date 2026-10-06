<?php

namespace App\Domain\Signals;

/**
 * Turns a provider failure into a stable code and a safe sentence.
 *
 * INPUT IS UNTRUSTED. The exception message from AiModelClient carries the
 * provider's own error text, which can echo a prompt. It is read here ONLY to pick a
 * category; it is never stored, logged or shown. What leaves this class is a fixed
 * code plus fixed wording.
 */
final class AiFailureClassifier
{
    /**
     * @return array{code: string, message: string, http: ?int}
     */
    public static function classify(\Throwable $e): array
    {
        if ($e instanceof \App\Domain\AI\Support\AiCredentialsExhaustedException) {
            return self::result('ai_rate_limited', 'All configured AI credentials are temporarily at their usage limit. Please try again in a few minutes.', 429);
        }

        $raw = $e->getMessage();
        $http = preg_match('/refused the request \(HTTP (\d{3})\)/', $raw, $m) ? (int) $m[1] : null;
        $text = strtolower($raw);

        $has = fn (string $pattern) => preg_match($pattern, $text) === 1;

        if ($http === 401 || $http === 403 || $has('/api key not valid|invalid api key|api_key_invalid|incorrect api key|authentication|unauthorized|permission denied/')) {
            return self::result('ai_invalid_credentials', 'The AI provider rejected the API key (invalid, revoked or not permitted). An administrator must replace the key in AI Providers.', $http);
        }
        if ($http === 402 || $has('/insufficient (balance|credit|funds)|billing|payment required|out of credit/')) {
            return self::result('ai_billing', 'The AI provider account has insufficient credit or a billing restriction. An administrator must resolve billing with the provider.', $http);
        }
        if ($http === 429) {
            return self::result('ai_rate_limited', 'The AI provider is rate limiting requests. Please try again in a few minutes.', $http);
        }
        if ($http === 404 || ($http === 400 && $has('/model/'))) {
            return self::result('ai_model_error', 'The configured AI model is not available from the provider. An administrator must choose a valid model in AI Providers.', $http);
        }
        if ($http !== null && $http >= 500) {
            return self::result('ai_provider_unavailable', 'The AI provider is temporarily unavailable. Please try again later.', $http);
        }
        if ($http !== null) {
            return self::result('ai_request_rejected', 'The AI provider rejected the request. An administrator should check the AI Providers configuration.', $http);
        }

        return self::result('generation_failed', 'Signal generation failed. Please try again later.', null);
    }

    /** @return array{code: string, message: string, http: ?int} */
    private static function result(string $code, string $message, ?int $http): array
    {
        return ['code' => $code, 'message' => $message, 'http' => $http];
    }
}
