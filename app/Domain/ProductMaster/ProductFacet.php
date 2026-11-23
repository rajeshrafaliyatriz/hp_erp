<?php

namespace App\Domain\ProductMaster;

use Illuminate\Support\Facades\Schema;

/**
 * The ids a "Product" facet matches (slice 15, track WPB, finding D-E4 of the products parity audit; decision of 14 Nov 2026, option (a)).
 *
 * The forms of a lead, a campaign and a signal point at a Product (level 1), but nothing in the data says they must: LeadLinks::productVisible accepts any visible master
 * row, and Signals stamps a Sub-product on purpose when a research run was for one offer. So a facet "this Product" that matches only the Product's own id would hide every
 * record filed under one of its Sub-products. A facet on a level-1 id therefore matches that id OR any ACTIVE child of it (a Sub-product, or a catalogue item that sits under
 * it); a facet on a Sub-product id has no children and stays an exact match. An archived child is not matched: it left the Portfolio, and its own facet still finds its rows.
 *
 * The children are read in ONE plain query (no JSON, no window functions, no CTEs: MariaDB 10.1 safe), never one per row, and the answer is remembered on the request, so
 * a request that builds its query more than once reads them once. They are the organisation's own rows and the shared ones (tenant 0), the rows it may see. The validators
 * that accept a Sub-product id stay as they are. (The memo lives on the request object: a caller outside an HTTP request that changes the data between two calls on the
 * same request object must use another request, as a queue job or a test does.)
 */
final class ProductFacet
{
    /**
     * @return list<int> the facet id first, then its active children in id order
     */
    public static function ids(int $tenant, int $productId): array
    {
        if ($productId <= 0) {
            return [];
        }
        if (! Schema::hasTable('g2g_product_master')) {
            return [$productId];
        }

        // One read per request: the signal feed builds its query several times (the page, the counts, the date lookup), and each of them asks for the same children.
        $bag = request()->attributes;
        $memo = "product_facet.{$tenant}.{$productId}";
        if ($bag->has($memo)) {
            return $bag->get($memo);
        }

        $children = ProductMaster::query()->visibleTo($tenant)->where('parent_id', $productId)->where('status', ProductMaster::STATUS_ACTIVE)
            ->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $ids = array_values(array_unique([$productId, ...$children]));
        $bag->set($memo, $ids);

        return $ids;
    }
}
