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
