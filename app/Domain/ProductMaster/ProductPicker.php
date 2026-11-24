<?php

namespace App\Domain\ProductMaster;

use Illuminate\Support\Facades\DB;

/**
 * `GET /api/product-master/picker`: the Sub-products a document may sell (slice 15, P2-E1, brief section 7). One list for every picker of every module (quotes, orders, invoices,
 * price books, opportunity lines, tickets, assets, FAQs, bundles) in place of eleven callers of `GET /crm/products`.
 *
 * What is OFFERED: a catalogue-backed Sub-product (kind `sub_product` or `item`) that is active (not archived), whose catalogue row is sold (switched on, `is_active`) and not in the
 * Recycle Bin, of the type asked, in the caller's own organisation (Sell is never shared, and since the S15 follow-up of 24 Nov 2026 neither is a Product or a Sub-product). A
 * Product (level 1) is never offered, a paused or binned or archived or never-sold Sub-product is not offered by this default scope. A document that already holds such an item
 * keeps it: it is resolved through `GET /{id}` and `GET /by-crm/{crmId}`, which answer for every state. `?scope=all` ({@see searchAll()}) offers every active Sub-product and item.
 *
 * The rows say what a line needs: `{crmProductId, masterId, name, productName, sku, unitPrice, currency, taxRateId, type}`, grouped by Product (the order is Product name, then name).
 * Search is as-you-type on the name and the SKU. `limit` is 20 by default and never more than 50. The CALLER's right is asked by the controller for the types asked
 * (ProductReadRights::canViewSell): this class is only handed types the caller may view.
 *
 * MariaDB 10.1 safe: joins, LIKE ... ESCAPE, LIMIT.
 */
final class ProductPicker
{
    public const LIMIT_DEFAULT = 20;

    public const LIMIT_MAX = 50;

    public function __construct(private readonly int $tenant)
    {
    }

    /** `?limit=`: 1 to 50, default 20. */
    public static function limit(mixed $value): int
    {
        if (! is_numeric($value)) {
            return self::LIMIT_DEFAULT;
        }

        return max(1, min(self::LIMIT_MAX, (int) $value));
    }

    /**
     * @param  list<string>  $types  the item types to offer (already checked against the caller's rights)
     * @return list<array<string, mixed>>
     */
    public function search(array $types, string $text, int $limit): array
    {
        if ($types === []) {
            return [];
        }
        // The organisation's own rows only (S15 follow-up, D-1): a Product, a Sub-product and a catalogue row belong to ONE organisation, so the Product a row sits under is the
        // organisation's own too. There is no shared tenant to read.
        $query = DB::table('g2g_product_master as m')
            ->join('crm_products as c', fn ($join) => $join->on('c.id', '=', 'm.crm_product_id')->where('c.sub_institute_id', '=', $this->tenant))
            ->leftJoin('g2g_product_master as p', fn ($join) => $join->on('p.id', '=', 'm.parent_id')->where('p.sub_institute_id', '=', $this->tenant))
            ->where('m.sub_institute_id', $this->tenant)
            ->where('m.status', ProductMaster::STATUS_ACTIVE)
            ->whereIn('m.kind', [ProductMaster::KIND_SUB_PRODUCT, ProductMaster::KIND_ITEM])
            ->whereNull('c.deleted_at')
            ->whereRaw('COALESCE(c.is_active, 1) <> 0')
            ->whereIn('c.item_type', $types);

        $text = ProductReadSupport::searchText($text);
        if ($text !== '') {
            $like = ProductReadSupport::likePattern($text);
            $escape = " ESCAPE '" . ProductReadSupport::LIKE_ESCAPE . "'";
            $query->where(fn ($w) => $w->whereRaw('c.name LIKE ?' . $escape, [$like])->orWhereRaw('c.sku LIKE ?' . $escape, [$like]));
        }

        return $query->orderByRaw("COALESCE(p.name, '')")->orderBy('c.name')->orderBy('c.id')->limit($limit)
            ->get(['c.id as crm_id', 'm.id as master_id', 'c.name as name', 'p.name as product_name', 'c.sku', 'c.unit_price', 'c.currency', 'c.tax_rate_id', 'c.item_type'])
            ->map(fn (object $r): array => [
                'crmProductId' => (int) $r->crm_id, 'masterId' => (int) $r->master_id, 'name' => (string) $r->name, 'productName' => $r->product_name,
                'sku' => $r->sku, 'unitPrice' => $r->unit_price !== null ? (float) $r->unit_price : null, 'currency' => $r->currency,
                'taxRateId' => $r->tax_rate_id !== null ? (int) $r->tax_rate_id : null, 'type' => (string) $r->item_type,
            ])->all();
    }

    /**
     * `?scope=all` (S15 follow-up, 24 Nov 2026, D-7: "all products and sub products should be selectable, not sold and sold both"). Every ACTIVE Sub-product and item of the
     * organisation, sold or not, not only the ones a document can sell. The Sell row may be missing (an About only), switched off (paused), or in the Recycle Bin:
     *
     *   state `sold`      a catalogue row that is switched on and not in the Recycle Bin: the row a document can hold, `crmProductId` is its number
     *   state `paused`    a catalogue row that is switched off (`is_active` 0): it exists, but nothing new may be sold from it
     *   state `not_sold`  no catalogue row at all, or one that is in the Recycle Bin: `crmProductId` is null, there is no price and no row for a document to hold
     * `sold` is `state === 'sold'`. A catalogue-only ITEM whose catalogue row is in the Recycle Bin is never offered (the item IS that row; the sync archives it); a Sub-product keeps
     * its About, so it is offered as `not_sold`. A Product (level 1) is not a row here: the Product box of the lead, campaign and opportunity forms reads `/lines?withSubProducts=1`.
     *
     * What a caller may SEE of the Sell side is the rule of every read (ProductReadRights): the number, SKU, price, currency and tax rate of a row are filled only when the caller may
     * view the Sell section of that row's type (`$viewable`); for anybody else the same rows come back with those fields null. The state and the names are open to every signed-in user.
     *
     * @param  list<string>  $viewable  the item types whose Sell section the caller may view
     * @param  string  $type  '' (every type), `product` (a Sub-product that is not a service), `service`
     * @return list<array<string, mixed>>
     */
    public function searchAll(array $viewable, string $type, string $text, int $limit): array
    {
        $state = "CASE WHEN c.id IS NULL OR c.deleted_at IS NOT NULL THEN 'not_sold' WHEN COALESCE(c.is_active, 1) = 0 THEN 'paused' ELSE 'sold' END";

        $query = DB::table('g2g_product_master as m')
            ->leftJoin('crm_products as c', fn ($join) => $join->on('c.id', '=', 'm.crm_product_id')->where('c.sub_institute_id', '=', $this->tenant))
            ->leftJoin('g2g_product_master as p', fn ($join) => $join->on('p.id', '=', 'm.parent_id')->where('p.sub_institute_id', '=', $this->tenant))
            ->where('m.sub_institute_id', $this->tenant)
            ->where('m.status', ProductMaster::STATUS_ACTIVE)
            ->whereIn('m.kind', [ProductMaster::KIND_SUB_PRODUCT, ProductMaster::KIND_ITEM])
            ->where(fn ($offered) => $offered->where('m.kind', ProductMaster::KIND_SUB_PRODUCT)->orWhereNull('c.id')->orWhereNull('c.deleted_at'));

        if ($type === 'service') {
            $query->where('c.item_type', 'service')->whereNull('c.deleted_at');
        } elseif ($type !== '') {
            $query->whereRaw("(c.id IS NULL OR c.deleted_at IS NOT NULL OR c.item_type <> 'service')");
        }

        $text = ProductReadSupport::searchText($text);
        if ($text !== '') {
            $like = ProductReadSupport::likePattern($text);
            $escape = " ESCAPE '" . ProductReadSupport::LIKE_ESCAPE . "'";
            // The SKU is Sell data: it is searched only among the rows whose type the caller may view, so a search cannot be used to read it.
            $query->where(function ($w) use ($like, $escape, $viewable) {
                $w->whereRaw('m.name LIKE ?' . $escape, [$like])->orWhereRaw('c.name LIKE ?' . $escape, [$like]);
                if ($viewable !== []) {
                    $w->orWhere(fn ($s) => $s->whereRaw('c.sku LIKE ?' . $escape, [$like])->whereIn('c.item_type', $viewable));
                }
            });
        }

        return $query->orderByRaw("COALESCE(p.name, '')")->orderByRaw('COALESCE(c.name, m.name)')->orderBy('m.id')->limit($limit)
            ->get(['c.id as crm_id', 'c.deleted_at as crm_deleted', 'm.id as master_id', 'm.name as master_name', 'c.name as crm_name', 'p.name as product_name', 'c.sku', 'c.unit_price', 'c.currency', 'c.tax_rate_id', 'c.item_type', DB::raw($state . ' as sell_state')])
            ->map(function (object $r) use ($viewable): array {
                $hasRow = $r->crm_id !== null && $r->crm_deleted === null;
                $mayView = $hasRow && in_array((string) $r->item_type, $viewable, true);
                $state = (string) $r->sell_state;

                return [
                    'crmProductId' => $mayView ? (int) $r->crm_id : null, 'masterId' => (int) $r->master_id,
                    'name' => (string) ($r->crm_name ?? $r->master_name), 'productName' => $r->product_name,
                    'sku' => $mayView ? $r->sku : null, 'unitPrice' => $mayView && $r->unit_price !== null ? (float) $r->unit_price : null, 'currency' => $mayView ? $r->currency : null,
                    'taxRateId' => $mayView && $r->tax_rate_id !== null ? (int) $r->tax_rate_id : null, 'type' => $hasRow ? (string) $r->item_type : null,
                    'sold' => $state === 'sold', 'state' => $state,
                ];
            })->all();
    }
}
