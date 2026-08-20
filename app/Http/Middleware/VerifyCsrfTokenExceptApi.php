<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Base;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * CSRF, SKIPPED ONLY FOR GENUINELY TOKEN-AUTHENTICATED REQUESTS.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * THE BUG THIS FIXES
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Several screens in the Next.js frontend post to WEB routes, not API routes -
 * `POST /task` is the notable one. Web routes carry VerifyCsrfToken, and the
 * frontend sends a Sanctum token, never a CSRF token. So those posts answered
 * 419 in production.
 *
 * They worked locally for the WRONG REASON: bootstrap/app.php exempts
 * `http://localhost:8000/*` when APP_ENV=local, which is a blanket exemption
 * covering every web route. Local therefore never exercised CSRF at all, and
 * the failure only appeared once deployed - the worst possible place to find it.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * WHY A BLANKET EXEMPTION WOULD BE A REAL VULNERABILITY
 * ═══════════════════════════════════════════════════════════════════════
 *
 * `front_desk\taskController::store()` accepts BOTH session and token auth: it
 * reads session values first and only overwrites them when `type=API`. Adding
 * `task` to the except list outright would let any third-party page post to it
 * using the victim's ambient session cookie - textbook CSRF.
 *
 * So the skip is conditional on the two things that make a request
 * non-ambient AND make the controller use the token's identity:
 *
 *   1. `type=API` - the branch where the controller replaces every session
 *      value with apiTenantId(), which is token-only.
 *   2. A token that actually resolves to a live, unexpired Sanctum token.
 *
 * An attacker's page cannot supply (2) for a victim: it cannot read the
 * victim's token cross-origin. A request that carries a valid token is
 * deliberate, not ambient - which is precisely the property CSRF protects.
 *
 * An invalid or missing token falls through to normal CSRF verification rather
 * than being refused here, so a genuine session-based form is unaffected.
 */
class VerifyCsrfTokenExceptApi extends Base
{
    public function handle($request, Closure $next)
    {
        if ($this->isTokenAuthenticatedApiCall($request)) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }

    private function isTokenAuthenticatedApiCall($request): bool
    {
        if ($request->input('type') !== 'API') {
            return false;
        }

        $token = $request->bearerToken() ?: $request->input('token');

        if (!$token) {
            return false;
        }

        $accessToken = PersonalAccessToken::findToken($token);

        if (!$accessToken) {
            return false;
        }

        // findToken() does not check expiry, so an expired token would
        // otherwise buy a CSRF bypass.
        if ($accessToken->expires_at && $accessToken->expires_at->isPast()) {
            return false;
        }

        return $accessToken->tokenable !== null;
    }
}
