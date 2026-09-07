<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Lms\CourseQuizGenerator;
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

    /* ─── Questions ─────────────────────────────────────────────────────────
     *
     * THE MISSING HALF OF QUIZ AUTHORING.
     *
     * Before these, a quiz could be created, named, given a pass mark and an
     * attempt limit — and never asked a single question. `question_paper`
     * store/update/destroy managed the PAPER; the only question endpoint was
     * `questions()` above, which READS a bank. No API path anywhere wrote
     * `lms_question_master` or `answer_master`, so every quiz authored through
     * this product had `total_ques = 0`, and the wizard rendered "0 questions"
     * without comment.
     *
     * That made the whole downstream chain — QuizScoringService, the KASBA
     * rating, the certificate gate — unreachable by any admin. The demo quiz on
     * live exists only because it was seeded by a console command.
     *
     * ── WHY THE PAPER IS THE PARENT ─────────────────────────────────────────
     *
     * Questions are addressed under `/assessments/{paper}/questions` rather than
     * standalone, because the paper is what carries tenancy, the course link,
     * and `question_ids`. Writing a question without updating the paper's
     * `question_ids` / `total_ques` / `total_marks` in the same transaction is
     * how a paper ends up disagreeing with its own contents.
     */

    /** Shared validation for a question and its options. */
    private function questionRules(): array
    {
        return [
            'question_title'      => 'required|string|max:2000',
            'description'         => 'nullable|string',
            'points'              => 'nullable|integer|min:1|max:100',
            'hint_text'           => 'nullable|string|max:1000',
            // A written question has no options and is marked by the model.
            'options'             => 'nullable|array|max:10',
            'options.*.answer'    => 'required|string|max:2000',
            'options.*.correct'   => 'nullable|boolean',
        ];
    }

    /**
     * The paper, if it belongs to the caller's organisation.
     *
     * 404 rather than 403 for another tenant's paper — saying "that is not
     * yours" confirms it exists.
     */
    private function findPaper($id, $subInstituteId)
    {
        return DB::table('question_paper')
            ->where('id', $id)
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->first();
    }

    /**
     * Recompute the paper's question list, count and total marks from what is
     * actually attached to it.
     *
     * Derived from the questions rather than incremented, so a question deleted
     * or re-pointed elsewhere cannot leave the paper claiming marks that no
     * longer exist. `question_ids` stays comma-separated: it is the format
     * questionpaperController writes and the player reads, and every paper
     * already in the table uses it.
     */
    private function syncPaperTotals($paperId, array $orderedIds): void
    {
        $ids = collect($orderedIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();

        $marks = $ids->isEmpty() ? 0 : (int) DB::table('lms_question_master')
            ->whereIn('id', $ids)
            ->whereNull('deleted_at')
            ->sum('points');

        DB::table('question_paper')->where('id', $paperId)->update([
            'question_ids' => $ids->isEmpty() ? null : $ids->implode(','),
            'total_ques'   => $ids->count(),
            // total_marks was never maintained by any write path; the wizard
            // and the scorer both need it to agree with the questions.
            'total_marks'  => $marks,
            'updated_at'   => now(),
        ]);
    }

    /** The paper's question_ids as an ordered array of ints. */
    private function paperQuestionIds($paper): array
    {
        return collect(explode(',', (string) $paper->question_ids))
            ->map(fn ($id) => (int) trim($id))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * GET /api/lms/assessments/{id}/questions
     *
     * The paper's OWN questions, with their options and — unlike the learner
     * endpoint — which option is correct. This is the authoring view, and it is
     * admin-gated for exactly that reason. QuizScoringService::questionsFor()
     * remains the learner path and still never selects `correct_answer`.
     */
    public function paperQuestions(Request $request, $id)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }
        if ($roleError = $this->guardAdmin($request)) {
            return $roleError;
        }

        $subInstituteId = $this->tenantId($request);
        $paper = $this->findPaper($id, $subInstituteId);

        if (!$paper) {
            return response()->json(['status' => false, 'message' => 'Assessment not found'], 404);
        }

        $ids = $this->paperQuestionIds($paper);

        if ($ids === []) {
            return response()->json(['status' => true, 'data' => [], 'total_marks' => 0]);
        }

        $questions = DB::table('lms_question_master')
            ->whereIn('id', $ids)
            ->whereNull('deleted_at')
            ->get(['id', 'question_title', 'description', 'points', 'hint_text'])
            ->keyBy('id');

        $options = DB::table('answer_master')
            ->whereIn('question_id', $ids)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'question_id', 'answer', 'correct_answer'])
            ->groupBy('question_id');

        // The paper's own order, not the database's - question_ids is ordered
        // and an author who sequenced their questions meant it.
        $ordered = [];

        foreach ($ids as $questionId) {
            $question = $questions[$questionId] ?? null;
            if (!$question) {
                continue;
            }

            $ordered[] = [
                'id'             => (int) $question->id,
                'question_title' => $question->question_title,
                'description'    => $question->description,
                'points'         => (int) ($question->points ?: 1),
                'hint_text'      => $question->hint_text,
                'options'        => ($options[$questionId] ?? collect())->map(fn ($o) => [
                    'id'      => (int) $o->id,
                    'answer'  => $o->answer,
                    'correct' => (bool) $o->correct_answer,
                ])->values(),
            ];
        }

        return response()->json([
            'status' => true,
            'data' => $ordered,
            'total_marks' => (int) DB::table('lms_question_master')
                ->whereIn('id', $ids)->whereNull('deleted_at')->sum('points'),
        ]);
    }

    /** POST /api/lms/assessments/{id}/questions — add a question to the paper. */
    public function storeQuestion(Request $request, $id)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }
        if ($roleError = $this->guardAdmin($request)) {
            return $roleError;
        }

        $subInstituteId = $this->tenantId($request);
        $paper = $this->findPaper($id, $subInstituteId);

        if (!$paper) {
            return response()->json(['status' => false, 'message' => 'Assessment not found'], 404);
        }

        $validator = Validator::make($request->all(), $this->questionRules());

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->messages()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        if ($refusal = $this->rejectUnmarkableOptions($request)) {
            return $refusal;
        }

        try {
            $questionId = DB::transaction(function () use ($request, $paper, $subInstituteId, $id) {
                $now = now();

                $questionId = DB::table('lms_question_master')->insertGetId([
                    // 1 = 'multiple' in question_type_master, the only type this
                    // installation defines.
                    'question_type_id' => 1,
                    'subject_id'       => (int) $paper->subject_id,
                    'standard_id'      => $paper->standard_id,
                    'question_title'   => $request->input('question_title'),
                    'description'      => $request->input('description'),
                    'points'           => (int) $request->input('points', 1),
                    'hint_text'        => $request->input('hint_text'),
                    'multiple_answer'  => $this->correctCount($request) > 1 ? 1 : 0,
                    'sub_institute_id' => $subInstituteId,
                    'status'           => 1,
                    'created_by'       => $this->contextUserId($request),
                    'created_on'       => $now,
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ]);

                $this->writeOptions($request, $questionId, $subInstituteId);

                // Appended, so an author adding a question does not reorder the
                // ones already there.
                $this->syncPaperTotals($id, [...$this->paperQuestionIds($paper), $questionId]);

                return $questionId;
            });

            return response()->json([
                'status' => true,
                'message' => 'Question added',
                'data' => ['id' => $questionId],
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to add the question',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /** PUT /api/lms/assessments/{id}/questions/{questionId} */
    public function updateQuestion(Request $request, $id, $questionId)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }
        if ($roleError = $this->guardAdmin($request)) {
            return $roleError;
        }

        $subInstituteId = $this->tenantId($request);
        $paper = $this->findPaper($id, $subInstituteId);

        if (!$paper) {
            return response()->json(['status' => false, 'message' => 'Assessment not found'], 404);
        }

        $question = DB::table('lms_question_master')
            ->where('id', $questionId)
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->first();

        if (!$question || !in_array((int) $questionId, $this->paperQuestionIds($paper), true)) {
            return response()->json(['status' => false, 'message' => 'Question not found'], 404);
        }

        $validator = Validator::make($request->all(), $this->questionRules());

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->messages()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        if ($refusal = $this->rejectUnmarkableOptions($request)) {
            return $refusal;
        }

        try {
            DB::transaction(function () use ($request, $id, $questionId, $subInstituteId, $paper) {
                DB::table('lms_question_master')->where('id', $questionId)->update([
                    'question_title'  => $request->input('question_title'),
                    'description'     => $request->input('description'),
                    'points'          => (int) $request->input('points', 1),
                    'hint_text'       => $request->input('hint_text'),
                    'multiple_answer' => $this->correctCount($request) > 1 ? 1 : 0,
                    'updated_by'      => $this->contextUserId($request),
                    'updated_at'      => now(),
                ]);

                /*
                 * Options are replaced wholesale rather than diffed.
                 *
                 * They are soft-deleted, not removed: `lms_quiz_response.answer_id`
                 * points at them, and hard-deleting an option would orphan the
                 * record of what a learner actually chose on a past attempt.
                 */
                if ($request->has('options')) {
                    DB::table('answer_master')
                        ->where('question_id', $questionId)
                        ->whereNull('deleted_at')
                        ->update(['deleted_at' => now()]);

                    $this->writeOptions($request, (int) $questionId, $subInstituteId);
                }

                // Points may have changed, so the paper's total marks must follow.
                $this->syncPaperTotals($id, $this->paperQuestionIds($paper));
            });

            return response()->json(['status' => true, 'message' => 'Question updated']);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to update the question',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /** DELETE /api/lms/assessments/{id}/questions/{questionId} */
    public function destroyQuestion(Request $request, $id, $questionId)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }
        if ($roleError = $this->guardAdmin($request)) {
            return $roleError;
        }

        $subInstituteId = $this->tenantId($request);
        $paper = $this->findPaper($id, $subInstituteId);

        if (!$paper) {
            return response()->json(['status' => false, 'message' => 'Assessment not found'], 404);
        }

        $ids = $this->paperQuestionIds($paper);

        if (!in_array((int) $questionId, $ids, true)) {
            return response()->json(['status' => false, 'message' => 'Question not found'], 404);
        }

        try {
            DB::transaction(function () use ($request, $id, $questionId, $ids, $subInstituteId) {
                DB::table('lms_question_master')
                    ->where('id', $questionId)
                    ->where('sub_institute_id', $subInstituteId)
                    ->update([
                        'deleted_at' => now(),
                        'deleted_by' => $this->contextUserId($request),
                    ]);

                $this->syncPaperTotals(
                    $id,
                    array_values(array_filter($ids, fn ($x) => $x !== (int) $questionId))
                );
            });

            return response()->json(['status' => true, 'message' => 'Question removed']);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to remove the question',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /** How many options this request marks correct. */
    private function correctCount(Request $request): int
    {
        return collect((array) $request->input('options', []))
            ->filter(fn ($o) => filter_var($o['correct'] ?? false, FILTER_VALIDATE_BOOLEAN))
            ->count();
    }

    /**
     * Refuse a multiple-choice question that nothing can mark.
     *
     * A question WITH options but NO correct one is the failure that produced
     * live question 1 — one option, `correct_answer = 0`, unmarkable forever.
     * The scorer handles it by sending it to the model and then to a human, but
     * that is a rescue, not an intention; an author should be told at the point
     * they make the mistake.
     *
     * Zero options is allowed and means a written answer.
     */
    private function rejectUnmarkableOptions(Request $request)
    {
        $options = (array) $request->input('options', []);

        if ($options === []) {
            return null;
        }

        if ($this->correctCount($request) === 0) {
            return response()->json([
                'status' => false,
                'message' => 'Mark at least one option correct, or remove all options to make '
                    . 'this a written answer.',
            ], 422);
        }

        return null;
    }

    /**
     * POST /api/lms/assessments/{id}/questions/generate
     *
     * Write this course's quiz from the course's own modules, lessons and
     * mapped capabilities. See CourseQuizGenerator for what the model is told
     * and why the prompt is shaped the way it is.
     *
     * Generated questions are APPENDED. Replacing what an author already wrote
     * would be a destructive act triggered by a button labelled "generate", and
     * a paper's question_ids is the only record of its ordering.
     */
    public function generateQuestions(Request $request, $id, CourseQuizGenerator $generator)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }
        if ($roleError = $this->guardAdmin($request)) {
            return $roleError;
        }

        $subInstituteId = $this->tenantId($request);
        $paper = $this->findPaper($id, $subInstituteId);

        if (!$paper) {
            return response()->json(['status' => false, 'message' => 'Assessment not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'count' => 'nullable|integer|min:' . CourseQuizGenerator::MIN_QUESTIONS
                . '|max:' . CourseQuizGenerator::MAX_QUESTIONS,
            'formats' => 'nullable|array',
            'formats.*' => 'string|in:mcq,short_answer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->messages()->first(),
            ], 422);
        }

        $count = (int) $request->input('count', 5);
        $formats = (array) $request->input('formats', ['mcq']);

        try {
            $result = $generator->generate((int) $paper->subject_id, (int) $subInstituteId, $count, $formats);
        } catch (\RuntimeException $e) {
            // The generator's own refusals are the caller's problem to fix -
            // no content, no AI credit - so they are reported as such rather
            // than as a server fault.
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'The question generator could not be reached.',
                'detail' => $e->getMessage(),
            ], 502);
        }

        if ($result['questions'] === []) {
            return response()->json([
                'status' => false,
                'message' => $result['dropped'] > 0
                    ? 'Every generated question was unusable, so nothing was saved. Try again, or add more detail to the lesson descriptions.'
                    : 'The generator returned no questions.',
            ], 422);
        }

        $actor = $this->contextUserId($request);

        $written = DB::transaction(function () use ($result, $paper, $subInstituteId, $actor, $id) {
            $now = now();
            $ids = [];

            foreach ($result['questions'] as $q) {
                $questionId = DB::table('lms_question_master')->insertGetId([
                    'question_type_id' => 1,
                    'subject_id'       => (int) $paper->subject_id,
                    // The module this question came from. Existing hand-authored
                    // questions leave it null, so the join that attributes a
                    // question to a module has always returned nothing.
                    'chapter_id'       => $q['chapter_id'],
                    // The capability it tests. This is what lets a pass move
                    // that capability specifically rather than the competency
                    // as a blanket - QuizScoringService::perItemRatings.
                    'kasba_item_id'    => $q['kasba_item_id'],
                    'standard_id'      => $paper->standard_id,
                    'question_title'   => $q['question_title'],
                    'points'           => $q['points'],
                    // The reference the AI marker grades a written answer
                    // against. The builder never wrote it, so every written
                    // question was marked with "(none given)".
                    'answer'           => $q['model_answer'],
                    'multiple_answer'  => 0,
                    'sub_institute_id' => $subInstituteId,
                    'status'           => 1,
                    'created_by'       => $actor,
                    'created_on'       => $now,
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ]);

                if ($q['options'] !== []) {
                    DB::table('answer_master')->insert(array_map(fn ($option) => [
                        'question_id'      => $questionId,
                        'answer'           => $option,
                        'correct_answer'   => $option === $q['correct_option'] ? 1 : 0,
                        'sub_institute_id' => $subInstituteId,
                        'created_by'       => $actor,
                        'created_at'       => $now,
                        'updated_at'       => $now,
                    ], $q['options']));
                }

                $ids[] = $questionId;
            }

            $this->syncPaperTotals($id, [...$this->paperQuestionIds($paper), ...$ids]);

            return $ids;
        });

        $cited = collect($result['questions'])->whereNotNull('kasba_item_id')->count();

        return response()->json([
            'status' => true,
            'message' => sprintf(
                '%d question(s) written from this course\'s content%s.%s',
                count($written),
                $cited > 0 ? ", {$cited} of them tied to a capability" : '',
                $result['dropped'] > 0
                    ? sprintf(' %d unusable question(s) were discarded.', $result['dropped'])
                    : ''
            ),
            'data' => [
                'created' => count($written),
                'dropped' => $result['dropped'],
                'cited' => $cited,
                'modules_used' => count($result['context']['modules']),
                'capabilities_available' => count($result['context']['capabilities']),
            ],
        ], 201);
    }

    /** Write a question's options, flagging the correct ones. */
    private function writeOptions(Request $request, int $questionId, $subInstituteId): void
    {
        $options = (array) $request->input('options', []);

        if ($options === []) {
            return;
        }

        $now = now();

        DB::table('answer_master')->insert(array_map(fn ($option) => [
            'question_id'      => $questionId,
            'answer'           => $option['answer'],
            // The ONLY place correctness is recorded, and the only thing the
            // scorer reads. It is never sent to a learner.
            'correct_answer'   => filter_var($option['correct'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
            'sub_institute_id' => $subInstituteId,
            'created_by'       => $this->contextUserId($request),
            'created_at'       => $now,
            'updated_at'       => $now,
        ], $options));
    }
}
