<?php

namespace App\Http\Controllers\Api\Competency;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * X-08(a) — WHAT AN IMPORT WOULD GIVE YOU, BEFORE YOU RUN IT.
 *
 * G-SEED-01's fifth requirement, and the part of X-08 that stands alone: the
 * import itself needs content decisions nobody has taken, but the NUMBER a
 * customer should see before deciding does not.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE MEASUREMENT THIS EXISTS TO PUBLISH.
 *
 *   The tenant-3 seed defined 27 KASBA items across 10 clinical competencies.
 *   **EXACTLY 1 matched a canonical skill.** 26 stayed as HOLDING labels - not
 *   because the seed was careless, but because FOUR OF THE FIVE KASBA DIMENSIONS
 *   ARE NOT SKILLS. "Hand hygiene compliance" is a behaviour; "Empathy in
 *   distressing situations" is an attitude. A SKILL library was never going to
 *   match them.
 *
 *   **That is what a real customer's first import will look like**, and they
 *   should learn it from this endpoint rather than from the result.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * IT REPORTS. IT IMPORTS NOTHING. Same rule as X-13: the system does not
 * manufacture a claim nobody made, and "these 3,347 roles are yours now" is a
 * claim. The customer decides.
 */
class SeedLibraryPreviewController extends Controller
{
    use ResolvesApiIdentity;

    public function index(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }

        $tenant = $identity['sub_institute_id'];

        // ── what the GLOBAL library offers ──────────────────────────────────
        $globalRoles  = DB::table('s_jobrole')->count()   /* no deleted_at on the global library */;
        $globalSkills = DB::table('master_skills')->count();

        // ── what this tenant already holds ──────────────────────────────────
        $ownRoles  = DB::table('s_user_jobrole')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->count();
        // whereNull('deleted_at') to match the job-role line above, which had it.
        // Without it this counted soft-deleted skills as content the tenant
        // holds - tenant 3 read as 170 when it has 168 - so the preview slightly
        // understated what an import would add.
        $ownSkills = DB::table('s_users_skills')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->count();

        // ── OVERLAP, by name, because that is how an import would match ─────
        // Reported as a candidate count and labelled as such: a name match is a
        // CANDIDATE for reconciliation, never a confirmed duplicate (R24 - a
        // match is not a relationship).
        $roleOverlap = DB::table('s_user_jobrole as t')
            ->join('s_jobrole as g', 'g.jobrole', '=', 't.jobrole')
            ->where('t.sub_institute_id', $tenant)->whereNull('t.deleted_at')
            ->distinct()->count('t.jobrole');

        $skillOverlap = DB::table('s_users_skills as t')
            ->join('master_skills as g', 'g.title', '=', 't.title')
            ->where('t.sub_institute_id', $tenant)->whereNull('t.deleted_at')
            ->distinct()->count('t.title');

        // ── THE VOCABULARY-DISTANCE NUMBER (G-SEED-01 R5) ───────────────────
        // How much of this tenant's KASBA vocabulary the skill library can
        // actually satisfy. This is the figure that predicts the import result.
        $items = DB::table('competency_kasba_item')->where('sub_institute_id', $tenant)->count();
        $target = DB::table('competency_kasba_item')->where('sub_institute_id', $tenant)
            ->whereNotNull('item_id')->count();

        $byDimension = DB::table('competency_kasba_item')
            ->where('sub_institute_id', $tenant)
            ->select('kasba_type', DB::raw('count(*) total'), DB::raw('sum(item_id is not null) resolved'))
            ->groupBy('kasba_type')->get()
            ->map(fn ($r) => [
                'dimension' => $r->kasba_type,
                'items'     => (int) $r->total,
                'resolved'  => (int) $r->resolved,
            ])->all();

        return response()->json(['status' => 1, 'data' => [
            'global'  => ['job_roles' => $globalRoles, 'skills' => $globalSkills],
            'tenant'  => ['job_roles' => $ownRoles, 'skills' => $ownSkills],
            // "candidates", not "duplicates" - deliberately.
            'name_match_candidates' => ['job_roles' => $roleOverlap, 'skills' => $skillOverlap],
            'vocabulary' => [
                'kasba_items'   => $items,
                'resolved'      => $target,
                'held_as_label' => $items - $target,
                'percent_resolved' => $items > 0 ? round(100 * $target / $items, 1) : null,
                'by_dimension'  => $byDimension,
            ],
            'expectation' => $items === 0
                ? 'No competencies defined yet, so there is nothing to measure the library against.'
                : 'FOUR OF THE FIVE KASBA DIMENSIONS ARE NOT SKILLS. A skill library cannot '
                  . 'resolve knowledge, attitude, behaviour or ability items, so most items will '
                  . 'arrive as labels. That is the normal first state, not an import failure.',
        ]]);
    }
}
