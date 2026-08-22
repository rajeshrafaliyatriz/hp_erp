<?php

namespace App\Http\Controllers\Api\Competency;

use App\Http\Controllers\Concerns\ResolvesEmployeeJobRole;
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
    use ResolvesEmployeeJobRole;
    use ResolvesCompetencyContext;

    /** 1..5. Zero is deliberately not a rating - see the class docblock. */
    private const MIN = 1;
    private const MAX = 5;

    /**
     * The five KASBA dimensions and the library table each one lives in.
     *
     * Identical to CompetencyDefinitionController::ITEM_TABLES, and it has to
     * stay identical: an item id only means something inside its own
     * dimension's table, so reading it against the wrong one silently resolves
     * to an unrelated row.
     */
    private const ITEM_TABLES = [
        'skill'     => 's_users_skills',
        'knowledge' => 's_user_knowledge',
        'ability'   => 's_user_ability',
        'attitude'  => 's_user_attitude',
        'behaviour' => 's_user_behaviour',
    ];

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
            ->first(['id', 'jobtitle_id', 'allocated_standards']);

        if (!$user) {
            return response()->json(['status' => 0, 'message' => 'Employee not found.'], 404);
        }

        // The employee's job role, resolved through BOTH columns that can hold
        // it. This read jobtitle_id alone and declared "no job role" for anyone
        // whose role lives in allocated_standards - 74 of 98 live employees -
        // so their competencies looked empty when they were simply unreachable.
        $jobroleId = $this->resolveJobRoleId($user);

        // A person with no job role has no requirements, so nothing to rate. That
        // is a NORMAL state for a new employee, not a failure - said in the payload
        // so the screen can explain it rather than showing a blank list.
        if (!$jobroleId) {
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
            ->where('m.jobrole_id', $jobroleId)
            ->orderBy('c.name')->orderBy('k.kasba_type')->orderBy('k.item_label')
            ->get([
                'k.id as kasba_item_id',
                'k.kasba_type',
                // item_id IS SELECTED NOW, and that is the fix. Only item_label
                // came back, and an item that RESOLVED to a library row has
                // item_label NULL by design - so the correctly-resolved items
                // were exactly the ones rendering as blank rows, while the
                // unresolved label-only ones displayed fine. 203 rows on live.
                'k.item_id',
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

        $items = $this->attachTitles($items, $sid);

        return response()->json([
            'status' => 1,
            'data'   => [
                'user_id'    => $subject,
                'jobrole_id' => $jobroleId,
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
     * POST /competency/kasba-rating/by-item - rate a LIBRARY ITEM directly.
     *
     * WHY THIS EXISTS ALONGSIDE store().
     *
     * store() keys on competency_kasba_item.id, so an item could only be rated
     * once somebody had linked it to a competency. On live that link exists for
     * one dimension and effectively no others:
     *
     *     skill      221 rows, 199 with a usable item_id
     *     knowledge   18 rows,   0
     *     ability      9 rows,   0
     *     attitude     8 rows,   0
     *     behaviour   10 rows,   1
     *
     * The 66 unlinked rows carry prose in item_label - "Infection control
     * protocols", "Hand hygiene compliance" - and none of those labels matches
     * a row in the dimension's library table in ANY tenant, so no backfill can
     * repair them. The effect on the product was that four of the Competency
     * Rating tab's five categories could not save at all.
     *
     * So a rating may now name (kasba_type, item_id) directly. WHERE THE ITEM
     * IS ALSO LINKED TO COMPETENCIES, BOTH ARE WRITTEN - the direct row and one
     * competency-linked row per link - so ProficiencyService::rollUp keeps
     * reading exactly what it read before. Nothing regresses for mapped items;
     * unmapped ones simply become ratable.
     *
     * An item belonging to no competency is NOT an error. The rating has a home
     * either way; the response says so, and the UI shows it as a note rather
     * than a failure.
     */
    public function storeByItem(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!\is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            'user_id'    => 'required|integer',
            'kasba_type' => 'required|in:' . implode(',', array_keys(self::ITEM_TABLES)),
            'item_id'    => 'required|integer|min:1',
            'rating'     => 'required|integer|min:' . self::MIN . '|max:' . self::MAX,
            'note'       => 'nullable|string|max:2000',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $sid     = (int) $context['sub_institute_id'];
        $actor   = (int) $context['user_id'];
        $type    = (string) $request->input('kasba_type');
        $itemId  = $request->integer('item_id');
        $subject = $request->integer('user_id');
        $rating  = $request->integer('rating');
        $note    = $request->input('note');

        // The subject must be the caller's own tenant's employee. A rating names
        // a person; naming someone else's is a leak in both directions.
        $userOk = DB::table('tbluser')
            ->where('id', $subject)->where('sub_institute_id', $sid)->exists();
        if (!$userOk) {
            return response()->json(['status' => 0, 'message' => 'Employee not found.'], 404);
        }

        /*
         * The item must exist IN ITS OWN DIMENSION'S TABLE, for this tenant.
         *
         * Checking the id without the dimension is what let kasba_type=behaviour
         * item_id=2645 dangle at a row that never existed. Five id spaces, five
         * tables; the pair is the key, never the id alone.
         */
        $item = DB::table(self::ITEM_TABLES[$type])
            ->where('id', $itemId)
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->first(['id', 'title']);

        if (!$item) {
            return response()->json([
                'status'  => 0,
                'message' => 'That ' . $type . ' item does not exist in this organisation\'s library.',
            ], 404);
        }

        // Every competency this item belongs to. An item can sit in several, and
        // one measurement of one thing is the same measurement in all of them.
        $linkedItemIds = DB::table('competency_kasba_item')
            ->where('sub_institute_id', $sid)
            ->where('kasba_type', $type)
            ->where('item_id', $itemId)
            ->pluck('id');

        $shared = [
            'rating'      => $rating,
            'assessor_id' => $actor,
            'source'      => 'kasba_library',
            'note'        => $note,
            'rated_at'    => now(),
            'updated_at'  => now(),
        ];

        DB::transaction(function () use ($sid, $subject, $type, $itemId, $linkedItemIds, $shared) {
            // The direct row - uq_ckr_direct makes this idempotent.
            DB::table('competency_kasba_rating')->updateOrInsert(
                [
                    'sub_institute_id' => $sid,
                    'user_id'          => $subject,
                    'kasba_type'       => $type,
                    'item_id'          => $itemId,
                ],
                $shared
            );

            // And one per competency link, so the existing roll-up is unaffected.
            foreach ($linkedItemIds as $kasbaItemId) {
                DB::table('competency_kasba_rating')->updateOrInsert(
                    [
                        'sub_institute_id' => $sid,
                        'user_id'          => $subject,
                        'kasba_item_id'    => (int) $kasbaItemId,
                    ],
                    $shared
                );
            }
        });

        return response()->json([
            'status'  => 1,
            'message' => 'Rating saved.',
            'data'    => [
                'kasba_type'       => $type,
                'item_id'          => $itemId,
                'user_id'          => $subject,
                'title'            => $item->title,
                'competencies_hit' => $linkedItemIds->count(),
                // Not an error, but the caller should be able to say so: a
                // rating on an unlinked item is recorded and will not appear in
                // any competency roll-up until somebody maps it.
                'rolls_up'         => $linkedItemIds->isNotEmpty(),
                'notice'           => $linkedItemIds->isEmpty()
                    ? 'Saved. This item is not part of any competency yet, so it will not affect competency scores until it is mapped in Competency Library.'
                    : null,
            ],
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

    /** The five KASBA dimensions and the library table each one names. */
    private const DIMENSION_TABLES = [
        'skill'     => 's_users_skills',
        'knowledge' => 's_user_knowledge',
        'ability'   => 's_user_ability',
        'attitude'  => 's_user_attitude',
        'behaviour' => 's_user_behaviour',
    ];

    /**
     * Gives every item a `title` the screen can actually render.
     *
     * ── THE DEFECT ─────────────────────────────────────────────────────────
     *
     * `competency_kasba_item` stores an item one of two ways: RESOLVED, with
     * `item_id` pointing into a library table and `item_label` NULL; or HELD,
     * with the customer's wording in `item_label` and `item_id` NULL. The read
     * above selected only `item_label`.
     *
     * So the items that were CORRECTLY resolved - 203 of them on live - came
     * back with no text at all, while the unresolved ones displayed fine. The
     * better the data, the emptier the screen.
     *
     * ── WHY IN PHP AND NOT A FIVE-WAY LEFT JOIN ────────────────────────────
     *
     * Because the join would be five OUTER joins against five tables to
     * populate one string, and COALESCE across five nullable columns is a
     * shape nobody reads twice. The list is 9-12 items for the largest role on
     * live and at most one query per dimension present, so the cost is a
     * handful of indexed lookups against a fixed, tiny set of ids.
     *
     * ── SOFT-DELETED LIBRARY ROWS ARE STILL TITLED, DELIBERATELY ───────────
     *
     * No `whereNull('deleted_at')` here. If a library item is retired while a
     * competency still references it, the honest thing to show is its name -
     * that is how somebody works out what to fix. Blanking it would hide the
     * problem rather than report it.
     *
     * @param \Illuminate\Support\Collection $items
     * @return \Illuminate\Support\Collection sorted by competency, dimension, title
     */
    private function attachTitles($items, int $sid)
    {
        // One bucket of ids per dimension actually present - never five queries
        // when the role only uses two.
        $wanted = [];
        foreach ($items as $row) {
            $type = mb_strtolower((string) $row->kasba_type);
            if ($row->item_id !== null && isset(self::DIMENSION_TABLES[$type])) {
                $wanted[$type][] = (int) $row->item_id;
            }
        }

        $titles = [];
        foreach ($wanted as $type => $ids) {
            $titles[$type] = DB::table(self::DIMENSION_TABLES[$type])
                ->where('sub_institute_id', $sid)
                ->whereIn('id', array_values(array_unique($ids)))
                ->pluck('title', 'id')
                ->all();
        }

        foreach ($items as $row) {
            $type = mb_strtolower((string) $row->kasba_type);
            $resolved = ($row->item_id !== null && isset($titles[$type][(int) $row->item_id]))
                ? $titles[$type][(int) $row->item_id]
                : null;

            // item_label is the fallback, not the other way round: a resolved
            // item's library title is the current name, and a label is a
            // snapshot of what somebody typed once.
            $row->title = $resolved ?? $row->item_label;

            // An id that resolves to nothing is a real condition - the library
            // row was hard-deleted - and the screen should say so rather than
            // print an empty cell.
            $row->title_missing = $row->title === null || $row->title === '';
        }

        // Re-sorted here because the SQL ordered by `k.item_label`, which is
        // NULL for exactly the rows this method just gave a title to.
        return $items
            ->sortBy(fn ($r) => [
                (string) ($r->competency_name ?? ''),
                (string) $r->kasba_type,
                mb_strtolower((string) ($r->title ?? '')),
            ])
            ->values();
    }
}
