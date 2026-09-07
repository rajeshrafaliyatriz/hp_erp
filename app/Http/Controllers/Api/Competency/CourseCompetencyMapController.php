<?php

namespace App\Http\Controllers\Api\Competency;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Competency\Concerns\ResolvesCompetencyContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * WHICH COMPETENCIES A COURSE DEVELOPS - `course_competency_map`.
 *
 * THE TABLE HAS TWO SHIPPED CONSUMERS AND HAD NO WRITER.
 *
 *   LearningAssigner        turns a competency gap into a course to assign
 *   RemediationRecommender  turns a lapse into a list of courses that fix it
 *
 * Both landed reading this table, both have been reading 56 seeded rows, and
 * nothing in the product could add a 57th. Course creation writes `sub_std_map`
 * and stops. So the two features exist, are correct, and have never had anything
 * to work with beyond whatever the seed happened to cover.
 *
 * FILLING THIS TABLE IS THE ONE CHANGE THAT MAKES ALREADY-BUILT THINGS START
 * WORKING, which is why it is built before the job-role-task map.
 *
 * SYNC SEMANTICS, deliberately, and identical to RoleCompetencyMapController:
 * rows absent from `items` are DELETED for that course. A competency dropped
 * from a course's list must stop being recommended for it, and an append-only
 * writer would leave the recommender citing a link somebody removed.
 *
 * R20 - THE CHAIN THIS RELIES ON:
 *   route       routes/api.php
 *   middleware  `profile:admin,hr` - RequireProfile, exact role_key since G-AUTH-02
 *   tenant      competencyContext() -> resolveApiIdentity(), never a request field
 *   actor       the same; created_by is never taken from the body (G-SEC-12)
 */
class CourseCompetencyMapController extends Controller
{
    use ResolvesCompetencyContext;

    /**
     * GET /competency/course-map?course_id=
     *
     * THE EMPTY READ IS A REAL ANSWER, NOT A GAP (L-14's note, carried).
     * A course with no competencies mapped is the normal state for every course
     * nobody has mapped yet, and the payload says so rather than leaving the
     * screen to guess from an empty array.
     */
    public function index(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), ['course_id' => 'required|integer']);
        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $sid = (int) $context['sub_institute_id'];
        $courseId = $request->integer('course_id');

        /*
         * TARGET AND ACHIEVED, SIDE BY SIDE.
         *
         * `m.proficiency_level` is the TARGET — what HR says this course is
         * meant to develop somebody to. What learners actually reach is
         * measured separately, from their quiz results, in
         * lms_course_competency_effectiveness.
         *
         * The two must stay in different columns. This method's own store()
         * syncs destructively, so a measured value written into
         * proficiency_level would be erased by the next save of the mapping;
         * and overwriting a declared intention with an observation destroys the
         * comparison that makes either number worth having.
         *
         * LEFT joined, so a course whose quiz nobody has sat yet reads exactly
         * as it did before — a target and no measurement.
         */
        $rows = DB::table('course_competency_map as m')
            ->join('competency as c', 'c.id', '=', 'm.competency_id')
            ->leftJoin('lms_course_competency_effectiveness as e', function ($join) use ($sid) {
                $join->on('e.competency_id', '=', 'm.competency_id')
                     ->on('e.course_id', '=', 'm.course_id')
                     ->where('e.sub_institute_id', '=', $sid);
            })
            ->where('m.sub_institute_id', $sid)
            ->where('m.course_id', $courseId)
            ->orderByDesc('m.is_primary')
            ->orderBy('c.name')
            ->get([
                'm.id', 'm.competency_id', 'm.proficiency_level', 'm.is_primary',
                'c.name as competency_name', 'c.code as competency_code',
                'e.derived_level', 'e.mean_percent', 'e.attempts', 'e.learners',
            ]);

        return response()->json([
            'status' => 1,
            'data'   => $rows->map(fn ($r) => [
                'id'                => (int) $r->id,
                'competency_id'     => (int) $r->competency_id,
                'competency_name'   => $r->competency_name,
                'competency_code'   => $r->competency_code,
                'proficiency_level' => $r->proficiency_level === null ? null : (int) $r->proficiency_level,
                'is_primary'        => (bool) $r->is_primary,
                // Measured, not declared. Null until somebody has sat the quiz.
                'achieved_level'    => $r->derived_level === null ? null : (int) $r->derived_level,
                'mean_percent'      => $r->mean_percent === null ? null : (float) $r->mean_percent,
                'quiz_attempts'     => (int) ($r->attempts ?? 0),
                'quiz_learners'     => (int) ($r->learners ?? 0),
            ])->values(),
            // Stated, not inferred from an empty array: nothing mapped is the
            // expected state for a course nobody has mapped, and the screen
            // should say that rather than imply a failure.
            'empty_is_expected' => $rows->isEmpty(),
        ]);
    }

    /**
     * POST /competency/course-map - SYNC a course's complete competency list.
     */
    public function store(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            'course_id'                  => 'required|integer',
            'items'                      => 'required|array|min:1',
            'items.*.competency_id'      => 'required|integer',
            'items.*.proficiency_level'  => 'nullable|integer|min:1|max:5',
            'items.*.is_primary'         => 'nullable|boolean',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $sid      = (int) $context['sub_institute_id'];
        $actor    = (int) $context['user_id'];
        $courseId = $request->integer('course_id');

        // The course must be the caller's own. Without this a mapping could be
        // hung off another tenant's course id - the hole G-SEC-29 spent twenty
        // controllers closing.
        $courseOk = DB::table('sub_std_map')
            ->where('id', $courseId)->where('sub_institute_id', $sid)->exists();
        if (!$courseOk) {
            return response()->json(['status' => 0, 'message' => 'Course not found.'], 404);
        }

        // A competency listed twice is user-trippable, so it is reported as a
        // sentence rather than as a unique-constraint violation.
        $seen = [];
        foreach ($request->input('items') as $i => $item) {
            $cid = (int) $item['competency_id'];
            if (isset($seen[$cid])) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'Item ' . ($i + 1) . ' repeats a competency already in this list.',
                ], 422);
            }
            $seen[$cid] = true;
        }

        // Every competency must exist inside the caller's own tenant. Reported as
        // one message so the user fixes the whole list in one pass.
        $valid = DB::table('competency')
            ->where('sub_institute_id', $sid)->whereNull('deleted_at')
            ->whereIn('id', array_keys($seen))->pluck('id')->all();
        $unknown = array_diff(array_keys($seen), $valid);
        if ($unknown) {
            return response()->json([
                'status'  => 0,
                'message' => 'These competencies do not exist in this organisation: ' . implode(', ', $unknown),
            ], 422);
        }

        $result = DB::transaction(function () use ($request, $sid, $actor, $courseId, $seen) {
            // SYNC, NOT APPEND. A competency dropped from a course must stop
            // being recommended for it; an append-only writer would leave
            // RemediationRecommender citing a link somebody removed.
            $removed = DB::table('course_competency_map')
                ->where('sub_institute_id', $sid)
                ->where('course_id', $courseId)
                ->whereNotIn('competency_id', array_keys($seen))
                ->delete();

            $n = 0;
            foreach ($request->input('items') as $item) {
                DB::table('course_competency_map')->updateOrInsert(
                    [
                        'sub_institute_id' => $sid,
                        'course_id'        => $courseId,
                        'competency_id'    => (int) $item['competency_id'],
                    ],
                    [
                        'proficiency_level' => isset($item['proficiency_level'])
                            ? (int) $item['proficiency_level'] : null,
                        'is_primary'        => !empty($item['is_primary']) ? 1 : 0,
                        'updated_at'        => now(),
                    ]
                );
                $n++;
            }

            return ['written' => $n, 'removed' => $removed];
        });

        return response()->json([
            'status'  => 1,
            'message' => 'Course competencies saved.',
            // `removed` is reported because a silent deletion is worse than none.
            'data'    => ['course_id' => $courseId] + $result,
        ], 201);
    }

    /** DELETE /competency/course-map/{id} - drop one mapping row. */
    public function destroy(Request $request, $id)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $deleted = DB::table('course_competency_map')
            ->where('sub_institute_id', (int) $context['sub_institute_id'])
            ->where('id', (int) $id)
            ->delete();

        return response()->json([
            'status'  => 1,
            'message' => $deleted ? 'Mapping removed.' : 'No mapping to remove.',
            'data'    => ['removed' => $deleted],
        ]);
    }
}
