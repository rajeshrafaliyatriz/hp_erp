<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route to the people allowed to act ABOVE the tenants.
 *
 *     Route::post(...)->middleware(['api.token', 'platform.owner']);
 *
 * ── WHY THIS IS NOT `profile:admin` ─────────────────────────────────────────
 *
 * `profile:` answers "what is this person WITHIN their organisation", and every
 * role it knows is scoped to one. Creating an organisation is not an act inside
 * one, so no `role_key` can authorise it - an administrator of tenant 6 has no
 * more business creating tenant 16 than an employee does.
 *
 * Membership lives in `platform_owners`, seeded by
 * 2026_09_08_100000_create_platform_owners_table. See that migration for why it
 * is a table rather than `tbluser.is_admin` (which is tenant-scoped, overloaded
 * with two meanings, and settable from an unauthenticated request body).
 *
 * ── 404, NOT 403 ────────────────────────────────────────────────────────────
 *
 * The house rule for cross-tenant refusals, and it applies with more force here:
 * 403 confirms that `/api/platform/organizations` exists and that the caller has
 * merely found the wrong door. There is no reason to tell an ordinary
 * administrator that a tenant-creation endpoint is there at all.
 *
 * ── IT AUTHENTICATES FOR ITSELF ─────────────────────────────────────────────
 *
 * Routes pair this with `api.token`, but this class resolves the token again
 * rather than trusting that pairing. A middleware whose safety depends on
 * another one being listed beside it is one route edit away from being a no-op,
 * and this is the gate on creating organisations.
 */
class RequirePlatformOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = trim((string) ($request->bearerToken() ?: $request->input('token')));

        if ($token === '') {
            return $this->deny();
        }

        $accessToken = PersonalAccessToken::findToken($token);
        $user = $accessToken?->tokenable;

        if (!$user) {
            return $this->deny();
        }

        // findToken() resolves by hash and does not consider expiry.
        if ($accessToken->expires_at !== null && $accessToken->expires_at->isPast()) {
            return $this->deny();
        }

        if (!self::isOwner((int) $user->id)) {
            return $this->deny();
        }

        return $next($request);
    }

    /**
     * Is this user a current platform owner?
     *
     * Public and static so a controller can ask the same question for a UI hint
     * without duplicating the rule. `revoked_at` is a column rather than a
     * deleted row, so a revoked owner is still on record and still refused.
     */
    public static function isOwner(?int $userId): bool
    {
        if (!$userId || $userId <= 0) {
            return false;
        }

        return DB::table('platform_owners')
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->exists();
    }

    /**
     * The same answer for "no token", "bad token", "expired token" and "not an
     * owner". Distinguishing them would tell a caller which part of the guess to
     * change.
     */
    private function deny(): Response
    {
        return response()->json([
            'status' => 0,
            'message' => 'Not found.',
        ], 404);
    }
}
