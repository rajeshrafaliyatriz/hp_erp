<?php

namespace App\Domain\ProductMaster;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The reader of the alias a pair merge leaves behind (slice 15, WPB, finding G-E4).
 *
 * A "same" answer on the pair review turns the catalogue item's master row into a HUSK (archived, origin `merged`, no pointers, kept for ever) and writes a decision row
 * item_master_id -> survivor_master_id into g2g_product_match_decisions. The decision row IS the alias of the retired id. Until this class nothing read it: a husk answered
 * like a live product everywhere that looks a product up by name or id.
 *
 * A husk is a row of ONE organisation (a merge refuses shared and foreign rows), so the lookup is always: that organisation's own row that is archived with origin
 * `merged`, AND that organisation's own `same` decision for it, AND a survivor row the organisation may see. An item that is not a husk is never mapped, whatever a
 * decision row says (a rolled-back merge restores the item and may leave a decision row). Another organisation's decision is never read.
 *
 * Read only. MariaDB 10.1 safe (plain SELECT, whereIn, no JSON, no window functions, no CTEs). A host without the decisions table has no alias: it answers "none".
 */
final class ProductAlias
{
    /** Whether a master row is a husk: archived, and archived BY a merge. */
    public static function isHusk(object $row): bool
    {
        return ($row->status ?? null) === ProductMaster::STATUS_ARCHIVED && ($row->origin ?? null) === ProductMerger::ORIGIN_MERGED;
    }

    /**
     * Husk id => survivor id, for those of these master ids that are the organisation's own husks with a `same` decision. An id that is not a husk, or whose decision is
     * missing, is not in the answer. When one item has more than one `same` decision (a merge undone and made again elsewhere) the newest wins.
     *
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    public static function survivorsOf(int $tenant, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id) => $id > 0)));
        if ($tenant <= 0 || $ids === [] || ! Schema::hasTable('g2g_product_master') || ! Schema::hasTable('g2g_product_match_decisions')) {
            return [];
        }

        $husks = ProductMaster::query()->where('sub_institute_id', $tenant)->whereIn('id', $ids)
            ->where('status', ProductMaster::STATUS_ARCHIVED)->where('origin', ProductMerger::ORIGIN_MERGED)->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($husks === []) {
            return [];
        }

        $map = [];
        foreach (DB::table('g2g_product_match_decisions')->where('sub_institute_id', $tenant)->whereIn('item_master_id', $husks)->where('decision', ProductMerger::SAME)
            ->orderBy('id')->get(['item_master_id', 'survivor_master_id']) as $d) {
            $map[(int) $d->item_master_id] = (int) $d->survivor_master_id;
        }
        if ($map === []) {
            return [];
        }

        // The survivor must be a row the organisation may see (its own, or a shared one).
        $visible = ProductMaster::query()->visibleTo($tenant)->whereIn('id', array_values(array_unique($map)))->pluck('id')->map(fn ($id) => (int) $id)->all();

        return array_filter($map, fn (int $survivor) => in_array($survivor, $visible, true));
    }

    /**
     * What a husk was merged into, for the read API: `{id, key, name}` of the survivor, or null for a row that is not a husk (or has no alias).
     *
     * @return array{id: int, key: ?string, name: string}|null
     */
    public static function mergedInto(ProductMaster $row, int $tenant): ?array
    {
        if (! self::isHusk($row)) {
            return null;
        }
        $survivor = self::survivorsOf($tenant, [(int) $row->id])[(int) $row->id] ?? null;
        $target = $survivor !== null ? ProductMaster::query()->visibleTo($tenant)->find($survivor) : null;

        return $target !== null ? ['id' => (int) $target->id, 'key' => $target->product_key, 'name' => (string) $target->name] : null;
    }
}
