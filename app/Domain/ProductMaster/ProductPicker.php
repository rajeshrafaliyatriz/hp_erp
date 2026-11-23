<?php

namespace App\Domain\ProductMaster;

use Illuminate\Support\Facades\DB;

/**
 * `GET /api/product-master/picker`: the Sub-products a document may sell (slice 15, P2-E1, brief section 7). One list for every picker of every module (quotes, orders, invoices,
 * price books, opportunity lines, tickets, assets, FAQs, bundles) in place of eleven callers of `GET /crm/products`.
 *
 * What is OFFERED: a catalogue-backed Sub-product (kind `sub_product` or `item`) that is active (not archived), whose catalogue row is sold (switched on, `is_active`) and not in the
 * Recycle Bin, of the type asked, in the caller's own organisation (Sell is never shared). A Product (level 1) is never offered, a paused or binned or archived or never-sold
 * Sub-product is not offered. A document that already holds such an item keeps it: it is resolved through `GET /{id}` and `GET /by-crm/{crmId}`, which answer for every state.
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
        $visible = array_values(array_unique([max(0, $this->tenant), ProductMaster::SHARED]));

        $query = DB::table('g2g_product_master as m')
            ->join('crm_products as c', fn ($join) => $join->on('c.id', '=', 'm.crm_product_id')->where('c.sub_institute_id', '=', $this->tenant))
            ->leftJoin('g2g_product_master as p', fn ($join) => $join->on('p.id', '=', 'm.parent_id')->whereIn('p.sub_institute_id', $visible))
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
}
