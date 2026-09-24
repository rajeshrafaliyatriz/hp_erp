<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;

/**
 * Per-caller throttle for the AI & Intelligence API.
 *
 * Keyed on the authenticated user where there is one and the IP otherwise, so one
 * organisation's runaway client cannot exhaust another's budget. The limit is
 * `ai.rate_limit.per_minute`.
 *
 * Runs after `AiAuth` so the key is a user id rather than a shared NAT address —
 * ordering that matters in an office where every browser presents the same IP.
 *
 * ── THE BUCKET PARAMETER, AND WHY IT EXISTS ─────────────────────────────────
 *
 * The key was `'ai:'.$userId`, full stop. Platform Services reuses this middleware, and
 * without a way to separate the buckets an administrator opening the Event Bus console
 * would spend the AI console's sixty-a-minute — so the AI screens would start answering
 * 429 for a reason nothing on screen could explain, and the person would be looking at
 * the wrong feature for the cause.
 *
 * `'ai'` stays the default, so every existing declaration in `routes/ai.php` keys
 * exactly as it did before this parameter existed.
 */
class AiRateLimit
{
    public function __construct(private readonly RateLimiter $rateLimiter)
    {
    }

    public function handle(Request $request, Closure $next, string $bucket = 'ai')
    {
        $key = $this->resolveKey($request, $bucket);
        $maxAttempts = (int) config('ai.rate_limit.per_minute', 60);

        if ($this->rateLimiter->tooManyAttempts($key, $maxAttempts)) {
            return response()->json([
                'success' => false,
                'message' => 'Too many requests.',
                'data' => null,
                'errors' => null,
            ], 429, [
                'Retry-After' => (string) $this->rateLimiter->availableIn($key),
            ]);
        }

        $this->rateLimiter->hit($key, 60);

        $response = $next($request);
        $response->headers->set('X-RateLimit-Limit', (string) $maxAttempts);
        $response->headers->set(
            'X-RateLimit-Remaining',
            (string) max(0, $maxAttempts - $this->rateLimiter->attempts($key))
        );

        return $response;
    }

    private function resolveKey(Request $request, string $bucket): string
    {
        $auth = $request->attributes->get('ai_auth', []);

        return $bucket . ':' . ($auth['user_id'] ?? $request->ip());
    }
}
