<?php

namespace App\Domain\Signals\Run;

/**
 * Hard, process-wide ceilings on the external calls of ONE verification run. Off (every call allowed) unless a verification command arms it, so
 * ordinary runs are untouched.
 *
 * Kinds, each counted at the one place the call is made:
 *   search_calls   every HTTP request to the search provider, retries included (OpportunityResearcher::gather)
 *   page_requests  every HTTP request the page fetcher makes, redirects, robots.txt and sitemap requests included (SafeUrlFetcher::fetch)
 *   ai_requests    every `complete` call StructuredAi makes, retries included; credential failover inside one call is bounded separately by the
 *                  armed `ai.failover` override (see VerifyLimits), so the provider-request ceiling is ai_requests x failover attempts
 *
 * take() is the only way to spend: it returns false (and records the refusal) once a ceiling is reached, so a caller must stop before the call.
 */
final class RunBudget
{
    public const SEARCH = 'search_calls';
    public const PAGE = 'page_requests';
    public const AI = 'ai_requests';
    /** every HTTP request to the AI provider: credential failover attempts AND the Gemini "ask again without thinking" request (AiModelClient) */
    public const AI_PROVIDER = 'ai_provider_requests';

    /** @var array<string, int>|null */
    private static ?array $limits = null;
    /** @var array<string, int> */
    private static array $used = [];
    /** @var array<string, int> */
    private static array $refused = [];

    /** @param  array<string, int>  $limits  kind => maximum; a kind that is absent is not limited */
    public static function arm(array $limits): void
    {
        self::$limits = array_map('intval', $limits);
        self::$used = [];
        self::$refused = [];
    }

    public static function disarm(): void
    {
        self::$limits = null;
        self::$used = [];
        self::$refused = [];
    }

    public static function armed(): bool
    {
        return self::$limits !== null;
    }

    public static function take(string $kind): bool
    {
        if (self::$limits === null || ! array_key_exists($kind, self::$limits)) {
            return true;
        }
        if ((self::$used[$kind] ?? 0) >= self::$limits[$kind]) {
            self::$refused[$kind] = (self::$refused[$kind] ?? 0) + 1;

            return false;
        }
        self::$used[$kind] = (self::$used[$kind] ?? 0) + 1;

        return true;
    }

    /** Would one more call of this kind be allowed? Does not spend. Used to skip gracefully before reaching the boundary that spends. */
    public static function available(string $kind): bool
    {
        return self::$limits === null || ! array_key_exists($kind, self::$limits) || (self::$used[$kind] ?? 0) < self::$limits[$kind];
    }

    /** Spend one call at the boundary that makes the request, or throw: fail closed, before the request exists. */
    public static function takeOrFail(string $kind): void
    {
        if (! self::take($kind)) {
            throw new RunBudgetExceeded($kind);
        }
    }

    /** @return array{limits: array<string, int>|null, used: array<string, int>, refused: array<string, int>} counts only, never a url or a key */
    public static function report(): array
    {
        return ['limits' => self::$limits, 'used' => self::$used, 'refused' => self::$refused];
    }
}
