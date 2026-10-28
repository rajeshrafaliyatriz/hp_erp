<?php

namespace App\Services\Competency;

use App\Services\Events\EventRecorder;
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
 * ── PHASE 0: THIS WAS THE ONLY WIRED PATH ───────────────────────────────────
 *
 * Measured by grep before this change: `writeItem`/`writeCompetency` had exactly
 * one external caller, QuizScoringService. KasbaRatingController::store(),
 * ::storeByItem(), ::destroy(), MyRatingController::store() and
 * AssessmentScoringService::approve() each did their own direct upsert into
 * competency_kasba_rating with no history and none of the policy below — so a
 * rating change only ever became visible, auditable or reactable-to when it came
 * from a quiz. Those five call sites now all go through this class. The methods
 * below exist to give each of their shapes — a single item, a competency's full
 * KASBA set, a library item's dual keying, an assessment's dual keying, and a
 * withdrawal — one writer and one emission point.
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
 *    HR rating an employee directly (KasbaRatingController) and HR rating a
 *    library item (storeByItem) are themselves 'manual' / human judgement, so
 *    they pass `force: true` to re-rate something HR already rated — the
 *    ASSESSOR_SOURCES guard exists to stop a *derived* source overwriting a
 *    person's judgement, not to stop HR re-rating their own prior judgement.
 *
 * 2. AUTO-APPLY RAISES. A DROP STAYS A PROPOSAL — for a DERIVED source only.
 *
 *    Applying a decrease without review is the one irreversible-feeling failure
 *    for a source nobody chose to trust that much: a badly generated question
 *    set quietly marks somebody down, and the first they know is a worse
 *    record. A drop from a quiz is still recorded as a pending proposal, so the
 *    evidence is not lost and a human can accept it.
 *
 *    HR and an employee's own self-rating are not derived — a person is
 *    deciding, in the moment, to lower a rating, and refusing to record what
 *    they just decided would make HR unable to correct a rating they got
 *    wrong. Both pass `allow_lower: true`.
 *
 * Both are reversible because every write appends to competency_rating_history
 * with the value it replaced.
 *
 * ── EMISSION: ONE EVENT PER (user, competency) PER LOGICAL WRITE ────────────
 *
 * A caller never emits directly. `competency.rating_changed` is recorded once,
 * inside the same transaction as the write(s) it describes, by whichever public
 * method the caller used — never per KASBA item. writeCompetency() looping
 * writeItem() once per item would otherwise emit once per item for what is, to
 * the gap-response chain, one change; coalescing at the competency grain is what
 * makes lms_assignments_origin_unique actually protective downstream.
 *
 * The payload never guesses an old/new LEVEL — only ProficiencyService::rollUp
 * derives a level, and this class never calls it. It names which KASBA items
 * changed and lets the reactor re-run the gap comparison fresh. See
 * EventCatalogue's header on why a guessed `from` is worse than no event.
 */
class RatingWriter
{
    /** Outcomes of a write attempt, returned so callers can report honestly. */
    public const WRITTEN = 'written';
    public const UNCHANGED = 'unchanged';
    public const SKIPPED_ASSESSOR = 'skipped_assessor_set';
    public const SKIPPED_LOWER = 'skipped_would_lower';
    public const CLEARED = 'cleared';

    /**
     * Sources that represent a person's explicit judgement about someone else.
     * A derived rating never overwrites one of these, unless the caller is
     * itself that same kind of judgement and passes `force`.
     */
    private const ASSESSOR_SOURCES = ['manual'];

    public function __construct(private readonly EventRecorder $events)
    {
    }

    /**
     * Write one KASBA-item rating and record what it replaced.
     *
     * No emission here — see the class header. Called directly by
     * writeCompetency()'s loop (which emits once for the whole competency) and
     * by writeAssessmentResult(); a caller writing a single standalone item
     * should use writeItemAndEmit() instead.
     *
     * @param  array{
     *     tenant:int, user_id:int, kasba_item_id:int, rating:int, source:string,
     *     assessor_id?:int|null, source_ref_id?:int|null, note?:string|null,
     *     competency_id?:int|null, course_id?:int|null, item_label?:string|null,
     *     kasba_type?:string|null, item_id?:int|null, allow_lower?:bool, force?:bool
     * }  $c
     */
    public function writeItem(array $c): string
    {
        return $this->writeRow([
            'sub_institute_id' => $c['tenant'],
            'user_id' => $c['user_id'],
            'kasba_item_id' => $c['kasba_item_id'],
        ], $c);
    }

    /**
     * Write one item and emit, for a caller that is not looping over a
     * competency's items — KasbaRatingController::store(), MyRatingController.
     */
    public function writeItemAndEmit(array $c): string
    {
        return DB::transaction(function () use ($c) {
            $outcome = $this->writeItem($c);

            if ($outcome === self::WRITTEN && !empty($c['competency_id'])) {
                $this->emitRatingChanged(
                    (int) $c['tenant'],
                    (int) $c['user_id'],
                    (int) $c['competency_id'],
                    (string) $c['source'],
                    $c['assessor_id'] ?? null,
                    [(int) $c['kasba_item_id']]
                );
            }

            return $outcome;
        });
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
     * One event, for the whole competency, if anything actually moved — never
     * one per item. See the class header.
     *
     * @param  array<int,int>  $perItem  kasba_item_id => rating, overriding the blanket value
     * @return array{written:int, skipped:int, items:array<int,string>}
     */
    public function writeCompetency(array $c, array $perItem = []): array
    {
        return DB::transaction(function () use ($c, $perItem) {
            $items = DB::table('competency_kasba_item')
                ->where('sub_institute_id', $c['tenant'])
                ->where('competency_id', $c['competency_id'])
                ->get(['id', 'kasba_type', 'item_id', 'item_label']);

            $written = 0;
            $skipped = 0;
            $outcomes = [];
            $changedItemIds = [];

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

                if ($outcome === self::WRITTEN) {
                    $written++;
                    $changedItemIds[] = (int) $item->id;
                } else {
                    $skipped++;
                }
            }

            if ($written > 0 && !empty($c['competency_id'])) {
                $this->emitRatingChanged(
                    (int) $c['tenant'],
                    (int) $c['user_id'],
                    (int) $c['competency_id'],
                    (string) $c['source'],
                    $c['assessor_id'] ?? null,
                    $changedItemIds
                );
            }

            return ['written' => $written, 'skipped' => $skipped, 'items' => $outcomes];
        });
    }

    /**
     * Rate a LIBRARY ITEM directly: the (kasba_type, item_id)-keyed row, and
     * one row per competency the item is linked into.
     *
     * This is KasbaRatingController::storeByItem()'s dual write, moved here so
     * it goes through the same decide()+history path as every other write.
     * One event per competency actually touched, since a single library item
     * can sit in several competencies and each is a separate gap to re-check.
     *
     * @return array{written:int, skipped:int, direct:string, competencies_hit:int}
     */
    public function writeLibraryItem(array $c): array
    {
        return DB::transaction(function () use ($c) {
            $direct = $this->writeRow([
                'sub_institute_id' => $c['tenant'],
                'user_id' => $c['user_id'],
                'kasba_type' => $c['kasba_type'],
                'item_id' => $c['item_id'],
            ], $c);

            $linked = DB::table('competency_kasba_item')
                ->where('sub_institute_id', $c['tenant'])
                ->where('kasba_type', $c['kasba_type'])
                ->where('item_id', $c['item_id'])
                ->get(['id', 'competency_id']);

            $written = $direct === self::WRITTEN ? 1 : 0;
            $skipped = $direct === self::WRITTEN ? 0 : 1;

            /** @var array<int,list<int>> $touched competency_id => changed kasba_item_ids */
            $touched = [];

            foreach ($linked as $row) {
                $outcome = $this->writeItem(array_merge($c, ['kasba_item_id' => (int) $row->id]));

                if ($outcome === self::WRITTEN) {
                    $written++;
                    if ($row->competency_id) {
                        $touched[(int) $row->competency_id][] = (int) $row->id;
                    }
                } else {
                    $skipped++;
                }
            }

            foreach ($touched as $competencyId => $itemIds) {
                $this->emitRatingChanged(
                    (int) $c['tenant'],
                    (int) $c['user_id'],
                    $competencyId,
                    (string) $c['source'],
                    $c['assessor_id'] ?? null,
                    $itemIds
                );
            }

            return [
                'written' => $written,
                'skipped' => $skipped,
                'direct' => $direct,
                'competencies_hit' => count($touched),
            ];
        });
    }

    /**
     * Two keyings of ONE assessment result — AssessmentScoringService::approve()'s
     * shape, which neither loops a competency's items nor a library item's
     * links, so it gets its own entry point rather than forcing that shape.
     *
     * Both passed `force: true, allow_lower: true` by the caller: an approved
     * assessment is a person's considered decision to accept a proposed rating,
     * the same standing as a manual rating, not a value to be silently blocked
     * or one-way like a quiz's auto-apply.
     */
    public function writeAssessmentResult(array $c): string
    {
        return DB::transaction(function () use ($c) {
            $outcome = self::UNCHANGED;

            if (!empty($c['kasba_item_id'])) {
                $outcome = $this->writeItem($c);
            }

            if (!empty($c['kasba_type']) && !empty($c['item_id'])) {
                $directOutcome = $this->writeRow([
                    'sub_institute_id' => $c['tenant'],
                    'user_id' => $c['user_id'],
                    'kasba_type' => $c['kasba_type'],
                    'item_id' => $c['item_id'],
                ], $c);

                // The kasba_item_id write is the one with a competency_id to
                // report on; the direct library row never carries one (see
                // writeRow's keying) so it cannot drive the emission on its
                // own — it only has a say in WRITTEN vs UNCHANGED overall.
                if ($outcome === self::UNCHANGED) {
                    $outcome = $directOutcome;
                }
            }

            if ($outcome === self::WRITTEN && !empty($c['competency_id'])) {
                $this->emitRatingChanged(
                    (int) $c['tenant'],
                    (int) $c['user_id'],
                    (int) $c['competency_id'],
                    (string) $c['source'],
                    $c['assessor_id'] ?? null,
                    !empty($c['kasba_item_id']) ? [(int) $c['kasba_item_id']] : []
                );
            }

            return $outcome;
        });
    }

    /**
     * Return an item to UNMEASURED.
     *
     * This is the only way to express "we no longer have a view on this", and
     * it is why deletion exists at all: overwriting with a low rating would say
     * something false. Recorded in history with new_rating NULL — distinct from
     * old_rating NULL, which means first measurement.
     *
     * A clearing can CLOSE a flagged gap (back to unmeasured, which is not a
     * shortfall) exactly as a raise can, so it emits on the same terms as a
     * write: once, for the competency the item belongs to, if it belongs to one.
     */
    public function clearItem(int $tenant, int $userId, int $kasbaItemId, string $source = 'manual', ?int $actorId = null): string
    {
        return DB::transaction(function () use ($tenant, $userId, $kasbaItemId, $source, $actorId) {
            $current = DB::table('competency_kasba_rating')
                ->where('sub_institute_id', $tenant)
                ->where('user_id', $userId)
                ->where('kasba_item_id', $kasbaItemId)
                ->first(['rating']);

            if (!$current) {
                return self::UNCHANGED;
            }

            $item = DB::table('competency_kasba_item')
                ->where('id', $kasbaItemId)
                ->first(['competency_id', 'kasba_type', 'item_id', 'item_label']);

            DB::table('competency_kasba_rating')
                ->where('sub_institute_id', $tenant)
                ->where('user_id', $userId)
                ->where('kasba_item_id', $kasbaItemId)
                ->delete();

            $now = now();

            DB::table('competency_rating_history')->insert([
                'sub_institute_id' => $tenant,
                'user_id' => $userId,
                'kasba_item_id' => $kasbaItemId,
                'kasba_type' => $item->kasba_type ?? null,
                'item_id' => $item->item_id ?? null,
                'item_label' => $item->item_label ?? null,
                'competency_id' => $item->competency_id ?? null,
                'course_id' => null,
                'old_rating' => $current->rating,
                // NULL here, deliberately — "returned to unmeasured", not a
                // rating of zero. Distinct from old_rating NULL above, which
                // means "this was the first measurement".
                'new_rating' => null,
                'source' => $source,
                'source_ref_id' => null,
                'assessor_id' => $actorId,
                'note' => 'Rating withdrawn; item returned to unmeasured.',
                'changed_at' => $now,
                'created_at' => $now,
            ]);

            if (!empty($item->competency_id)) {
                $this->emitRatingChanged($tenant, $userId, (int) $item->competency_id, $source, $actorId, [$kasbaItemId]);
            }

            return self::CLEARED;
        });
    }

    /** The whole policy, in one place, so it reads as a policy. */
    private function decide(?object $current, int $rating, bool $allowLower, bool $force): string
    {
        if (!$current) {
            return self::WRITTEN;
        }

        if (!$force && in_array($current->source, self::ASSESSOR_SOURCES, true)) {
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
     * The one upsert+history write, under either keying: $keys identifies the
     * row (kasba_item_id, OR kasba_type+item_id both present), $c carries the
     * value and the history fields. Every public write method above funnels
     * through this.
     */
    private function writeRow(array $keys, array $c): string
    {
        $current = DB::table('competency_kasba_rating')->where($keys)->first(['rating', 'source']);

        $decision = $this->decide(
            $current,
            (int) $c['rating'],
            (bool) ($c['allow_lower'] ?? false),
            (bool) ($c['force'] ?? false)
        );

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
                'kasba_item_id' => $keys['kasba_item_id'] ?? null,
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
     * Record `competency.rating_changed`. The only thing this class ever
     * emits, and the only place it does so — every public write method above
     * calls here, never EventRecorder directly.
     *
     * No old/new LEVEL in the payload: deriving one means calling
     * ProficiencyService::rollUp, which this class deliberately never does.
     * The reactor re-runs the gap comparison itself, against the current state,
     * which is the only honest way to answer "does this still matter".
     *
     * @param  list<int>  $kasbaItemIdsChanged
     */
    private function emitRatingChanged(
        int $tenant,
        int $userId,
        int $competencyId,
        string $source,
        ?int $assessorId,
        array $kasbaItemIdsChanged
    ): void {
        $competencyName = DB::table('competency')->where('id', $competencyId)->value('name');

        $this->events->record(
            'competency.rating_changed',
            $tenant,
            'competency',
            $competencyId,
            $assessorId,
            [
                'user_id' => $userId,
                'competency_id' => $competencyId,
                'competency_name' => $competencyName,
                'source' => $source,
                'kasba_item_ids_changed' => array_values(array_unique(array_map('intval', $kasbaItemIdsChanged))),
            ]
        );
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
