<?php

namespace App\Services\Lms;

use App\Services\Competency\AssessmentScoringService;
use App\Services\Competency\ProficiencyService;
use App\Services\Competency\RatingWriter;
use App\Services\DeepSeekService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Marks an LMS course quiz, and turns the result into a competency rating.
 *
 * ── THE ONE RULE THIS CLASS EXISTS TO ENFORCE ───────────────────────────────
 *
 * THE SERVER DECIDES WHAT IS CORRECT. NOTHING THE CLIENT SENDS IS CONSULTED.
 *
 * That is not a general principle here, it is a correction. The legacy LMS
 * scorer renders each option as
 *
 *     value="{$ansarr['id']}##{$ansarr['correct_answer']}"     online_exam.blade.php:106
 *
 * and then reads the correctness flag back out of the submitted value in
 * onlineExamController::get_calculate_marks(). The page is told the answer and
 * asked to report whether it got it right. It also never resets its
 * accumulator between questions, and marks every non-empty written answer
 * correct. None of it is reusable, and none of it is reached from here.
 *
 * The only ground truth is `answer_master.correct_answer`, read fresh at
 * submission. The only weight is `lms_question_master.points`.
 *
 * ── WHAT IS REUSED, DELIBERATELY ────────────────────────────────────────────
 *
 * `AssessmentScoringService::ratingFor()` and its RATING_BANDS convert a
 * percentage to a 1-5 competency rating. Reused rather than reimplemented
 * because a quiz-derived 3 and an assessment-derived 3 have to mean the same
 * thing — they land in the same column, on the same person, and a manager
 * comparing them would have no way to know if they were on different scales.
 *
 * `DeepSeekService::chatJson()` marks written answers, with the prompt shape
 * copied from AssessmentScoringService::markingPrompt(). Two properties of
 * that prompt were measured and must not be "improved": the system message
 * ends on "a single valid JSON object." and nothing more, and the answers are
 * fed as a JSON array. Both are explained at length at their original site.
 *
 * ── WHAT DOES NOT HAPPEN ────────────────────────────────────────────────────
 *
 * An AI failure is never a zero. Answers the model could not mark stay
 * unscored, `awaiting_review` counts them, and the attempt reports it. A model
 * that could not be reached must not become a mark against the learner.
 */
class QuizScoringService
{
    public const STATUS_IN_PROGRESS = 'in-progress';
    public const STATUS_SUBMITTED = 'submitted';

    /** Where a rating written by this service says it came from. */
    public const RATING_SOURCE = 'lms_quiz';

    /**
     * The fewest scored questions before a quiz will propose a rating at all.
     *
     * Same threshold, and the same reasoning, as
     * AssessmentScoringService::MIN_QUESTIONS_TO_PROPOSE: one question is a coin
     * toss, not a measurement, and a coin toss must not become somebody's
     * competency record.
     */
    public const MIN_QUESTIONS_TO_RATE = 2;

    public function __construct(
        private readonly AssessmentScoringService $assessments,
        private readonly DeepSeekService $ai,
        private readonly RatingWriter $ratings,
    ) {
    }

    /* ─── Reading the quiz ──────────────────────────────────────────────── */

    /**
     * The quiz paper for a course, if it has one.
     *
     * A course's quiz is the question_paper whose subject_id is that course.
     * Where a course has several, the most recent wins — the builder writes one
     * per course, and an older paper is a superseded draft.
     */
    public function paperForCourse($courseId, $subInstituteId): ?object
    {
        return DB::table('question_paper')
            ->where('subject_id', $courseId)
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The paper's questions with their options — WITHOUT which option is right.
     *
     * `correct_answer` is deliberately not selected. It is not a matter of the
     * client choosing to ignore it: a field that reaches the browser is a field
     * the learner can read, and the legacy exam page proves that is not
     * hypothetical.
     *
     * @return array<int,array<string,mixed>>
     */
    public function questionsFor(object $paper): array
    {
        $ids = $this->questionIds($paper);

        if ($ids === []) {
            return [];
        }

        $questions = DB::table('lms_question_master')
            ->whereIn('id', $ids)
            ->whereNull('deleted_at')
            ->get(['id', 'question_title', 'description', 'points', 'multiple_answer', 'hint_text']);

        $options = DB::table('answer_master')
            ->whereIn('question_id', $questions->pluck('id'))
            ->whereNull('deleted_at')
            ->get(['id', 'question_id', 'answer'])
            ->groupBy('question_id');

        // Presented in the paper's own order, not the database's: question_ids
        // is an ordered list and an author who sequenced their questions meant
        // it.
        $byId = $questions->keyBy('id');

        $ordered = [];

        foreach ($ids as $id) {
            $question = $byId[$id] ?? null;
            if (!$question) {
                continue;
            }

            $choices = ($options[$id] ?? collect())
                ->map(fn ($option) => ['id' => (int) $option->id, 'answer' => $option->answer])
                ->values()
                ->all();

            $ordered[] = [
                'id' => (int) $question->id,
                'question_title' => $question->question_title,
                'description' => $question->description,
                'points' => (int) ($question->points ?: 1),
                'multiple_answer' => (bool) $question->multiple_answer,
                'hint_text' => $question->hint_text,
                // No options means nothing to choose from, so it is a written
                // answer. That is the discriminator rather than
                // question_type_id, which is 1 ("multiple") on all 367 live
                // questions and so distinguishes nothing.
                'is_written' => $choices === [],
                'options' => $choices,
            ];
        }

        return $ordered;
    }

    /** question_paper.question_ids is a comma-separated ordered list. */
    public function questionIds(object $paper): array
    {
        return collect(explode(',', (string) $paper->question_ids))
            ->map(fn ($id) => (int) trim($id))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /* ─── Scoring ───────────────────────────────────────────────────────── */

    /**
     * Mark one submission and write the attempt.
     *
     * @param  array<int,mixed>  $answers  question_id => answer_id | [answer_id] | text
     * @return array<string,mixed>
     */
    public function score(int $attemptId, object $paper, array $answers): array
    {
        $questions = DB::table('lms_question_master')
            ->whereIn('id', $this->questionIds($paper))
            ->whereNull('deleted_at')
            ->get(['id', 'question_title', 'points', 'multiple_answer', 'answer'])
            ->keyBy('id');

        // THE ONLY SOURCE OF TRUTH. Read here, at marking time, from the
        // database - never from the request, and never from anything that was
        // rendered into the page.
        $correct = DB::table('answer_master')
            ->whereIn('question_id', $questions->keys())
            ->where('correct_answer', 1)
            ->whereNull('deleted_at')
            ->get(['id', 'question_id'])
            ->groupBy('question_id');

        $rows = [];
        $earned = 0.0;
        $possible = 0.0;
        $written = [];

        foreach ($questions as $id => $question) {
            $points = (float) ($question->points ?: 1);
            $possible += $points;

            $given = $answers[$id] ?? null;
            $keys = $correct[$id] ?? collect();

            if ($keys->isEmpty()) {
                /*
                 * No correct option is recorded for this question.
                 *
                 * Two different situations land here and both must be handled
                 * the same way: a written question (no options at all), and a
                 * multiple-choice question whose author never flagged the right
                 * answer - live question 1 is exactly that, with one option
                 * carrying correct_answer=0.
                 *
                 * Neither can be marked by comparison. Guessing "wrong" would
                 * penalise the learner for the author's omission, so both go to
                 * the model, and if that fails, to a human.
                 */
                $rows[] = [
                    'question_id' => (int) $id,
                    'answer_id' => null,
                    'narrative' => is_scalar($given) ? (string) $given : null,
                    'is_correct' => null,
                    'score' => null,
                    'max_score' => $points,
                    'ai_marked' => 0,
                    'feedback' => null,
                ];

                if (is_scalar($given) && trim((string) $given) !== '') {
                    $written[] = [
                        'question_id' => (int) $id,
                        'question_text' => (string) $question->question_title,
                        'model_answer' => (string) ($question->answer ?: ''),
                        'answer_text' => (string) $given,
                        'max_score' => $points,
                    ];
                }

                continue;
            }

            $expected = $keys->pluck('id')->map(fn ($v) => (int) $v)->sort()->values()->all();
            $chosen = collect(is_array($given) ? $given : ($given === null ? [] : [$given]))
                ->map(fn ($v) => (int) $v)
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->all();

            /*
             * Exact set equality, both directions.
             *
             * On a multi-answer question, selecting three of four correct
             * options is not three-quarters right in any way this schema can
             * express - there is no partial-credit column - so it is wrong.
             * Comparing only one direction would mark "everything selected" as
             * fully correct, which is the classic way a multi-select quiz
             * becomes free marks.
             */
            $isCorrect = $chosen === $expected && $chosen !== [];
            $awarded = $isCorrect ? $points : 0.0;
            $earned += $awarded;

            $rows[] = [
                'question_id' => (int) $id,
                // The first choice, for single-answer questions where a column
                // is more useful than a JSON blob; the full set is in the
                // narrative for multi-answer.
                'answer_id' => $chosen[0] ?? null,
                'narrative' => count($chosen) > 1 ? implode(',', $chosen) : null,
                'is_correct' => $isCorrect ? 1 : 0,
                'score' => $awarded,
                'max_score' => $points,
                'ai_marked' => 0,
                'feedback' => null,
            ];
        }

        $this->writeResponses($attemptId, $rows);

        // Written answers are marked in one call after the objective marking is
        // already saved, so a model failure cannot cost the learner the marks
        // they had definitely earned.
        $marked = $written === [] ? ['scored' => 0, 'failed' => 0] : $this->markWritten($attemptId, $written);

        $earned += (float) ($marked['earned'] ?? 0);

        $awaiting = DB::table('lms_quiz_response')
            ->where('attempt_id', $attemptId)
            ->whereNull('score')
            ->count();

        return [
            'score' => round($earned, 2),
            'max_score' => round($possible, 2),
            'percent' => $possible > 0 ? round($earned / $possible * 100, 2) : 0.0,
            'questions' => $questions->count(),
            'awaiting_review' => $awaiting,
        ];
    }

    /** Replace this attempt's responses. An attempt is submitted once. */
    private function writeResponses(int $attemptId, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $now = now();

        DB::table('lms_quiz_response')->where('attempt_id', $attemptId)->delete();

        DB::table('lms_quiz_response')->insert(array_map(
            fn ($row) => $row + ['attempt_id' => $attemptId, 'created_at' => $now, 'updated_at' => $now],
            $rows
        ));
    }

    /**
     * Mark the written answers with DeepSeek, in ONE call.
     *
     * One call and not one per answer: a quiz with eight written answers would
     * otherwise make eight synchronous HTTP requests inside one web request.
     *
     * @return array{scored:int, failed:int, earned:float}
     */
    private function markWritten(int $attemptId, array $written): array
    {
        try {
            $result = $this->ai->chatJson([
                [
                    'role' => 'system',
                    // MUST end on "a single valid JSON object." and nothing
                    // more - measured; see
                    // AssessmentScoringService::markingSystemPrompt().
                    'content' => 'You mark workplace capability answers. You are strict but fair, '
                        . 'you mark only against the reference answer given, and you reply with '
                        . 'a single valid JSON object.',
                ],
                ['role' => 'user', 'content' => $this->markingPrompt($written)],
            ], ['json' => true, 'temperature' => 0.2]);
        } catch (\Throwable $e) {
            // Not fatal, and never a zero. The answers stay unscored and the
            // attempt reports them as awaiting review.
            Log::warning('LMS quiz AI marking failed', [
                'attempt_id' => $attemptId,
                'error' => $e->getMessage(),
            ]);

            return ['scored' => 0, 'failed' => count($written), 'earned' => 0.0];
        }

        $marks = collect($result['marks'] ?? [])->keyBy('response_id');
        $byMax = collect($written)->keyBy('question_id');

        $scored = 0;
        $earned = 0.0;

        foreach ($marks as $questionId => $mark) {
            $question = $byMax[$questionId] ?? null;
            if (!$question) {
                continue;
            }

            // Clamped rather than trusted. A model that returns 40 on a
            // 5-point question would otherwise put the attempt above 100%.
            $awarded = max(0.0, min((float) ($mark['score'] ?? 0), (float) $question['max_score']));

            DB::table('lms_quiz_response')
                ->where('attempt_id', $attemptId)
                ->where('question_id', $questionId)
                ->update([
                    'score' => $awarded,
                    'is_correct' => $awarded >= (float) $question['max_score'] ? 1 : 0,
                    'ai_marked' => 1,
                    'feedback' => $mark['feedback'] ?? null,
                    'updated_at' => now(),
                ]);

            $earned += $awarded;
            $scored++;
        }

        return ['scored' => $scored, 'failed' => count($written) - $scored, 'earned' => $earned];
    }

    /**
     * The marking prompt.
     *
     * Shape copied from AssessmentScoringService::markingPrompt(), including
     * the two properties measured there: the answers are a JSON ARRAY (a
     * key: value block makes the model imitate the input and reply with
     * whitespace), and the prompt ends on an imperative rather than on a JSON
     * literal, so there is nothing left to continue.
     */
    private function markingPrompt(array $written): string
    {
        $blocks = (string) json_encode(
            array_map(fn ($row) => [
                'response_id' => $row['question_id'],
                'max_score' => (float) $row['max_score'],
                'question' => $row['question_text'],
                'reference_answer' => $row['model_answer'] !== ''
                    ? $row['model_answer']
                    : '(none given - mark on the question alone)',
                'candidate_answer' => $row['answer_text'],
            ], $written),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );

        return <<<TXT
Mark each answer below.

OUTPUT
Reply with one JSON object of this shape and nothing else:
{"marks":[{"response_id":<the id given>,"score":<number>,"feedback":<one sentence>}]}

RULES
- Score between 0 and that answer's max_score. Never above it.
- Mark against the reference_answer where one is given. Reward correct substance,
  not length or confidence.
- A blank or irrelevant answer scores 0.
- Give one short sentence of feedback the person could act on.
- Return every response_id you were given, exactly once.

ANSWERS (JSON array)
$blocks

Mark every answer above and reply with that JSON object now.
TXT;
    }

    /* ─── The result becoming a rating ──────────────────────────────────── */

    /**
     * Turn a passed attempt into competency rating proposals.
     *
     * ── WHY A PROPOSAL AND NOT A DIRECT WRITE ───────────────────────────────
     *
     * The product decision is that an LMS quiz writes the rating without human
     * review. AssessmentScoringService::approve() is the only path from a score
     * to a rating and is deliberately gated on a person, on the stated grounds
     * that a test result is evidence rather than a verdict.
     *
     * Both are satisfied by making the gate CONFIGURABLE rather than absent: a
     * proposal is always written, and `lms_course_settings.auto_apply_rating`
     * decides whether it is approved on the spot. The rule is turned off for
     * that course by somebody who chose to, the proposal survives as the record
     * of what happened, and a rating that turns out to be wrong can be traced
     * to the attempt that caused it through `source_ref_id`.
     *
     * @return array{proposed:int, applied:int, competencies:array<int,int>}
     */
    public function proposeRatings(object $attempt, $subInstituteId, ?int $actorId): array
    {
        // A failed attempt is evidence of not having learned it, which is not
        // the same as evidence of a level. Only a pass proposes anything.
        if (!$attempt->passed || $attempt->percent === null) {
            return ['proposed' => 0, 'applied' => 0, 'competencies' => []];
        }

        if ((int) $attempt->questions < self::MIN_QUESTIONS_TO_RATE) {
            return ['proposed' => 0, 'applied' => 0, 'competencies' => []];
        }

        $mappings = DB::table('course_competency_map')
            ->where('course_id', $attempt->course_id)
            ->where('sub_institute_id', $subInstituteId)
            ->get(['competency_id', 'proficiency_level']);

        if ($mappings->isEmpty()) {
            return ['proposed' => 0, 'applied' => 0, 'competencies' => []];
        }

        $percent = (float) $attempt->percent;
        $rating = $this->assessments->ratingFor($percent);

        $autoApply = (bool) DB::table('lms_course_settings')
            ->where('course_id', $attempt->course_id)
            ->value('auto_apply_rating');

        $proposed = 0;
        $applied = 0;
        $competencies = [];

        foreach ($mappings as $mapping) {
            $competencyId = (int) $mapping->competency_id;
            $competencies[] = $competencyId;

            // `competency`, the same table CourseCompetencyMapController joins
            // to - `name`, not `competency_name`.
            $item = DB::table('competency')
                ->where('id', $competencyId)
                ->first(['id', 'name']);

            /*
             * The level this person is actually at, for the proposal's
             * "current" column.
             *
             * This used to read a rating row keyed (kasba_type='competency',
             * item_id) - the same non-existent shape applyRating used to write,
             * so `current_rating` was ALWAYS null and every proposal read as a
             * first-ever measurement. The real current value is the weighted
             * roll-up, which is what the gap and every screen show.
             */
            $rollUp = app(ProficiencyService::class)
                ->rollUp((int) $subInstituteId, (int) $attempt->user_id, [$competencyId]);

            $currentLevel = $rollUp[$competencyId]['level'] ?? null;

            $proposalId = DB::table('competency_assessment_rating_proposal')->insertGetId([
                'sub_institute_id' => $subInstituteId,
                // The LMS attempt, NOT a competency_assessment_attempt. The two
                // id spaces overlap, which is why `source` exists on this table
                // - without it this column would silently resolve to an
                // unrelated row rather than fail.
                'attempt_id' => $attempt->id,
                'test_id' => $attempt->paper_id,
                'user_id' => $attempt->user_id,
                'subject_type' => 'employee',
                'source' => self::RATING_SOURCE,
                'kasba_type' => 'competency',
                'item_id' => $competencyId,
                'item_label' => $item->name ?? null,
                'competency_id' => $competencyId,
                'questions' => (int) $attempt->questions,
                'scored_percent' => $percent,
                'proposed_rating' => $rating,
                // The roll-up is a weighted mean and can be fractional; the
                // column is a rating. Rounded rather than truncated so 2.8
                // reads as 3 and not as 2.
                'current_rating' => $currentLevel === null ? null : (int) round($currentLevel),
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $proposed++;

            if (!$autoApply) {
                continue;
            }

            /*
             * Which ratings a quiz may supersede is RatingWriter's decision, not
             * this method's - it is the same question for every writer and was
             * previously answered differently in three places. The short form:
             * an assessor's judgement is never overwritten, a self-rating is,
             * and a drop is left as a proposal.
             */
            $applied += $this->applyRating(
                $proposalId,
                $subInstituteId,
                $attempt,
                $competencyId,
                $rating,
                $percent,
                $actorId
            ) > 0 ? 1 : 0;
        }

        return ['proposed' => $proposed, 'applied' => $applied, 'competencies' => $competencies];
    }

    /**
     * Write the rating where the readers actually look.
     *
     * ── WHAT THIS USED TO DO, AND WHY IT NEVER WORKED ───────────────────────
     *
     * It wrote ONE row keyed (kasba_type = 'competency', item_id = <competency>)
     * with kasba_item_id NULL. Every reader of a rating joins on kasba_item_id —
     * ProficiencyService::rollUp, which is the only place a competency level is
     * derived, and KasbaRatingController::index, which feeds the employee
     * drawer. NULL matches nothing. The learner was told "your competency rating
     * has been updated" and no level, gap, radar or drawer moved.
     *
     * The write now goes through RatingWriter, which keys on kasba_item_id and
     * records what it replaced. Neither database held a row of the old shape, so
     * there is nothing to back-fill.
     *
     * ── PER CAPABILITY WHERE THE QUESTIONS SAY SO ───────────────────────────
     *
     * If the paper's questions cite the KASBA item they test
     * (lms_question_master.kasba_item_id, written by the AI generator), each item
     * moves on its own evidence: answer the secure-coding questions well and the
     * code-review ones badly and those two items diverge, which is the entire
     * reason capability is modelled per item rather than per competency.
     *
     * When no question cites anything — every hand-authored quiz today — the
     * only honest reading of one percentage is "this much of the competency as a
     * whole", so every item under it moves together.
     */
    private function applyRating(
        int $proposalId,
        $subInstituteId,
        object $attempt,
        int $competencyId,
        int $rating,
        float $percent,
        ?int $actorId
    ): int {
        $perItem = $this->perItemRatings($attempt, $competencyId, $subInstituteId);

        $result = $this->ratings->writeCompetency([
            'tenant' => (int) $subInstituteId,
            'user_id' => (int) $attempt->user_id,
            'competency_id' => $competencyId,
            'course_id' => (int) $attempt->course_id,
            'rating' => $rating,
            'source' => self::RATING_SOURCE,
            'assessor_id' => $actorId,
            'source_ref_id' => (int) $attempt->id,
            'note' => sprintf(
                'Scored %.1f%% on the course quiz (attempt #%d).',
                $percent,
                $attempt->id
            ),
            // Auto-apply raises; a drop stays a proposal for a person to decide.
            // See RatingWriter's header for why.
            'allow_lower' => false,
        ], $perItem);

        DB::table('competency_assessment_rating_proposal')->where('id', $proposalId)->update([
            // Only claim it was applied if something actually moved. A proposal
            // marked approved when every item was skipped is a lie the review
            // queue would then hide, because it filters to pending.
            'status' => $result['written'] > 0 ? 'approved' : 'pending',
            'decided_by' => $result['written'] > 0 ? $actorId : null,
            'decided_at' => $result['written'] > 0 ? now() : null,
            'note' => $result['written'] > 0
                ? sprintf(
                    'Applied automatically to %d capability item(s): this course has auto_apply_rating on.',
                    $result['written']
                )
                : 'Not applied automatically - every capability item was either set by an assessor or already at or above this level.',
            'updated_at' => now(),
        ]);

        return $result['written'];
    }

    /**
     * Per-KASBA-item ratings derived from the questions that cite them.
     *
     * Returns kasba_item_id => rating for every cited item with enough scored
     * questions to mean anything. An item with one question is left out and
     * falls back to the competency-wide figure — MIN_QUESTIONS_TO_RATE exists
     * precisely so a coin toss does not become somebody's record, and that
     * threshold applies per item as much as per attempt.
     *
     * @return array<int,int>
     */
    private function perItemRatings(object $attempt, int $competencyId, $subInstituteId): array
    {
        $rows = DB::table('lms_quiz_response as r')
            ->join('lms_question_master as q', 'q.id', '=', 'r.question_id')
            ->join('competency_kasba_item as k', 'k.id', '=', 'q.kasba_item_id')
            ->where('r.attempt_id', $attempt->id)
            ->where('k.competency_id', $competencyId)
            ->where('k.sub_institute_id', $subInstituteId)
            // An answer the AI could not mark is unscored, not wrong. Folding it
            // in as a zero is the failure this service refuses everywhere else.
            ->whereNotNull('r.score')
            ->get(['q.kasba_item_id', 'r.score', 'r.max_score']);

        if ($rows->isEmpty()) {
            return [];
        }

        $perItem = [];

        foreach ($rows->groupBy('kasba_item_id') as $itemId => $responses) {
            if ($responses->count() < self::MIN_QUESTIONS_TO_RATE) {
                continue;
            }

            $max = (float) $responses->sum('max_score');

            if ($max <= 0) {
                continue;
            }

            $perItem[(int) $itemId] = $this->assessments->ratingFor(
                round(((float) $responses->sum('score') / $max) * 100, 2)
            );
        }

        return $perItem;
    }

    /* ─── Course effectiveness ──────────────────────────────────────────── */

    /**
     * Fold this attempt into what the course is measured to achieve.
     *
     * Kept apart from `course_competency_map.proficiency_level`, which is HR's
     * declared TARGET and is destructively rewritten whenever the mapping is
     * saved. Target and achieved side by side is what makes a course whose
     * takers consistently fall short visible as weak teaching.
     *
     * Recomputed from the attempts rather than incremented, so a deleted or
     * corrected attempt is reflected instead of being baked in forever.
     */
    public function recordEffectiveness(object $attempt, $subInstituteId): void
    {
        $mappings = DB::table('course_competency_map')
            ->where('course_id', $attempt->course_id)
            ->where('sub_institute_id', $subInstituteId)
            ->get(['competency_id', 'proficiency_level']);

        if ($mappings->isEmpty()) {
            return;
        }

        $stats = DB::table('lms_quiz_attempt')
            ->where('course_id', $attempt->course_id)
            ->where('sub_institute_id', $subInstituteId)
            ->where('status', self::STATUS_SUBMITTED)
            ->whereNotNull('percent')
            ->whereNull('deleted_at')
            ->selectRaw('COUNT(*) as attempts, COUNT(DISTINCT user_id) as learners, AVG(percent) as mean_percent')
            ->first();

        if (!$stats || (int) $stats->attempts === 0) {
            return;
        }

        $mean = round((float) $stats->mean_percent, 2);
        $derived = $this->assessments->ratingFor($mean);
        $now = now();

        foreach ($mappings as $mapping) {
            $keys = [
                'sub_institute_id' => $subInstituteId,
                'course_id' => $attempt->course_id,
                'competency_id' => (int) $mapping->competency_id,
            ];

            $values = [
                'attempts' => (int) $stats->attempts,
                'learners' => (int) $stats->learners,
                'mean_percent' => $mean,
                'derived_level' => $derived,
                // Snapshotted so the comparison survives the mapping being
                // re-saved, which deletes and rewrites proficiency_level.
                'target_level' => $mapping->proficiency_level !== null
                    ? (int) $mapping->proficiency_level
                    : null,
                'last_computed_at' => $now,
                'updated_at' => $now,
            ];

            if (DB::table('lms_course_competency_effectiveness')->where($keys)->exists()) {
                DB::table('lms_course_competency_effectiveness')->where($keys)->update($values);
            } else {
                DB::table('lms_course_competency_effectiveness')
                    ->insert($keys + $values + ['created_at' => $now]);
            }
        }
    }
}
