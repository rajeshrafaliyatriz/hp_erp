<?php

namespace App\Http\Middleware;

use App\Services\Ai\AiScopeResolver;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Authenticates a caller of the AI & Intelligence API.
 *
 * The same gate `RequireApiToken` applies to the rest of this application's API —
 * a valid, unexpired Sanctum token whose owner still exists — and then one step
 * more: it resolves the caller's identity facts and puts them on the request for
 * `AiContextHydrator` to turn into a scope.
 *
 * It is a separate class rather than a reuse of `RequireApiToken` because that one
 * answers only "is the caller authenticated" and deliberately leaves identity to
 * the controller. The AI routes need the identity resolved *before* the controller,
 * so that no AI controller is ever in a position to read a tenant from input.
 *
 * LMS K-12's equivalent is `McpAuth`, which reads a GenTux JWT. That package is not
 * installed here and the rest of G2G authenticates with Sanctum, so this validates
 * a Sanctum token instead. The attribute it sets — `ai_auth` — carries the same
 * facts under the same names, so everything downstream is identical.
 */
class AiAuth
{
    public function __construct(private readonly AiScopeResolver $resolver)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        $token = trim((string) ($request->bearerToken() ?: $request->input('token')));

        if ($token === '') {
            return $this->deny('An authentication token is required.', 401);
        }

        $accessToken = PersonalAccessToken::findToken($token);
        $user = $accessToken?->tokenable;

        if (! $user) {
            return $this->deny('The authentication token is invalid.', 401);
        }

        // findToken() resolves by hash and does not consider expiry. Without this
        // an expired credential keeps working forever.
        if ($accessToken->expires_at !== null && $accessToken->expires_at->isPast()) {
            return $this->deny('The authentication token has expired.', 401);
        }

        $request->attributes->set('ai_auth', $this->resolver->identityFor($user));

        return $next($request);
    }

    private function deny(string $message, int $status)
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => null,
            'errors' => null,
        ], $status);
    }
}
