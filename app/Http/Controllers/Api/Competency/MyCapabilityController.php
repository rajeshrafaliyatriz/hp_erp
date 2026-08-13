<?php

namespace App\Http\Controllers\Api\Competency;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Competency\Concerns\ResolvesCompetencyContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * THE EMPLOYEE'S OWN CAPABILITY — framework, taxonomy and KASBA items, scoped to
 * the caller's own job role and to nobody else's.
 *
 * WHY THIS IS A SEPARATE CONTROLLER FROM KasbaRatingController, WHICH RUNS THE
 * SAME JOIN:
 *
 *     KasbaRatingController::index() takes `user_id` as a parameter, because HR
 *     rating someone else must name them. THIS ONE TAKES NO SUBJECT AT ALL.
 *
 * That is the entire security design and it is structural, not a check:
 *
 *     AN ENDPOINT THAT ACCEPTS NO user_id CANNOT BE MADE TO RETURN SOMEBODY
 *     ELSE'S DATA. There is no parameter to tamper with, no id to guess, and no
 *     authorisation rule that can be got wrong later, because there is no
 *     decision being made.
 *
 * IT IS DELIBERATELY NOT ENFORCED BY `data_scope`. That column exists on
 * tbluserprofilemaster with values organization/self and is READ BY NOTHING -
 * measured, zero occurrences across app/. A property that depends on a column
 * nobody reads is not a property. If `data_scope` is ever enforced, this endpoint
 * keeps working unchanged, because it never relied on it.
 *
 * A user_id IN THE REQUEST IS IGNORED, NOT REFUSED. A stale value in a caller's
 * localStorage is common and must not lock a legitimate employee out; ignoring it
 * is equally safe because it never reaches a query. Same reasoning as
 * ResolvesApiIdentity applies to a request tenant.
 *
 * R20 - THE CHAIN THIS RELIES ON:
 *   route     routes/api.php, api.token guarded - any authenticated employee
 *   identity  competencyContext() -> resolveApiIdentity(), token only
 *   subject   THE CALLER. Never a request field.
 *   read-only this controller writes nothing.
 */
class MyCapabilityController extends Controller
{
    use ResolvesCompetencyContext;

    /**
     * GET /competency/my-capability
     *
     * Everything an employee is entitled to see about their own capability:
     * their job role, the competencies it requires, the KASBA items beneath
     * them, and their own ratings where any exist.
     */
    public function index(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $sid = (int) $context['sub_institute_id'];

        // THE SUBJECT IS THE CALLER. Taken from the resolved identity, which is
        // derived from the token and cannot be influenced by the request.
        $me = (int) $context['user_id'];

        $user = DB::table('tbluser')
            ->where('id', $me)->where('sub_institute_id', $sid)
            ->first(['id', 'jobtitle_id']);

        if (!$user) {
            // The token resolved to a user who is not in the token's own tenant.
            // That should be impossible; refusing is cheaper than reasoning about
            // how it happened.
            return response()->json(['status' => 0, 'message' => 'Unable to identify your record.'], 401);
        }

        $jobroleId = $user->jobtitle_id ? (int) $user->jobtitle_id : null;

        if (!$jobroleId) {
            return $this->payload($me, null, null, collect(), true,
                'You do not have a job role yet, so no competencies are required of you. Ask your HR team to set one.');
        }

        $jobrole = DB::table('s_user_jobrole')
            ->where('id', $jobroleId)->where('sub_institute_id', $sid)
            ->first(['id', 'jobrole', 'department']);

        $items = DB::table('jobrole_competency_map as m')
            ->join('competency_kasba_item as k', 'k.competency_id', '=', 'm.competency_id')
            ->leftJoin('competency as c', 'c.id', '=', 'm.competency_id')
            // The caller's OWN ratings only. Bound by $me, which came from the
            // token - not by anything the request could name.
            ->leftJoin('competency_kasba_rating as r', function ($j) use ($me) {
                $j->on('r.kasba_item_id', '=', 'k.id')->where('r.user_id', '=', $me);
            })
            ->where('m.sub_institute_id', $sid)
            ->where('k.sub_institute_id', $sid)
            ->where('m.jobrole_id', $jobroleId)
            ->orderBy('c.name')->orderBy('k.kasba_type')->orderBy('k.item_label')
            ->get([
                'k.id as kasba_item_id',
                'k.kasba_type',
                'k.item_label',
                'k.weight',
                'm.competency_id',
                'c.name as competency_name',
                'c.description as competency_description',
                'm.required_proficiency',
                'm.is_mandatory',
                'r.rating',
                'r.rated_at',
            ]);

        return $this->payload($me, $jobroleId, $jobrole, $items, $items->isEmpty(),
            $items->isEmpty()
                ? 'Your job role does not have any competencies mapped to it yet. Ask your HR team to add them.'
                : null);
    }

    /**
     * The response shape, built once so the three exits cannot drift apart.
     *
     * GROUPED BY COMPETENCY, THEN BY KASBA TYPE - because that is how the model
     * is authored and how a person reads it. The five dimensions are derived from
     * the data present, NOT from a hardcoded list: a tenant that has authored only
     * knowledge and skill items should see two groups, not five with three empty.
     */
    private function payload(int $me, ?int $jobroleId, $jobrole, $items, bool $empty, ?string $reason)
    {
        $byCompetency = $items->groupBy('competency_id')->map(function ($group) {
            $first = $group->first();

            return [
                'competency_id'          => $first->competency_id ? (int) $first->competency_id : null,
                'competency_name'        => $first->competency_name,
                'competency_description' => $first->competency_description,
                'required_proficiency'   => $first->required_proficiency,
                'is_mandatory'           => (bool) $first->is_mandatory,
                'items_total'            => $group->count(),
                'items_rated'            => $group->whereNotNull('rating')->count(),
                // Types present in THIS competency, not the full vocabulary.
                'by_type'                => $group->groupBy('kasba_type')->map(fn ($t) => $t->values())->all(),
            ];
        })->values();

        $rated = $items->whereNotNull('rating')->count();

        return response()->json([
            'status' => 1,
            'data'   => [
                'user_id'    => $me,
                'jobrole_id' => $jobroleId,
                'jobrole'    => $jobrole->jobrole ?? null,
                'department' => $jobrole->department ?? null,
                'competencies' => $byCompetency,
                'items_total'  => $items->count(),
                'items_rated'  => $rated,
                // UNRATED IS NOT ZERO. Reported as a count of what is outstanding,
                // never as a score of nothing.
                'items_unrated' => $items->count() - $rated,
            ],
            'empty_is_expected' => $empty,
            'empty_reason'      => $reason,
            'scope'             => 'self',
        ]);
    }
}
