<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires a valid, unexpired Sanctum token on the route.
 *
 * routes/api.php has no middleware group of its own, so a route is protected
 * only if its controller happens to guard itself. A sweep of all 689 API routes
 * found 25 that guarded nothing at all - among them the whole competency
 * dashboard, an unauthenticated newsletter send, the Gemini endpoints that
 * spend paid LLM credits, and an unauthenticated write to suggested_course.
 *
 * This is the gate for those routes. It answers "is the caller authenticated",
 * nothing more; what they are then allowed to see or do is decided by
 * ResolvesApiIdentity / ResolvesLmsIdentity in the controller, and by
 * TaskPermissionMiddleware for role-gated abilities.
 *
 * Deliberately not applied as a global API group: routes that must stay open
 * (OTP, signup, Google login) would then need exempting one by one, and an
 * exemption list that drifts is how a public route becomes an accident. Listing
 * the protected routes in routes/api.php keeps the decision visible at the
 * point the route is declared.
 */
class RequireApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = trim((string) ($request->bearerToken() ?: $request->input('token')));

        if ($token === '') {
            return response()->json([
                'status'  => 0,
                'message' => 'Token not provided',
            ], 401);
        }

        $accessToken = PersonalAccessToken::findToken($token);

        if (!$accessToken || !$accessToken->tokenable) {
            return response()->json([
                'status'  => 0,
                'message' => 'Invalid token',
            ], 401);
        }

        // findToken() resolves by hash and does not consider expiry.
        if ($accessToken->expires_at !== null && $accessToken->expires_at->isPast()) {
            return response()->json([
                'status'  => 0,
                'message' => 'Token expired',
            ], 401);
        }

        return $next($request);
    }
}
