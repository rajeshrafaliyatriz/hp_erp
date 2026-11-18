<?php

namespace App\Domain\ProductMaster;

use App\Domain\Signals\Products\ProductCatalog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Finds the Product Master row a stored value means.
 *
 * Today one product goes by several spellings: the product key (`scholarclone`), its stored `business_fit` value (`scholar`), its label
 * (`ScholarClone`) and the Product Portfolio group name (`Scholar K-12`, `scholar_k12`). Each of them reaches the same row. `multiple`, an empty
 * value and anything unknown reach nothing: this never guesses a product from a title or a keyword.
 *
 * Reads see the organisation's own rows and the shared ones (tenant 0); an organisation never sees another organisation's rows.
 */
final class ProductResolver
{
    /** A product (top level) from a key, label, stored business_fit value or Product Portfolio group name. */
    public function product(int $tenantId, ?string $value): ?ProductMaster
    {
        $v = trim((string) $value);
        if ($v === '') {
            return null;
        }

        $rows = $this->products($tenantId);

        // 1) The existing normaliser knows the keys, labels and business_fit values of the built-in and the organisation's own products.
        $key = ProductCatalog::normalise($v, $tenantId > 0 ? $tenantId : null);
        if ($key !== null && ($hit = $rows->first(fn (ProductMaster $r) => $r->product_key === $key))) {
            return $hit;
        }

        // 2) Otherwise compare the squashed text with the stored key, signal key, Portfolio group name and name.
        $want = $this->squash($v);
        foreach ($rows as $r) {
            foreach ([$r->product_key, $r->signal_key, $r->portfolio_name, $r->name] as $candidate) {
                if ($candidate !== null && $candidate !== '' && $this->squash((string) $candidate) === $want) {
                    return $r;
                }
            }
        }

        return null;
    }

    /** A sub-product from the Product Portfolio's offer id ("P2", "BUN-01"). Null when unknown or when the id is ambiguous. */
    public function subProduct(int $tenantId, ?string $offerId): ?ProductMaster
    {
        $o = trim((string) $offerId);
        if ($o === '') {
            return null;
        }

        $offerRows = DB::table('g2g_product_offers')
            ->where('offer_id', $o)
            ->where(fn ($q) => $q->where('sub_institute_id', $tenantId)->orWhereNull('sub_institute_id'))
            ->pluck('id');
        if ($offerRows->isEmpty()) {
            return null;
        }

        $rows = ProductMaster::visibleTo($tenantId)->active()
            ->where('kind', ProductMaster::KIND_SUB_PRODUCT)->whereIn('offer_row_id', $offerRows->all())->get();

        return $rows->count() === 1 ? $rows->first() : null;
    }

    /**
     * The most specific row a signal supports. Its matched offer's sub-product wins when the signal has no product value or the offer belongs to
     * the same product; when the two disagree the stored product value wins and the offer is ignored; with neither, nothing.
     */
    public function forSignal(int $tenantId, ?string $businessFit, ?string $offerId): ?ProductMaster
    {
        $product = $this->product($tenantId, $businessFit);
        $sub = $this->subProduct($tenantId, $offerId);

        if ($sub !== null && ($product === null || (int) $sub->parent_id === (int) $product->id)) {
            return $sub;
        }

        return $product;
    }

    /** @return Collection<int, ProductMaster> the active products visible to the organisation, its own first */
    private function products(int $tenantId): Collection
    {
        return ProductMaster::visibleTo($tenantId)->active()->where('kind', ProductMaster::KIND_PRODUCT)->get()
            ->sortBy(fn (ProductMaster $r) => $r->sub_institute_id === ProductMaster::SHARED ? 1 : 0)->values();
    }

    private function squash(string $s): string
    {
        return str_replace(['-', '_', ' ', '.', '/'], '', strtolower($s));
    }
}
