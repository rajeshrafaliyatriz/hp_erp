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
 */
class AiRateLimit
{
    public function __construct(private readonly RateLimiter $rateLimiter)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        $key = $this->resolveKey($request);
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

    private function resolveKey(Request $request): string
    {
        $auth = $request->attributes->get('ai_auth', []);

        return 'ai:' . ($auth['user_id'] ?? $request->ip());
    }
}
