<?php

namespace App\Http\Controllers\Api\Competency;

use App\Http\Controllers\Api\Competency\Concerns\ResolvesCompetencyContext;
use App\Http\Controllers\Concerns\ResolvesEmployeeJobRole;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * AN EMPLOYEE RATING THEIR OWN CAPABILITY.
 *
 * ── WHY THIS EXISTS ─────────────────────────────────────────────────────────
 *
 * `competency_kasba_rating` already carries `source = 'self'` rows — 20 on dev,
 * 15 on live — so the data model always expected employees to rate themselves.
 * Nothing could write one. Every route on KasbaRatingController is
 * `profile:admin,hr`, so an employee attempting to self-rate got a 403, and
 * MyCapabilityController is explicitly read-only ("this controller writes
 * nothing"). Self-rating was not broken; it was never connected.
 *
 * ── THE SUBJECT IS THE TOKEN OWNER, AND THERE IS NO OTHER WAY TO NAME ONE ───
 *
 * KasbaRatingController::store() requires `user_id`, because HR rating somebody
 * else must name them. THIS ONE ACCEPTS NO SUBJECT AT ALL. That is the whole
 * security design, and it is structural rather than a check:
 *
 *     AN ENDPOINT THAT ACCEPTS NO SUBJECT CANNOT BE MADE TO RATE SOMEBODY ELSE.
 *     There is no parameter to tamper with and no authorisation rule that can be
 *     got wrong later, because no decision is being made.
 *
 * Same argument MyCapabilityController makes for reading. A `user_id` in the
 * request is IGNORED, not refused — a stale value in localStorage is common and
 * must not lock a legitimate employee out, and ignoring it is equally safe
 * because it never reaches a query.
 *
 * ── A SELF-RATING IS AN OPINION, NOT AN ASSESSMENT ──────────────────────────
 *
 * It is written with `source = 'self'` and NEVER overwrites a rating somebody
 * else gave. HR's verdict and the employee's own view are different facts about
 * the same item, and collapsing them would silently destroy the assessment
 * record — the employee would appear to have been assessed at whatever they
 * claimed. Where both exist, the reader is shown both.
 */
class MyRatingController extends Controller
{
    use ResolvesCompetencyContext;
    use ResolvesEmployeeJobRole;

    /** The rating scale, matching KasbaRatingController so the two cannot drift. */
    private const MIN = 1;

    private const MAX = 5;

    /**
     * POST /competency/my-rating — rate one of MY OWN capability items.
     */
    public function store(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            // NO user_id. Deliberately absent — see the class docblock.
            'kasba_item_id' => 'required|integer',
            'rating'        => 'required|integer|min:' . self::MIN . '|max:' . self::MAX,
            'note'          => 'nullable|string|max:2000',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $sid = (int) $context['sub_institute_id'];
        $me  = (int) $context['user_id'];
        $itemId = $request->integer('kasba_item_id');

        if ($me <= 0) {
            return response()->json(['status' => 0, 'message' => 'Unable to identify your record.'], 401);
        }

        /*
         * THE ITEM MUST BE ONE THIS EMPLOYEE'S OWN ROLE ACTUALLY REQUIRES.
         *
         * A tenant check alone would let somebody rate themselves against every
         * competency in the organisation, including ones belonging to roles they
         * do not hold — inflating a capability record against work they were
         * never asked to do. The join below is the same one MyCapabilityController
         * reads through, so what can be rated is exactly what is displayed.
         */
        $user = DB::table('tbluser')
            ->where('id', $me)->where('sub_institute_id', $sid)
            ->first(['id', 'jobtitle_id', 'allocated_standards']);

        if (!$user) {
            return response()->json(['status' => 0, 'message' => 'Unable to identify your record.'], 401);
        }

        /*
         * EVERY ROLE THIS EMPLOYEE'S RECORD ASSOCIATES THEM WITH, not just the
         * first one resolveJobRoleId() happens to prefer.
         *
         * tbluser holds the role in TWO columns and they can disagree — measured:
         * user 63 has jobtitle_id = 4331 and allocated_standards = 4342, and six
         * employees are in that state. resolveJobRoleId() returns jobtitle_id
         * first, so a single-role check refused an item on 4342 with a 404 even
         * though that employee already had a self-rating on it.
         *
         * The ambiguity is in the source data, and refusing to let somebody rate
         * a competency their own record associates them with is the wrong way to
         * resolve it. Accepting any candidate role is still bounded — it is
         * their record naming those roles, not a request parameter — so this
         * cannot reach an arbitrary competency.
         */
        $candidateRoles = $this->candidateJobRoleIds($user);

        if ($candidateRoles === []) {
            return response()->json([
                'status'  => 0,
                'message' => 'You do not have a job role yet, so there are no competencies to rate. '
                           . 'Ask your HR team to set one.',
            ], 409);
        }

        $rateable = DB::table('jobrole_competency_map as m')
            ->join('competency_kasba_item as k', 'k.competency_id', '=', 'm.competency_id')
            ->where('m.sub_institute_id', $sid)
            ->whereIn('m.jobrole_id', $candidateRoles)
            ->where('k.sub_institute_id', $sid)
            ->where('k.id', $itemId)
            ->exists();

        if (!$rateable) {
            return response()->json([
                'status'  => 0,
                'message' => 'That capability item is not part of your job role, so you cannot rate yourself on it.',
            ], 404);
        }

        /*
         * NEVER OVERWRITE SOMEBODY ELSE'S ASSESSMENT.
         *
         * updateOrInsert on (tenant, user, item) — which is what the HR endpoint
         * does — would let a self-rating replace an assessor's verdict in place.
         * A row whose assessor_id is not the employee themselves is somebody
         * else's judgement and is left exactly as it is.
         */
        $existing = DB::table('competency_kasba_rating')
            ->where('sub_institute_id', $sid)
            ->where('user_id', $me)
            ->where('kasba_item_id', $itemId)
            ->first(['id', 'assessor_id', 'source']);

        if ($existing && (int) $existing->assessor_id !== $me && $existing->source !== 'self') {
            return response()->json([
                'status'  => 0,
                'message' => 'This item has already been rated by an assessor, so your own rating '
                           . 'would replace theirs. Ask them to review it instead.',
                'assessed_by_other' => true,
            ], 409);
        }

        $values = [
            'rating'      => $request->integer('rating'),
            'assessor_id' => $me,
            'source'      => 'self',
            'note'        => $request->input('note'),
            'rated_at'    => now(),
            'updated_at'  => now(),
        ];

        if ($existing) {
            DB::table('competency_kasba_rating')->where('id', $existing->id)->update($values);
        } else {
            DB::table('competency_kasba_rating')->insert($values + [
                'sub_institute_id' => $sid,
                'user_id'          => $me,
                'kasba_item_id'    => $itemId,
                'created_at'       => now(),
            ]);
        }

        return response()->json([
            'status'  => 1,
            'message' => 'Your rating was saved. It is recorded as your own view, not an assessment — '
                       . 'an assessor can still review it.',
            'data'    => [
                'kasba_item_id' => $itemId,
                'rating'        => $request->integer('rating'),
                'source'        => 'self',
            ],
            'scope' => 'self',
        ], $existing ? 200 : 201);
    }

    /**
     * DELETE /competency/my-rating — withdraw MY OWN rating.
     *
     * Only ever removes a row this employee wrote. An assessor's rating is not
     * theirs to delete, and the same check that protects it on write protects it
     * here.
     */
    public function destroy(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            'kasba_item_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $sid = (int) $context['sub_institute_id'];
        $me  = (int) $context['user_id'];

        $removed = DB::table('competency_kasba_rating')
            ->where('sub_institute_id', $sid)
            ->where('user_id', $me)
            ->where('kasba_item_id', $request->integer('kasba_item_id'))
            ->where('source', 'self')
            ->where('assessor_id', $me)
            ->delete();

        return response()->json([
            'status'  => 1,
            'message' => $removed
                ? 'Your rating was withdrawn.'
                : 'There was no self-rating of yours to withdraw.',
            'scope'   => 'self',
        ]);
    }
}
