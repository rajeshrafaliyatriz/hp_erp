<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ResolvesLmsIdentity;
use App\Services\Lms\QuizScoringService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * The course quiz: sit it, submit it, see what it did to your record.
 *
 * ── WHERE THIS SITS IN THE COURSE LIFECYCLE ─────────────────────────────────
 *
 *   finish every lesson  ->  QUIZ UNLOCKS  ->  pass it  ->  CERTIFICATE UNLOCKS
 *                                          ->  and the competency rating moves
 *
 * Both gates are deliberate. The quiz stays locked until the lessons are done,
 * because a quiz you can sit without doing the course measures what you already
 * knew. The certificate stays locked until the quiz is passed, because a
 * certificate awarded for opening six files certifies attendance, not learning.
 *
 * ── WHAT IS NEVER SENT TO THE CLIENT ────────────────────────────────────────
 *
 * `answer_master.correct_answer`. The legacy exam page put it in the option's
 * own `value` attribute and then trusted the submission to report correctness
 * back. Nothing here does that: `questionsFor()` does not select the column,
 * and marking reads it fresh from the database at submit time.
 */
class LmsQuizController extends Controller
{
    use ResolvesLmsIdentity;

    public function __construct(private readonly QuizScoringService $quiz)
    {
    }

    private function guardApiToken(Request $request)
    {
        $identity = $this->lmsIdentity($request);

        return is_array($identity) ? null : $identity;
    }

    private function tenantId(Request $request)
    {
        return $this->lmsTenantId($request);
    }

    /**
     * GET /api/lms/learning/courses/{courseId}/quiz
     *
     * The quiz for a course, plus everything the screen needs to explain
     * itself: whether it is unlocked, how many attempts are left, and what the
     * learner scored last time.
     *
     * Answering "why can I not start this?" here rather than letting the client
     * guess is what stops the button being offered and then refused.
     */
    public function show(Request $request, $courseId)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }

        $userId = $this->contextUserId($request);
        $subInstituteId = $this->tenantId($request);

        $course = DB::table('sub_std_map')
            ->where('id', $courseId)
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->first(['id', 'display_name']);

        if (!$course) {
            return response()->json(['status' => false, 'message' => 'Course not found'], 404);
        }

        $paper = $this->quiz->paperForCourse($courseId, $subInstituteId);

        if (!$paper) {
            // Not an error: most courses have no quiz, and the player needs to
            // know that so it can offer the certificate directly.
            return response()->json([
                'status' => true,
                'data' => ['has_quiz' => false, 'course_id' => (int) $courseId],
            ]);
        }

        $settings = DB::table('lms_course_settings')->where('course_id', $courseId)->first();
        $gate = $this->lessonGate($userId, $courseId);
        $attempts = $this->attemptsFor($userId, $paper->id, $subInstituteId);

        $maxAttempts = $settings->max_attempts ? (int) $settings->max_attempts : null;
        $used = $attempts->where('status', QuizScoringService::STATUS_SUBMITTED)->count();
        $best = $attempts->max('percent');
        $passed = $attempts->contains(fn ($a) => (bool) $a->passed);

        $reason = null;

        if (!$gate['open']) {
            $reason = "Finish all {$gate['total']} lessons first - you have completed {$gate['done']}.";
        } elseif ($maxAttempts !== null && $used >= $maxAttempts && !$passed) {
            $reason = "You have used all {$maxAttempts} attempts.";
        }

        return response()->json([
            'status' => true,
            'data' => [
                'has_quiz' => true,
                'course_id' => (int) $courseId,
                'course_title' => $course->display_name,
                'paper_id' => (int) $paper->id,
                'paper_name' => $paper->paper_name,
                'paper_desc' => $paper->paper_desc,
                'time_allowed' => $paper->timelimit_enable ? (int) $paper->time_allowed : null,
                'total_questions' => count($this->quiz->questionIds($paper)),
                'passing_score' => $settings->passing_score !== null ? (int) $settings->passing_score : null,
                'max_attempts' => $maxAttempts,
                'attempts_used' => $used,
                'attempts_left' => $maxAttempts === null ? null : max(0, $maxAttempts - $used),
                'lessons_total' => $gate['total'],
                'lessons_done' => $gate['done'],
                // The one flag the button binds to, and the sentence that
                // explains it when it is false.
                'can_start' => $reason === null,
                'locked_reason' => $reason,
                'best_percent' => $best !== null ? (float) $best : null,
                'passed' => $passed,
                'attempts' => $attempts->values(),
            ],
        ]);
    }

    /**
     * POST /api/lms/learning/courses/{courseId}/quiz/start
     *
     * Open an attempt and hand back the questions, without their answers.
     */
    public function start(Request $request, $courseId)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }

        $userId = $this->contextUserId($request);
        $subInstituteId = $this->tenantId($request);

        $paper = $this->quiz->paperForCourse($courseId, $subInstituteId);

        if (!$paper) {
            return response()->json(['status' => false, 'message' => 'This course has no quiz.'], 404);
        }

        // Enrolment first: the quiz is part of the course, and somebody who is
        // not on the course has no business sitting it.
        $enrolled = DB::table('lms_course_enroll')
            ->where('user_id', $userId)
            ->where('course_id', $courseId)
            ->whereIn('status', ['enrolled', 'in-progress', 'completed'])
            ->whereNull('deleted_at')
            ->exists();

        if (!$enrolled) {
            return response()->json([
                'status' => false,
                'message' => 'You are not enrolled in this course.',
            ], 403);
        }

        $gate = $this->lessonGate($userId, $courseId);

        if (!$gate['open']) {
            return response()->json([
                'status' => false,
                'message' => "Finish all {$gate['total']} lessons before taking the quiz - "
                    . "you have completed {$gate['done']}.",
                'data' => $gate,
            ], 422);
        }

        $settings = DB::table('lms_course_settings')->where('course_id', $courseId)->first();
        $maxAttempts = $settings && $settings->max_attempts ? (int) $settings->max_attempts : null;

        $attempts = $this->attemptsFor($userId, $paper->id, $subInstituteId);
        $used = $attempts->where('status', QuizScoringService::STATUS_SUBMITTED)->count();

        if ($attempts->contains(fn ($a) => (bool) $a->passed)) {
            return response()->json([
                'status' => false,
                'message' => 'You have already passed this quiz.',
            ], 422);
        }

        if ($maxAttempts !== null && $used >= $maxAttempts) {
            return response()->json([
                'status' => false,
                'message' => "You have used all {$maxAttempts} attempts for this quiz.",
            ], 422);
        }

        /*
         * Reuse an unfinished attempt rather than opening another.
         *
         * Somebody who closed the tab mid-quiz and came back should not have
         * burned an attempt. The unique key is (tenant, user, paper,
         * attempt_no), so a second insert at the same number would fail anyway
         * - returning the open one is both the correct behaviour and the one
         * the constraint already implies.
         */
        $open = $attempts->firstWhere('status', QuizScoringService::STATUS_IN_PROGRESS);

        if ($open) {
            return response()->json([
                'status' => true,
                'message' => 'Resuming your attempt.',
                'data' => [
                    'attempt_id' => (int) $open->id,
                    'attempt_no' => (int) $open->attempt_no,
                    'started_at' => $open->started_at,
                    'time_allowed' => $paper->timelimit_enable ? (int) $paper->time_allowed : null,
                    'passing_score' => $settings->passing_score ?? null,
                    'questions' => $this->quiz->questionsFor($paper),
                ],
            ]);
        }

        $now = now();

        $attemptId = DB::table('lms_quiz_attempt')->insertGetId([
            'sub_institute_id' => $subInstituteId,
            'course_id' => $courseId,
            'paper_id' => $paper->id,
            'user_id' => $userId,
            'attempt_no' => ($attempts->max('attempt_no') ?? 0) + 1,
            'status' => QuizScoringService::STATUS_IN_PROGRESS,
            'passing_score' => $settings->passing_score ?? null,
            'started_at' => $now,
            'created_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Quiz started.',
            'data' => [
                'attempt_id' => $attemptId,
                'attempt_no' => ($attempts->max('attempt_no') ?? 0) + 1,
                'started_at' => $now->toDateTimeString(),
                'time_allowed' => $paper->timelimit_enable ? (int) $paper->time_allowed : null,
                'passing_score' => $settings->passing_score ?? null,
                'questions' => $this->quiz->questionsFor($paper),
            ],
        ], 201);
    }

    /**
     * POST /api/lms/learning/quiz/{attemptId}/submit
     *
     * Mark the attempt, and — if it passed — move the competency rating and the
     * course's measured effectiveness.
     */
    public function submit(Request $request, $attemptId)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }

        $userId = $this->contextUserId($request);
        $subInstituteId = $this->tenantId($request);

        $validator = Validator::make($request->all(), [
            'answers' => 'required|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->messages()->first(),
            ], 422);
        }

        // The caller's OWN attempt, in their own organisation. Somebody else's
        // attempt id must not be submittable, however it was obtained.
        $attempt = DB::table('lms_quiz_attempt')
            ->where('id', $attemptId)
            ->where('user_id', $userId)
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->first();

        if (!$attempt) {
            return response()->json(['status' => false, 'message' => 'Attempt not found'], 404);
        }

        if ($attempt->status === QuizScoringService::STATUS_SUBMITTED) {
            return response()->json([
                'status' => false,
                'message' => 'This attempt has already been submitted.',
            ], 422);
        }

        $paper = DB::table('question_paper')->where('id', $attempt->paper_id)->first();

        if (!$paper) {
            return response()->json(['status' => false, 'message' => 'Quiz paper not found'], 404);
        }

        /*
         * `answers` is question_id => chosen answer_id (or an array of them, or
         * text). Keys are normalised to integers because a JSON object always
         * delivers them as strings, and the scorer looks them up by question id.
         */
        $answers = [];

        foreach ((array) $request->input('answers', []) as $questionId => $given) {
            $answers[(int) $questionId] = $given;
        }

        $result = $this->quiz->score((int) $attempt->id, $paper, $answers);

        $passingScore = $attempt->passing_score !== null ? (int) $attempt->passing_score : null;

        /*
         * A quiz with no passing score set is passed by completing it.
         *
         * The alternative - refusing to decide - would leave the certificate
         * permanently unreachable on every course whose author never opened the
         * Assessments step, which is most of them.
         */
        $passed = $passingScore === null
            ? true
            : $result['percent'] >= $passingScore;

        DB::table('lms_quiz_attempt')->where('id', $attempt->id)->update([
            'status' => QuizScoringService::STATUS_SUBMITTED,
            'score' => $result['score'],
            'max_score' => $result['max_score'],
            'percent' => $result['percent'],
            'passed' => $passed ? 1 : 0,
            'questions' => $result['questions'],
            'awaiting_review' => $result['awaiting_review'],
            'submitted_at' => now(),
            'updated_by' => $userId,
            'updated_at' => now(),
        ]);

        $saved = DB::table('lms_quiz_attempt')->where('id', $attempt->id)->first();

        // The competency rating and the course's effectiveness both follow from
        // the saved attempt, never from the in-flight figures.
        $ratings = $this->quiz->proposeRatings($saved, $subInstituteId, $userId);
        $this->quiz->recordEffectiveness($saved, $subInstituteId);

        return response()->json([
            'status' => true,
            'message' => $passed
                ? 'Quiz passed.'
                : 'Quiz submitted. You did not reach the pass mark this time.',
            'data' => [
                'attempt_id' => (int) $attempt->id,
                'score' => (float) $result['score'],
                'max_score' => (float) $result['max_score'],
                'percent' => (float) $result['percent'],
                'passing_score' => $passingScore,
                'passed' => $passed,
                'questions' => $result['questions'],
                // Non-zero means a model could not mark some written answers.
                // They are unscored, NOT zero, and a human can still mark them.
                'awaiting_review' => $result['awaiting_review'],
                'ratings_proposed' => $ratings['proposed'],
                'ratings_applied' => $ratings['applied'],
                // So the learner is told their record changed, rather than
                // discovering it later on a competency screen.
                'certificate_available' => $passed,
            ],
        ]);
    }

    /**
     * GET /api/lms/learning/quiz/{attemptId}
     *
     * A submitted attempt with its per-question outcome, for the results screen.
     */
    public function result(Request $request, $attemptId)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }

        $userId = $this->contextUserId($request);
        $subInstituteId = $this->tenantId($request);

        $attempt = DB::table('lms_quiz_attempt')
            ->where('id', $attemptId)
            ->where('user_id', $userId)
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->first();

        if (!$attempt) {
            return response()->json(['status' => false, 'message' => 'Attempt not found'], 404);
        }

        $paper = DB::table('question_paper')->where('id', $attempt->paper_id)->first();

        /*
         * Whether the correct answers are shown is the paper author's choice.
         *
         * `result_show_ans` exists on question_paper for exactly this, and a
         * course that allows retries usually should NOT reveal them - otherwise
         * attempt two is a transcription exercise.
         */
        $showAnswers = $paper && (int) $paper->result_show_ans === 1;

        $responses = DB::table('lms_quiz_response as r')
            ->leftJoin('lms_question_master as q', 'q.id', '=', 'r.question_id')
            ->where('r.attempt_id', $attempt->id)
            ->get([
                'r.question_id', 'r.answer_id', 'r.narrative', 'r.is_correct',
                'r.score', 'r.max_score', 'r.ai_marked', 'r.feedback',
                'q.question_title',
            ]);

        if ($showAnswers) {
            $correct = DB::table('answer_master')
                ->whereIn('question_id', $responses->pluck('question_id'))
                ->where('correct_answer', 1)
                ->whereNull('deleted_at')
                ->get(['question_id', 'answer'])
                ->groupBy('question_id');

            $responses->transform(function ($row) use ($correct) {
                $row->correct_answer = ($correct[$row->question_id] ?? collect())
                    ->pluck('answer')->implode(', ');

                return $row;
            });
        }

        return response()->json([
            'status' => true,
            'data' => [
                'attempt' => $attempt,
                'show_answers' => $showAnswers,
                'responses' => $responses,
            ],
        ]);
    }

    /* ─── Helpers ───────────────────────────────────────────────────────── */

    /**
     * Are the lessons finished?
     *
     * Lessons only, deliberately — NOT the combined lesson-and-session figure
     * the certificate uses. A session is often scheduled after the course
     * material is done, and making the quiz wait for it would leave the learner
     * unable to finish the course until a date somebody else picked.
     *
     * A course with no lessons at all is open: there is nothing to finish.
     *
     * @return array{open:bool, total:int, done:int}
     */
    private function lessonGate($userId, $courseId): array
    {
        $total = DB::table('content_master')
            ->where('subject_id', $courseId)
            ->whereNull('deleted_at')
            ->count();

        $done = DB::table('lms_content_progress')
            ->where('user_id', $userId)
            ->where('course_id', $courseId)
            ->where('status', 'completed')
            ->whereNull('deleted_at')
            ->count();

        return ['open' => $total === 0 || $done >= $total, 'total' => $total, 'done' => $done];
    }

    /** This learner's attempts at one paper, newest first. */
    private function attemptsFor($userId, $paperId, $subInstituteId)
    {
        return DB::table('lms_quiz_attempt')
            ->where('user_id', $userId)
            ->where('paper_id', $paperId)
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->orderByDesc('attempt_no')
            ->get([
                'id', 'attempt_no', 'status', 'score', 'max_score', 'percent',
                'passed', 'awaiting_review', 'started_at', 'submitted_at',
            ]);
    }
}
