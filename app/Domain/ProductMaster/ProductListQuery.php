<?php

namespace App\Domain\ProductMaster;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `GET /api/product-master/list`: the paged, flat list the unified Product Portfolio screen reads (slice 15, P2-E1, brief section 1).
 *
 * One row per master row the organisation can see (its own: nothing is shared between organisations since the isolation of 24 Nov 2026): a level-1 Product (kind `product`, with its Sub-product count), a Sub-product
 * with an About, a Sell or both (kind `sub_product`), or a catalogue-only item (kind `item`, which is a Sub-product with no About). The rows come back in the order the
 * screen draws them, so a page can be grouped by running down it: the Products alphabetically, each Product row followed by its Sub-products (by name), then the Sub-products
 * that have no Product yet (`needsProduct`) at the end. The order is the same on every page.
 *
 * What a row carries for EVERY signed-in user: names, ids, the Product, the Sell STATE (not_sold, sold, paused, binned), the readiness, the Signals count and the ICP state.
 * What it carries ONLY for a caller who may view the Sell section of that row's own type (ProductReadRights::canViewSell): `sku`, `category`, `qtyInStock` (null for a
 * service), `unitPrice`, `currency` and `taxPercent` (the percentage of the row's tax rate, S16). For anyone else those keys are not there at all, and the SKU is not searchable or sortable either (a search for "ABC-1", or a sort by
 * price, must not tell HR which item holds that SKU or what it costs).
 *
 * Filters: `q` (name, offer id, and the SKU of an item the caller may view), `productId`, `type` (subproduct | service), `sold` (yes | no | paused), `readiness`, `status`
 * (active | archived | all, default active). A Product row has no type, no sold state and no readiness, so a filter on one of those leaves only Sub-products.
 * Sorts: `sort` = name (default) | readiness | signals | price, `dir` = asc | desc (see order()).
 *
 * SQL is plain joins, CASE, COALESCE, LIKE ... ESCAPE and LIMIT / OFFSET: nothing the live MariaDB 10.1 lacks. The three tables (master, Portfolio offers, CRM catalogue) are
 * joined on their own primary keys, so a row is never counted twice.
 */
final class ProductListQuery
{
    /** The Sell state of a master row, from its catalogue row `c`. A Product (kind product) has none. One expression: the filter and the answer both use it. */
    private const STATE_SQL = "CASE WHEN m.kind = 'product' THEN NULL WHEN c.id IS NULL THEN 'not_sold' WHEN c.deleted_at IS NOT NULL THEN 'binned' WHEN c.is_active = 0 THEN 'paused' ELSE 'sold' END";

    /** A Sub-product that sits under no Product: its kind is not `product` and it has no parent. */
    private const STRAY_SQL = "(m.kind <> 'product' AND m.parent_id IS NULL)";

    /** The `status` filter value that lists active and archived records together. */
    public const STATUS_ALL = 'all';

    /** The sorts a client may ask for. An unknown one is `name`; `price` is also `name` for a caller who may view no Sell section. */
    public const SORTS = ['name', 'readiness', 'signals', 'price'];

    private ?bool $rates = null;

    /** @param array{user: object, user_id: int, sub_institute_id: int} $identity */
    public function __construct(private readonly array $identity, private readonly ProductReadRights $rights)
    {
    }

    /**
     * @param  array<string, mixed>  $params  the query string
     * @return array{rows: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function run(array $params): array
    {
        $tenant = $this->identity['sub_institute_id'];
        $per = ProductReadSupport::perPage($params['per'] ?? null);
        $page = ProductReadSupport::page($params['page'] ?? null);
        $meta = fn (int $total, bool $available): array => ['available' => $available] + ProductReadSupport::paging($total, $page, $per) + ['can' => $this->rights->can()];

        // A host that has not had the Product Master database yet answers an empty, honest list instead of an error.
        if (! Schema::hasTable('g2g_product_master')) {
            return ['rows' => [], 'meta' => $meta(0, false)];
        }

        $status = $this->statusFilter($params);
        $query = $this->filtered($params, $tenant, $status);
        $total = (int) (clone $query)->count();
        [$orderSql, $orderBindings] = $this->order($params, $tenant);
        $records = $query->orderByRaw($orderSql, $orderBindings)->offset(($page - 1) * $per)->limit($per)->get($this->columns());

        return ['rows' => $this->rows($records, $tenant, $status), 'meta' => $meta($total, true)];
    }

    // ---------------------------------------------------------------- the query

    private function filtered(array $params, int $tenant, string $status): Builder
    {
        // The organisation's OWN rows and nothing else (S15 follow-up, D-1): a row of organisation 0, or of another organisation, is never listed, never joined as a Product,
        // an About or a catalogue row. A caller with no organisation (a tenant of 0 or below) reads none: -1 is no organisation's id.
        $own = $tenant > 0 ? $tenant : -1;
        $query = DB::table('g2g_product_master as m')
            ->where('m.sub_institute_id', $own)
            ->when($status !== self::STATUS_ALL, fn ($q) => $q->where('m.status', $status))
            // The Product a row sits under: the organisation's own.
            ->leftJoin('g2g_product_master as p', fn ($join) => $join->on('p.id', '=', 'm.parent_id')->where('p.sub_institute_id', $own))
            // The Portfolio offer behind the row: the organisation's own.
            ->leftJoin('g2g_product_offers as o', fn ($join) => $join->on('o.id', '=', 'm.offer_row_id')->where('o.sub_institute_id', $own))
            // The catalogue row: Sell is never shared, so it must be this organisation's own.
            ->leftJoin('crm_products as c', fn ($join) => $join->on('c.id', '=', 'm.crm_product_id')->where('c.sub_institute_id', '=', $tenant));
        // The tax rate of the catalogue row (S16): the organisation's own, one row at most, so the join can never count a record twice. A host or a test world with no rate table has none.
        if ($this->hasRates()) {
            $query->leftJoin('crm_tax_rates as tr', fn ($join) => $join->on('tr.id', '=', 'c.tax_rate_id')->where('tr.sub_institute_id', '=', $tenant));
        }

        $product = $params['productId'] ?? null;
        if (is_numeric($product) && (int) $product > 0) {
            $query->where('m.parent_id', (int) $product);
        }
        $this->filterByText($query, $params);
        $this->filterByKind($query, $params);

        return $query;
    }

    private function filterByText(Builder $query, array $params): void
    {
        $text = ProductReadSupport::searchText($params['q'] ?? '');
        if ($text === '') {
            return;
        }
        $like = ProductReadSupport::likePattern($text);
        $escape = " ESCAPE '" . ProductReadSupport::LIKE_ESCAPE . "'";
        // The SKU and the catalogue's product number (PRD-... / SVC-...) are Sell data: they are searched only among the items whose type the caller may view, so a search cannot
        // be used to read them (slice 15, WPA1, the product number is found the way the SKU is).
        $types = $this->rights->viewableTypes();

        $query->where(function ($w) use ($like, $escape, $types) {
            $w->whereRaw('m.name LIKE ?' . $escape, [$like])->orWhereRaw('o.offer_id LIKE ?' . $escape, [$like]);
            if ($types !== []) {
                $w->orWhere(fn ($s) => $s->where(fn ($either) => $either->whereRaw('c.sku LIKE ?' . $escape, [$like])->orWhereRaw('c.product_no LIKE ?' . $escape, [$like]))
                    ->whereIn('c.item_type', $types));
            }
        });
    }

    private function filterByKind(Builder $query, array $params): void
    {
        $type = (string) ($params['type'] ?? '');
        if ($type === 'service') {
            $query->where('m.kind', '<>', ProductMaster::KIND_PRODUCT)->where('c.item_type', 'service');
        } elseif ($type === 'subproduct') {
            // A Sub-product with no catalogue row (an About only) is a Sub-product, never a service. A Product has no type.
            $query->where('m.kind', '<>', ProductMaster::KIND_PRODUCT)->whereRaw("COALESCE(c.item_type, 'product') <> 'service'");
        }

        $sold = (string) ($params['sold'] ?? '');
        if (in_array($sold, ['yes', 'no', 'paused'], true)) {
            $states = ['yes' => ['sold'], 'paused' => ['paused'], 'no' => ['not_sold', 'binned']][$sold];
            $query->whereRaw('(' . self::STATE_SQL . ') IN (' . implode(', ', array_fill(0, count($states), '?')) . ')', $states);
        }

        $readiness = trim((string) ($params['readiness'] ?? ''));
        if ($readiness !== '') {
            $query->where('o.readiness_status', $readiness);
        }
    }

    /** `status`: active (the default), archived, or all, so an archived record can be found and restored. */
    private function statusFilter(array $params): string
    {
        $status = $params['status'] ?? null;

        return in_array($status, [ProductMaster::STATUS_ARCHIVED, self::STATUS_ALL], true) ? $status : ProductMaster::STATUS_ACTIVE;
    }

    /**
     * ORDER BY. The rows are always in GROUPS, so a page can be drawn by running down it: the Products by name (the strays, which have no Product, last), each Product row first
     * and then its Sub-products. `sort` and `dir` order the Sub-products inside a group, except for `name`, where `dir` turns the groups and the names the other way too.
     *   name       the default, and what an unknown sort is
     *   readiness  the Portfolio readiness text
     *   signals    how many Signals were found for it
     *   price      the unit price, for a caller who may view Sell; a price the caller may not see (the other item type) sorts as none, so the order cannot be used to read it;
     *              for a caller who may view no Sell at all it is `name`
     * A row with no value for the sort sorts last in either direction.
     *
     * @return array{0: string, 1: list<mixed>} the SQL and its bindings
     */
    private function order(array $params, int $tenant): array
    {
        $sort = (string) ($params['sort'] ?? 'name');
        $sort = in_array($sort, self::SORTS, true) ? $sort : 'name';
        $dir = strtolower((string) ($params['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
        $key = null;
        $bindings = [];
        if ($sort === 'readiness') {
            $key = 'o.readiness_status';
        } elseif ($sort === 'signals' && Schema::hasTable('g2g_company_opportunities')) {
            $key = "CASE WHEN (m.kind = 'product' AND m.signal_key IS NULL) OR (m.kind <> 'product' AND o.id IS NULL) THEN NULL ELSE (SELECT COUNT(*) FROM g2g_company_opportunities s WHERE s.sub_institute_id = ? AND s.is_sample = 0 AND ((m.kind = 'product' AND s.business_fit = m.signal_key) OR (m.kind <> 'product' AND s.relevant_offer_id = o.offer_id))) END";
            $bindings[] = $tenant;
        } elseif ($sort === 'price' && ($types = $this->rights->viewableTypes()) !== []) {
            $key = 'CASE WHEN c.item_type IN (' . implode(', ', array_fill(0, count($types), '?')) . ') THEN c.unit_price ELSE NULL END';
            $bindings = $types;
        }

        $byName = $key === null ? $dir : 'ASC';
        $groupName = "CASE WHEN " . self::STRAY_SQL . " THEN '' ELSE COALESCE(p.name, m.name) END";
        $parts = [
            'CASE WHEN ' . self::STRAY_SQL . ' THEN 1 ELSE 0 END',
            "{$groupName} {$byName}",
            'CASE WHEN ' . self::STRAY_SQL . ' THEN 0 ELSE COALESCE(p.id, m.id) END ' . $byName,
            "CASE WHEN m.kind = 'product' THEN 0 ELSE 1 END",
        ];
        $orderBindings = [];
        if ($key !== null) {
            $parts[] = "CASE WHEN ({$key}) IS NULL THEN 1 ELSE 0 END";
            $orderBindings = array_merge($orderBindings, $bindings);
            $parts[] = "({$key}) {$dir}";
            $orderBindings = array_merge($orderBindings, $bindings);
        }
        $parts[] = "m.name {$byName}";
        $parts[] = 'm.id ASC';

        return [implode(', ', $parts), $orderBindings];
    }

    /** @return list<\Illuminate\Contracts\Database\Query\Expression|string> */
    private function columns(): array
    {
        return [
            'm.id', 'm.kind', 'm.name', 'm.product_key', 'm.status', 'm.parent_id', 'm.signal_key', 'm.offer_row_id', 'm.crm_product_id', 'm.sub_institute_id',
            'p.name as parent_name',
            'o.id as offer_pk', 'o.offer_id as offer_code', 'o.readiness_status as readiness',
            'c.id as crm_pk', 'c.item_type as item_type', 'c.sku as sku', 'c.category as category', 'c.unit_price as unit_price', 'c.currency as currency',
            'c.qty_in_stock as qty_in_stock',
            $this->hasRates() ? 'tr.percentage as tax_percent' : DB::raw('NULL as tax_percent'),
            DB::raw(self::STATE_SQL . ' as sell_state'),
        ];
    }

    /** Whether this host has the tax rate table; asked once per request. */
    private function hasRates(): bool
    {
        return $this->rates ??= Schema::hasTable('crm_tax_rates');
    }

    // ---------------------------------------------------------------- the rows

    /**
     * @param  iterable<int, object>  $records
     * @return list<array<string, mixed>>
     */
    private function rows(iterable $records, int $tenant, string $status): array
    {
        $records = collect($records)->values();
        $offerCodes = $records->pluck('offer_code')->filter()->map(fn ($c) => (string) $c)->unique()->values()->all();
        $signalsByOffer = ProductLinks::signalsByOffer($tenant, $offerCodes);
        $icpByOffer = ProductLinks::icpByOffer($tenant, $offerCodes);

        $products = $records->where('kind', ProductMaster::KIND_PRODUCT);
        $signalsByKey = $products->isEmpty() ? [] : ProductReadSupport::signalsByProductKey($tenant);
        $icpByProduct = $products->isEmpty() ? [] : ProductLinks::icpByProduct($tenant, ProductMaster::whereIn('id', $products->pluck('id')->all())->get());
        $counts = $products->isEmpty() ? [] : DB::table('g2g_product_master')
            ->whereIn('parent_id', $products->pluck('id')->all())->where('sub_institute_id', $tenant > 0 ? $tenant : -1)
            ->where('kind', '<>', ProductMaster::KIND_PRODUCT)->when($status !== self::STATUS_ALL, fn ($q) => $q->where('status', $status))
            ->selectRaw('parent_id as pid, COUNT(*) as n')->groupBy('parent_id')->pluck('n', 'pid')->all();
        // What each Product costs: the price of its Sub-products per currency (S16), ONE grouped query for every Product on the page.
        $totals = $products->isEmpty() ? [] : (new ProductPriceTotals($tenant, $this->rights))->forProducts($products->pluck('id')->map(fn ($id) => (int) $id)->all());

        return $records->map(function (object $r) use ($signalsByOffer, $icpByOffer, $signalsByKey, $icpByProduct, $counts, $totals): array {
            $isProduct = $r->kind === ProductMaster::KIND_PRODUCT;
            $offer = $r->offer_code !== null ? (string) $r->offer_code : null;
            $hasSell = ! $isProduct && $r->crm_pk !== null;
            $row = [
                'id' => (int) $r->id, 'kind' => $r->kind, 'name' => (string) $r->name, 'key' => $r->product_key, 'status' => (string) $r->status,
                'productId' => $isProduct || $r->parent_id === null ? null : (int) $r->parent_id,
                'productName' => $isProduct ? null : $r->parent_name,
                'type' => $hasSell ? $r->item_type : null,
                'state' => $r->sell_state,
                'readiness' => $isProduct ? null : $r->readiness,
                'signalsFound' => $isProduct ? ($r->signal_key !== null ? (int) ($signalsByKey[$r->signal_key] ?? 0) : null) : ($offer !== null ? (int) ($signalsByOffer[$offer] ?? 0) : null),
                'icp' => $isProduct ? ($icpByProduct[(int) $r->id] ?? null) : ($offer !== null ? ($icpByOffer[$offer] ?? ProductLinks::ICP_NONE) : null),
                'hasAbout' => ! $isProduct && $r->offer_pk !== null,
                'hasSell' => $hasSell,
                'needsProduct' => ! $isProduct && $r->parent_id === null,
                'shared' => false,   // nothing is shared any more (S15 follow-up, D-1); the key stays so the screen does not change
                'subProductCount' => $isProduct ? (int) ($counts[(int) $r->id] ?? 0) : null,
            ];
            if ($isProduct) {
                // For a caller who may view no Sell, `priceTotals` is [] and nothing is counted: the answer carries no price (ProductPriceTotals).
                $row['priceTotals'] = $totals[(int) $r->id]['priceTotals'] ?? [];
                $row['subProductsCounted'] = $totals[(int) $r->id]['subProductsCounted'] ?? 0;
            }
            // Sell data: only for a caller who may view the Sell section of THIS row's type; for anybody else the keys are simply not there.
            // `crmProductId` (slice 15, WPA1, the contract with the list screen) is the catalogue row's id: the number the image, usage, custom-field and picker calls are keyed by.
            // A caller who may view the Sell of SOME type gets the key on every row: the id where it may view this row's type, null where the record has no Sell side (or one of a
            // type it may not view, which it cannot tell apart). HR, who may view no Sell, never gets the key, not even as null.
            if ($hasSell && $this->rights->canViewSell($r->item_type)) {
                $row['crmProductId'] = (int) $r->crm_pk;
                $row['sku'] = $r->sku;
                $row['category'] = $r->category;
                // A service never carries stock.
                $row['qtyInStock'] = $r->item_type === 'service' || $r->qty_in_stock === null ? null : (float) $r->qty_in_stock;
                $row['unitPrice'] = $r->unit_price !== null ? (float) $r->unit_price : null;
                $row['currency'] = $r->currency;
                $row['taxPercent'] = $r->tax_percent !== null ? (float) $r->tax_percent : null;
            } elseif ($this->rights->canViewAnySell()) {
                $row['crmProductId'] = null;
            }

            return $row;
        })->all();
    }
}
