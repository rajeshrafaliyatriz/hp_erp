<?php

namespace App\Http\Controllers\Api\Competency;

use App\Http\Controllers\Api\Competency\Concerns\ResolvesCompetencyContext;
use App\Http\Controllers\Controller;
use App\Services\Competency\AssessmentScoringService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * The administrator's half of an assessment: read it, assign it, mark it,
 * decide what it means.
 *
 * AiAssessmentController is the TAKER's side - generate, publish, serve to me,
 * accept my answers. Everything here is about somebody else's test, which is
 * why it is a separate class with a different guard: every route is
 * profile:admin,hr, and every one of them takes a subject.
 *
 * ── THE THREE THINGS THAT COULD NOT BE DONE BEFORE ──────────────────────────
 *
 * 1. READ A DRAFT. publish() existed and the UI told HR to "read the questions
 *    before publishing", but nothing could fetch them - not one endpoint
 *    returned a question with its answer. HR was asked to approve content it
 *    was structurally unable to see.
 *
 * 2. MARK A WRITTEN ANSWER. Short answers were stored with score = NULL and
 *    scored_by = NULL, and `scored_by = 'manual'` was a value no code could
 *    ever write. An answer could be given and never marked by anyone, ever.
 *
 * 3. TURN A RESULT INTO A RATING. The taker's controller is explicit that
 *    submitting must not move proficiency - "only on explicit confirmation
 *    elsewhere". This is elsewhere.
 */
class AssessmentReviewController extends Controller
{
    use ResolvesCompetencyContext;

    public function __construct(private readonly AssessmentScoringService $scoring)
    {
    }

    /**
     * GET /competency/ai-assessment/tests
     *
     * Every test in the tenant, with how many questions it holds and how many
     * people have sat it. Nothing listed tests before, so a generated draft was
     * findable only by remembering the id the generate call returned.
     */
    public function index(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = (int) $context['sub_institute_id'];

        $tests = DB::table('competency_assessment_test as t')
            ->leftJoin('s_user_jobrole as r', 'r.id', '=', 't.jobrole_id')
            ->leftJoin('competency as c', 'c.id', '=', 't.competency_id')
            ->where('t.sub_institute_id', $sid)
            ->whereNull('t.deleted_at')
            ->when($request->filled('status'), fn ($q) => $q->where('t.status', $request->input('status')))
            ->orderByDesc('t.id')
            ->get([
                't.id', 't.title', 't.status', 't.scope_type', 't.model', 't.published_at',
                't.time_limit_minutes', 't.pass_percent', 't.is_open', 't.created_at',
                'r.jobrole as jobrole', 'c.name as competency_name',
            ]);

        if ($tests->isNotEmpty()) {
            $ids = $tests->pluck('id')->all();

            $questions = DB::table('competency_assessment_question')
                ->whereIn('test_id', $ids)->selectRaw('test_id, COUNT(*) n')
                ->groupBy('test_id')->pluck('n', 'test_id');

            $attempts = DB::table('competency_assessment_attempt')
                ->whereIn('test_id', $ids)
                ->selectRaw('test_id, COUNT(*) n, SUM(submitted_at IS NOT NULL) done, SUM(awaiting_review) waiting')
                ->groupBy('test_id')->get()->keyBy('test_id');

            $tests->each(function ($t) use ($questions, $attempts) {
                $a = $attempts->get($t->id);
                $t->questions       = (int) ($questions[$t->id] ?? 0);
                $t->assigned        = (int) ($a->n ?? 0);
                $t->submitted       = (int) ($a->done ?? 0);
                $t->awaiting_review = (int) ($a->waiting ?? 0);
            });
        }

        return response()->json([
            'status' => 1,
            'data'   => $tests,
            'empty_is_expected' => $tests->isEmpty(),
            'empty_reason' => 'No assessments have been generated yet.',
        ]);
    }

    /**
     * GET /competency/ai-assessment/tests/{id}
     *
     * The questions, WITH their correct answers and model answers.
     *
     * This is the one place in the system that returns them, and it is
     * admin/hr only. The whole point of the draft/publish split is that a
     * person reads what an LLM wrote before an employee is assessed on it, and
     * that is impossible without seeing the answer it marks against.
     */
    public function show(Request $request, int $id)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = (int) $context['sub_institute_id'];

        $test = DB::table('competency_assessment_test as t')
            ->leftJoin('s_user_jobrole as r', 'r.id', '=', 't.jobrole_id')
            ->where('t.id', $id)->where('t.sub_institute_id', $sid)->whereNull('t.deleted_at')
            ->first(['t.*', 'r.jobrole as jobrole']);

        if (!$test) {
            return response()->json(['status' => 0, 'message' => 'Assessment not found.'], 404);
        }

        $questions = DB::table('competency_assessment_question')
            ->where('test_id', $id)->orderBy('sort_order')
            ->get(['id', 'format', 'question_text', 'options', 'correct_option', 'model_answer',
                   'max_score', 'sort_order', 'cited_item_label', 'cited_kasba_type',
                   'cited_competency_name', 'cited_required_proficiency'])
            ->map(function ($q) {
                $q->options = $q->options ? json_decode($q->options, true) : null;
                return $q;
            });

        return response()->json([
            'status' => 1,
            'data'   => ['test' => $test, 'questions' => $questions],
        ]);
    }

    /**
     * POST /competency/ai-assessment/tests/{id}/assign
     *
     * Give named people a sitting, with an optional due date.
     *
     * Assigning does NOT check job role. A published test aimed at one role can
     * still be handed to somebody outside it deliberately - for a secondment,
     * a promotion, a cross-check - and refusing that would be the system
     * overruling the person who chose it. mayTake() honours the assignment for
     * exactly this reason.
     */
    public function assign(Request $request, int $id)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            'user_ids'   => 'required|array|min:1',
            'user_ids.*' => 'integer',
            'due_date'   => 'nullable|date',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $sid   = (int) $context['sub_institute_id'];
        $actor = (int) $context['user_id'];

        $test = DB::table('competency_assessment_test')
            ->where('id', $id)->where('sub_institute_id', $sid)->whereNull('deleted_at')->first();

        if (!$test) {
            return response()->json(['status' => 0, 'message' => 'Assessment not found.'], 404);
        }
        if ($test->status !== 'published') {
            return response()->json([
                'status' => 0,
                'message' => 'Publish this assessment before assigning it. An unpublished test cannot be opened by the person it is given to.',
            ], 422);
        }

        // Only people who belong to this organisation.
        $users = DB::table('tbluser')->where('sub_institute_id', $sid)
            ->whereIn('id', $request->input('user_ids'))->pluck('id');

        $assigned = 0; $already = 0;

        foreach ($users as $userId) {
            $exists = DB::table('competency_assessment_attempt')
                ->where('test_id', $id)->where('user_id', $userId)->exists();

            if ($exists) {
                // Re-assigning only ever moves the due date. It never resets a
                // sitting - that would silently destroy answers already given.
                if ($request->filled('due_date')) {
                    DB::table('competency_assessment_attempt')
                        ->where('test_id', $id)->where('user_id', $userId)
                        ->update(['due_date' => $request->input('due_date'), 'updated_at' => now()]);
                }
                $already++;
                continue;
            }

            DB::table('competency_assessment_attempt')->insert([
                'sub_institute_id' => $sid,
                'test_id'          => $id,
                'user_id'          => $userId,
                'assigned_by'      => $actor,
                'due_date'         => $request->input('due_date'),
                'status'           => 'assigned',
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);
            $assigned++;
        }

        $missing = count($request->input('user_ids')) - $users->count();

        return response()->json([
            'status' => 1,
            'data'   => ['assigned' => $assigned, 'already_assigned' => $already, 'not_in_tenant' => $missing],
            'message' => sprintf(
                '%d person(s) assigned.%s%s',
                $assigned,
                $already ? " $already already had it." : '',
                $missing ? " $missing id(s) are not in this organisation and were skipped." : ''
            ),
        ]);
    }

    /**
     * GET /competency/ai-assessment/attempts
     *
     * Who has sat what, and where each one stands. The results view for HR.
     */
    public function attempts(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = (int) $context['sub_institute_id'];

        $rows = DB::table('competency_assessment_attempt as a')
            ->join('competency_assessment_test as t', 't.id', '=', 'a.test_id')
            ->leftJoin('tbluser as u', 'u.id', '=', 'a.user_id')
            ->where('a.sub_institute_id', $sid)
            ->when($request->filled('test_id'), fn ($q) => $q->where('a.test_id', $request->integer('test_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('a.status', $request->input('status')))
            ->orderByDesc('a.submitted_at')->orderByDesc('a.id')
            ->limit(500)
            ->get([
                'a.id', 'a.test_id', 'a.user_id', 'a.due_date', 'a.started_at', 'a.submitted_at',
                'a.total_score', 'a.max_score', 'a.percent', 'a.awaiting_review', 'a.status',
                't.title', 't.pass_percent',
                DB::raw("TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) as employee"),
            ]);

        return response()->json([
            'status' => 1,
            'data'   => $rows,
            'empty_is_expected' => $rows->isEmpty(),
            'empty_reason' => 'Nobody has been assigned an assessment yet.',
        ]);
    }

    /**
     * GET /competency/ai-assessment/attempts/{id}/answers
     *
     * Everything one person wrote, with the reference answer beside it, so a
     * written answer can actually be marked.
     */
    public function answers(Request $request, int $id)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = (int) $context['sub_institute_id'];

        $attempt = DB::table('competency_assessment_attempt')
            ->where('id', $id)->where('sub_institute_id', $sid)->first();

        if (!$attempt) {
            return response()->json(['status' => 0, 'message' => 'Attempt not found.'], 404);
        }

        $answers = DB::table('competency_assessment_question as q')
            ->leftJoin('competency_assessment_response as r', function ($j) use ($attempt) {
                $j->on('r.question_id', '=', 'q.id')->where('r.user_id', '=', $attempt->user_id);
            })
            ->where('q.test_id', $attempt->test_id)->orderBy('q.sort_order')
            ->get(['q.id as question_id', 'q.format', 'q.question_text', 'q.options', 'q.correct_option',
                   'q.model_answer', 'q.max_score', 'q.cited_item_label', 'q.cited_kasba_type',
                   'r.id as response_id', 'r.answer_text', 'r.selected_option', 'r.score',
                   'r.scored_by', 'r.answered_at'])
            ->map(function ($a) {
                $a->options = $a->options ? json_decode($a->options, true) : null;
                return $a;
            });

        return response()->json([
            'status' => 1,
            'data'   => ['attempt' => $attempt, 'answers' => $answers],
        ]);
    }

    /**
     * POST /competency/ai-assessment/responses/{id}/score
     *
     * Mark one answer by hand. Writes scored_by = 'manual' - a value the schema
     * has always allowed and no code has ever produced.
     *
     * A human mark OVERRIDES an AI one and says so in the record, which is the
     * whole reason scored_by exists rather than a boolean.
     */
    public function scoreAnswer(Request $request, int $id, AssessmentScoringService $scoring)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), ['score' => 'required|numeric|min:0']);
        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $sid = (int) $context['sub_institute_id'];

        $row = DB::table('competency_assessment_response as r')
            ->join('competency_assessment_question as q', 'q.id', '=', 'r.question_id')
            ->where('r.id', $id)->where('r.sub_institute_id', $sid)
            ->first(['r.id', 'r.test_id', 'r.user_id', 'q.max_score']);

        if (!$row) {
            return response()->json(['status' => 0, 'message' => 'Answer not found.'], 404);
        }

        $score = (float) $request->input('score');
        if ($score > (float) $row->max_score) {
            return response()->json([
                'status' => 0,
                'message' => "That is more than the question is worth ({$row->max_score}).",
            ], 422);
        }

        DB::table('competency_assessment_response')->where('id', $id)->update([
            'score' => $score, 'scored_by' => 'manual', 'updated_at' => now(),
        ]);

        // The totals and the proposals both change when a mark changes, so they
        // are recomputed rather than left to drift.
        $attemptId = DB::table('competency_assessment_attempt')
            ->where('test_id', $row->test_id)->where('user_id', $row->user_id)->value('id');

        $totals = $attemptId ? $scoring->finalise((int) $attemptId, $sid) : null;

        return response()->json([
            'status' => 1,
            'data'   => ['score' => $score, 'totals' => $totals],
            'message' => 'Answer marked. The result and any rating proposals have been recalculated.',
        ]);
    }

    /**
     * GET /competency/ai-assessment/proposals
     *
     * What assessment results are SUGGESTING about people, awaiting a decision.
     */
    public function proposals(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = (int) $context['sub_institute_id'];

        $rows = DB::table('competency_assessment_rating_proposal as p')
            ->leftJoin('tbluser as u', 'u.id', '=', 'p.user_id')
            ->leftJoin('competency as c', 'c.id', '=', 'p.competency_id')
            ->leftJoin('competency_assessment_test as t', 't.id', '=', 'p.test_id')
            ->where('p.sub_institute_id', $sid)
            ->where('p.status', $request->input('status', 'pending'))
            ->orderByDesc('p.id')->limit(500)
            ->get([
                'p.id', 'p.user_id', 'p.item_label', 'p.kasba_type', 'p.questions',
                'p.scored_percent', 'p.proposed_rating', 'p.current_rating', 'p.status',
                'p.decided_at', 'c.name as competency_name', 't.title as test_title',
                DB::raw("TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) as employee"),
            ]);

        return response()->json([
            'status' => 1,
            'data'   => $rows,
            // The thresholds that produced every proposed_rating, so a reviewer
            // can see what the number MEANS rather than trusting it.
            'bands'  => AssessmentScoringService::RATING_BANDS,
            'min_questions_to_propose' => AssessmentScoringService::MIN_QUESTIONS_TO_PROPOSE,
            'empty_is_expected' => $rows->isEmpty(),
            'empty_reason' => 'No assessment result is currently proposing a rating change.',
        ]);
    }

    /**
     * POST /competency/ai-assessment/proposals/{id}/decide
     *
     * Approve and the rating is written with source='assessment'; reject and
     * the result stays on record while the rating does not move.
     */
    public function decide(Request $request, int $id)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            'decision' => 'required|string|in:approve,reject',
            'note'     => 'nullable|string|max:1000',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $sid   = (int) $context['sub_institute_id'];
        $actor = (int) $context['user_id'];
        $note  = $request->input('note');

        $result = $request->input('decision') === 'approve'
            ? $this->scoring->approve($id, $sid, $actor, $note)
            : $this->scoring->reject($id, $sid, $actor, $note);

        return response()->json([
            'status'  => $result['ok'] ? 1 : 0,
            'message' => $result['message'],
        ], $result['ok'] ? 200 : 422);
    }
}
