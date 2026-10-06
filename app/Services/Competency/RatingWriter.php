<?php

namespace App\Services\Competency;

use Illuminate\Support\Facades\DB;

/**
 * The one place a capability rating is written, and the only place its change
 * is recorded.
 *
 * ── WHY THIS IS A SERVICE AND NOT TWO MORE UPSERTS ──────────────────────────
 *
 * Three writers already upsert competency_kasba_rating with slightly different
 * rules — KasbaRatingController (fans a library item out across the
 * competencies that contain it), AssessmentScoringService::approve (writes both
 * keyings when the proposal resolves to a library item) and
 * QuizScoringService::applyRating (wrote neither keying the readers use — see
 * below). Adding history to each of them separately is how they drift.
 *
 * This codebase already paid for that lesson once: ResolvesCompetencyGap's own
 * header records that a second implementation of the gap comparison produced an
 * 85.9% wrong-answer rate. One writer, or the rules diverge.
 *
 * ── THE DEFECT THIS FIXES ───────────────────────────────────────────────────
 *
 * QuizScoringService wrote a rating keyed
 *
 *     (sub_institute_id, user_id, kasba_type = 'competency', item_id = <competency>)
 *
 * with kasba_item_id left NULL. Every reader joins on kasba_item_id:
 * ProficiencyService::rollUp (the only place a competency level is derived) and
 * KasbaRatingController::index (the employee drawer) both do
 * `on('r.kasba_item_id', '=', 'i.id')`. NULL never matches. So a passing learner's
 * rating was written, reported back to them as "your competency rating has been
 * updated", and was invisible to their level, their gap, the Me-dashboard radar,
 * the drawer and career-path readiness — everywhere it was supposed to show.
 *
 * 'competency' was also not a value any reader understood: competency_kasba_item
 * .kasba_type is one of skill / knowledge / ability / attitude / behaviour, and
 * nothing joins or filters on 'competency'.
 *
 * No rows exist in either database with that shape, so nothing needs
 * back-filling — the feature had simply never worked.
 *
 * ── THE TWO POLICY DECISIONS, STATED RATHER THAN BURIED ─────────────────────
 *
 * 1. A QUIZ MAY SUPERSEDE A SELF-RATING. IT MAY NOT SUPERSEDE AN ASSESSOR.
 *
 *    The previous rule skipped both 'manual' and 'self', justified as: "A
 *    manager's considered judgement being silently replaced by a
 *    multiple-choice score is exactly the failure mode the human gate existed
 *    to prevent." That argument is right, and it is an argument about 'manual'.
 *
 *    A self-rating is the weakest evidence in the system — an unvalidated claim
 *    by the person being measured. A scored assessment is stronger evidence, and
 *    letting the weaker one block the stronger means the measurement never
 *    counts for anyone who has ever rated themselves. On live tenant 6 that is
 *    15 of Milan's 20 ratings, so under the old rule his gap could never close
 *    no matter what he passed.
 *
 * 2. AUTO-APPLY RAISES. A DROP STAYS A PROPOSAL.
 *
 *    Applying a decrease without review is the one irreversible-feeling failure:
 *    a badly generated question set quietly marks somebody down, and the first
 *    they know is a worse record. A drop is still recorded as a pending
 *    proposal, so the evidence is not lost and a human can accept it.
 *
 * Both are reversible because every write appends to competency_rating_history
 * with the value it replaced.
 */
class RatingWriter
{
    /** Outcomes of a write attempt, returned so callers can report honestly. */
    public const WRITTEN = 'written';
    public const UNCHANGED = 'unchanged';
    public const SKIPPED_ASSESSOR = 'skipped_assessor_set';
    public const SKIPPED_LOWER = 'skipped_would_lower';

    /**
     * Sources that represent a person's explicit judgement about someone else.
     * A derived rating never overwrites one of these.
     */
    private const ASSESSOR_SOURCES = ['manual'];

    /**
     * Write one KASBA-item rating and record what it replaced.
     *
     * @param  array{
     *     tenant:int, user_id:int, kasba_item_id:int, rating:int, source:string,
     *     assessor_id?:int|null, source_ref_id?:int|null, note?:string|null,
     *     competency_id?:int|null, course_id?:int|null, item_label?:string|null,
     *     kasba_type?:string|null, item_id?:int|null, allow_lower?:bool
     * }  $c
     */
    public function writeItem(array $c): string
    {
        $keys = [
            'sub_institute_id' => $c['tenant'],
            'user_id' => $c['user_id'],
            'kasba_item_id' => $c['kasba_item_id'],
        ];

        $current = DB::table('competency_kasba_rating')
            ->where($keys)
            ->first(['rating', 'source']);

        $decision = $this->decide($current, (int) $c['rating'], (bool) ($c['allow_lower'] ?? false));

        if ($decision !== self::WRITTEN) {
            return $decision;
        }

        $now = now();

        $values = [
            'rating' => (int) $c['rating'],
            'assessor_id' => $c['assessor_id'] ?? null,
            'source' => $c['source'],
            'source_ref_id' => $c['source_ref_id'] ?? null,
            'note' => $c['note'] ?? null,
            'rated_at' => $now,
            'updated_at' => $now,
        ];

        DB::transaction(function () use ($keys, $values, $current, $c, $now) {
            if ($current) {
                DB::table('competency_kasba_rating')->where($keys)->update($values);
            } else {
                DB::table('competency_kasba_rating')->insert($keys + $values + ['created_at' => $now]);
            }

            DB::table('competency_rating_history')->insert([
                'sub_institute_id' => $c['tenant'],
                'user_id' => $c['user_id'],
                'kasba_item_id' => $c['kasba_item_id'],
                'kasba_type' => $c['kasba_type'] ?? null,
                'item_id' => $c['item_id'] ?? null,
                'item_label' => $c['item_label'] ?? null,
                'competency_id' => $c['competency_id'] ?? null,
                'course_id' => $c['course_id'] ?? null,
                // NULL means first measurement, which is not the same as a rise
                // from zero - zero is deliberately not a rating on this scale.
                'old_rating' => $current->rating ?? null,
                'new_rating' => (int) $c['rating'],
                'source' => $c['source'],
                'source_ref_id' => $c['source_ref_id'] ?? null,
                'assessor_id' => $c['assessor_id'] ?? null,
                'note' => $c['note'] ?? null,
                'changed_at' => $now,
                'created_at' => $now,
            ]);
        });

        return self::WRITTEN;
    }

    /**
     * Apply one competency-level result across that competency's KASBA items.
     *
     * Used when the questions carry no capability citation — a hand-authored
     * quiz — so the only honest reading of the score is "this much of the
     * competency as a whole". Every item under it moves together.
     *
     * When questions DO cite items, the caller passes $perItem and each item
     * moves on its own evidence, which is the point of measuring at this level.
     *
     * @param  array<int,int>  $perItem  kasba_item_id => rating, overriding the blanket value
     * @return array{written:int, skipped:int, items:array<int,string>}
     */
    public function writeCompetency(array $c, array $perItem = []): array
    {
        $items = DB::table('competency_kasba_item')
            ->where('sub_institute_id', $c['tenant'])
            ->where('competency_id', $c['competency_id'])
            ->get(['id', 'kasba_type', 'item_id', 'item_label']);

        $written = 0;
        $skipped = 0;
        $outcomes = [];

        foreach ($items as $item) {
            /*
             * array_merge, NOT the + operator.
             *
             * `$c + [...]` keeps the LEFT operand on a key collision, so the
             * per-item rating was silently discarded and every item was written
             * with the blanket competency-wide value - which is exactly the
             * behaviour this method exists to avoid. It looked correct in a
             * live test until one item was deliberately answered badly and came
             * back with the same rating as the three answered well.
             */
            $outcome = $this->writeItem(array_merge($c, [
                'kasba_item_id' => (int) $item->id,
                'kasba_type' => $item->kasba_type,
                'item_id' => $item->item_id,
                'item_label' => $item->item_label,
                'rating' => $perItem[(int) $item->id] ?? $c['rating'],
            ]));

            $outcomes[(int) $item->id] = $outcome;
            $outcome === self::WRITTEN ? $written++ : $skipped++;
        }

        return ['written' => $written, 'skipped' => $skipped, 'items' => $outcomes];
    }

    /** The whole policy, in one place, so it reads as a policy. */
    private function decide(?object $current, int $rating, bool $allowLower): string
    {
        if (!$current) {
            return self::WRITTEN;
        }

        if (in_array($current->source, self::ASSESSOR_SOURCES, true)) {
            return self::SKIPPED_ASSESSOR;
        }

        if ((int) $current->rating === $rating) {
            // Writing an identical value would append a history row saying
            // nothing changed, which is worse than useless on a trend.
            return self::UNCHANGED;
        }

        if ($rating < (int) $current->rating && !$allowLower) {
            return self::SKIPPED_LOWER;
        }

        return self::WRITTEN;
    }

    /**
     * The history behind one employee's competency, newest first.
     *
     * Read by the capability screens; kept here so the shape of a history row
     * is defined next to the thing that writes it.
     */
    public function historyFor(int $tenant, int $userId, ?int $competencyId = null, int $limit = 200)
    {
        return DB::table('competency_rating_history as h')
            ->leftJoin('competency as c', 'c.id', '=', 'h.competency_id')
            ->leftJoin('sub_std_map as s', 's.id', '=', 'h.course_id')
            ->leftJoin('tbluser as a', 'a.id', '=', 'h.assessor_id')
            ->where('h.sub_institute_id', $tenant)
            ->where('h.user_id', $userId)
            ->when($competencyId, fn ($q) => $q->where('h.competency_id', $competencyId))
            ->orderByDesc('h.changed_at')
            ->orderByDesc('h.id')
            ->limit($limit)
            ->get([
                'h.id', 'h.kasba_item_id', 'h.kasba_type', 'h.item_label',
                'h.competency_id', 'h.course_id', 'h.old_rating', 'h.new_rating',
                'h.source', 'h.source_ref_id', 'h.note', 'h.changed_at',
                'c.name as competency_name',
                's.display_name as course_title',
                DB::raw("TRIM(CONCAT(COALESCE(a.first_name,''),' ',COALESCE(a.last_name,''))) as assessor_name"),
            ]);
    }
}
