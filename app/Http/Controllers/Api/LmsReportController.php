<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ResolvesLmsIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The three LMS reports.
 *
 * ── WHY THESE EXIST NOW AND NOT BEFORE ──────────────────────────────────────
 *
 * `tblmenumaster_g2g` has carried three LMS report rows since it was written —
 * Employee Analysis (151), Quiz Progress (152), Question-wise (153) — under a
 * parent (125) whose `status` is 0, with no permission rows and no screen. Menu
 * entries pointing at nothing.
 *
 * They were unbuildable until now for a plain reason: two of the three had no
 * data to report on. `lms_quiz_attempt` and `lms_quiz_response` did not exist,
 * and quiz authoring did not either, so "Quiz Progress" and "Question-wise"
 * would have been three empty tables. That changed with the quiz work.
 *
 * ── EVERY FIGURE IS DERIVED ─────────────────────────────────────────────────
 *
 * Nothing here is stored or cached. A report that disagrees with the screen it
 * summarises is worse than no report, and the only way to guarantee they agree
 * is to compute from the same rows the screens read.
 */
class LmsReportController extends Controller
{
    use ResolvesLmsIdentity;

    /** Reports describe other people, so they are an administrative surface. */
    private const ADMIN_PROFILES = ['admin', 'hr'];

    private function guardApiToken(Request $request)
    {
        return $this->guardLmsToken($request);
    }

    private function guardAdmin(Request $request)
    {
        return $this->guardLmsProfile(
            $request,
            self::ADMIN_PROFILES,
            'Your profile is not permitted to view LMS reports.'
        );
    }

    private function tenantId(Request $request)
    {
        return $this->lmsTenantId($request);
    }

    /**
     * GET /api/lms/reports/employee-analysis
     *
     * One row per learner: what they have been given, what they have finished,
     * and when they were last seen learning.
     */
    public function employeeAnalysis(Request $request)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }
        if ($roleError = $this->guardAdmin($request)) {
            return $roleError;
        }

        $tenant = $this->tenantId($request);

        try {
            /*
             * Enrolments per learner, and how many are finished.
             *
             * Grouped in the database rather than pulled and counted in PHP:
             * a tenant with 1,498 enrolments would otherwise ship all of them
             * to build a handful of rows.
             */
            $enrolments = DB::table('lms_course_enroll')
                ->where('sub_institute_id', $tenant)
                ->whereNull('deleted_at')
                ->selectRaw('user_id,
                    COUNT(*) as enrolled,
                    SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN status = "in-progress" THEN 1 ELSE 0 END) as in_progress,
                    SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END) as pending')
                ->groupBy('user_id')
                ->get()
                ->keyBy('user_id');

            if ($enrolments->isEmpty()) {
                return response()->json(['status' => true, 'data' => [], 'meta' => $this->emptyMeta()]);
            }

            $userIds = $enrolments->keys()->all();

            $lessons = DB::table('lms_content_progress')
                ->where('sub_institute_id', $tenant)
                ->whereIn('user_id', $userIds)
                ->whereNull('deleted_at')
                ->selectRaw('user_id,
                    SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as lessons_done,
                    SUM(time_spent_seconds) as seconds,
                    MAX(COALESCE(updated_at, created_at)) as last_activity')
                ->groupBy('user_id')
                ->get()
                ->keyBy('user_id');

            $quizzes = DB::table('lms_quiz_attempt')
                ->where('sub_institute_id', $tenant)
                ->whereIn('user_id', $userIds)
                ->where('status', 'submitted')
                ->whereNull('deleted_at')
                ->selectRaw('user_id, COUNT(*) as attempts,
                    SUM(CASE WHEN passed = 1 THEN 1 ELSE 0 END) as passed,
                    AVG(percent) as mean_percent')
                ->groupBy('user_id')
                ->get()
                ->keyBy('user_id');

            $certificates = DB::table('lms_certificates')
                ->where('sub_institute_id', $tenant)
                ->whereIn('user_id', $userIds)
                ->whereNull('deleted_at')
                ->selectRaw('user_id, COUNT(*) as issued')
                ->groupBy('user_id')
                ->get()
                ->keyBy('user_id');

            $people = DB::table('tbluser as u')
                ->leftJoin('hrms_departments as d', 'd.id', '=', 'u.department_id')
                ->whereIn('u.id', $userIds)
                ->get([
                    'u.id', 'u.employee_no', 'u.email', 'd.department',
                    DB::raw("TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) as learner_name"),
                ])
                ->keyBy('id');

            $rows = collect($userIds)->map(function ($userId) use (
                $enrolments, $lessons, $quizzes, $certificates, $people
            ) {
                $e = $enrolments[$userId];
                $l = $lessons[$userId] ?? null;
                $q = $quizzes[$userId] ?? null;
                $person = $people[$userId] ?? null;

                $enrolled = (int) $e->enrolled;
                $completed = (int) $e->completed;

                return [
                    'user_id' => (int) $userId,
                    'learner_name' => $person->learner_name ?? ('User #' . $userId),
                    'employee_no' => $person->employee_no ?? null,
                    'department' => $person->department ?? null,
                    'enrolled' => $enrolled,
                    'completed' => $completed,
                    'in_progress' => (int) $e->in_progress,
                    'pending' => (int) $e->pending,
                    'completion_rate' => $enrolled > 0 ? (int) round($completed / $enrolled * 100) : 0,
                    'lessons_completed' => (int) ($l->lessons_done ?? 0),
                    // Hours to one decimal: a 20-minute session is 0.3h, and
                    // reporting it as 0 makes a working learner look idle.
                    'hours_spent' => round((int) ($l->seconds ?? 0) / 3600, 1),
                    'quiz_attempts' => (int) ($q->attempts ?? 0),
                    'quizzes_passed' => (int) ($q->passed ?? 0),
                    'mean_quiz_percent' => $q && $q->mean_percent !== null
                        ? round((float) $q->mean_percent, 1)
                        : null,
                    'certificates' => (int) ($certificates[$userId]->issued ?? 0),
                    'last_activity' => $l->last_activity ?? null,
                ];
            })
            ->sortByDesc('enrolled')
            ->values();

            return response()->json([
                'status' => true,
                'data' => $rows,
                'meta' => [
                    'learners' => $rows->count(),
                    'enrolled' => (int) $rows->sum('enrolled'),
                    'completed' => (int) $rows->sum('completed'),
                    'hours_spent' => round((float) $rows->sum('hours_spent'), 1),
                    'certificates' => (int) $rows->sum('certificates'),
                ],
            ]);
        } catch (\Exception $e) {
            return $this->fail($e, 'Failed to build the employee analysis report');
        }
    }

    /**
     * GET /api/lms/reports/quiz-progress
     *
     * One row per quiz: who sat it, how they did, and whether it is doing its
     * job. A quiz nobody passes is either too hard or teaching the wrong thing.
     */
    public function quizProgress(Request $request)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }
        if ($roleError = $this->guardAdmin($request)) {
            return $roleError;
        }

        $tenant = $this->tenantId($request);

        try {
            $papers = DB::table('question_paper as p')
                ->leftJoin('sub_std_map as c', 'c.id', '=', 'p.subject_id')
                ->where('p.sub_institute_id', $tenant)
                ->whereNull('p.deleted_at')
                ->get([
                    'p.id', 'p.paper_name', 'p.total_ques', 'p.total_marks',
                    'p.subject_id as course_id', 'c.display_name as course_name',
                ]);

            if ($papers->isEmpty()) {
                return response()->json(['status' => true, 'data' => [], 'meta' => $this->emptyMeta()]);
            }

            $stats = DB::table('lms_quiz_attempt')
                ->where('sub_institute_id', $tenant)
                ->whereIn('paper_id', $papers->pluck('id'))
                ->where('status', 'submitted')
                ->whereNull('deleted_at')
                ->selectRaw('paper_id,
                    COUNT(*) as attempts,
                    COUNT(DISTINCT user_id) as learners,
                    SUM(CASE WHEN passed = 1 THEN 1 ELSE 0 END) as passes,
                    AVG(percent) as mean_percent,
                    MAX(percent) as best_percent,
                    MIN(percent) as worst_percent,
                    SUM(awaiting_review) as awaiting_review,
                    MAX(submitted_at) as last_attempt')
                ->groupBy('paper_id')
                ->get()
                ->keyBy('paper_id');

            $settings = DB::table('lms_course_settings')
                ->whereIn('course_id', $papers->pluck('course_id')->filter())
                ->get(['course_id', 'passing_score'])
                ->keyBy('course_id');

            $rows = $papers->map(function ($paper) use ($stats, $settings) {
                $s = $stats[$paper->id] ?? null;
                $attempts = (int) ($s->attempts ?? 0);
                $passes = (int) ($s->passes ?? 0);

                return [
                    'paper_id' => (int) $paper->id,
                    'paper_name' => $paper->paper_name,
                    'course_id' => $paper->course_id ? (int) $paper->course_id : null,
                    'course_name' => $paper->course_name,
                    'questions' => (int) $paper->total_ques,
                    'total_marks' => (int) $paper->total_marks,
                    'passing_score' => $paper->course_id && isset($settings[$paper->course_id])
                        ? $settings[$paper->course_id]->passing_score
                        : null,
                    'attempts' => $attempts,
                    'learners' => (int) ($s->learners ?? 0),
                    'passes' => $passes,
                    'pass_rate' => $attempts > 0 ? (int) round($passes / $attempts * 100) : null,
                    'mean_percent' => $s && $s->mean_percent !== null ? round((float) $s->mean_percent, 1) : null,
                    'best_percent' => $s && $s->best_percent !== null ? round((float) $s->best_percent, 1) : null,
                    'worst_percent' => $s && $s->worst_percent !== null ? round((float) $s->worst_percent, 1) : null,
                    'awaiting_review' => (int) ($s->awaiting_review ?? 0),
                    'last_attempt' => $s->last_attempt ?? null,
                    /*
                     * A quiz with no questions cannot be sat, which is a
                     * DIFFERENT problem from one nobody has attempted. Said
                     * plainly here because it was the state of every quiz this
                     * product could author until recently.
                     */
                    'unusable' => (int) $paper->total_ques === 0,
                ];
            })
            ->sortByDesc('attempts')
            ->values();

            return response()->json([
                'status' => true,
                'data' => $rows,
                'meta' => [
                    'papers' => $rows->count(),
                    'unusable' => $rows->where('unusable', true)->count(),
                    'attempts' => (int) $rows->sum('attempts'),
                    'passes' => (int) $rows->sum('passes'),
                    'awaiting_review' => (int) $rows->sum('awaiting_review'),
                ],
            ]);
        } catch (\Exception $e) {
            return $this->fail($e, 'Failed to build the quiz progress report');
        }
    }

    /**
     * GET /api/lms/reports/question-wise?paper_id=
     *
     * One row per question: how often it was answered and how often correctly.
     *
     * This is the report that improves a quiz. A question everybody gets right
     * measures nothing; one almost everybody gets wrong is usually badly worded
     * rather than hard, and either way the author needs to see which.
     */
    public function questionWise(Request $request)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }
        if ($roleError = $this->guardAdmin($request)) {
            return $roleError;
        }

        $tenant = $this->tenantId($request);
        $paperId = $request->input('paper_id');

        try {
            $paperQuery = DB::table('question_paper')
                ->where('sub_institute_id', $tenant)
                ->whereNull('deleted_at');

            if ($paperId) {
                $paperQuery->where('id', $paperId);
            }

            $papers = $paperQuery->get(['id', 'paper_name', 'question_ids']);

            if ($papers->isEmpty()) {
                return response()->json(['status' => true, 'data' => [], 'meta' => $this->emptyMeta()]);
            }

            $questionIds = $papers->flatMap(fn ($p) => collect(explode(',', (string) $p->question_ids))
                ->map(fn ($id) => (int) trim($id))->filter())->unique()->values();

            if ($questionIds->isEmpty()) {
                return response()->json([
                    'status' => true,
                    'data' => [],
                    'meta' => $this->emptyMeta() + ['note' => 'These papers have no questions.'],
                ]);
            }

            $questions = DB::table('lms_question_master')
                ->whereIn('id', $questionIds)
                ->whereNull('deleted_at')
                ->get(['id', 'question_title', 'points'])
                ->keyBy('id');

            /*
             * Responses are scoped through the ATTEMPT, not directly.
             *
             * lms_quiz_response carries no tenant column - it hangs off the
             * attempt, which does. Joining through it is what keeps one
             * organisation's answers out of another's report.
             */
            $responses = DB::table('lms_quiz_response as r')
                ->join('lms_quiz_attempt as a', 'a.id', '=', 'r.attempt_id')
                ->where('a.sub_institute_id', $tenant)
                ->where('a.status', 'submitted')
                ->whereNull('a.deleted_at')
                ->whereIn('r.question_id', $questionIds)
                ->when($paperId, fn ($q) => $q->where('a.paper_id', $paperId))
                ->selectRaw('r.question_id,
                    COUNT(*) as answered,
                    SUM(CASE WHEN r.is_correct = 1 THEN 1 ELSE 0 END) as correct,
                    SUM(CASE WHEN r.is_correct IS NULL THEN 1 ELSE 0 END) as unmarked,
                    AVG(r.score) as mean_score')
                ->groupBy('r.question_id')
                ->get()
                ->keyBy('question_id');

            $paperOf = [];
            foreach ($papers as $paper) {
                foreach (explode(',', (string) $paper->question_ids) as $id) {
                    $id = (int) trim($id);
                    if ($id) {
                        $paperOf[$id] = $paper;
                    }
                }
            }

            $rows = $questionIds->map(function ($id) use ($questions, $responses, $paperOf) {
                $question = $questions[$id] ?? null;
                if (!$question) {
                    return null;
                }

                $r = $responses[$id] ?? null;
                $answered = (int) ($r->answered ?? 0);
                $correct = (int) ($r->correct ?? 0);
                // Unmarked answers are excluded from the rate rather than
                // counted wrong: nobody has decided them yet.
                $marked = $answered - (int) ($r->unmarked ?? 0);

                return [
                    'question_id' => (int) $id,
                    'question_title' => $question->question_title,
                    'points' => (int) ($question->points ?: 1),
                    'paper_id' => (int) ($paperOf[$id]->id ?? 0),
                    'paper_name' => $paperOf[$id]->paper_name ?? null,
                    'answered' => $answered,
                    'correct' => $correct,
                    'unmarked' => (int) ($r->unmarked ?? 0),
                    'correct_rate' => $marked > 0 ? (int) round($correct / $marked * 100) : null,
                    'mean_score' => $r && $r->mean_score !== null ? round((float) $r->mean_score, 2) : null,
                ];
            })
            ->filter()
            ->values();

            $withData = $rows->whereNotNull('correct_rate');

            return response()->json([
                'status' => true,
                'data' => $rows,
                'meta' => [
                    'questions' => $rows->count(),
                    'answered' => (int) $rows->sum('answered'),
                    'unanswered_questions' => $rows->where('answered', 0)->count(),
                    // The two ends worth an author's attention.
                    'too_easy' => $withData->where('correct_rate', '>=', 95)->count(),
                    'too_hard' => $withData->where('correct_rate', '<=', 20)->count(),
                ],
            ]);
        } catch (\Exception $e) {
            return $this->fail($e, 'Failed to build the question-wise report');
        }
    }

    /** The shape an empty report returns, so the client never special-cases null. */
    private function emptyMeta(): array
    {
        return ['learners' => 0, 'papers' => 0, 'questions' => 0, 'attempts' => 0];
    }

    /**
     * The shared failure response.
     *
     * Defined here rather than assumed: LmsGovernanceController called an
     * identical `$this->fail()` in thirteen catch blocks with no definition
     * anywhere, so every one of its error paths raised a PHP fatal instead of
     * returning JSON. Not repeating that.
     */
    private function fail(\Throwable $e, string $message)
    {
        \Illuminate\Support\Facades\Log::error('LMS report: ' . $message, [
            'exception' => $e->getMessage(),
            'file' => $e->getFile() . ':' . $e->getLine(),
        ]);

        return response()->json([
            'status' => false,
            'message' => $message,
            'error' => $e->getMessage(),
        ], 500);
    }
}
