<?php

namespace App\Http\Middleware;

use App\Support\RoleKey;
use App\Support\SubjectAuthority;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route to a NAMED TIER of App\Support\SubjectAuthority.
 *
 *     Route::put(...)->middleware('subject:hr_elevated');
 *     Route::post(...)->middleware('subject:people_managers');
 *
 * ── WHY NOT `profile:admin,hr` ──────────────────────────────────────────────
 *
 * That was the obvious choice and it cannot express the requirement. Two
 * reasons, both read off RoleKey::ALIASES:
 *
 *   1. THERE IS NO ALIAS FOR department_head. The vocabulary is admin, hr,
 *      manager, employee, executive, auditor, recruiter - and 'manager' means
 *      ['hr_manager', 'reporting_manager']. No route argument can authorise a
 *      department head, so a gate written with `profile:` silently excludes one
 *      of the two roles that is supposed to be included.
 *   2. `profile:admin,hr` excludes executive and auditor, whose entire purpose
 *      is to read the organisation and change nothing. Gating an HR read
 *      against them is not a fix, it is a different bug.
 *
 * ── WHY NOT `menuright:` ────────────────────────────────────────────────────
 *
 * Because routes/api.php already records what that costs. A guard naming menu
 * 225 returned 403 to EVERYONE, the administrator included, the day that menu
 * row was rolled back: "A GUARD THAT NAMES A MENU IS A DEPENDENCY ON A ROW."
 * Revoking a menu should hide a screen, never 403 the people who still hold it.
 *
 * ── THE SHAPE IS RequireProfile'S, ON PURPOSE ───────────────────────────────
 *
 * Token-only identity, expiry checked, `{status: 0}` refusal envelope, 401 for
 * an authentication failure and 403 for an authorisation one. This sits beside
 * `profile:` in the same route file, so behaving differently from it would be a
 * trap for whoever reads the two lines together.
 *
 * An unknown tier name is a 500, not a 403. A typo in a route argument is a
 * programming error, and failing it closed would look exactly like a
 * legitimate refusal - which is how a guard silently denies everyone.
 */
class RequireSubjectAuthority
{
    public function handle(Request $request, Closure $next, string $tierName): Response
    {
        $tier = SubjectAuthority::tier($tierName);

        if ($tier === null) {
            return response()->json([
                'status'  => 0,
                'message' => 'Unknown authority tier: ' . $tierName,
            ], 500);
        }

        $roleKey = $this->resolveRoleKey($request);

        // A Response here is an authentication failure, returned as-is.
        if ($roleKey instanceof Response) {
            return $roleKey;
        }

        if (SubjectAuthority::roleSatisfies($roleKey, $tier)) {
            return $next($request);
        }

        return response()->json([
            'status'  => 0,
            'message' => 'You do not have permission to perform this action.',
        ], 403);
    }

    /**
     * Who is calling, as a role_key.
     *
     * Through RoleKey, so LEGACY_NAMES resolves the profiles that predate the
     * column - 30 of 42 on live, including every tenant's "Admin" and "HR".
     *
     * @return string|null|Response  role_key, null if unresolvable, or a 401.
     */
    protected function resolveRoleKey(Request $request)
    {
        $token = trim((string) ($request->bearerToken() ?: $request->input('token')));

        if ($token === '') {
            return response()->json(['status' => 0, 'message' => 'Token not provided'], 401);
        }

        $accessToken = PersonalAccessToken::findToken($token);
        $user = $accessToken?->tokenable;

        if (!$user) {
            return response()->json(['status' => 0, 'message' => 'Invalid token'], 401);
        }

        if ($accessToken->expires_at !== null && $accessToken->expires_at->isPast()) {
            return response()->json(['status' => 0, 'message' => 'Token expired'], 401);
        }

        return RoleKey::forUser($user);
    }
}
