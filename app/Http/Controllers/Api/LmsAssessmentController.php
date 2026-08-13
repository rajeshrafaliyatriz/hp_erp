<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ResolvesLmsIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Course assessments (quizzes) for the Course Builder.
 *
 * Assessments live in question_paper, which already has a full controller -
 * but every one of its routes is registered in routes/lms.php as a web route.
 * Those are session-authenticated and CSRF-protected, so a cross-origin call
 * from the Next.js app gets a 419 rather than a response. This controller is an
 * additive /api surface over the same table; routes/lms.php is left untouched,
 * so the old frontend's Assessment Library keeps working exactly as it does now.
 *
 * A paper belongs to a course through question_paper.subject_id, which holds a
 * sub_std_map.id - the same convention chapter_master.subject_id uses. Verified
 * against the existing 61 rows, all of which resolve to real courses.
 *
 * Note the course-wide passing score is NOT here: question_paper has no such
 * column, and the wizard treats it as a rule for the whole course, so it lives
 * on lms_course_settings.passing_score alongside max_attempts.
 */
class LmsAssessmentController extends Controller
{
    use ResolvesLmsIdentity;

    /** Profiles allowed to author assessments. */
    private const ADMIN_PROFILES = ['admin', 'hr', 'super', 'principal', 'teacher'];

    /**
     * Same token guard the other LMS API controllers use: only enforced when
     * type=API is present, which is what withLaravelParams always sends.
     */
    private function guardApiToken(Request $request)
    {
        // Was: `if ($request->input('type') !== 'API') return null;` followed by
        // a token check that discarded the token's owner. Omitting `type`
        // skipped authentication entirely. Identity now always comes from the
        // token - see ResolvesLmsIdentity.
        return $this->guardLmsToken($request);
    }

    /** Null when the caller may author, a 403 response otherwise. */
    private function guardAdmin(Request $request)
    {
        // The profile now comes from the caller's tbluser row, not from
        // a `user_profile_name` they supplied themselves.
        return $this->guardLmsProfile($request, self::ADMIN_PROFILES, 'Your profile is not permitted to manage assessments.');
    }

    private function tenantId(Request $request)
    {
        // The caller's own organisation, from their token - not from whatever
        // sub_institute_id the request asked for.
        return $this->lmsTenantId($request);
    }

    /** The course, or null when it does not exist in this tenant. */
    private function findCourse($courseId, $subInstituteId)
    {
        return DB::table('sub_std_map')
            ->where('id', $courseId)
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->first();
    }

    private function rules(): array
    {
        return [
            'course_id'        => 'required|integer',
            'paper_name'       => 'required|string|max:191',
            'paper_desc'       => 'nullable|string',
            'attempt_allowed'  => 'nullable|integer|min:1|max:100',
            'time_allowed'     => 'nullable|integer|min:1|max:1440',
            'timelimit_enable' => 'nullable|boolean',
            'open_date'        => 'nullable|date',
            'close_date'       => 'nullable|date|after_or_equal:open_date',
            'shuffle_question' => 'nullable|boolean',
            'show_feedback'    => 'nullable|boolean',
            'result_show_ans'  => 'nullable|boolean',
            'exam_type'        => 'nullable|string|max:100',
            'question_ids'     => 'nullable|array',
            'question_ids.*'   => 'integer',
        ];
    }

    /**
     * Columns written on both create and update.
     *
     * question_ids is stored comma-separated because that is the existing
     * format questionpaperController writes and the player reads - changing it
     * would break every paper already in the table.
     */
    private function payload(Request $request, $course): array
    {
        $questionIds = collect((array) $request->input('question_ids', []))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        return [
            'paper_name'       => $request->input('paper_name'),
            'paper_desc'       => $request->input('paper_desc'),
            'subject_id'       => (int) $course->id,
            'standard_id'      => $course->standard_id,
            'attempt_allowed'  => $request->input('attempt_allowed'),
            'time_allowed'     => $request->input('time_allowed'),
            'timelimit_enable' => $request->boolean('timelimit_enable') ? 1 : 0,
            'open_date'        => $request->input('open_date'),
            'close_date'       => $request->input('close_date'),
            'shuffle_question' => $request->boolean('shuffle_question') ? 1 : 0,
            'show_feedback'    => $request->boolean('show_feedback') ? 1 : 0,
            'result_show_ans'  => $request->boolean('result_show_ans') ? 1 : 0,
            'exam_type'        => $request->input('exam_type', 'quiz'),
            'question_ids'     => $questionIds->isEmpty() ? null : $questionIds->implode(','),
            'total_ques'       => $questionIds->count(),
            'show_hide'        => 1,
        ];
    }

    /**
     * GET /api/lms/assessments?course_id=
     *
     * Every quiz attached to a course, newest first.
     */
    public function index(Request $request)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }

        $subInstituteId = $this->tenantId($request);
        $courseId = $request->input('course_id');

        if (!$subInstituteId || !$courseId) {
            return response()->json([
                'status' => false,
                'message' => 'sub_institute_id and course_id are required',
            ], 422);
        }

        try {
            $papers = DB::table('question_paper')
                ->where('subject_id', $courseId)
                ->where('sub_institute_id', $subInstituteId)
                ->whereNull('deleted_at')
                ->orderByDesc('id')
                ->get([
                    'id', 'paper_name', 'paper_desc', 'subject_id', 'standard_id',
                    'attempt_allowed', 'time_allowed', 'timelimit_enable',
                    'open_date', 'close_date', 'shuffle_question', 'show_feedback',
                    'result_show_ans', 'exam_type', 'total_ques', 'total_marks',
                    'question_ids', 'show_hide', 'created_at',
                ])
                ->map(function ($paper) {
                    // Hand back a real array; the column is a comma-separated string.
                    $paper->question_ids = $paper->question_ids
                        ? array_values(array_filter(array_map('intval', explode(',', $paper->question_ids))))
                        : [];
                    $paper->total_ques = (int) $paper->total_ques;
                    $paper->total_marks = (int) $paper->total_marks;
                    return $paper;
                });

            return response()->json(['status' => true, 'data' => $papers]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to load assessments',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/lms/assessments/questions?course_id=
     *
     * The question bank available to a course, for picking which questions a
     * quiz contains. Scoped through the course's chapters, since
     * lms_question_master rows hang off chapter_id.
     */
    public function questions(Request $request)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }

        $subInstituteId = $this->tenantId($request);
        $courseId = $request->input('course_id');

        if (!$subInstituteId || !$courseId) {
            return response()->json([
                'status' => false,
                'message' => 'sub_institute_id and course_id are required',
            ], 422);
        }

        try {
            $questions = DB::table('lms_question_master as q')
                ->leftJoin('chapter_master as c', 'c.id', '=', 'q.chapter_id')
                ->where('q.subject_id', $courseId)
                ->where('q.sub_institute_id', $subInstituteId)
                ->whereNull('q.deleted_at')
                ->orderBy('q.id')
                ->limit(500)
                ->get([
                    'q.id', 'q.question_title', 'q.points', 'q.question_type_id',
                    'q.chapter_id', 'c.chapter_name',
                ]);

            return response()->json(['status' => true, 'data' => $questions]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to load the question bank',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /** POST /api/lms/assessments - create a quiz on a course. */
    public function store(Request $request)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }
        if ($roleError = $this->guardAdmin($request)) {
            return $roleError;
        }

        $subInstituteId = $this->tenantId($request);

        $validator = Validator::make($request->all(), $this->rules());
        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->messages()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $course = $this->findCourse($request->input('course_id'), $subInstituteId);
        if (!$course) {
            return response()->json(['status' => false, 'message' => 'Course not found'], 404);
        }

        try {
            $id = DB::table('question_paper')->insertGetId(
                $this->payload($request, $course) + [
                    'sub_institute_id' => $subInstituteId,
                    'syear'            => $request->input('syear'),
                    'created_by'       => $this->contextUserId($request),
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]
            );

            return response()->json([
                'status' => true,
                'message' => 'Assessment created',
                'data' => DB::table('question_paper')->find($id),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to create the assessment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /** PUT /api/lms/assessments/{id} */
    public function update(Request $request, $id)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }
        if ($roleError = $this->guardAdmin($request)) {
            return $roleError;
        }

        $subInstituteId = $this->tenantId($request);

        $validator = Validator::make($request->all(), $this->rules());
        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->messages()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        // Scoped by tenant so one institute cannot edit another's paper.
        $paper = DB::table('question_paper')
            ->where('id', $id)
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->first();

        if (!$paper) {
            return response()->json(['status' => false, 'message' => 'Assessment not found'], 404);
        }

        $course = $this->findCourse($request->input('course_id'), $subInstituteId);
        if (!$course) {
            return response()->json(['status' => false, 'message' => 'Course not found'], 404);
        }

        try {
            DB::table('question_paper')->where('id', $id)->update(
                $this->payload($request, $course) + [
                    'updated_by' => $this->contextUserId($request),
                    'updated_at' => now(),
                ]
            );

            return response()->json([
                'status' => true,
                'message' => 'Assessment updated',
                'data' => DB::table('question_paper')->find($id),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to update the assessment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * DELETE /api/lms/assessments/{id}
     *
     * Soft delete, matching every other write in this module - a paper learners
     * have already attempted should stay resolvable.
     */
    public function destroy(Request $request, $id)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }
        if ($roleError = $this->guardAdmin($request)) {
            return $roleError;
        }

        $subInstituteId = $this->tenantId($request);

        $paper = DB::table('question_paper')
            ->where('id', $id)
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->first();

        if (!$paper) {
            return response()->json(['status' => false, 'message' => 'Assessment not found'], 404);
        }

        try {
            DB::table('question_paper')->where('id', $id)->update([
                'deleted_at' => now(),
                'deleted_by' => $this->contextUserId($request),
            ]);

            return response()->json(['status' => true, 'message' => 'Assessment deleted']);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete the assessment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
