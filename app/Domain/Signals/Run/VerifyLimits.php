<?php

namespace App\Domain\Signals\Run;

/**
 * The fail-closed plan for a verification run. Pure: it takes the command's options and the effective configuration and either returns the exact
 * target and ceilings, or a list of reasons it refuses. It opens no connection and calls nothing.
 *
 * Exactly one tenant, one product, one batch. Every ceiling must be configured (config signals.verify.*) as a positive integer and may not exceed
 * the absolute ceiling below, which is in code so that configuration cannot raise it. A missing or out-of-range value refuses the run before any
 * external call; nothing falls back to a default here.
 */
final class VerifyLimits
{
    /** Absolute ceilings; configuration may lower a limit, never raise it past these. */
    public const CEILINGS = [
        'max_search_calls' => 44,        // 22 planned searches, each retried at most once (HTTP 429)
        'max_page_requests' => 120,      // every page, robots.txt, sitemap and redirect request
        'max_ai_requests' => 6,          // 3 AI steps x 2 attempts
        'ai_failover_attempts' => 3,     // credentials tried inside one AI request
    ];

    /**
     * @param  array{tenant?: mixed, product?: mixed, batches?: mixed, trigger?: mixed}  $options  the command's raw options
     * @param  array<string, mixed>  $config  config('signals.verify')
     * @param  list<string>  $knownProducts
     * @param  array<string, mixed>  $runtime  scheduled_research (bool), products (list) as configured
     * @return array{ok: bool, errors: list<string>, tenant: ?int, product: ?string, limits: array<string, int>}
     */
    public static function plan(array $options, array $config, array $knownProducts, array $runtime = []): array
    {
        $errors = [];

        $tenant = $options['tenant'] ?? null;
        if (! is_scalar($tenant) || ! preg_match('/^[1-9]\d*$/', trim((string) $tenant))) {
            $errors[] = '--tenant must be one organisation id (a positive integer); a verification run never loops over organisations';
        }
        $product = $options['product'] ?? null;
        if (! is_string($product) || $product === '' || str_contains($product, ',') || ! in_array(strtolower(trim($product)), $knownProducts, true)) {
            $errors[] = '--product must name exactly one known product; it is never taken from SIGNALS_PRODUCTS';
        }
        $batches = $options['batches'] ?? 1;
        if ((string) $batches !== '1') {
            $errors[] = '--batches must be 1 in a verification run';
        }
        if (($options['trigger'] ?? 'manual') !== 'manual') {
            $errors[] = '--trigger must be manual in a verification run';
        }
        if (! empty($runtime['scheduled_research'])) {
            $errors[] = 'SIGNALS_SCHEDULED_RESEARCH must be off';
        }

        $limits = [];
        foreach (self::CEILINGS as $key => $ceiling) {
            $v = $config[$key] ?? null;
            if (! is_numeric($v) || (int) $v != $v || (int) $v < 1) {
                $errors[] = "signals.verify.{$key} must be set to a positive integer";

                continue;
            }
            if ((int) $v > $ceiling) {
                $errors[] = "signals.verify.{$key} is {$v}, above the absolute ceiling {$ceiling}";

                continue;
            }
            $limits[$key] = (int) $v;
        }

        $ok = $errors === [];

        return ['ok' => $ok, 'errors' => $errors, 'tenant' => $ok ? (int) $tenant : null, 'product' => $ok ? strtolower(trim((string) $product)) : null, 'limits' => $ok ? $limits : []];
    }

    /** The RunBudget limits, and the config overrides that bound what the budget cannot count directly (AI failover inside one request). @param array<string,int> $limits */
    public static function toBudget(array $limits): array
    {
        return [
            RunBudget::SEARCH => $limits['max_search_calls'], RunBudget::PAGE => $limits['max_page_requests'], RunBudget::AI => $limits['max_ai_requests'],
            // Counted at the HTTP request itself, so it also bounds the extra Gemini request and any retry added later.
            RunBudget::AI_PROVIDER => $limits['max_ai_requests'] * $limits['ai_failover_attempts'],
        ];
    }

    /** @param array<string,int> $limits @return array<string, int> config keys to force for the run */
    public static function configOverrides(array $limits): array
    {
        return [
            'ai.failover.max_attempts' => $limits['ai_failover_attempts'],
            'ai.failover.max_transient_attempts' => $limits['ai_failover_attempts'],
            'signals.ai_attempts' => 2,
            'signals.official_site.max_schools_per_run' => 1,
        ];
    }

    /** Hard maximum provider requests implied by the limits (for the report): search, page, and AI provider requests. @param array<string,int> $limits @return array<string,int> */
    public static function maxima(array $limits): array
    {
        return ['search_calls' => $limits['max_search_calls'], 'page_requests' => $limits['max_page_requests'],
            'ai_requests' => $limits['max_ai_requests'], 'ai_provider_requests' => $limits['max_ai_requests'] * $limits['ai_failover_attempts']];
    }
}
