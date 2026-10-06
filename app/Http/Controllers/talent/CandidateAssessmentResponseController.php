<?php

namespace App\Http\Controllers\talent;

use App\Http\Controllers\Controller;
use App\Services\Competency\AssessmentScoringService;
use App\Services\Talent\CandidateAssessmentService;
use App\Services\Talent\RecruitmentAssessmentGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * A candidate sitting their own assessment. Public, unauthenticated, one test wide.
 *
 * ── HOW IDENTITY WORKS HERE ─────────────────────────────────────────────────
 *
 * Identical in shape to OfferResponseController, deliberately: a candidate is
 * not a user of this product, and giving them a login means a second identity
 * model, registration, password reset and lockout - all before they can answer
 * one question. The 64-character link IS the authorisation. Its sha256 is the
 * unique key of exactly one assessment row; it opens that row and nothing else.
 *
 * The tenant comes from the assessment row, NEVER from the request.
 *
 * ── WHAT THE CANDIDATE MUST NOT BE ABLE TO SEE ──────────────────────────────
 *
 * `competency_assessment_question` holds `correct_option` and `model_answer` in
 * the same row as the question text. Selecting the row and handing it back would
 * ship the answer key to the person being tested, over an unauthenticated
 * endpoint, in the payload of the page they are sitting.
 *
 * So the projection here is explicit and additive: every field is named, and
 * neither of those two is among them. There is no `select('*')` anywhere in this
 * class, and there must never be one - a later column would join the payload
 * silently.
 *
 * ── WHEN THE TOKEN BURNS ────────────────────────────────────────────────────
 *
 * On FINAL SUBMIT, not on first open. A sitting is resumed - a candidate closes
 * a laptop lid, loses wifi, or opens the link on their phone - and burning on
 * open would lock them out of a test they had not answered.
 */
class CandidateAssessmentResponseController extends Controller
{
    public function __construct(
        private CandidateAssessmentService $assessments,
        private AssessmentScoringService $scoring,
    ) {
    }

    /**
     * GET /api/candidate-assessment/{token}
     *
     * The paper, plus anything already answered so a resumed sitting is not lost.
     */
    public function show(Request $request, string $token)
    {
        $resolved = $this->assessments->resolve($token);

        if (!$resolved['row']) {
            return $this->gone($resolved['reason']);
        }

        $row = $resolved['row'];
        $sid = (int) $row->sub_institute_id;

        $test = DB::table('competency_assessment_test')
            ->where('id', $row->test_id)->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->first(['id', 'title', 'instructions', 'time_limit_minutes']);

        if (!$test) {
            return $this->gone('not_found');
        }

        // NAMED COLUMNS ONLY. correct_option and model_answer are the answer key.
        $questions = DB::table('competency_assessment_question')
            ->where('test_id', $test->id)->where('sub_institute_id', $sid)
            ->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'format', 'question_text', 'options', 'max_score', 'sort_order']);

        $answers = $row->attempt_id
            ? DB::table('competency_assessment_response')
                ->where('sub_institute_id', $sid)
                ->where('test_id', $test->id)
                ->where('user_id', $row->candidate_id)
                ->where('subject_type', 'candidate')
                ->pluck('answer_text', 'question_id')
            : collect();

        $application = DB::table('talent_job_applications')
            ->where('id', $row->application_id)->where('sub_institute_id', $sid)
            ->first(['first_name']);

        $organisation = DB::table('institute_detail')
            ->where('sub_institute_id', $sid)->value('organization_name');

        return response()->json([
            'status' => 1,
            'data' => [
                'organisation'   => $organisation,
                'candidate_name' => $application->first_name ?? null,
                'title'          => $test->title,
                'instructions'   => $test->instructions,
                'time_limit_minutes' => $test->time_limit_minutes,
                'expires_at'     => $row->token_expires_at,
                'started_at'     => $row->status === CandidateAssessmentService::STATUS_INVITED ? null : $row->invited_at,
                'total_marks'    => (float) $questions->sum('max_score'),
                'questions'      => $questions->map(fn ($q) => [
                    'id'        => (int) $q->id,
                    'format'    => $q->format,
                    'question'  => $q->question_text,
                    // Stored as a JSON string in a longtext column, never a json type
                    // (MariaDB 10.1 on live has none). Decoded here so the client
                    // does not have to know that.
                    'options'   => $q->options ? json_decode($q->options, true) : null,
                    'max_score' => (float) $q->max_score,
                    'answer'    => $answers[$q->id] ?? null,
                ])->values(),
            ],
        ], 200);
    }

    /**
     * POST /api/candidate-assessment/{token}/answer
     *
     * Saves ONE answer and does not burn the token. This is what makes a long
     * paper survivable: a dropped connection costs the current question, not
     * the sitting.
     */
    public function saveAnswer(Request $request, string $token)
    {
        $resolved = $this->assessments->resolve($token);

        if (!$resolved['row']) {
            return $this->gone($resolved['reason']);
        }

        /*
         * TWO FIELDS, NOT ONE, matching AiAssessmentController::submit().
         *
         * `selected_option` is the MCQ choice and `answer_text` is prose or code.
         * They are separate columns and the existing engine marks MCQs from
         * `selected_option`, so collapsing them into one field here would have
         * produced answers the shared scorer could not read.
         */
        /*
         * The option limit tracks the COLUMN, via the generator's const.
         *
         * It was hardcoded to 50 and stayed there when
         * 2026_09_04_120000_widen_assessment_option_columns widened the column to
         * 255 - so the API rejected exactly the long options the migration had
         * just made storable, and two of four answers were silently refused mid
         * sitting. A literal that has to be remembered is one that will not be.
         */
        $validator = Validator::make($request->all(), [
            'question_id'     => 'required|integer',
            'answer'          => 'nullable|string|max:20000',
            'selected_option' => 'nullable|string|max:' . RecruitmentAssessmentGenerator::MAX_OPTION_CHARS,
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $row = $resolved['row'];
        $sid = (int) $row->sub_institute_id;

        // The question must belong to THIS token's test. Without this check the
        // question_id is caller-controlled and could write a response row against
        // another tenant's test.
        $question = DB::table('competency_assessment_question')
            ->where('id', $request->integer('question_id'))
            ->where('test_id', $row->test_id)
            ->where('sub_institute_id', $sid)
            ->first(['id', 'format', 'correct_option', 'max_score']);

        if (!$question) {
            return response()->json(['status' => 0, 'message' => 'That question is not part of this assessment.'], 404);
        }

        $attemptId = $this->openAttempt($row, $sid);

        DB::table('competency_assessment_response')->updateOrInsert(
            [
                'question_id'  => $question->id,
                'user_id'      => $row->candidate_id,
                'subject_type' => 'candidate',
            ],
            [
                'sub_institute_id' => $sid,
                'test_id'          => $row->test_id,
                'answer_text'      => $request->input('answer'),
                'selected_option'  => $request->input('selected_option'),
                'answered_at'      => now(),
                'updated_at'       => now(),
                'created_at'       => now(),
            ]
        );

        return response()->json(['status' => 1, 'message' => 'Saved.', 'attempt_id' => $attemptId], 200);
    }

    /**
     * POST /api/candidate-assessment/{token}/submit
     *
     * Burns the token, auto-scores the MCQs, hands the written answers to the
     * marker, and lets CandidateAssessmentService decide on shortlisting.
     */
    public function submit(Request $request, string $token)
    {
        $resolved = $this->assessments->resolve($token);

        if (!$resolved['row']) {
            return $this->gone($resolved['reason']);
        }

        $row = $resolved['row'];
        $sid = (int) $row->sub_institute_id;
        $attemptId = $this->openAttempt($row, $sid);

        /*
         * THE TOKEN BURNS BEFORE MARKING, IN ITS OWN TRANSACTION.
         *
         * Marking makes a network call that takes seconds. If the token were
         * burned afterwards, a candidate who double-clicked Submit - or whose
         * browser retried - would start a second marking run against the same
         * answers while the first was still going.
         */
        DB::transaction(function () use ($row, $sid, $attemptId) {
            DB::table('talent_candidate_assessments')->where('id', $row->id)->update([
                'token_used_at' => now(),
                'attempt_id'    => $attemptId,
                'status'        => CandidateAssessmentService::STATUS_SUBMITTED,
                'submitted_at'  => now(),
                'updated_at'    => now(),
            ]);

            DB::table('competency_assessment_attempt')
                ->where('id', $attemptId)->where('sub_institute_id', $sid)
                ->update(['submitted_at' => now(), 'status' => 'submitted', 'updated_at' => now()]);
        });

        $this->autoScoreMultipleChoice($row, $sid);

        $fresh = DB::table('talent_candidate_assessments')->where('id', $row->id)->first();
        $result = $this->assessments->finaliseAndShortlist($fresh, $sid, null);

        /*
         * The candidate is told it is done, and NOTHING about the outcome.
         *
         * Whether they qualified is a recruiting decision that a person may still
         * override, and telling someone "you passed" before a recruiter has seen
         * it makes a promise the product cannot keep.
         */
        return response()->json([
            'status' => 1,
            'message' => 'Your answers have been submitted. The hiring team will be in touch.',
            'submitted_at' => now()->toDateTimeString(),
            'awaiting_review' => $result['awaiting'] > 0,
        ], 200);
    }

    /**
     * The attempt row this sitting writes against, created on first answer.
     *
     * `subject_type = 'candidate'` is the whole reason the column exists: a
     * candidate id and an employee id are drawn from different sequences and can
     * collide, and without this the two would share an attempt row.
     */
    private function openAttempt(object $row, int $sid): int
    {
        if ($row->attempt_id) {
            return (int) $row->attempt_id;
        }

        $existing = DB::table('competency_assessment_attempt')
            ->where('sub_institute_id', $sid)
            ->where('test_id', $row->test_id)
            ->where('user_id', $row->candidate_id)
            ->where('subject_type', 'candidate')
            ->value('id');

        $attemptId = (int) ($existing ?: DB::table('competency_assessment_attempt')->insertGetId([
            'sub_institute_id' => $sid,
            'test_id'          => $row->test_id,
            'user_id'          => $row->candidate_id,
            'subject_type'     => 'candidate',
            'started_at'       => now(),
            'status'           => 'in_progress',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]));

        DB::table('talent_candidate_assessments')->where('id', $row->id)->update([
            'attempt_id' => $attemptId,
            'status'     => $row->status === CandidateAssessmentService::STATUS_INVITED
                ? CandidateAssessmentService::STATUS_STARTED
                : $row->status,
            'updated_at' => now(),
        ]);

        return $attemptId;
    }

    /**
     * MCQs are marked without the model - the correct option is on the question,
     * so an LLM call would spend money to introduce doubt where there is none.
     *
     * The comparison itself is AssessmentScoringService::scoreMultipleChoice(),
     * NOT a copy of it here. An employee sitting the same paper through
     * AiAssessmentController must get the same mark, and the two had already
     * drifted once.
     */
    private function autoScoreMultipleChoice(object $row, int $sid): void
    {
        $rows = DB::table('competency_assessment_response as r')
            ->join('competency_assessment_question as q', 'q.id', '=', 'r.question_id')
            ->where('r.sub_institute_id', $sid)
            ->where('r.test_id', $row->test_id)
            ->where('r.user_id', $row->candidate_id)
            ->where('r.subject_type', 'candidate')
            ->where('q.format', 'mcq')
            ->whereNull('r.score')
            ->get(['r.id', 'r.selected_option', 'q.format', 'q.correct_option', 'q.max_score']);

        foreach ($rows as $r) {
            $score = $this->scoring->scoreMultipleChoice($r, $r->selected_option);

            if ($score === null) {
                continue;
            }

            DB::table('competency_assessment_response')->where('id', $r->id)->update([
                'score'      => $score,
                'scored_by'  => 'auto',
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Unknown, expired and already-used all return 410 with the same shape, so
     * the response cannot be used to tell a real token from a guessed one.
     */
    private function gone(?string $reason)
    {
        $message = match ($reason) {
            'used'    => 'This assessment link has already been used.',
            'expired' => 'This assessment link has expired. Ask the hiring team for a new one.',
            default   => 'This assessment link is not valid.',
        };

        return response()->json(['status' => 0, 'message' => $message, 'reason' => $reason], 410);
    }
}
