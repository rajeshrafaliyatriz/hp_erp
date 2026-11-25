<?php

namespace App\Domain\ProductMaster;

use App\Domain\Money\Currencies;
use App\Domain\Money\TaxCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a Product costs: the price of its Sub-products added up, per currency (S16, 25 Nov 2026; contract section 4, "Product total").
 *
 * A Product (level 1) is never priced itself; its total is the sum of the Sub-products and items that sit under it and are SOLD at a price:
 *   counted    an ACTIVE Sub-product or item whose catalogue row exists, is not in the Recycle Bin, is switched on (`is_active`) and has a `unit_price`
 *   uncounted  every other ACTIVE Sub-product or item of the Product (an About only, a paused or binned row, a row with no price yet)
 * Each counted row adds its price to the subtotal and the tax of ITS OWN rate (TaxCalculator: exclusive, half up, per price) to the tax; the total is the sum. An empty currency counts
 * under the organisation's default currency (Currencies::defaultFor), a currency outside the catalogue (legacy text) under that text, in upper case. A currency is a line of its own: nothing
 * is ever converted.
 *
 * One entry per currency, ordered by how many rows it counts (the most first), then by code: {currency, subtotal, tax, total, counted, uncounted}. `uncounted` is the number of active
 * Sub-products that are priced in NO currency, the same on every entry of the Product; `subProductsCounted` is the sum of `counted` over the entries.
 *
 * Who may see it: only the types of Sell the caller may view (ProductReadRights::viewableTypes). A row of a type the caller may not view is neither counted nor uncounted: it is as if it were not
 * there, so the total can never be used to read a price. A caller who may view no Sell at all gets `priceTotals: []` and no question is asked of the database.
 *
 * The cost: ONE grouped query for any number of Products and Sub-products (`GROUP BY` the Product, the state of the row and its currency, price and rate), never one per Sub-product:
 * tests/Feature/ProductPriceTotalsTest.php counts the statements for 3 and for 12 Sub-products and wants the same number. MariaDB 10.1 safe: joins, CASE, GROUP BY positions.
 */
final class ProductPriceTotals
{
    /** @param ProductReadRights $rights the caller's, which says which Sell types the prices may be read for */
    public function __construct(private readonly int $tenant, private readonly ProductReadRights $rights)
    {
    }

    /**
     * @param  list<int>  $productIds ids of level-1 Products (master rows)
     * @return array<int, array{priceTotals: list<array{currency: string, subtotal: float, tax: float, total: float, counted: int, uncounted: int}>, subProductsCounted: int}> an entry for every id asked for
     */
    public function forProducts(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        $out = [];
        foreach ($productIds as $id) {
            $out[$id] = ['priceTotals' => [], 'subProductsCounted' => 0];
        }
        $types = array_values(array_intersect($this->rights->viewableTypes(), CatalogueFields::ITEM_TYPES));
        if ($productIds === [] || $types === [] || $this->tenant <= 0 || ! Schema::hasTable('g2g_product_master') || ! Schema::hasTable('crm_products')) {
            return $out;
        }

        $rows = $this->groups($productIds, $types);
        $default = null;
        $per = [];        // product id => currency => [minor subtotal, minor tax, counted]
        $uncounted = [];  // product id => n
        foreach ($rows as $r) {
            $id = (int) $r->pid;
            $n = (int) $r->n;
            if ((int) $r->hidden === 1) {
                continue;
            }
            if ((int) $r->counted !== 1) {
                $uncounted[$id] = ($uncounted[$id] ?? 0) + $n;

                continue;
            }
            $currency = Currencies::normalise($r->cur) ?? ($default ??= Currencies::defaultFor($this->tenant));
            $decimals = Currencies::decimals($currency);
            $minor = TaxCalculator::toMinor($r->price, $decimals);
            $tax = TaxCalculator::taxMinor($minor, TaxCalculator::percentMilli($r->pct));
            $slot = $per[$id][$currency] ?? [0, 0, 0];
            $per[$id][$currency] = [$slot[0] + $minor * $n, $slot[1] + $tax * $n, $slot[2] + $n];
        }

        foreach ($per as $id => $byCurrency) {
            $entries = [];
            foreach ($byCurrency as $currency => [$sub, $tax, $counted]) {
                $decimals = Currencies::decimals($currency);
                $entries[] = [
                    'currency' => (string) $currency, 'subtotal' => TaxCalculator::fromMinor($sub, $decimals), 'tax' => TaxCalculator::fromMinor($tax, $decimals),
                    'total' => TaxCalculator::fromMinor($sub + $tax, $decimals), 'counted' => $counted, 'uncounted' => $uncounted[$id] ?? 0,
                ];
            }
            usort($entries, fn (array $a, array $b): int => [$b['counted'], $a['currency']] <=> [$a['counted'], $b['currency']]);
            $out[$id] = ['priceTotals' => $entries, 'subProductsCounted' => array_sum(array_column($entries, 'counted'))];
        }

        return $out;
    }

    /**
     * The one query: the active Sub-products and items of the Products, grouped by what decides their share of the total.
     *
     * @param  list<int>  $ids
     * @param  list<string>  $types the item types the caller may view (a fixed set, so they are written into the statement, not bound)
     * @return \Illuminate\Support\Collection<int, object{pid: int, counted: int, hidden: int, cur: ?string, price: mixed, pct: mixed, n: int}>
     */
    private function groups(array $ids, array $types)
    {
        $list = "'" . implode("', '", $types) . "'";
        $counted = "(c.id IS NOT NULL AND c.deleted_at IS NULL AND c.is_active = 1 AND c.unit_price IS NOT NULL AND c.item_type IN ({$list}))";
        $rates = Schema::hasTable('crm_tax_rates');

        $query = DB::table('g2g_product_master as m')
            ->leftJoin('crm_products as c', fn ($join) => $join->on('c.id', '=', 'm.crm_product_id')->where('c.sub_institute_id', '=', $this->tenant));
        if ($rates) {
            $query->leftJoin('crm_tax_rates as t', fn ($join) => $join->on('t.id', '=', 'c.tax_rate_id')->where('t.sub_institute_id', '=', $this->tenant));
        }

        return $query
            ->where('m.sub_institute_id', $this->tenant)->whereIn('m.parent_id', $ids)->where('m.kind', '<>', ProductMaster::KIND_PRODUCT)->where('m.status', ProductMaster::STATUS_ACTIVE)
            ->selectRaw(
                "m.parent_id AS pid, CASE WHEN {$counted} THEN 1 ELSE 0 END AS counted, CASE WHEN c.id IS NOT NULL AND c.item_type NOT IN ({$list}) THEN 1 ELSE 0 END AS hidden, "
                . "CASE WHEN {$counted} THEN c.currency END AS cur, CASE WHEN {$counted} THEN c.unit_price END AS price, "
                . ($rates ? "CASE WHEN {$counted} THEN t.percentage END" : 'NULL') . ' AS pct, COUNT(*) AS n'
            )
            ->groupByRaw('1, 2, 3, 4, 5, 6')
            ->get();
    }
}
