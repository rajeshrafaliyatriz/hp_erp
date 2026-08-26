<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * The one place that turns a job role NAME into a job role ID.
 *
 * ── WHY THIS EXISTS ─────────────────────────────────────────────────────────
 *
 * There were FIVE near-copies of this before it: FrameworkController,
 * LibraryController, CareerPathController::roleByName(),
 * EmployeeCompetencyProfileController and CareerJourneyController. Two of them
 * took `->first()` with no ambiguity guard at all - so on a name belonging to
 * roles in two departments they silently picked whichever the database returned
 * first, and looked authoritative doing it.
 *
 * Extracted from FrameworkController::resolveJobroleId(), which was the only
 * one that also verified tenant ownership.
 *
 * ── THE PRECEDENCE ──────────────────────────────────────────────────────────
 *
 *   1. an explicit `jobrole_id`, IF the tenant owns it
 *   2. otherwise the name, IF it resolves to exactly one live role
 *   3. otherwise NULL
 *
 * ── IT REFUSES TO GUESS, AND THAT IS THE POINT ──────────────────────────────
 *
 * An ambiguous name yields NULL, not the first match. 90 role names in one live
 * tenant belong to roles in more than one department, and 7,143 rows carry such
 * a name. A coin-toss link that looks authoritative is worse than a visibly
 * missing one - the earlier name-matched provenance backfill resolved 5,470
 * rows by guessing and none of them can now be trusted.
 *
 * NULL here is not a failure. It means "this row keeps working by name until a
 * person says which role it meant", and `jobroles:backfill-ids` lists every one.
 *
 * ── THE OWNERSHIP CHECK IS NOT OPTIONAL ─────────────────────────────────────
 *
 * Accepting a caller-supplied `jobrole_id` without it is how a tenant-1 request
 * could attach its data to tenant 2's role. The foreign keys on these columns
 * enforce EXISTENCE only, never tenancy, so this check is the guard that
 * actually keeps organisations apart.
 */
trait ResolvesJobRoleId
{
    /**
     * @param int|string|null $explicitId a caller-supplied id, if any
     * @param string|null     $name       the role name to fall back to
     */
    protected function resolveJobRoleIdFrom($explicitId, ?string $name, int $subInstituteId): ?int
    {
        if ($explicitId !== null && $explicitId !== '' && (int) $explicitId > 0) {
            $owned = DB::table('s_user_jobrole')
                ->where('id', (int) $explicitId)
                ->where('sub_institute_id', $subInstituteId)
                ->whereNull('deleted_at')
                ->exists();

            return $owned ? (int) $explicitId : null;
        }

        return $this->jobRoleIdByName($name, $subInstituteId);
    }

    /**
     * A name to exactly one id, or NULL.
     *
     * limit(2) is enough to tell "exactly one" from "more than one", and avoids
     * dragging back every namesake just to count them.
     */
    protected function jobRoleIdByName(?string $name, int $subInstituteId): ?int
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }

        $matches = DB::table('s_user_jobrole')
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->whereRaw('LOWER(TRIM(jobrole)) = ?', [mb_strtolower($name)])
            ->limit(2)
            ->pluck('id');

        return $matches->count() === 1 ? (int) $matches->first() : null;
    }

    /**
     * Convenience for the common controller shape: read both from the request.
     *
     * Writers should call this and store the result ALONGSIDE the name, never
     * instead of it - roughly twenty screens still read the name column, and
     * one `whereIn('jobrole', ...)` in CommandCenterService feeds thirteen
     * metrics on its own.
     */
    protected function resolveJobRoleIdFromRequest($request, int $subInstituteId, string $nameKey = 'jobrole'): ?int
    {
        return $this->resolveJobRoleIdFrom(
            $request->input('jobrole_id'),
            $request->input($nameKey),
            $subInstituteId
        );
    }
}
