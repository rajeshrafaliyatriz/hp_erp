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

    public function __construct(private readonly DeepSeekService $ai)
    {
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
        $pending = DB::table('competency_assessment_response as r')
            ->join('competency_assessment_question as q', 'q.id', '=', 'r.question_id')
            ->join('competency_assessment_attempt as a', function ($j) {
                $j->on('a.test_id', '=', 'r.test_id')->on('a.user_id', '=', 'r.user_id');
            })
            ->where('a.id', $attemptId)
            ->where('r.sub_institute_id', $tenantId)
            ->where('q.format', 'short_answer')
            ->whereNull('r.score')
            ->whereNotNull('r.answer_text')
            ->where('r.answer_text', '<>', '')
            ->get(['r.id', 'q.question_text', 'q.model_answer', 'q.max_score', 'r.answer_text']);

        if ($pending->isEmpty()) {
            return ['scored' => 0, 'failed' => 0, 'reason' => null];
        }

        if (!$this->ai->isConfigured()) {
            return ['scored' => 0, 'failed' => $pending->count(), 'reason' => 'not_configured'];
        }

        try {
            $result = $this->ai->chatJson([
                ['role' => 'system', 'content' =>
                    'You mark workplace capability answers. You are strict but fair, you mark only '
                    . 'against the reference answer given, and you return only valid JSON.'],
                ['role' => 'user', 'content' => $this->markingPrompt($pending)],
            ], ['json' => true, 'temperature' => 0.2]);
        } catch (\Throwable $e) {
            Log::warning('Short-answer marking failed; answers left for human review', [
                'attempt' => $attemptId, 'error' => $e->getMessage(),
            ]);
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

            DB::table('competency_assessment_response')->where('id', $row->id)->update([
                'score'      => $score,
                'scored_by'  => 'ai',
                'updated_at' => now(),
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
     * @return array{total:float, max:float, percent:?float, awaiting:int, proposals:int}
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

        $total = 0.0; $max = 0.0; $awaiting = 0;

        foreach ($rows as $row) {
            $max += (float) $row->max_score;
            if ($row->score !== null) {
                $total += (float) $row->score;
            } elseif ($row->answered_at !== null) {
                // Answered but not yet marked. Counted as outstanding, NOT as 0.
                $awaiting++;
            }
        }

        $percent = $max > 0 ? round($total / $max * 100, 2) : null;

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
            'proposals' => $this->buildProposals($attempt, $rows, $tenantId),
            'cycles'    => $this->feedReviewCycles($attempt, $percent, $tenantId),
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
                    $common
                );
            }

            // The direct row too, when the item resolves to a library entry -
            // the same pair storeByItem() keeps in step.
            if ($proposal->kasba_type && $proposal->item_id) {
                $this->upsertRating(
                    ['sub_institute_id' => $tenantId, 'user_id' => $proposal->user_id,
                     'kasba_type' => $proposal->kasba_type, 'item_id' => $proposal->item_id],
                    $common
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
    private function upsertRating(array $keys, array $values): void
    {
        $exists = DB::table('competency_kasba_rating')->where($keys)->exists();

        if ($exists) {
            DB::table('competency_kasba_rating')->where($keys)->update($values);

            return;
        }

        DB::table('competency_kasba_rating')->insert($keys + $values + ['created_at' => now()]);
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
    private function markingPrompt($pending): string
    {
        $blocks = $pending->map(function ($row) {
            return "response_id={$row->id}\n"
                . "MAX SCORE: {$row->max_score}\n"
                . "QUESTION: {$row->question_text}\n"
                . 'REFERENCE ANSWER: ' . ($row->model_answer ?: '(none given - mark on the question alone)') . "\n"
                . "CANDIDATE ANSWER: {$row->answer_text}";
        })->implode("\n\n---\n\n");

        return <<<TXT
Mark each answer below.

RULES
- Score between 0 and that answer's MAX SCORE. Never above it.
- Mark against the REFERENCE ANSWER where one is given. Reward correct substance,
  not length or confidence.
- A blank or irrelevant answer scores 0.
- Give one short sentence of feedback the person could act on.
- Return every response_id you were given, exactly once.

ANSWERS
$blocks

Return JSON: {"marks":[{"response_id":123,"score":1,"feedback":"..."}]}
TXT;
    }
}
