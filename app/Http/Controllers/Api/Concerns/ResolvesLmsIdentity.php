<?php

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Identity, tenant and role resolution for the LMS API controllers.
 *
 * The LMS surface had three stacked ways past its own guards:
 *
 *   1. guardApiToken() opened with `if ($request->input('type') !== 'API')
 *      return null;` - dropping the `type` parameter skipped authentication
 *      altogether.
 *   2. The role came from `$request->input('user_profile_name')`, so
 *      `&user_profile_name=admin` granted institute administration.
 *   3. The authoring guard treated an empty profile as permitted, so omitting
 *      the parameter granted course-authoring rights.
 *
 * All three are closed here. The token decides who the caller is, the caller's
 * row in tbluser decides their profile, and the profile decides what they may
 * do - the same chain TaskPermissionMiddleware already uses.
 *
 * The controllers keep their original private method names and signatures, so
 * none of their ~200 call sites had to change; only the bodies delegate here.
 */
trait ResolvesLmsIdentity
{
    use ResolvesApiIdentity;

    /** Resolved once per request - these controllers call the guards repeatedly. */
    private ?array $lmsIdentityCache = null;

    /**
     * @return array{user:object, user_id:int, sub_institute_id:int, profile_name:string}|\Illuminate\Http\JsonResponse
     */
    protected function lmsIdentity(Request $request)
    {
        if ($this->lmsIdentityCache !== null) {
            return $this->lmsIdentityCache;
        }

        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $identity['profile_name'] = $this->lmsProfileName($identity['user']);

        return $this->lmsIdentityCache = $identity;
    }


    /**
     * Exact role_key matching, shared by every LMS profile gate.
     *
     * G-AUTH-02 - the last two instances of G-AUTH-01's class. Matching was by
     * SUBSTRING of the display name, so str_contains('reporting manager',
     * 'manager') is true and any future role whose name contains another's
     * collides: hr_executive/hr_manager, department_head/head.
     *
     * The route/guard vocabulary ('admin', 'hr') is unchanged so no call site
     * moves; ALIASES maps each term to the role_keys it means. Identical
     * mapping to RequireProfile, so the two agree by construction.
     *
     * LEGACY_NAMES covers the 13 profiles that predate role_key, matched EXACTLY
     * on the lowercased name - never by substring.
     */
    private const LMS_ALIASES = [
        'admin'     => ['administrator'],
        'hr'        => ['hr_manager', 'hr_executive'],
        'manager'   => ['hr_manager', 'reporting_manager'],
        'employee'  => ['employee'],
        'executive' => ['executive'],
        'auditor'   => ['auditor'],
        'recruiter' => ['recruiter'],
    ];

    private const LMS_LEGACY_NAMES = [
        'admin'                      => 'administrator',
        'organization administrator' => 'administrator',
        'hr'                         => 'hr_manager',
    ];

    protected function lmsRoleMatches(object $user, array $allowed): bool
    {
        $profileId = (int) ($user->user_profile_id ?? 0);
        if ($profileId <= 0 || $allowed === []) {
            return false;
        }

        $profile = DB::table('tbluserprofilemaster')->where('id', $profileId)->first(['role_key', 'name']);
        if (!$profile) {
            return false;
        }

        $roleKey = trim((string) ($profile->role_key ?? ''));
        if ($roleKey === '') {
            $roleKey = self::LMS_LEGACY_NAMES[strtolower(trim((string) $profile->name))] ?? '';
        }
        if ($roleKey === '') {
            return false;
        }

        foreach ($allowed as $permitted) {
            if (in_array($roleKey, self::LMS_ALIASES[strtolower(trim($permitted))] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The caller's profile name from tbluser.user_profile_id, lowercased.
     * Empty string when the account has no resolvable profile - historical rows
     * predate profile assignment, and those callers are treated as
     * non-privileged rather than refused outright.
     */
    protected function lmsProfileName(object $user): string
    {
        $profileId = (int) ($user->user_profile_id ?? 0);

        if ($profileId <= 0) {
            return '';
        }

        $name = DB::table('tbluserprofilemaster')->where('id', $profileId)->value('name');

        return strtolower(trim((string) $name));
    }

    /**
     * Authentication gate. Null when the request may proceed, otherwise the
     * JsonResponse to return immediately.
     */
    protected function guardLmsToken(Request $request)
    {
        $identity = $this->lmsIdentity($request);

        return is_array($identity) ? null : $identity;
    }

    /**
     * Role gate. $allowed is matched as a substring against the caller's real
     * profile name. An unresolvable profile is refused, not waved through.
     */
    protected function guardLmsProfile(Request $request, array $allowed, string $message)
    {
        $identity = $this->lmsIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        if ($this->lmsRoleMatches($identity['user'], $allowed)) {
            return null;
        }

        return response()->json(['status' => false, 'message' => $message], 403);
    }

    /**
     * The caller's own organisation. Resolves identity itself rather than
     * assuming a guard ran first, so an endpoint that forgets to authenticate
     * scopes its queries to null and returns nothing - instead of scoping them
     * to whatever tenant the request asked for.
     */
    protected function lmsTenantId(Request $request): ?int
    {
        $identity = $this->lmsIdentity($request);

        return is_array($identity) ? $identity['sub_institute_id'] : null;
    }

    /**
     * The caller's own user id.
     *
     * Across these controllers `user_id` was always the actor or the caller
     * themselves - never the subject, which is carried by a route parameter or
     * an explicit field. Reading it from the request meant three things:
     *
     *   - "my courses", "my sessions", "my deadlines" returned whoever the
     *     caller named instead of the caller;
     *   - created_by / updated_by / deleted_by recorded whoever the caller
     *     named, so the attribution trail could be forged;
     *   - LmsGovernanceController's "you cannot deactivate your own account"
     *     check compared the target against an attacker-supplied id, so it
     *     guarded nothing.
     */
    protected function contextUserId(Request $request): ?int
    {
        $identity = $this->lmsIdentity($request);

        return is_array($identity) ? $identity['user_id'] : null;
    }
}
