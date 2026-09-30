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
 * ALL FIVE NOW LIVE HERE. Each moved verbatim, so the consolidation is a
 * refactor and not a re-opened decision:
 *
 *   COMPETENCY_ELEVATED (5)  -> HR_ELEVATED
 *   LEAVE_ELEVATED (3)       -> RECORD_OWNERS, provably identical to
 *   ProfileVisibility (3)    -> RECORD_OWNERS, the same three strings
 *   TaskPermissionMiddleware -> TASK_PRIVILEGED (6, the only tier without auditor)
 *   CapabilityProgress (4)   -> deleted; it was the only one with
 *                               reporting_manager and it now uses PEOPLE_MANAGERS
 *
 * The property worth keeping: PEOPLE_MANAGERS is DERIVED from HR_ELEVATED's
 * membership rather than restated, so the two cannot drift apart. Any new tier
 * should be built the same way.
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
     * Maintains the record itself, as opposed to reading it.
     *
     * Narrower than HR_ELEVATED: it drops `executive` and `auditor`, which exist
     * to read the organisation and change nothing. Was two identical private
     * copies - ResolvesLeaveContext::LEAVE_ELEVATED and an inline array in
     * ProfileVisibility - so merging them is provable rather than a judgement:
     * the two were already the same three strings.
     */
    public const RECORD_OWNERS = [
        'administrator', 'hr_manager', 'hr_executive',
    ];

    /**
     * May WRITE another person's performance or development record.
     *
     * RECORD_OWNERS plus the two line-management roles, TENANT-WIDE.
     *
     * ── WHY THIS IS BUILT ON RECORD_OWNERS AND NOT ON HR_ELEVATED ───────────
     *
     * It was HR_ELEVATED + managers, which meant `auditor` and `executive`
     * could write. Caught by testing rather than by reading: an auditor token
     * set manager_rating on a colleague's review and got 200, and passed the
     * compensation-decision gate.
     *
     * That contradicted this file's own stated reason for including them -
     * "executive and auditor exist to read the organisation and change
     * nothing". A tier whose justification argues against its own membership is
     * a tier that will be used wrongly.
     *
     * So: HR_ELEVATED is what may READ somebody else's record. RECORD_OWNERS
     * and PEOPLE_MANAGERS are what may WRITE one. Reads are deliberately wider
     * than writes, which is the whole point of having an auditor.
     *
     * ── WHY MANAGERS ARE TENANT-WIDE ────────────────────────────────────────
     *
     * A reporting manager rates their reports and a department head owns their
     * department's reviews, and neither "my team" nor "my department" can be
     * enforced today: tbluser.reporting_manager_id is populated on 8 of 2345
     * rows on the application database and 0 of 299 on live. So this grants
     * more than "my team" means, and far less than the nothing-at-all that was
     * enforced before. It narrows to real team scope the day reporting lines
     * exist, with no call-site changes.
     */
    public const PEOPLE_MANAGERS = [
        // SPREAD, not restated: the tier cannot fall out of step with the one
        // it is built from.
        ...self::RECORD_OWNERS,
        'reporting_manager',
        'department_head',
    ];


    /**
     * Task Management's elevated set, moved verbatim.
     *
     * Differs from PEOPLE_MANAGERS by dropping `auditor` - the only tier here
     * that does - because an auditor reads the organisation and has no business
     * in somebody else's task queue. Kept as its own tier rather than folded
     * into PEOPLE_MANAGERS precisely so that difference stays deliberate
     * instead of being lost in a merge.
     */
    public const TASK_PRIVILEGED = [
        'administrator', 'hr_manager', 'hr_executive', 'executive',
        'reporting_manager', 'department_head',
    ];

    /** Route-argument vocabulary for the `subject:` middleware. */
    public const TIERS = [
        'hr_elevated'     => self::HR_ELEVATED,
        'people_managers' => self::PEOPLE_MANAGERS,
        'record_owners'   => self::RECORD_OWNERS,
    ];

    /**
     * Tiers that authorise a WRITE to somebody else's record.
     *
     * Named so the distinction is checkable rather than remembered: HR_ELEVATED
     * is a read tier and must never gate a write.
     */
    public const WRITE_TIERS = ['record_owners', 'people_managers'];

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
