<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use App\Support\RoleKey;
use Symfony\Component\HttpFoundation\Response;

/**
 * RequireProfile, for routes that serve BOTH surfaces.
 *
 *     Route::get('/payroll-type', ...)->middleware('hrit.role:admin,hr');
 *
 * The payroll routes in routes/hrms.php are reached two ways: by the Next.js
 * frontend with a Sanctum token, and by the Blade admin screens with a session
 * cookie and no token at all. Plain `profile:` refuses the second with a 401,
 * so it cannot be used here - which is a large part of why these routes ended
 * up with no server-side role gate whatsoever (F-91), leaving
 * payroll-shell.tsx, a React component, as the entire control.
 *
 * The order is the same one PayrollController::payrollTenantId() already uses
 * for the tenant, and for the same reason: token first, then session, and
 * NEVER the request body. Both are things the server established; the request
 * body is a claim by the caller.
 *
 * Note what this does NOT do: it never falls back to "no identity = allow".
 * A caller this cannot resolve is refused, because `auth` has already run and
 * an authenticated caller with an unreadable profile is a data problem, not a
 * licence.
 */
class RequireHritRole extends RequireProfile
{
    /**
     * @return string|null|Response  role_key, null if unresolvable, or a 401.
     */
    protected function resolveRoleKey(Request $request)
    {
        $token = trim((string) ($request->bearerToken() ?: $request->input('token')));

        if ($token !== '') {
            $accessToken = PersonalAccessToken::findToken($token);
            $user = $accessToken?->tokenable;

            if (!$user) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'Invalid token',
                ], 401);
            }

            if ($accessToken->expires_at !== null && $accessToken->expires_at->isPast()) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'Token expired',
                ], 401);
            }

            return RoleKey::forUser($user);
        }

        // No token: the Blade surface. authMiddleware has already established
        // that this session is logged in, so user_id is present and trustworthy.
        $sessionUserId = $request->hasSession() ? $request->session()->get('user_id') : null;

        if (!is_numeric($sessionUserId)) {
            return response()->json([
                'status'  => 0,
                'message' => 'Token not provided',
            ], 401);
        }

        return RoleKey::forUserId((int) $sessionUserId);
    }

    /**
     * A browser gets a page, an API caller gets JSON.
     *
     * The parent always answers JSON, which is right for /api. These routes can
     * be opened directly in a browser, and a raw JSON 403 there is a worse
     * answer than the application's own "not permitted" page.
     */
    protected function deny(Request $request): Response
    {
        if ($request->expectsJson()
            || $request->input('type') === 'API'
            || $request->header('Accept') === 'application/json') {
            return response()->json([
                'status'  => 0,
                'message' => 'You do not have permission to perform this action.',
            ], 403);
        }

        // There is no resources/views/errors/403.blade.php in this application,
        // so a plain response rather than a view that would itself 500.
        return response(
            'You do not have permission to view this page.',
            403,
            ['Content-Type' => 'text/plain; charset=UTF-8']
        );
    }
}
