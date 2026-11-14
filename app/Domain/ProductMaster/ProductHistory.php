<?php

namespace App\Domain\ProductMaster;

use App\Support\ColumnExists;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `GET /api/product-master/{id}/history`: ONE list of every change of one record, newest first (slice 15, P2-E1, brief section 4; audit finding G-2).
 *
 * A record lives in up to three stores and the one-record writer files an audit line for each store it changed (ProductWriteAudit), under the store's own entity type:
 *
 *   about    the Portfolio offer       g2g_product_offers   portfolio.offer.created / .updated   entity id = the master row's offer_row_id
 *   sell     the CRM catalogue row     crm_products         crm.product.created / .updated / .deleted   entity id = its crm_product_id
 *   record   the master row            g2g_product_master   product.master.created / .updated   entity id = the master row's own id
 *
 * The three are read from g2g_audit_log (the projection every audit line lands in) in one query, so the order and the pages are exact. A line is `{at, by, store, event, changes:
 * [{field, old, new}]}`; `by` is the person's name (null for the system), `event` the last word of the line's type (created, updated, deleted).
 *
 * WHO sees what. The Sell store is what the screen keeps from people: a catalogue row's history carries its price, cost and commission. So a caller who may not view the Sell
 * section of this record's type (ProductReadRights::canViewSellOf) gets no line of the sell store at all, and no line of ANY store that touches `unit_price`, `cost_price` or
 * `commission_rate` (a line is shown whole or not at all, so that a page is always a whole page). About and record lines are open to every signed-in user of the organisation.
 *
 * Read only. MariaDB 10.1 safe: LIKE, INSTR, LIMIT / OFFSET; the people's names are read in a second query, not joined (no CONCAT needed).
 */
final class ProductHistory
{
    /** The entity type each store is filed under, and the name the screen knows it by. */
    public const STORES = ['crm_products' => 'sell', 'g2g_product_offers' => 'about', 'g2g_product_master' => 'record'];

    /** The prefix of the event types a store's lines have. */
    private const TYPE_PREFIX = ['crm_products' => 'crm.product', 'g2g_product_offers' => 'portfolio.offer', 'g2g_product_master' => 'product.master'];

    /** The fields only a Sell viewer may see a change of, whichever store the line is in. */
    public const PRICE_FIELDS = ['unit_price', 'cost_price', 'commission_rate'];

    public function __construct(private readonly int $tenant, private readonly ProductReadRights $rights)
    {
    }

    /**
     * @return array{rows: list<array<string, mixed>>, meta: array{total: int, page: int, perPage: int, lastPage: int}}
     */
    public function page(ProductMaster $row, int $page, int $perPage): array
    {
        if (! Schema::hasTable('g2g_audit_log')) {
            return ['rows' => [], 'meta' => ProductReadSupport::paging(0, $page, $perPage)];
        }

        $mayViewSell = $this->rights->canViewSellOf($row);
        $stores = [['g2g_product_master', (int) $row->id]];
        if ($row->offer_row_id !== null) {
            $stores[] = ['g2g_product_offers', (int) $row->offer_row_id];
        }
        if ($row->crm_product_id !== null && $mayViewSell) {
            $stores[] = ['crm_products', (int) $row->crm_product_id];
        }

        $query = DB::table('g2g_audit_log as a')->where('a.sub_institute_id', $this->tenant)
            ->where(function ($any) use ($stores) {
                foreach ($stores as [$entityType, $entityId]) {
                    $any->orWhere(fn ($one) => $one->where('a.entity_type', $entityType)->where('a.entity_id', $entityId)->where('a.type', 'like', self::TYPE_PREFIX[$entityType] . '%'));
                }
            });
        if (! $mayViewSell) {
            foreach (self::PRICE_FIELDS as $field) {
                $query->whereRaw("INSTR(COALESCE(a.detail, ''), ?) = 0", ['"field":"' . $field . '"']);
            }
        }

        $total = (int) (clone $query)->count();
        $lines = $query->orderByDesc('a.occurred_at')->orderByDesc('a.id')->offset(($page - 1) * $perPage)->limit($perPage)
            ->get(['a.id', 'a.type', 'a.entity_type', 'a.actor_id', 'a.detail', 'a.occurred_at']);
        $names = $this->names($lines->pluck('actor_id')->filter()->unique()->values()->all());

        return [
            'rows' => $lines->map(function (object $line) use ($names): array {
                $detail = json_decode((string) ($line->detail ?? ''), true);
                $changes = is_array($detail) && is_array($detail['changes'] ?? null) ? $detail['changes'] : [];
                $type = (string) $line->type;

                return [
                    'at' => $line->occurred_at,
                    'by' => $line->actor_id !== null ? ($names[(int) $line->actor_id] ?? null) : null,
                    'byId' => $line->actor_id !== null ? (int) $line->actor_id : null,
                    'store' => self::STORES[$line->entity_type] ?? 'record',
                    'event' => substr($type, (int) strrpos($type, '.') + 1),
                    'type' => $type,
                    'changes' => array_values(array_map(fn ($c) => ['field' => $c['field'] ?? null, 'old' => $c['oldValue'] ?? null, 'new' => $c['newValue'] ?? null], array_filter($changes, 'is_array'))),
                ];
            })->all(),
            'meta' => ProductReadSupport::paging($total, $page, $perPage),
        ];
    }

    /**
     * @param  list<int|string>  $ids
     * @return array<int, string> user id => name (null names are left out)
     */
    private function names(array $ids): array
    {
        if ($ids === [] || ! Schema::hasTable('tbluser')) {
            return [];
        }
        $have = ColumnExists::all('tbluser');
        $columns = array_values(array_intersect(['first_name', 'last_name'], $have));
        $out = [];
        foreach (DB::table('tbluser')->whereIn('id', $ids)->get(array_merge(['id'], $columns, in_array('user_name', $have, true) ? ['user_name'] : [])) as $u) {
            $name = trim(trim((string) ($u->first_name ?? '')) . ' ' . trim((string) ($u->last_name ?? '')));
            $name = $name !== '' ? $name : trim((string) ($u->user_name ?? ''));
            if ($name !== '') {
                $out[(int) $u->id] = $name;
            }
        }

        return $out;
    }
}
