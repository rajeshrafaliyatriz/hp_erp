<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
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
 * no route file changes; ALIASES maps each to the role_keys it means.
 *
 * LEGACY: 13 profiles predate role_key and 4 of them have users. They are
 * resolved by an EXACT name match in LEGACY_NAMES, not by substring, so those
 * accounts keep working without reopening the hole.
 *
 * A caller whose profile cannot be resolved is refused: this middleware guards
 * writes, and an unknown role is not a licence to perform one.
 */
class RequireProfile
{
    public function handle(Request $request, Closure $next, string ...$allowed): Response
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

        if ($this->profileMatches($user, $allowed)) {
            return $next($request);
        }

        return response()->json([
            'status'  => 0,
            'message' => 'You do not have permission to perform this action.',
        ], 403);
    }

    /**
     * Route-argument vocabulary -> the role_keys it authorises.
     *
     * Deliberately reproduces what the substring matcher granted, so this change
     * fixes the CLASS without silently re-drawing anyone's access:
     * 'manager' matched both "HR Manager" and "Reporting Manager", and still does.
     */
    private const ALIASES = [
        'admin'      => ['administrator'],
        'hr'         => ['hr_manager', 'hr_executive'],
        'manager'    => ['hr_manager', 'reporting_manager'],
        'employee'   => ['employee'],
        'executive'  => ['executive'],
        'auditor'    => ['auditor'],
        'recruiter'  => ['recruiter'],
    ];

    /**
     * Profiles that predate role_key, matched EXACTLY on lowercased name.
     *
     * Checked against the substring matcher across both arg-sets in routes/
     * ('admin,hr' and 'admin,hr,manager'): only four profiles decide differently,
     * and all four have ZERO users.
     *
     * Three are named exactly "HR" and are restored here.
     *
     * The fourth, id 38 "Deparment Administrator", is DELIBERATELY NOT restored.
     * It passed only because "deparment administrator" contains "admin" - which
     * is the collision this change exists to remove, and a department
     * administrator is not an institute administrator. Zero users, so nothing
     * breaks; recorded so the denial is a decision rather than an oversight.
     */
    private const LEGACY_NAMES = [
        'admin'                      => 'administrator',
        'organization administrator' => 'administrator',
        'hr'                         => 'hr_manager',
    ];

    /** @param array<int, string> $allowed */
    private function profileMatches(object $user, array $allowed): bool
    {
        $profileId = (int) ($user->user_profile_id ?? 0);

        if ($profileId <= 0 || $allowed === []) {
            return false;
        }

        $profile = DB::table('tbluserprofilemaster')->where('id', $profileId)
            ->first(['role_key', 'name']);

        if (!$profile) {
            return false;
        }

        $roleKey = trim((string) ($profile->role_key ?? ''));

        if ($roleKey === '') {
            $name    = strtolower(trim((string) $profile->name));
            $roleKey = self::LEGACY_NAMES[$name] ?? '';
        }

        if ($roleKey === '') {
            return false;
        }

        foreach ($allowed as $permitted) {
            $permitted = strtolower(trim($permitted));

            // An alias the map does not know grants nothing, rather than
            // falling through to a looser comparison.
            if (in_array($roleKey, self::ALIASES[$permitted] ?? [], true)) {
                return true;
            }
        }

        return false;
    }
}
