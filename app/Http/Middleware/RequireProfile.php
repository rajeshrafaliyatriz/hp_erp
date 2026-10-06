<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use App\Support\RoleKey;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route to callers holding one of the named user profiles.
 *
 *     Route::post(...)->middleware('profile:admin,hr');
 *
 * Server-side role enforcement existed in exactly one module. Task Management
 * has TaskPermissionMiddleware; the other five had none, so any authenticated
 * employee could call the write endpoints directly and, for instance, rate a
 * colleague's competency or alter a performance review. Hiding the button in
 * the UI is not a control - the endpoint is still there.
 *
 * The profile comes from tbluser.user_profile_id -> tbluserprofilemaster.name,
 * the same chain TaskPermissionMiddleware and ResolvesLmsIdentity use, so all
 * three agree on what a caller is.
 *
 * MATCHING IS EXACT, ON role_key - NOT a substring of the display name.
 *
 * Substring matching was the original approach and it silently over-granted:
 * str_contains('reporting manager', 'manager') is true, so a Reporting Manager
 * passed a gate written for HR Managers. The same collision is waiting for any
 * role whose name contains another's - hr_executive/hr_manager,
 * department_head/head. That is the exact failure role_key was introduced (D-010)
 * to end: authorization must key on a stable identifier, never on wording a
 * tenant can edit.
 *
 * The route's arguments stay in the old vocabulary ('admin', 'hr', 'manager') so
 * no route file changes; App\Support\RoleKey::ALIASES maps each to the role_keys
 * it means, and RoleKey::LEGACY_NAMES resolves the 13 profiles that predate
 * role_key by an EXACT name match.
 *
 * Both tables, and the resolution itself, moved to RoleKey in Sprint 1 of the
 * HRIT remediation: the Leave API and the payroll routes needed the same answer,
 * and a second copy of an authorization table is how the two drift apart. This
 * class keeps the HTTP behaviour and delegates the question.
 *
 * A caller whose profile cannot be resolved is refused: this middleware guards
 * writes, and an unknown role is not a licence to perform one.
 */
class RequireProfile
{
    public function handle(Request $request, Closure $next, string ...$allowed): Response
    {
        $roleKey = $this->resolveRoleKey($request);

        // A JsonResponse here is an authentication failure, returned as-is.
        if ($roleKey instanceof Response) {
            return $roleKey;
        }

        if (RoleKey::satisfies($roleKey, $allowed)) {
            return $next($request);
        }

        return $this->deny($request);
    }

    /** The refusal. Overridden by RequireHritRole, which also serves browsers. */
    protected function deny(Request $request): Response
    {
        return response()->json([
            'status'  => 0,
            'message' => 'You do not have permission to perform this action.',
        ], 403);
    }

    /**
     * Who is calling, as a role_key.
     *
     * Token only. RequireHritRole overrides this to add a session fallback for
     * the payroll routes, which serve the token-authenticated Next.js frontend
     * AND the session-authenticated Blade screens from the same URLs.
     *
     * @return string|null|Response  role_key, null if unresolvable, or a 401.
     */
    protected function resolveRoleKey(Request $request)
    {
        $token = trim((string) ($request->bearerToken() ?: $request->input('token')));

        if ($token === '') {
            return response()->json([
                'status'  => 0,
                'message' => 'Token not provided',
            ], 401);
        }

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

}
