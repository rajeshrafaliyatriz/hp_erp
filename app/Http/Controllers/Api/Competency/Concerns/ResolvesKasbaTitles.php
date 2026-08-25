<?php

namespace App\Http\Controllers\Api\Competency\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * GIVES A KASBA ITEM A TITLE THE SCREEN CAN ACTUALLY RENDER.
 *
 * Extracted verbatim from KasbaRatingController so the Competency Framework
 * Studio can reuse it instead of growing a second copy. The rule this codebase
 * already follows for the proficiency roll-up applies here too: a second
 * implementation is a second answer, and two titles that disagree are worse
 * than one that is wrong, because nobody knows which to trust.
 *
 * ── THE DEFECT IT EXISTS FOR ────────────────────────────────────────────────
 *
 * `competency_kasba_item` stores an item one of two ways: RESOLVED, with
 * `item_id` pointing into a library table and `item_label` NULL; or HELD, with
 * the customer's wording in `item_label` and `item_id` NULL. A read that selects
 * only `item_label` gets nothing for the resolved ones - 203 of them on live -
 * while the unresolved ones display fine. **The better the data, the emptier the
 * screen.**
 *
 * ── WHY IN PHP AND NOT A FIVE-WAY LEFT JOIN ─────────────────────────────────
 *
 * The join would be five OUTER joins against five tables to populate one string,
 * and COALESCE across five nullable columns is a shape nobody reads twice. This
 * runs at most one query per dimension actually present, against a fixed and
 * tiny set of ids.
 *
 * ── SOFT-DELETED LIBRARY ROWS ARE STILL TITLED, DELIBERATELY ────────────────
 *
 * No `whereNull('deleted_at')`. If a library item is retired while a competency
 * still references it, the honest thing to show is its name - that is how
 * somebody works out what to fix. Blanking it would hide the problem rather
 * than report it.
 */
trait ResolvesKasbaTitles
{
    /** The five KASBA dimensions and the library table each one names. */
    private static array $kasbaDimensionTables = [
        'skill'     => 's_users_skills',
        'knowledge' => 's_user_knowledge',
        'ability'   => 's_user_ability',
        'attitude'  => 's_user_attitude',
        'behaviour' => 's_user_behaviour',
    ];

    /**
     * Stamps `title` and `title_missing` onto every row.
     *
     * Each row needs `kasba_type`, `item_id` and `item_label`. Mutates in place
     * and returns the same collection, unsorted - callers order it themselves,
     * because the useful order differs per screen.
     *
     * @param  \Illuminate\Support\Collection  $items
     * @return \Illuminate\Support\Collection
     */
    protected function attachKasbaTitles($items, int $subInstituteId)
    {
        // One bucket of ids per dimension actually present - never five queries
        // when the competency only uses two.
        $wanted = [];
        foreach ($items as $row) {
            $type = mb_strtolower((string) $row->kasba_type);
            if ($row->item_id !== null && isset(self::$kasbaDimensionTables[$type])) {
                $wanted[$type][] = (int) $row->item_id;
            }
        }

        $titles = [];
        foreach ($wanted as $type => $ids) {
            $titles[$type] = DB::table(self::$kasbaDimensionTables[$type])
                ->where('sub_institute_id', $subInstituteId)
                ->whereIn('id', array_values(array_unique($ids)))
                ->pluck('title', 'id')
                ->all();
        }

        foreach ($items as $row) {
            $type = mb_strtolower((string) $row->kasba_type);
            $resolved = ($row->item_id !== null && isset($titles[$type][(int) $row->item_id]))
                ? $titles[$type][(int) $row->item_id]
                : null;

            // `item_label` is the FALLBACK, not the other way round: a resolved
            // item's library title is its current name, while a label is a
            // snapshot of what somebody typed once and never revisited.
            $row->title = $resolved ?? $row->item_label;

            // An id that resolves to nothing is a real condition - the library
            // row was hard-deleted - and the screen should say so rather than
            // print an empty cell.
            $row->title_missing = $row->title === null || $row->title === '';
        }

        return $items;
    }
}
