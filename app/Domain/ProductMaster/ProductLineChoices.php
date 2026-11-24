<?php

namespace App\Domain\ProductMaster;

/**
 * `GET /api/product-master/lines`: the Products (level 1) a form can offer, and with `?withSubProducts=1` the Sub-products and items under each (S15 follow-up, 24 Nov 2026,
 * D-7; contract section 4.4). Names, ids and kinds only: nothing priced, so it is open to every signed-in user of the organisation, as it always was.
 *
 * The default answer is what the route always gave, `{id, key, name}` for every ACTIVE Product by name, and is not changed by the new parameter existing.
 *
 * With the parameter every active Product comes first with, right after it, its active Sub-products and items (by name), each as `{id, key, name, kind, parentName, sold}`
 * (`kind` is `product`, `sub_product` or `item`; `parentName` is null for a Product). `sold` is true for a Sub-product or item that has a catalogue row that is switched on and not
 * in the Recycle Bin (the meaning it has in the picker's `scope=all`), false for one that is not sold in CRM, and null for a Product (a Product is never sold itself). The form
 * shows "Not sold" next to a row whose `sold` is false; every row can still be chosen, because the form stores the master id. A Sub-product or item that sits under no active Product (it has no Product yet, or its
 * Product is archived) is still a thing a person may pick, so it follows the Products, with the name of its Product when it has one. The lead, campaign and opportunity forms
 * store the MASTER id of whatever is chosen, so a Sub-product is a valid choice there (the validators accept any row of the organisation).
 *
 * The organisation's own rows only (D-1).
 */
final class ProductLineChoices
{
    public function __construct(private readonly int $tenant)
    {
    }

    /** @return list<array{id: int, key: string, name: string}> */
    public function lines(): array
    {
        return $this->products()->map(fn (ProductMaster $r) => ['id' => (int) $r->id, 'key' => $r->product_key, 'name' => $r->name])->values()->all();
    }

    /** @return list<array{id: int, key: string, name: string, kind: string, parentName: ?string, sold: ?bool}> */
    public function withSubProducts(): array
    {
        $products = $this->products();
        $under = ProductMaster::visibleTo($this->tenant)->active()->whereIn('kind', [ProductMaster::KIND_SUB_PRODUCT, ProductMaster::KIND_ITEM])
            ->orderBy('name')->orderBy('id')->get()->groupBy(fn (ProductMaster $r) => (int) ($r->parent_id ?? 0));
        $names = ProductMaster::visibleTo($this->tenant)->where('kind', ProductMaster::KIND_PRODUCT)->pluck('name', 'id');
        $sold = $this->soldCatalogueIds($under->flatten(1));

        $row = fn (ProductMaster $r, ?string $parentName): array => [
            'id' => (int) $r->id, 'key' => $r->product_key, 'name' => $r->name, 'kind' => (string) $r->kind, 'parentName' => $parentName,
            'sold' => $r->kind === ProductMaster::KIND_PRODUCT ? null : ($r->crm_product_id !== null && isset($sold[(int) $r->crm_product_id])),
        ];
        $out = [];
        $listed = [];
        foreach ($products as $product) {
            $out[] = $row($product, null);
            $listed[(int) $product->id] = true;
            foreach ($under->get((int) $product->id, collect()) as $child) {
                $out[] = $row($child, (string) $product->name);
            }
        }
        // Whatever hangs under no ACTIVE Product: no Product at all, or one that is archived.
        foreach ($under as $parentId => $children) {
            if (isset($listed[$parentId])) {
                continue;
            }
            foreach ($children as $child) {
                $out[] = $row($child, $parentId > 0 && isset($names[$parentId]) ? (string) $names[$parentId] : null);
            }
        }

        return $out;
    }

    /**
     * The catalogue rows among those of these Sub-products and items that are SOLD: switched on and not in the Recycle Bin, the organisation's own (Sell is never shared).
     *
     * @param  \Illuminate\Support\Collection<int, ProductMaster>  $rows
     * @return array<int, true> catalogue id => true
     */
    private function soldCatalogueIds(\Illuminate\Support\Collection $rows): array
    {
        $ids = $rows->pluck('crm_product_id')->filter()->unique()->values()->all();
        if ($ids === [] || ! \Illuminate\Support\Facades\Schema::hasTable('crm_products')) {
            return [];
        }

        return \Illuminate\Support\Facades\DB::table('crm_products')->where('sub_institute_id', $this->tenant)->whereIn('id', $ids)->whereNull('deleted_at')->whereRaw('COALESCE(is_active, 1) <> 0')
            ->pluck('id')->mapWithKeys(fn ($id) => [(int) $id => true])->all();
    }

    /** @return \Illuminate\Support\Collection<int, ProductMaster> the active Products by name */
    private function products(): \Illuminate\Support\Collection
    {
        return ProductMaster::visibleTo($this->tenant)->where('kind', ProductMaster::KIND_PRODUCT)->active()->orderBy('name')->get();
    }
}
