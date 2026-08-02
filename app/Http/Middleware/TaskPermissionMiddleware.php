<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Backs the `task.permission:{ability}` middleware the task-management routes
 * have always declared. The alias was never registered and this class never
 * written, so every write route in the group 500ed on dispatch.
 *
 * The rule mirrors the role gating the previous frontend enforced in its UI
 * (localStorage user_profile_name), moved server-side where it belongs:
 *
 *   - every request must carry a valid Sanctum token
 *   - abilities that destroy or administer are refused to the Employee
 *     profile; everything else any authenticated user may do
 *
 * The profile comes from tbluser.user_profile_id -> tbluserprofilemaster.name
 * (Admin / HR / Employee, seeded per tenant). A user with no resolvable
 * profile is treated as non-privileged rather than refused outright, because
 * historical rows predate profile assignment.
 */
class TaskPermissionMiddleware
{
    /** Abilities that alter or remove other people's work. */
    private const PRIVILEGED = [
        'task.delete',
        'task.approve',
        'project.create',
        'project.manage',
        'workstream.manage',
        'dependency.manage',
        'milestone.manage',
        'notification.manage',
    ];

    public function handle(Request $request, Closure $next, string $ability = ''): Response
    {
        $token = trim((string) ($request->bearerToken() ?: $request->input('token')));

        if ($token === '') {
            return response()->json(['status' => 0, 'message' => 'Token is required.'], 401);
        }

        $accessToken = PersonalAccessToken::findToken($token);
        $user = $accessToken?->tokenable;

        if (!$user) {
            return response()->json(['status' => 0, 'message' => 'Unauthenticated.'], 401);
        }

        if (in_array($ability, self::PRIVILEGED, true) && $this->isEmployee($user)) {
            return response()->json([
                'status' => 0,
                'message' => 'You do not have permission to perform this action.',
            ], 403);
        }

        return $next($request);
    }

    private function isEmployee(object $user): bool
    {
        $profileId = (int) ($user->user_profile_id ?? 0);
        if ($profileId <= 0) {
            return false;
        }

        $name = DB::table('tbluserprofilemaster')
            ->where('id', $profileId)
            ->value('name');

        return strcasecmp(trim((string) $name), 'Employee') === 0;
    }
}
