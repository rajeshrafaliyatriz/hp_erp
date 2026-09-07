<?php

namespace App\Services\Competency;

use App\Services\DeepSeekService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turning a set of answers into a result, and a result into a PROPOSAL.
 *
 * ── THE RULE THIS WHOLE CLASS EXISTS TO KEEP ────────────────────────────────
 *
 * A test result is EVIDENCE, not a verdict. AiAssessmentController has always
 * said so - "proficiency changes only on explicit confirmation elsewhere" - but
 * there was no elsewhere, so a score could never become a rating at all. This
 * is that elsewhere: scoring produces a proposal, a person approves it, and
 * only then is competency_kasba_rating written.
 *
 * ── UNMEASURED IS NEITHER ZERO NOR PASS ─────────────────────────────────────
 *
 * The same principle the roll-up already enforces. A KASBA item whose questions
 * were all left unanswered, or are all still awaiting review, produces NO
 * proposal. It does not produce a proposal of 1. Silence is reported as
 * silence; the alternative is telling someone they are incompetent at something
 * nobody measured.
 */
class AssessmentScoringService
{
    /**
     * Percent correct on an item -> a 1-5 proficiency rating.
     *
     * THESE THRESHOLDS ARE A CHOICE, NOT A MEASUREMENT, so they are written
     * down here rather than buried in an expression, and they are returned to
     * the caller so the screen can show the reader what produced the number.
     *
     * The scale is deliberately not linear at the bottom: scoring 30% on a
     * capability is not "level 1.5 of 5", it is someone who cannot yet do the
     * thing. The top band is narrow for the same reason in reverse - 5 means
     * "can teach it", which almost everything short of a full score is not.
     *
     * @var array<int,array{min:float,label:string}>
     */
    public const RATING_BANDS = [
        5 => ['min' => 90.0, 'label' => 'Expert - near-complete mastery'],
        4 => ['min' => 75.0, 'label' => 'Advanced - solid working command'],
        3 => ['min' => 60.0, 'label' => 'Intermediate - dependable on routine work'],
        2 => ['min' => 40.0, 'label' => 'Basic - has the foundations'],
        1 => ['min' => 0.0,  'label' => 'Awareness - aware of it, not yet able'],
    ];

    /**
     * The smallest number of SCORED questions an item needs before this will
     * propose anything for it.
     *
     * One question is a coin toss, not a measurement. An item with a single
     * scored question is reported with its score and no proposed rating.
     */
    public const MIN_QUESTIONS_TO_PROPOSE = 2;

    /**
     * Formats a machine cannot mark by comparing strings, so a model marks them.
     *
     * `mcq` is absent because it auto-scores against `correct_option` at submit
     * time. Anything added here MUST also be handled by markingPrompt(), or its
     * answers would be sent to the model under prose instructions.
     *
     * A format that is in neither place is the dangerous case: it would never be
     * auto-scored and never AI-marked, so `awaiting_review` would never reach
     * zero and the attempt would sit unscored forever with nothing reporting it.
     */
    public const AI_MARKED_FORMATS = ['short_answer', 'coding'];

    public function __construct(private readonly DeepSeekService $ai)
    {
    }

    /**
     * The mark for ONE multiple-choice answer, or null if it is not markable here.
     *
     * ── WHY THIS IS A METHOD AND NOT TWO COMPARISONS ────────────────────────
     *
     * Two callers award MCQ marks: AiAssessmentController::submit() for an
     * employee, and CandidateAssessmentResponseController for a candidate on a
     * magic link. They were written months apart and had already drifted - one
     * compared `selected_option` exactly, the other lower-cased and trimmed
     * `answer_text`. Same paper, two different marks, and nothing would have
     * reported the disagreement.
     *
     * This is the same failure the audit found in four writers of employee rows,
     * and the same remedy: one implementation, both callers.
     *
     * The comparison is EXACT on a trimmed string. Not case-insensitive: the
     * option label is echoed back from what the server sent, so a case
     * difference means the client altered it, and quietly accepting that would
     * mark 'b' correct for a question whose options are 'B' and 'b'.
     *
     * Returns null when the question is not an MCQ or carries no key - null is
     * "awaiting review", which is not the same as a zero. A zero is a mark
     * against the person; null says nobody has marked it yet.
     */
    public function scoreMultipleChoice(object $question, ?string $selectedOption): ?float
    {
        if (($question->format ?? null) !== 'mcq' || ($question->correct_option ?? null) === null) {
            return null;
        }

        return trim((string) $selectedOption) === trim((string) $question->correct_option)
            ? (float) $question->max_score
            : 0.0;
    }

    /**
     * Score every short answer on this attempt in ONE DeepSeek call.
     *
     * One call, not one per answer: a test with twelve written answers would
     * otherwise make twelve synchronous HTTP requests inside one web request,
     * and the client timeout is 120 seconds for ALL of them together.
     *
     * A failure here is not fatal and is never a zero. The answers stay
     * unscored, `awaiting_review` counts them, and a human can still score
     * them. A model that could not be reached must not become a mark against
     * the person who answered.
     *
     * @return array{scored:int, failed:int, reason:?string}
     */
    public function scoreShortAnswers(int $attemptId, int $tenantId): array
    {
        /*
         * The attempt is resolved FIRST and its identity bound as values, rather
         * than joined column-to-column.
         *
         * Joining on subject_type looked natural and fails at run time: these
         * tables do not share a collation. competency_assessment_attempt and
         * _rating_proposal are utf8mb4_general_ci (they were created by raw DDL),
         * while _question, _response and _test are utf8mb4_unicode_ci - so
         * comparing two VARCHARs across that boundary raises
         * "Illegal mix of collations". Binding the value sidesteps it entirely
         * and reads better besides.
         */
        $attempt = DB::table('competency_assessment_attempt')
            ->where('id', $attemptId)->where('sub_institute_id', $tenantId)
            ->first(['test_id', 'user_id', 'subject_type']);

        if (!$attempt) {
            return ['scored' => 0, 'failed' => 0, 'reason' => null];
        }

        $pending = DB::table('competency_assessment_response as r')
            ->join('competency_assessment_question as q', 'q.id', '=', 'r.question_id')
            ->where('r.sub_institute_id', $tenantId)
            ->where('r.test_id', $attempt->test_id)
            ->where('r.user_id', $attempt->user_id)
            // Keeps an employee and a candidate sharing an id apart.
            ->where('r.subject_type', $attempt->subject_type ?? 'employee')
            // Everything a machine cannot mark by string equality.
            ->whereIn('q.format', self::AI_MARKED_FORMATS)
            ->whereNull('r.score')
            ->whereNotNull('r.answer_text')
            ->where('r.answer_text', '<>', '')
            ->get(['r.id', 'q.format', 'q.question_text', 'q.model_answer', 'q.max_score', 'r.answer_text']);

        if ($pending->isEmpty()) {
            return ['scored' => 0, 'failed' => 0, 'reason' => null];
        }

        if (!$this->ai->isConfigured()) {
            return ['scored' => 0, 'failed' => $pending->count(), 'reason' => 'not_configured'];
        }

        /*
         * ONE CALL PER FORMAT, NOT ONE PER ATTEMPT.
         *
         * Prose and code need genuinely different instructions - prose is marked
         * against a reference answer, code must NOT be, because there are
         * unbounded correct programs and comparing to one implementation
         * penalises a different-but-correct solution.
         *
         * Splitting by format also isolates the risk. A code answer is an order
         * of magnitude longer than a written one, and DeepSeekService throws on a
         * truncated response - so one batch mixing both would let a long coding
         * answer take the prose marks down with it. Now a failure in one format
         * leaves the other format's marks applied, and the rest await review.
         */
        $result = ['marks' => []];
        $failedFormats = 0;

        foreach ($pending->groupBy('format') as $format => $group) {
            try {
                $answer = $this->ai->chatJson([
                    ['role' => 'system', 'content' => $this->markingSystemPrompt((string) $format)],
                    ['role' => 'user', 'content' => $this->markingPrompt($group, (string) $format)],
                ], ['json' => true, 'temperature' => 0.2]);

                foreach ($answer['marks'] ?? [] as $mark) {
                    $result['marks'][] = $mark;
                }
            } catch (\Throwable $e) {
                $failedFormats++;
                Log::warning('Marking failed for one format; those answers left for human review', [
                    'attempt' => $attemptId, 'format' => $format, 'error' => $e->getMessage(),
                ]);
            }
        }

        if ($failedFormats > 0 && empty($result['marks'])) {
            return ['scored' => 0, 'failed' => $pending->count(), 'reason' => 'ai_error'];
        }

        $byId = $pending->keyBy('id');
        $scored = 0;

        foreach ($result['marks'] ?? [] as $mark) {
            $row = $byId->get((int) ($mark['response_id'] ?? 0));
            if (!$row) {
                // A response id the model invented. Dropped, never applied.
                continue;
            }

            // Clamped: a model that returns 9 out of a max of 1 has misread the
            // question, and its number must not become someone's mark.
            $score = max(0, min((float) $row->max_score, (float) ($mark['score'] ?? 0)));

            /*
             * The model's one-sentence justification is KEPT.
             *
             * markingPrompt() has always asked for `feedback` and the parser has
             * always received it, and it was then dropped on the floor because
             * there was no column for it. So a person marked down by a model
             * could never be told why - and for a CANDIDATE that is worse than
             * unhelpful: a hiring decision has to be explainable to the manager
             * reading it, and to the candidate if they ask.
             *
             * Trimmed and capped so a model that ignores "one short sentence"
             * cannot write an essay into every row.
             */
            $feedback = trim((string) ($mark['feedback'] ?? ''));

            DB::table('competency_assessment_response')->where('id', $row->id)->update([
                'score'       => $score,
                'scored_by'   => 'ai',
                'ai_feedback' => $feedback !== '' ? mb_substr($feedback, 0, 1000) : null,
                'updated_at'  => now(),
            ]);
            $scored++;
        }

        return ['scored' => $scored, 'failed' => $pending->count() - $scored, 'reason' => null];
    }

    /**
     * Total the attempt, and propose a rating per KASBA item.
     *
     * Scores are STORED on the attempt rather than recomputed on read. A test
     * can be superseded and its questions can change; a result has to keep
     * saying what it said on the day it was taken.
     *
     * `attributed_*` covers only the questions that cite a capability item; the
     * plain figures cover the whole paper. They differ once a test mixes
     * capability questions with un-cited ones (aptitude, coding), which is why
     * both are returned rather than one being quietly used for both jobs.
     *
     * @return array{total:float, max:float, percent:?float, awaiting:int,
     *               attributed_total:float, attributed_max:float, attributed_percent:?float,
     *               proposals:int, cycles:int}
     */
    public function finalise(int $attemptId, int $tenantId): array
    {
        $attempt = DB::table('competency_assessment_attempt')
            ->where('id', $attemptId)->where('sub_institute_id', $tenantId)->first();

        if (!$attempt) {
            return ['total' => 0.0, 'max' => 0.0, 'percent' => null, 'awaiting' => 0, 'proposals' => 0];
        }

        $rows = DB::table('competency_assessment_question as q')
            ->leftJoin('competency_assessment_response as r', function ($j) use ($attempt) {
                $j->on('r.question_id', '=', 'q.id')->where('r.user_id', '=', $attempt->user_id);
            })
            ->where('q.test_id', $attempt->test_id)
            ->get(['q.id', 'q.kasba_item_id', 'q.max_score', 'q.cited_kasba_type', 'q.cited_item_label',
                   'q.cited_competency_id', 'r.score', 'r.answered_at']);

        /*
         * TWO TOTALS, ON PURPOSE.
         *
         * `total` / `max` are what the PERSON scored - every question on the
         * paper, which is the only honest answer to "how did they do".
         *
         * `attributedTotal` / `attributedMax` count only questions that cite a
         * capability item. Since kasba_item_id became nullable (so aptitude and
         * coding questions can exist at all), those two sets can differ.
         *
         * Keeping them apart is the whole point. buildProposals() has always
         * skipped questions without an item, so if the attempt percent were
         * computed over everything and the proposals over a subset, an attempt
         * would report 70% while its per-item numbers refused to add up to it -
         * and nothing would say why. A silent arithmetic divergence is the worst
         * possible shape of bug in something that scores people, so the two
         * figures are computed separately and consumed deliberately.
         */
        $total = 0.0; $max = 0.0; $awaiting = 0;
        $attributedTotal = 0.0; $attributedMax = 0.0;

        foreach ($rows as $row) {
            $score = $row->score !== null ? (float) $row->score : null;
            $weight = (float) $row->max_score;

            $max += $weight;
            if ($score !== null) {
                $total += $score;
            } elseif ($row->answered_at !== null) {
                // Answered but not yet marked. Counted as outstanding, NOT as 0.
                $awaiting++;
            }

            if ($row->kasba_item_id) {
                $attributedMax += $weight;
                if ($score !== null) {
                    $attributedTotal += $score;
                }
            }
        }

        $percent = $max > 0 ? round($total / $max * 100, 2) : null;
        $attributedPercent = $attributedMax > 0 ? round($attributedTotal / $attributedMax * 100, 2) : null;

        DB::table('competency_assessment_attempt')->where('id', $attemptId)->update([
            'total_score'     => $total,
            'max_score'       => $max,
            'percent'         => $percent,
            'awaiting_review' => $awaiting,
            'submitted_at'    => $attempt->submitted_at ?? now(),
            'status'          => $awaiting > 0 ? 'awaiting_review' : 'scored',
            'updated_at'      => now(),
        ]);

        return [
            'total' => $total, 'max' => $max, 'percent' => $percent, 'awaiting' => $awaiting,
            /*
             * Reported alongside the overall figures so a caller can SEE the two
             * and reconcile them, rather than discovering the difference by
             * arithmetic that does not add up.
             */
            'attributed_total'   => $attributedTotal,
            'attributed_max'     => $attributedMax,
            'attributed_percent' => $attributedPercent,
            'proposals' => $this->buildProposals($attempt, $rows, $tenantId),
            /*
             * A review cycle measures CAPABILITY, so it gets the attributed
             * percent - the part of the paper that actually cites capabilities.
             * On an all-competency test (every employee test today) the two are
             * identical, so nothing changes for existing data.
             */
            'cycles'    => $this->feedReviewCycles($attempt, $attributedPercent ?? $percent, $tenantId),
        ];
    }

    /**
     * A review cycle that uses this assessment gets the person's score.
     *
     * ── WHY THIS EXISTS ─────────────────────────────────────────────────────
     *
     * A review cycle asks "how capable is this person?" and, until now, could
     * only answer it with a manager's judgement - while an assessment sat one
     * table away having actually measured it. The two were built separately and
     * never introduced.
     *
     * A cycle opts in by setting `test_id`. Cycles that do not (which is every
     * existing one - all 4 live cycles and 140 participant rows) are untouched
     * and keep working exactly as before.
     *
     * ── IT ONLY EVER FILLS A BLANK ──────────────────────────────────────────
     *
     * A participant already carrying a score was rated by a person, and a test
     * result must not overwrite somebody's judgement - the same rule the rating
     * proposals follow. Where both exist, the human one stands and the test
     * result is still on record against the attempt.
     *
     * @return int participant rows updated
     */
    private function feedReviewCycles(object $attempt, ?float $percent, int $tenantId): int
    {
        if ($percent === null) {
            return 0;
        }

        /*
         * A review cycle appraises EMPLOYEES. A candidate sitting the same test -
         * which is exactly what recruitment does, deliberately reusing a
         * published test - must not have their score written into somebody's
         * performance cycle. s_competency_assessments is matched on
         * (cycle_id, user_id), and user_id is not a tbluser id for a candidate,
         * so without this a candidate's percent could land on whichever employee
         * shares that id.
         */
        if (($attempt->subject_type ?? 'employee') !== 'employee') {
            return 0;
        }

        // The column is only present once 2026_08_26_160000 has run.
        if (!$this->hasColumn('s_competency_assessment_cycles', 'test_id')
            || !$this->hasColumn('s_competency_assessments', 'attempt_id')) {
            return 0;
        }

        $cycleIds = DB::table('s_competency_assessment_cycles')
            ->where('sub_institute_id', $tenantId)
            ->where('test_id', $attempt->test_id)
            ->whereNull('deleted_at')
            ->pluck('id');

        if ($cycleIds->isEmpty()) {
            return 0;
        }

        return DB::table('s_competency_assessments')
            ->where('sub_institute_id', $tenantId)
            ->whereIn('cycle_id', $cycleIds)
            ->where('user_id', $attempt->user_id)
            ->whereNull('deleted_at')
            // Only a blank. See the note above.
            ->whereNull('score')
            ->update([
                'score'        => $percent,
                'attempt_id'   => $attempt->id,
                'status'       => 'completed',
                'completed_at' => now(),
                'updated_at'   => now(),
            ]);
    }

    /** information_schema directly - live is MariaDB 10.1. */
    private function hasColumn(string $table, string $column): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            [$table, $column]
        ) !== [];
    }

    /**
     * One proposal per KASBA item the test actually measured.
     *
     * Items with fewer than MIN_QUESTIONS_TO_PROPOSE scored questions are still
     * recorded - with their score and a NULL proposed_rating - so the reviewer
     * can see the item was touched and why no rating is offered. That is the
     * difference between "we did not measure this" and "we measured it as
     * nothing".
     */
    private function buildProposals(object $attempt, $rows, int $tenantId): int
    {
        $byItem = [];

        foreach ($rows as $row) {
            if (!$row->kasba_item_id || $row->score === null) {
                continue;
            }
            $key = (int) $row->kasba_item_id;
            $byItem[$key] ??= ['scored' => 0, 'total' => 0.0, 'max' => 0.0, 'row' => $row];
            $byItem[$key]['scored']++;
            $byItem[$key]['total'] += (float) $row->score;
            $byItem[$key]['max']   += (float) $row->max_score;
        }

        if ($byItem === []) {
            return 0;
        }

        $items = DB::table('competency_kasba_item')
            ->whereIn('id', array_keys($byItem))->get()->keyBy('id');

        $written = 0;

        foreach ($byItem as $itemId => $agg) {
            $item = $items->get($itemId);
            $percent = $agg['max'] > 0 ? round($agg['total'] / $agg['max'] * 100, 2) : null;

            $proposed = ($percent !== null && $agg['scored'] >= self::MIN_QUESTIONS_TO_PROPOSE)
                ? $this->ratingFor($percent)
                : null;

            // What the person is rated at TODAY, so the reviewer is deciding a
            // change rather than reading a number in isolation.
            $current = DB::table('competency_kasba_rating')
                ->where('sub_institute_id', $tenantId)
                ->where('user_id', $attempt->user_id)
                ->where('kasba_item_id', $itemId)
                ->value('rating');

            $keys = [
                'attempt_id'    => $attempt->id,
                'kasba_item_id' => $itemId,
                'kasba_type'    => $item->kasba_type ?? null,
                'item_id'       => $item->item_id ?? null,
            ];

            $values = [
                'sub_institute_id' => $tenantId,
                'test_id'          => $attempt->test_id,
                'user_id'          => $attempt->user_id,
                /*
                 * Carried from the attempt so approve()'s guard has something to
                 * read. Without this the proposal would default to 'employee' and
                 * a candidate's result could still become a proficiency rating -
                 * the guard would be looking at the wrong fact.
                 */
                'subject_type'     => $attempt->subject_type ?? 'employee',
                'item_label'       => $item->item_label ?? ($agg['row']->cited_item_label ?? null),
                'competency_id'    => $item->competency_id ?? ($agg['row']->cited_competency_id ?? null),
                'questions'        => $agg['scored'],
                'scored_percent'   => $percent,
                'proposed_rating'  => $proposed,
                'current_rating'   => $current !== null ? (int) $current : null,
                'status'           => 'pending',
                'updated_at'       => now(),
            ];

            /*
             * Same defect as approve(): updateOrInsert() writes its values on the
             * update branch too, so re-finalising an attempt (which happens every
             * time an assessor marks one more written answer) reset the
             * proposal's `created_at` and lost when it was first raised.
             */
            $existing = DB::table('competency_assessment_rating_proposal')->where($keys)->exists();

            if ($existing) {
                DB::table('competency_assessment_rating_proposal')->where($keys)->update($values);
            } else {
                DB::table('competency_assessment_rating_proposal')
                    ->insert($keys + $values + ['created_at' => now()]);
            }
            $written++;
        }

        return $written;
    }

    /** The band a percentage falls in. See RATING_BANDS. */
    public function ratingFor(float $percent): int
    {
        foreach (self::RATING_BANDS as $rating => $band) {
            if ($percent >= $band['min']) {
                return $rating;
            }
        }

        return 1;
    }

    /**
     * Accept a proposal: THIS is where a test result finally becomes a rating.
     *
     * Writes with source='assessment' - a value competency_kasba_rating has
     * reserved since it was created and which nothing has ever written, so a
     * rating that came from a test is distinguishable forever from one a person
     * typed.
     *
     * Both keying modes are written when the item is competency-linked, exactly
     * as KasbaRatingController::storeByItem() does, so ProficiencyService's
     * roll-up sees it.
     */
    public function approve(int $proposalId, int $tenantId, ?int $actorId, ?string $note = null): array
    {
        $proposal = DB::table('competency_assessment_rating_proposal')
            ->where('id', $proposalId)->where('sub_institute_id', $tenantId)->first();

        if (!$proposal) {
            return ['ok' => false, 'message' => 'Proposal not found.'];
        }
        if ($proposal->status !== 'pending') {
            return ['ok' => false, 'message' => 'This proposal has already been decided.'];
        }
        if ($proposal->proposed_rating === null) {
            return ['ok' => false, 'message' =>
                'This item has too few scored questions to propose a rating, so there is nothing to approve.'];
        }

        /*
         * ONLY AN EMPLOYEE'S RESULT MAY BECOME A PROFICIENCY RATING.
         *
         * competency_kasba_rating is keyed on user_id and has no foreign key, and
         * neither does competency_assessment_rating_proposal. Since candidates can
         * now sit assessments, `user_id` is no longer guaranteed to be a tbluser
         * id - and a candidate id that happened to equal a real employee's id
         * would, without this, write a rating onto that employee's record. It
         * would look exactly like a legitimate assessment result, and there would
         * be nothing in the row to say otherwise.
         *
         * The check is here rather than at the caller because this method is the
         * only thing in the codebase that writes competency_kasba_rating from an
         * assessment, so guarding it guards every route to it - including any
         * future one nobody has written yet.
         */
        $subject = $proposal->subject_type ?? 'employee';

        if ($subject !== 'employee') {
            return ['ok' => false, 'message' =>
                'This result belongs to a ' . $subject . ', not an employee, so it cannot become a '
                . 'proficiency rating. Candidate assessment results are advisory and live with the '
                . 'application.'];
        }

        DB::transaction(function () use ($proposal, $tenantId, $actorId, $note) {
            /*
             * `created_at` IS NOT IN HERE, AND THAT IS THE FIX.
             *
             * updateOrInsert() applies its second argument on BOTH branches, so
             * carrying `created_at` in it rewrote the ORIGINAL creation time of
             * an existing rating every time somebody approved a later proposal
             * for the same item. The row then claimed to have been created at the
             * moment of its most recent edit, which quietly destroys the one fact
             * "when was this person first rated on this?" depends on.
             *
             * See upsertRating() below: the timestamp is set on insert only.
             */
            $common = [
                'rating'      => (int) $proposal->proposed_rating,
                'assessor_id' => $actorId,
                'source'      => 'assessment',
                'note'        => $note ?: 'From assessment result.',
                'rated_at'    => now(),
                'updated_at'  => now(),
            ];

            if ($proposal->kasba_item_id) {
                $this->upsertRating(
                    ['sub_institute_id' => $tenantId, 'user_id' => $proposal->user_id,
                     'kasba_item_id' => $proposal->kasba_item_id],
                    $common,
                    $proposal
                );
            }

            // The direct row too, when the item resolves to a library entry -
            // the same pair storeByItem() keeps in step.
            if ($proposal->kasba_type && $proposal->item_id) {
                $this->upsertRating(
                    ['sub_institute_id' => $tenantId, 'user_id' => $proposal->user_id,
                     'kasba_type' => $proposal->kasba_type, 'item_id' => $proposal->item_id],
                    $common,
                    $proposal
                );
            }

            DB::table('competency_assessment_rating_proposal')->where('id', $proposal->id)->update([
                'status' => 'approved', 'decided_by' => $actorId, 'decided_at' => now(),
                'note' => $note, 'updated_at' => now(),
            ]);
        });

        return ['ok' => true, 'message' => sprintf(
            'Rating %d recorded for "%s".', $proposal->proposed_rating, $proposal->item_label ?: 'the item'
        )];
    }

    /**
     * updateOrInsert, except `created_at` is written ONLY on the insert.
     *
     * Laravel's updateOrInsert applies one array to both branches, which is right
     * for a value that should always be refreshed and wrong for a creation
     * timestamp. There is no flag to say "insert only", so the branch is taken
     * explicitly.
     *
     * @param  array<string,mixed>  $keys    identifying columns
     * @param  array<string,mixed>  $values  columns to write, WITHOUT created_at
     */
    private function upsertRating(array $keys, array $values, ?object $proposal = null): void
    {
        $current = DB::table('competency_kasba_rating')->where($keys)->first(['rating']);

        if ($current) {
            DB::table('competency_kasba_rating')->where($keys)->update($values);
        } else {
            DB::table('competency_kasba_rating')->insert($keys + $values + ['created_at' => now()]);
        }

        /*
         * ── HISTORY, ADDED WITHOUT CHANGING ANY DECISION ────────────────────
         *
         * Nothing above this comment changed: the same rows are written, on the
         * same conditions, by the same rules. This only records WHAT CHANGED,
         * so an approved assessment shows up on the employee's capability
         * history beside a course result instead of being the one kind of
         * rating movement that leaves no trace.
         *
         * Writing an unchanged value is skipped for the same reason it is in
         * RatingWriter: a history row saying nothing happened is noise on a
         * trend line.
         */
        if ($current && (int) $current->rating === (int) $values['rating']) {
            return;
        }

        DB::table('competency_rating_history')->insert([
            'sub_institute_id' => $keys['sub_institute_id'],
            'user_id' => $keys['user_id'],
            'kasba_item_id' => $keys['kasba_item_id'] ?? null,
            'kasba_type' => $keys['kasba_type'] ?? null,
            'item_id' => $keys['item_id'] ?? null,
            'item_label' => $proposal->item_label ?? null,
            'competency_id' => $proposal->competency_id ?? null,
            // An assessment is not attached to a course, so this stays null -
            // the history row's `source` says where it came from.
            'course_id' => null,
            'old_rating' => $current->rating ?? null,
            'new_rating' => (int) $values['rating'],
            'source' => $values['source'] ?? 'assessment',
            'source_ref_id' => $proposal->attempt_id ?? null,
            'assessor_id' => $values['assessor_id'] ?? null,
            'note' => $values['note'] ?? null,
            'changed_at' => now(),
            'created_at' => now(),
        ]);
    }

    /** Reject: the result stays on record, the rating does not change. */
    public function reject(int $proposalId, int $tenantId, ?int $actorId, ?string $note = null): array
    {
        $updated = DB::table('competency_assessment_rating_proposal')
            ->where('id', $proposalId)->where('sub_institute_id', $tenantId)->where('status', 'pending')
            ->update(['status' => 'rejected', 'decided_by' => $actorId, 'decided_at' => now(),
                      'note' => $note, 'updated_at' => now()]);

        return $updated
            ? ['ok' => true, 'message' => 'Proposal rejected. The rating is unchanged and the result stays on record.']
            : ['ok' => false, 'message' => 'Proposal not found, or already decided.'];
    }

    /** The marking prompt. Built from the answers, never from a fixed subject list. */
    /**
     * The role the model is asked to take, which differs by format.
     *
     * Prose is marked against a reference answer. Code must NOT be: there are
     * unbounded correct programs, and a model told to compare against one
     * implementation marks down a different-but-correct solution. That single
     * difference is why the two cannot share a prompt.
     */
    private function markingSystemPrompt(string $format): string
    {
        /*
         * BOTH MESSAGES MUST END ON "a single valid JSON object." AND NOTHING MORE.
         *
         * Measured 2026-09-04 against deepseek-chat in JSON mode, on BOTH formats:
         *
         *   "...a single valid JSON object."                              -> marks
         *   "...a single valid JSON object and nothing else - no prose,
         *      no markdown fences."                                       -> BLANK
         *   "...You reply in JSON."                                       -> BLANK
         *
         * Reproduced on the short-answer and coding prompts independently, so it
         * is the phrasing, not one prompt's content. The failure is silent: HTTP
         * 200, finish_reason=stop, ~40 completion tokens of pure whitespace. It
         * bills, it does not error, and every answer falls through to human
         * review as though the model had merely been unavailable.
         *
         * The belt-and-braces trailer was never needed anyway:
         * response_format=json_object already forbids prose and fences. Adding
         * emphasis here measurably costs marks.
         *
         * BUT THIS WORDING IS NOT THE FIX, ONLY A CONTRIBUTOR. The corrected
         * phrasing marked correctly twice and then blanked three times on the
         * same bytes. What actually settled it was feeding the answers as JSON
         * (answersAsJson()); the safety net is DeepSeekService::perturb(), whose
         * last attempt leaves JSON mode. Both are still here because this clause
         * was measured to matter - just not to be sufficient.
         */
        if ($format === 'coding') {
            return 'You review code submissions against a stated task. You judge whether the code '
                 . 'actually solves the task, how it handles edge cases, and whether it is readable. '
                 . 'A correct solution that differs from any example implementation is fully correct. '
                 . 'You reply with a single valid JSON object.';
        }

        return 'You mark workplace capability answers. You are strict but fair, you mark only '
             . 'against the reference answer given, and you reply with a single valid JSON object.';
    }

    /*
     * ── HOW MARKING WAS FIXED, IN THE ORDER THE CAUSES ACTUALLY MATTERED ─────
     *
     * Marking returned HTTP 200, finish_reason=stop, and ~40 completion tokens of
     * PURE WHITESPACE. It billed, it never errored, and every written answer fell
     * through to human review as though the model had been unavailable. Three
     * things were found, and only the first is the root cause:
     *
     *   1. FORMAT IMITATION - the real one. See answersAsJson() below. The
     *      answers were fed as `response_id=34 / MAX SCORE: 30 / QUESTION: ...`,
     *      and the model imitated that shape instead of answering. Feeding them
     *      as JSON took marking from intermittent to 6/6 with zero retries.
     *
     *   2. SYSTEM-MESSAGE WORDING - real, secondary. See markingSystemPrompt().
     *      Measurably shifted the failure rate; never eliminated it.
     *
     *   3. THE PROMPT ENDING ON A JSON LITERAL - both prompts used to close with
     *      `Return JSON: {"marks":[...]}`, which is one more thing to continue
     *      rather than obey. Hence the shape kept here: schema declared BEFORE
     *      the data, and the prompt ending on an imperative with nothing
     *      continuable after it.
     *
     * The lesson worth keeping is diagnostic, not cosmetic: a blank JSON-mode
     * reply usually means the model is CONTINUING something in the prompt. Resend
     * with response_format removed and read what it writes - that is what made an
     * apparent DeepSeek fault legible in one call.
     */
    /**
     * The answers to be marked, as a JSON array.
     *
     * ── WHY THE INPUT IS JSON AND NOT THE READABLE KEY: VALUE BLOCK ──────────
     *
     * The block used to read:
     *
     *     response_id=34
     *     MAX SCORE: 30
     *     QUESTION: ...
     *
     * and marking failed intermittently. The plain-text fallback showed why: the
     * reply came back as `_id=34 score=30 feedback="..."`. The model was not
     * answering in JSON at all - it was CONTINUING the shape of the data it had
     * just been shown, because `response_id=34` is a pattern to imitate. In JSON
     * mode that continuation is unemittable, so the API returned whitespace and
     * every answer silently fell through to human review.
     *
     * Feeding the answers as JSON makes imitation produce exactly what is wanted.
     * The instructions stay prose, because those are not meant to be copied.
     *
     * JSON_UNESCAPED_* keeps the model reading the candidate's own words rather
     * than \u-escapes, which matters when the answer is not in English. Code is a
     * plain string field for the same reason a ``` fence was dropped earlier: a
     * fenced block invited a reply that began "Python:" with the candidate's code
     * echoed back as prose.
     *
     * @param  \Illuminate\Support\Collection  $pending
     */
    private function answersAsJson($pending, callable $shape): string
    {
        return (string) json_encode(
            $pending->map($shape)->values()->all(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );
    }

    private function markingPrompt($pending, string $format = 'short_answer'): string
    {
        if ($format === 'coding') {
            $blocks = $this->answersAsJson($pending, fn ($row) => [
                'response_id'          => (int) $row->id,
                'max_score'            => (float) $row->max_score,
                'task'                 => (string) $row->question_text,
                'a_good_solution_does' => (string) ($row->model_answer ?: '(not specified - judge against the task alone)'),
                'submitted_code'       => (string) $row->answer_text,
            ]);

            return <<<TXT
Review each code submission below and award a mark.

OUTPUT
Reply with one JSON object of this shape and nothing else. Do not restate the code.
{"marks":[{"response_id":<the id given>,"score":<number>,"feedback":<one sentence>}]}

RULES
- Score between 0 and that submission's max_score. Never above it.
- Judge against the task, not against any one implementation. A correct solution
  written differently from what you would have written is still fully correct.
- Weigh, in this order: does it solve the task, does it handle edge and boundary
  cases, is it reasonably efficient, is it readable.
- Code that cannot run, or that solves a different problem, scores 0.
- Pseudocode that is clearly correct earns partial credit, not full marks.
- Give one short sentence of feedback naming the single most useful thing to fix.
- Return every response_id you were given, exactly once.

SUBMISSIONS (JSON array)
$blocks

Mark every submission above and reply with that JSON object now.
TXT;
        }

        $blocks = $this->answersAsJson($pending, fn ($row) => [
            'response_id'      => (int) $row->id,
            'max_score'        => (float) $row->max_score,
            'question'         => (string) $row->question_text,
            'reference_answer' => (string) ($row->model_answer ?: '(none given - mark on the question alone)'),
            'candidate_answer' => (string) $row->answer_text,
        ]);

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
}
