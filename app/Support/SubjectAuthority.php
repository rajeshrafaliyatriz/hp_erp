<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Who may act on somebody else's HR record.
 *
 * ── WHY THIS EXISTS: FIVE LISTS, NO TWO THE SAME ────────────────────────────
 *
 * The same question was answered independently in five places, and they had
 * drifted:
 *
 *   ResolvesCompetencyContext::COMPETENCY_ELEVATED     5 roles, the reference
 *   ResolvesLeaveContext::LEAVE_ELEVATED               3, drops executive/auditor
 *   TaskPermissionMiddleware::ELEVATED                 6, the only one with department_head
 *   CapabilityProgressController (inline)              4, the only one with reporting_manager
 *   ProfileVisibility (inline)                         3, a field-redaction tier
 *
 * A second copy of an authorization table is how the first copy stops being the
 * only one. These are TIERS rather than one list, because the answer genuinely
 * differs by question - reading a colleague's rating is not the same act as
 * approving their salary revision - and a tier can be named, whereas a fifth
 * array cannot.
 *
 * ── WHY RESOLUTION GOES THROUGH RoleKey, AND THIS IS THE IMPORTANT PART ─────
 *
 * competencySubject() read `p.role_key` with a raw join, deliberately, so that
 * a blank role_key could not be rescued by a display name. Measured on the two
 * live databases, that is not a hardening - it is an outage:
 *
 *   live   20 profiles pass `profile:admin,hr` and FAIL a raw role_key check
 *          including tenant 7's HR (59 users), tenant 7's Admin (31),
 *          tenant 3's Admin (31) and tenant 3's HR (12)
 *   app    10 profiles, 5 with users
 *
 * 30 of 42 live profiles have `role_key = NULL`; they resolve only because
 * RoleKey::LEGACY_NAMES maps the lowercased names 'admin' and 'hr'. So the raw
 * read silently refuses the legitimate administrator of nine organisations,
 * with the message "You may only access your own competency profile."
 *
 * THE GUARD WAS NOT TOO PERMISSIVE THERE. IT WAS TOO RESTRICTIVE, AND SILENTLY.
 *
 * Every check here therefore resolves through RoleKey, exactly as the route
 * middleware does, so a gate and a row guard can never disagree about what a
 * caller is. The day role_key is backfilled everywhere, this changes nothing.
 */
final class SubjectAuthority
{
    /**
     * May read and write another person's HR record.
     *
     * The competency reference list, unchanged: administrators and HR maintain
     * these records, and executive/auditor exist to read the organisation.
     */
    public const HR_ELEVATED = [
        'administrator', 'hr_manager', 'hr_executive', 'executive', 'auditor',
    ];

    /**
     * HR_ELEVATED plus the two line-management roles, TENANT-WIDE.
     *
     * Wider than HR_ELEVATED on purpose, and the reason is recorded because it
     * is a decision rather than an oversight: a reporting manager rates their
     * reports and a department head owns their department's reviews, and
     * neither "my team" nor "my department" can be enforced today -
     * tbluser.reporting_manager_id is populated on 8 of 2345 rows on the
     * application database and 0 of 299 on live.
     *
     * So this grants more than the words "my team" mean, and far less than the
     * nothing-at-all that was being enforced before. It narrows to real team
     * scope the day reporting lines are filled in, and nothing that uses this
     * tier needs to change when that happens.
     */
    public const PEOPLE_MANAGERS = [
        'administrator', 'hr_manager', 'hr_executive', 'executive', 'auditor',
        'reporting_manager', 'department_head',
    ];

    /** Route-argument vocabulary for the `subject:` middleware. */
    public const TIERS = [
        'hr_elevated'     => self::HR_ELEVATED,
        'people_managers' => self::PEOPLE_MANAGERS,
    ];

    /* ── The verdict, so the ladder is written once ────────────────────── */

    public const OK        = 'ok';
    public const NOT_FOUND = 'not_found';   // -> 404
    public const FORBIDDEN = 'forbidden';   // -> 403

    /**
     * May $callerId act on $subjectId, and if not, which refusal?
     *
     * The body of competencySubject() with the HTTP responses removed, so
     * performance, offboarding and competency share one ordered ladder instead
     * of three copies that drift. Each caller maps the verdict onto its own
     * module's response envelope, which is the only part that legitimately
     * differs between them.
     *
     * THE ORDER CARRIES THE MEANING:
     *
     *   NOT_FOUND comes before FORBIDDEN, always. A 403 on a cross-tenant id
     *   confirms that id exists, so an elevated caller could enumerate another
     *   organisation one request at a time. 404 tells them nothing.
     *
     *   The self-check is settled before any role lookup, so a person acting on
     *   their own record costs one query and can never be refused by a role
     *   list - which is what makes a self-service screen safe to build on top
     *   of the same endpoint HR uses.
     */
    public static function verdict(int $callerId, int $subjectId, $tenantId, array $tier): string
    {
        if ($subjectId <= 0 || $callerId <= 0) {
            return self::NOT_FOUND;
        }

        $inTenant = DB::table('tbluser')
            ->where('id', $subjectId)
            ->where('sub_institute_id', $tenantId)
            ->exists();

        if (!$inTenant) {
            return self::NOT_FOUND;
        }

        if ($subjectId === $callerId) {
            return self::OK;
        }

        return self::userSatisfies($callerId, $tier) ? self::OK : self::FORBIDDEN;
    }

    /** The tier a route named, or null when the name is not one we define. */
    public static function tier(string $name): ?array
    {
        return self::TIERS[strtolower(trim($name))] ?? null;
    }

    /**
     * Does this user hold one of the roles in $tier?
     *
     * Resolved through RoleKey, so LEGACY_NAMES applies and the answer matches
     * what the route middleware would have said. See the class docblock for why
     * that is not optional.
     */
    public static function userSatisfies(?int $userId, array $tier): bool
    {
        if (!$userId || $userId <= 0) {
            return false;
        }

        return self::roleSatisfies(RoleKey::forUserId($userId), $tier);
    }

    /**
     * The same question when the role_key is already known.
     *
     * Null grants nothing, ever - an unresolvable profile is not a licence.
     */
    public static function roleSatisfies(?string $roleKey, array $tier): bool
    {
        $roleKey = trim((string) $roleKey);

        return $roleKey !== '' && in_array($roleKey, $tier, true);
    }
}
