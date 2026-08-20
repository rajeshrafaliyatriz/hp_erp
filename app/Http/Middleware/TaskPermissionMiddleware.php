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
 *   - a PRIVILEGED ability requires proof of an ELEVATED role; anything else
 *     any authenticated user may do
 *
 * The role comes from tbluser.user_profile_id -> tbluserprofilemaster.role_key,
 * with an exact-name fallback for the profiles that predate role_key.
 *
 * AMENDED: this class previously asked "is this an Employee?" and refused only
 * on a yes, which meant an unresolvable profile was granted full delete and
 * approve rights, and renaming the Employee profile disabled the check
 * entirely. The question is now positive and unresolved cases are REFUSED —
 * see isPrivileged() for the full reasoning.
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
        // Reading every employee's productivity, or the permission matrix, is
        // an administrative act even though it is a GET.
        'report.view',
    ];

    /**
     * Roles that may perform the abilities above.
     *
     * Deliberately includes the two people-management roles: deleting or
     * approving a subordinate's task is a manager's job, not only an admin's.
     * `employee`, `auditor` and `recruiter` are absent - an auditor reads, and
     * a recruiter has no business in another team's task queue.
     */
    private const ELEVATED = [
        'administrator',
        'hr_manager',
        'hr_executive',
        'executive',
        'reporting_manager',
        'department_head',
    ];

    /** Profiles that predate role_key, resolved by EXACT name. */
    private const LEGACY_NAMES = [
        'admin'                      => 'administrator',
        'organization administrator' => 'administrator',
        'hr'                         => 'hr_manager',
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

        if (in_array($ability, self::PRIVILEGED, true) && !$this->isPrivileged($user)) {
            return response()->json([
                'status' => 0,
                'message' => 'You do not have permission to perform this action.',
            ], 403);
        }

        return $next($request);
    }

    /**
     * MAY THIS CALLER PERFORM A PRIVILEGED ACTION?
     *
     * ═══════════════════════════════════════════════════════════════════════
     * THIS REPLACES A CHECK THAT FAILED OPEN, IN TWO WAYS
     * ═══════════════════════════════════════════════════════════════════════
     *
     * The previous form asked `isEmployee()` and refused only on a true. That
     * inverted the burden of proof, and both of its escape hatches granted
     * delete/approve/admin rather than withholding it:
     *
     *   1. A user whose `user_profile_id` was 0 or null returned FALSE - "not
     *      an employee" - and sailed through with full rights. A missing role
     *      is not evidence of seniority.
     *   2. It compared the profile's DISPLAY NAME to the literal 'Employee'.
     *      Profile names are tenant-editable; renaming that role to "Associate"
     *      or "Staff" silently granted every employee destructive rights.
     *
     * The question is now positive: the caller must PROVE a privileged role.
     * Anything unresolved is refused, which is the correct default for a guard
     * that stands in front of writes.
     *
     * Keyed on `tbluserprofilemaster.role_key` - the stable machine name -
     * exactly as RequireProfile does, with the same exact-match legacy fallback
     * so the profiles that predate role_key keep working.
     */
    private function isPrivileged(object $user): bool
    {
        $profileId = (int) ($user->user_profile_id ?? 0);

        if ($profileId <= 0) {
            return false;
        }

        $profile = DB::table('tbluserprofilemaster')
            ->where('id', $profileId)
            ->first(['role_key', 'name']);

        if (!$profile) {
            return false;
        }

        $roleKey = trim((string) ($profile->role_key ?? ''));

        if ($roleKey === '') {
            // EXACT name match, never a substring: 'Reporting Manager' must not
            // be matched by a rule meant for 'Manager'.
            $roleKey = self::LEGACY_NAMES[strtolower(trim((string) $profile->name))] ?? '';
        }

        return $roleKey !== '' && in_array($roleKey, self::ELEVATED, true);
    }
}
