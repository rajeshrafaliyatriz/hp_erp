<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * The one place that answers "what role is this user?".
 *
 * `role_key` (D-010) is the stable identifier; `tbluserprofilemaster.name` is a
 * display label a tenant can edit, and authorization must never key on it. That
 * rule was already enforced inside RequireProfile, and then reproduced nowhere
 * else - the Leave API had no role check at all, the payroll routes had none,
 * and the Next.js frontend was still substring-matching the display name
 * (F-104). Three consumers, one question, so it lives here once.
 *
 * Resolution order, deliberately:
 *   1. tbluserprofilemaster.role_key   - the stable identifier
 *   2. LEGACY_NAMES, matched EXACTLY   - the 13 profiles that predate role_key
 *   3. null                            - and null grants nothing, ever
 *
 * There is no substring step. str_contains('reporting manager', 'manager') is
 * true, which is how a Reporting Manager used to pass a gate written for HR
 * Managers; the same collision waits for hr_executive/hr_manager and
 * department_head/head.
 */
final class RoleKey
{
    /** Every role_key the platform defines, in privilege order for display. */
    public const ALL = [
        'employee',
        'reporting_manager',
        'department_head',
        'hr_executive',
        'hr_manager',
        'administrator',
        'executive',
        'auditor',
        'recruiter',
    ];

    /**
     * Route-argument vocabulary -> the role_keys it authorises.
     *
     * Lifted verbatim from RequireProfile so the two cannot drift. It reproduces
     * what the old substring matcher granted on purpose: 'manager' matched both
     * "HR Manager" and "Reporting Manager", and still does.
     */
    public const ALIASES = [
        'admin'     => ['administrator'],
        'hr'        => ['hr_manager', 'hr_executive'],
        'manager'   => ['hr_manager', 'reporting_manager'],
        'employee'  => ['employee'],
        'executive' => ['executive'],
        'auditor'   => ['auditor'],
        'recruiter' => ['recruiter'],
    ];

    /**
     * Profiles that predate role_key, matched EXACTLY on the lowercased name.
     *
     * Mirrors RequireProfile::LEGACY_NAMES. Note what is absent: profile 38
     * "Deparment Administrator" is NOT mapped to administrator. It used to pass
     * an admin gate because its name contains "admin", which is precisely the
     * collision being removed, and a department administrator is not an
     * institute administrator. It has zero users, so nothing breaks.
     */
    public const LEGACY_NAMES = [
        'admin'                      => 'administrator',
        'organization administrator' => 'administrator',
        'hr'                         => 'hr_manager',
    ];

    /** Resolved profile ids, so a request that asks repeatedly pays once. */
    private static array $cache = [];

    /** The role_key for a tbluserprofilemaster id, or null if it cannot be resolved. */
    public static function forProfileId(?int $profileId): ?string
    {
        if (!$profileId || $profileId <= 0) {
            return null;
        }

        if (array_key_exists($profileId, self::$cache)) {
            return self::$cache[$profileId];
        }

        $profile = DB::table('tbluserprofilemaster')
            ->where('id', $profileId)
            ->first(['role_key', 'name']);

        return self::$cache[$profileId] = $profile ? self::fromProfile($profile) : null;
    }

    /** The role_key for a tbluser id, or null. */
    public static function forUserId(?int $userId): ?string
    {
        if (!$userId || $userId <= 0) {
            return null;
        }

        $profileId = DB::table('tbluser')->where('id', $userId)->value('user_profile_id');

        return self::forProfileId($profileId ? (int) $profileId : null);
    }

    /** The role_key carried by an already-loaded user object. */
    public static function forUser(?object $user): ?string
    {
        return self::forProfileId(isset($user->user_profile_id) ? (int) $user->user_profile_id : null);
    }

    /** role_key from a profile row that already has role_key + name. */
    public static function fromProfile(object $profile): ?string
    {
        $roleKey = trim((string) ($profile->role_key ?? ''));

        if ($roleKey !== '') {
            return $roleKey;
        }

        $name = strtolower(trim((string) ($profile->name ?? '')));

        return self::LEGACY_NAMES[$name] ?? null;
    }

    /**
     * Does $roleKey satisfy any of $allowed, where $allowed uses the route
     * vocabulary ('admin', 'hr', 'manager', ...)?
     *
     * An alias the map does not know grants nothing, rather than falling through
     * to a looser comparison.
     */
    public static function satisfies(?string $roleKey, array $allowed): bool
    {
        if ($roleKey === null || $roleKey === '' || $allowed === []) {
            return false;
        }

        foreach ($allowed as $permitted) {
            $permitted = strtolower(trim((string) $permitted));

            if (in_array($roleKey, self::ALIASES[$permitted] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    /** Only for tests that need to observe a profile change within one process. */
    public static function flushCache(): void
    {
        self::$cache = [];
    }
}
