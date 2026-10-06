<?php

namespace App\Services\Competency;

use Illuminate\Support\Facades\DB;

/**
 * SLICE 1, ITEM 6 — THE ONE NAMED ROLL-UP (Q-E2).
 *
 * Competency proficiency is DERIVED from per-item KASBA ratings. It is never
 * stored, never entered directly, and never computed anywhere else.
 *
 * ─── NO SCREEN, REPORT OR EXPORT DOES ITS OWN ARITHMETIC ────────────────────
 * Every caller asks this class. The rule is not "prefer this service" - it is
 * that a second implementation is a second answer, and two numbers that disagree
 * are worse than one number that is wrong, because nobody knows which to trust.
 *
 * ─── UNMEASURED IS NEITHER ZERO NOR PASS ────────────────────────────────────
 * The single easiest thing here to get wrong, and the first thing an auditor at a
 * regulated customer will test.
 *
 *   - An item with NO RATING ROW is UNMEASURED. It is not scored 0, and it is
 *     not skipped as if it passed.
 *   - Unmeasured items are EXCLUDED from the weighted average - averaging them
 *     as zero would understate, and treating them as met would overstate.
 *   - The measured WEIGHT is reported alongside, so a roll-up computed from 20%
 *     of a competency's weight can never be read as a full measurement.
 *   - A competency with NOTHING measured returns level NULL, not 0.
 *
 * That is why the return carries `measured_weight` and `total_weight` rather
 * than a single number: a level without its coverage is not interpretable.
 */
class ProficiencyService
{
    /**
     * Roll up one employee's proficiency for the given competencies.
     *
     * @param  int[]  $competencyIds
     * @return array<int, array{level: float|null, measured_weight: float, total_weight: float, coverage: float, items: array}>
     */
    public function rollUp(int $tenantId, int $userId, array $competencyIds): array
    {
        if ($competencyIds === []) {
            return [];
        }

        $items = DB::table('competency_kasba_item as i')
            ->leftJoin('competency_kasba_rating as r', function ($join) use ($userId, $tenantId) {
                $join->on('r.kasba_item_id', '=', 'i.id')
                    ->where('r.user_id', '=', $userId)
                    ->where('r.sub_institute_id', '=', $tenantId);
            })
            ->where('i.sub_institute_id', $tenantId)
            ->whereIn('i.competency_id', $competencyIds)
            ->get([
                'i.id', 'i.competency_id', 'i.kasba_type', 'i.item_id', 'i.item_label',
                'i.weight', 'r.rating', 'r.rated_at',
            ]);

        $out = [];

        foreach ($competencyIds as $cid) {
            $own = $items->where('competency_id', $cid);

            $totalWeight    = 0.0;
            $measuredWeight = 0.0;
            $weighted       = 0.0;
            $detail         = [];

            foreach ($own as $it) {
                $w = (float) $it->weight;
                $totalWeight += $w;

                // The distinction the whole class exists to preserve.
                $measured = $it->rating !== null;

                if ($measured) {
                    $measuredWeight += $w;
                    $weighted       += $w * (float) $it->rating;
                }

                $detail[] = [
                    'kasba_item_id' => (int) $it->id,
                    'kasba_type'    => $it->kasba_type,
                    'item_id'       => $it->item_id ? (int) $it->item_id : null,
                    'item_label'    => $it->item_label,
                    'weight'        => $w,
                    'rating'        => $measured ? (int) $it->rating : null,
                    'measured'      => $measured,
                ];
            }

            $out[$cid] = [
                // NULL, not 0, when nothing is measured. A competency nobody has
                // been rated on has no level - it does not have a level of zero.
                'level'           => $measuredWeight > 0 ? round($weighted / $measuredWeight, 2) : null,
                'measured_weight' => round($measuredWeight, 4),
                'total_weight'    => round($totalWeight, 4),
                // How much of the competency this level actually speaks for.
                'coverage'        => $totalWeight > 0 ? round($measuredWeight / $totalWeight, 4) : 0.0,
                'items'           => $detail,
            ];
        }

        return $out;
    }

    /**
     * The same roll-up, for many people at once.
     *
     * ── WHY THIS EXISTS RATHER THAN A LOOP ──────────────────────────────────
     *
     * rollUp() binds one user_id INSIDE the join, so answering "who is below
     * the bar on this competency" by calling it per person is one query per
     * employee - on a preview that re-runs as the admin ticks boxes, against a
     * resolver that legitimately returns hundreds of people.
     *
     * The arithmetic is deliberately NOT reimplemented in SQL. It is the same
     * fold as rollUp(), over rows fetched once, so the two cannot drift: this
     * class's whole reason for existing is that a weighted mean computed in two
     * places eventually becomes two different answers, and ResolvesCompetencyGap
     * records what that cost last time.
     *
     * @param  list<int>  $userIds
     * @param  list<int>  $competencyIds
     * @return array<int,array<int,array{level:float|null, coverage:float, measured_weight:float, total_weight:float}>>
     *         keyed user_id => competency_id => figures
     */
    public function rollUpMany(int $tenantId, array $userIds, array $competencyIds): array
    {
        if ($userIds === [] || $competencyIds === []) {
            return [];
        }

        /*
         * TWO READS, NOT ONE LEFT JOIN.
         *
         * A left join here repeats an item once per rating it matched, so with
         * many users in scope an item rated by somebody else appears only under
         * THEIR row - and folding that into a per-person total silently drops
         * the item from everyone else's denominator, understating their
         * coverage. Reading the items and the ratings separately makes the
         * denominator per-person-independent, which is what it actually is.
         */
        $items = DB::table('competency_kasba_item')
            ->where('sub_institute_id', $tenantId)
            ->whereIn('competency_id', $competencyIds)
            ->get(['id', 'competency_id', 'weight']);

        $ratings = DB::table('competency_kasba_rating')
            ->where('sub_institute_id', $tenantId)
            ->whereIn('user_id', $userIds)
            ->whereIn('kasba_item_id', $items->pluck('id'))
            ->get(['user_id', 'kasba_item_id', 'rating'])
            // (user, item) is unique on this table, so one rating per pair.
            ->keyBy(fn ($r) => $r->user_id . ':' . $r->kasba_item_id);

        $byCompetency = $items->groupBy('competency_id');
        $out = [];

        foreach ($userIds as $userId) {
            foreach ($competencyIds as $cid) {
                $totalWeight = 0.0;
                $measuredWeight = 0.0;
                $weighted = 0.0;

                foreach ($byCompetency->get($cid) ?? collect() as $item) {
                    $w = (float) $item->weight;
                    // Every item counts toward the denominator whether or not
                    // this person has been rated on it - that is what coverage
                    // measures.
                    $totalWeight += $w;

                    $rating = $ratings->get($userId . ':' . $item->id);

                    // Unmeasured is the ABSENCE of a rating, never a zero.
                    if ($rating && $rating->rating !== null) {
                        $measuredWeight += $w;
                        $weighted += $w * (float) $rating->rating;
                    }
                }

                $out[$userId][$cid] = [
                    'level' => $measuredWeight > 0 ? round($weighted / $measuredWeight, 2) : null,
                    'measured_weight' => round($measuredWeight, 4),
                    'total_weight' => round($totalWeight, 4),
                    'coverage' => $totalWeight > 0 ? round($measuredWeight / $totalWeight, 4) : 0.0,
                ];
            }
        }

        return $out;
    }
}
