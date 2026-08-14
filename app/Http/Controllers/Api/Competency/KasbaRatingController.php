<?php

namespace App\Http\Controllers\Api\Competency;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Competency\Concerns\ResolvesCompetencyContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * RATINGS ON KASBA ITEMS - the last link before the gap, and it had no writer.
 *
 * `competency_kasba_rating` held 160 seeded rows. NO controller, route or service
 * wrote it: both existing rating routes are GET, and `ProficiencyService` only
 * LEFT JOINs it. So the gap engine read the right table, on the right model, and
 * nothing in the product could put a rating in it.
 *
 * THE ROUTES ARE NEW, NOT REPURPOSED. The two GET routes on
 * `/competency/assessment-cycles/...` keep meaning what they meant.
 *
 * KEYED ON kasba_item_id - the same key `ProficiencyService::rollUp` joins on.
 * Not on skill_id, and nothing here touches `s_skill_matrix`, which is a separate
 * rating surface for dashboards and is not part of this chain.
 *
 * UNMEASURED STAYS UNMEASURED. There is no way to write a rating of "none" here.
 * Absence is expressed by the ROW NOT EXISTING, which is what `rollUp` reads as
 * `measured = false` and excludes from both the level and the coverage numerator.
 * A rating of 0 is not accepted (`min:1`) precisely so that "unrated" and "rated
 * badly" can never collide in the same column.
 *
 * R20 - THE CHAIN THIS RELIES ON:
 *   route      routes/api.php
 *   middleware `profile:admin,hr` - RequireProfile, exact role_key since G-AUTH-02
 *   tenant     competencyContext() -> resolveApiIdentity(), never a request field
 *   actor      the same; assessor_id is never taken from the body (G-SEC-12)
 */
class KasbaRatingController extends Controller
{
    use ResolvesCompetencyContext;

    /** 1..5. Zero is deliberately not a rating - see the class docblock. */
    private const MIN = 1;
    private const MAX = 5;

    /**
     * Rate one KASBA item for one employee.
     *
     * Idempotent on (tenant, user, item): rating the same item again UPDATES the
     * row rather than adding a second one, so a correction does not double-count
     * in the roll-up.
     */
    /**
     * GET /competency/kasba-rating?user_id=N — WHAT THIS PERSON CAN BE RATED ON.
     *
     * THE MISSING HALF. store() has existed and been proved since the rating work,
     * and NOTHING IN THE FRONTEND CALLED IT - measured: zero callers across
     * services/, hooks/ and components/. The reason was not the write; it was that
     * no read said WHICH ITEMS a given person is even supposed to be rated on. A
     * write endpoint with no candidate list is a form with no fields.
     *
     * THE CHAIN THIS WALKS, and it is the same chain the gap reads:
     *   tbluser.jobtitle_id -> jobrole_competency_map.jobrole_id
     *                       -> competency_id
     *                       -> competency_kasba_item (the ratable items)
     *   LEFT JOIN competency_kasba_rating so an UNRATED item comes back as
     *   rating = null rather than being absent. Absent and zero are different
     *   answers and the caller must be able to tell them apart.
     *
     * EVERY FIGURE IS TENANT-SCOPED FROM THE TOKEN. `user_id` names the SUBJECT,
     * never the identity - the caller is still whoever the token says.
     */
    public function index(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $sid     = (int) $context['sub_institute_id'];
        $subject = $request->integer('user_id');

        // Same tenant check the write makes. A read that leaks is still a leak.
        $user = DB::table('tbluser')
            ->where('id', $subject)->where('sub_institute_id', $sid)
            ->first(['id', 'jobtitle_id']);

        if (!$user) {
            return response()->json(['status' => 0, 'message' => 'Employee not found.'], 404);
        }

        // A person with no job role has no requirements, so nothing to rate. That
        // is a NORMAL state for a new employee, not a failure - said in the payload
        // so the screen can explain it rather than showing a blank list.
        if (!$user->jobtitle_id) {
            return response()->json([
                'status' => 1,
                'data'   => ['user_id' => $subject, 'jobrole_id' => null, 'items' => []],
                'empty_is_expected' => true,
                'empty_reason'      => 'This employee has no job role, so no competencies are required of them yet.',
            ]);
        }

        $items = DB::table('jobrole_competency_map as m')
            ->join('competency_kasba_item as k', 'k.competency_id', '=', 'm.competency_id')
            ->leftJoin('competency as c', 'c.id', '=', 'm.competency_id')
            ->leftJoin('competency_kasba_rating as r', function ($j) use ($subject) {
                $j->on('r.kasba_item_id', '=', 'k.id')->where('r.user_id', '=', $subject);
            })
            ->where('m.sub_institute_id', $sid)
            ->where('k.sub_institute_id', $sid)
            ->where('m.jobrole_id', $user->jobtitle_id)
            ->orderBy('c.name')->orderBy('k.kasba_type')->orderBy('k.item_label')
            ->get([
                'k.id as kasba_item_id',
                'k.kasba_type',
                'k.item_label',
                'k.weight',
                'm.competency_id',
                'c.name as competency_name',
                'm.required_proficiency',
                'm.is_mandatory',
                'r.rating',
                'r.note',
                'r.rated_at',
            ]);

        return response()->json([
            'status' => 1,
            'data'   => [
                'user_id'    => $subject,
                'jobrole_id' => (int) $user->jobtitle_id,
                'items'      => $items,
                'rated'      => $items->whereNotNull('rating')->count(),
                'total'      => $items->count(),
            ],
            // A job role with no competencies mapped is the other normal empty.
            'empty_is_expected' => $items->isEmpty(),
            'empty_reason'      => $items->isEmpty()
                ? 'This job role has no competencies mapped to it yet. Add them in Role Requirements.'
                : null,
            'rating_range' => ['min' => self::MIN, 'max' => self::MAX],
        ]);
    }

    public function store(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            'kasba_item_id' => 'required|integer',
            'user_id'       => 'required|integer',
            'rating'        => 'required|integer|min:' . self::MIN . '|max:' . self::MAX,
            'note'          => 'nullable|string|max:2000',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $sid    = (int) $context['sub_institute_id'];
        $actor  = (int) $context['user_id'];
        $itemId = $request->integer('kasba_item_id');
        $subject = $request->integer('user_id');

        // The item must be the caller's own tenant's. Without this a rating could
        // be hung off another tenant's competency item - the same hole that made
        // item_id dangle before it was validated per dimension.
        $itemOk = DB::table('competency_kasba_item')
            ->where('id', $itemId)->where('sub_institute_id', $sid)->exists();
        if (!$itemOk) {
            return response()->json(['status' => 0, 'message' => 'Competency item not found.'], 404);
        }

        // The subject must be the caller's own tenant's employee, for the same
        // reason. A rating names a person; naming someone else's is a leak in
        // both directions.
        $userOk = DB::table('tbluser')
            ->where('id', $subject)->where('sub_institute_id', $sid)->exists();
        if (!$userOk) {
            return response()->json(['status' => 0, 'message' => 'Employee not found.'], 404);
        }

        DB::table('competency_kasba_rating')->updateOrInsert(
            ['sub_institute_id' => $sid, 'user_id' => $subject, 'kasba_item_id' => $itemId],
            [
                'rating'      => $request->integer('rating'),
                'assessor_id' => $actor,
                'source'      => 'manual',
                'note'        => $request->input('note'),
                'rated_at'    => now(),
                'updated_at'  => now(),
            ]
        );

        return response()->json([
            'status'  => 1,
            'message' => 'Rating saved.',
            'data'    => ['kasba_item_id' => $itemId, 'user_id' => $subject],
        ], 201);
    }

    /**
     * Remove a rating - which returns the item to UNMEASURED, not to zero.
     *
     * This is the only way to express "we no longer have a view on this", and it
     * is why deletion exists at all: overwriting with a low rating would say
     * something false.
     */
    public function destroy(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            'kasba_item_id' => 'required|integer',
            'user_id'       => 'required|integer',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $deleted = DB::table('competency_kasba_rating')
            ->where('sub_institute_id', (int) $context['sub_institute_id'])
            ->where('user_id', $request->integer('user_id'))
            ->where('kasba_item_id', $request->integer('kasba_item_id'))
            ->delete();

        return response()->json([
            'status'  => 1,
            'message' => $deleted ? 'Rating removed; the item is unmeasured again.' : 'No rating to remove.',
            'data'    => ['removed' => $deleted],
        ]);
    }
}
