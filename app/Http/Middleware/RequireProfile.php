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
 * Matching is case-insensitive and by substring, because tenants seed profile
 * names with their own wording ("HR Manager", "Super Admin"). A caller whose
 * profile cannot be resolved is refused: this middleware guards writes, and an
 * unknown role is not a licence to perform one.
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

    /** @param array<int, string> $allowed */
    private function profileMatches(object $user, array $allowed): bool
    {
        $profileId = (int) ($user->user_profile_id ?? 0);

        if ($profileId <= 0 || $allowed === []) {
            return false;
        }

        $name = DB::table('tbluserprofilemaster')->where('id', $profileId)->value('name');
        $name = strtolower(trim((string) $name));

        if ($name === '') {
            return false;
        }

        foreach ($allowed as $permitted) {
            $permitted = strtolower(trim($permitted));
            if ($permitted !== '' && str_contains($name, $permitted)) {
                return true;
            }
        }

        return false;
    }
}
