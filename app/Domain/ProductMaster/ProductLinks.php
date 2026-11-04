<?php

namespace App\Domain\ProductMaster;

use App\Domain\Signals\Products\ProductCatalog;
use App\Domain\Signals\Products\SubProductIcp;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the Products screen shows about a record besides its own fields: the product line it belongs to, the Signals found for it and the state of its
 * ideal-customer profile (ICP). Read-only, tenant-scoped, and a FIXED number of queries however many records are asked about (one page of the list
 * is one call), so the list stays as cheap as it was.
 *
 * ICP state, per record: `active`, `draft` or `archived` (the best of the organisation's saved profiles for it), `template` (a built-in product that runs
 * on its built-in ICP and has none saved), `none` (it could have one and has none), or null (an ICP does not apply: a catalogue-only item, or a product
 * Signals has no value for). Only the state is returned here, never the profile's content: the ICP editor keeps its own administrator-only rights.
 */
final class ProductLinks
{
    public const ICP_ACTIVE = 'active';

    public const ICP_DRAFT = 'draft';

    public const ICP_ARCHIVED = 'archived';

    public const ICP_TEMPLATE = 'template';

    public const ICP_NONE = 'none';

    private const ICP_RANK = [self::ICP_ACTIVE => 3, self::ICP_DRAFT => 2, self::ICP_ARCHIVED => 1];

    /** The fields every catalogue item carries, empty when it has no master row. */
    public const NO_LINK = ['masterId' => null, 'masterKind' => null, 'masterStatus' => null, 'productLine' => null, 'offerId' => null, 'signalsFound' => null, 'icp' => null];

    /**
     * For catalogue items (crm_products ids): the master row pointing at each, its product line, and for a sub-product its offer id, Signals count and ICP.
     *
     * @param  list<int>  $crmProductIds
     * @return array<int, array{masterId:?int, masterKind:?string, masterStatus:?string, productLine:?array{id:int, name:string}, offerId:?string, signalsFound:?int, icp:?string}>
     */
    public static function forCatalogueItems(int $tenant, array $crmProductIds): array
    {
        $out = [];
        foreach ($crmProductIds as $id) {
            $out[(int) $id] = self::NO_LINK;
        }
        if ($out === [] || $tenant <= 0 || ! Schema::hasTable('g2g_product_master')) {
            return $out;
        }

        $rows = ProductMaster::where('sub_institute_id', $tenant)->whereIn('crm_product_id', array_keys($out))->get();
        if ($rows->isEmpty()) {
            return $out;
        }
        $parents = ProductMaster::visibleTo($tenant)->whereIn('id', $rows->pluck('parent_id')->filter()->unique()->values()->all())->get()->keyBy('id');
        $offers = self::offerIds($rows);
        $signals = self::signalsByOffer($tenant, array_values($offers));
        $icp = self::icpByOffer($tenant, array_values($offers));

        foreach ($rows as $row) {
            $parent = $row->parent_id !== null ? $parents->get($row->parent_id) : null;
            $offerId = $row->offer_row_id !== null ? ($offers[(int) $row->offer_row_id] ?? null) : null;
            $out[(int) $row->crm_product_id] = [
                'masterId' => (int) $row->id, 'masterKind' => $row->kind, 'masterStatus' => $row->status,
                'productLine' => $parent ? ['id' => (int) $parent->id, 'name' => (string) $parent->name] : null,
                'offerId' => $offerId,
                'signalsFound' => $offerId !== null ? ($signals[$offerId] ?? 0) : null,
                'icp' => $offerId !== null ? ($icp[$offerId] ?? self::ICP_NONE) : null,
            ];
        }

        return $out;
    }

    /**
     * What the Products list needs to know about the product lines behind it, in one small object:
     *   available     the Product Master exists on this server (a host that has not had the S1 database yet answers false, and the screen shows no scope switch)
     *   linked        how many of the organisation's live catalogue items of this type are tied to a sub-product (the list shows its extra columns only when there are any)
     *   productLines  the active product lines the organisation can see (its own and the built-in ones)
     *   subProducts   the active sub-products it can see
     *
     * @return array{available:bool, linked:int, productLines:int, subProducts:int}
     */
    public static function summary(int $tenant, string $itemType): array
    {
        $none = ['available' => false, 'linked' => 0, 'productLines' => 0, 'subProducts' => 0];
        if ($tenant <= 0 || ! Schema::hasTable('g2g_product_master')) {
            return $none;
        }

        $kinds = ProductMaster::visibleTo($tenant)->active()->whereIn('kind', [ProductMaster::KIND_PRODUCT, ProductMaster::KIND_SUB_PRODUCT])
            ->selectRaw('kind, COUNT(*) as n')->groupBy('kind')->pluck('n', 'kind');

        return [
            'available' => true,
            'linked' => self::linkedCount($tenant, $itemType),
            'productLines' => (int) ($kinds[ProductMaster::KIND_PRODUCT] ?? 0),
            'subProducts' => (int) ($kinds[ProductMaster::KIND_SUB_PRODUCT] ?? 0),
        ];
    }

    /** How many of the organisation's live catalogue items of this type are tied to a sub-product: the Products list shows its extra columns only when there are any. */
    public static function linkedCount(int $tenant, string $itemType): int
    {
        if ($tenant <= 0 || ! Schema::hasTable('g2g_product_master')) {
            return 0;
        }

        return (int) DB::table('g2g_product_master as m')->join('crm_products as p', 'p.id', '=', 'm.crm_product_id')
            ->where('m.sub_institute_id', $tenant)->where('m.kind', ProductMaster::KIND_SUB_PRODUCT)->where('m.status', ProductMaster::STATUS_ACTIVE)
            ->where('p.sub_institute_id', $tenant)->where('p.item_type', $itemType)->whereNull('p.deleted_at')->count();
    }

    /**
     * The ICP state of products (top-level master rows), by master id.
     *
     * @param  Collection<int, ProductMaster>  $products
     * @return array<int, ?string>
     */
    public static function icpByProduct(int $tenant, Collection $products): array
    {
        $defs = ProductCatalog::definitionsFor($tenant);
        $lineOf = [];
        foreach ($products as $p) {
            $lineOf[(int) $p->id] = $p->kind === ProductMaster::KIND_PRODUCT ? ($defs[$p->product_key]['icp_product_line'] ?? null) : null;
        }
        $lines = array_values(array_unique(array_filter($lineOf)));
        $best = [];
        if ($lines !== [] && Schema::hasTable('g2g_icp_profiles')) {
            foreach (DB::table('g2g_icp_profiles')->where('sub_institute_id', $tenant)->whereIn('product_line', $lines)->get(['product_line', 'status']) as $r) {
                $best[$r->product_line] = self::better($best[$r->product_line] ?? null, (string) $r->status);
            }
        }

        $out = [];
        foreach ($products as $p) {
            $line = $lineOf[(int) $p->id];
            $out[(int) $p->id] = match (true) {
                $line === null => null,
                isset($best[$line]) => $best[$line],
                ($defs[$p->product_key]['icp_template'] ?? null) !== null => self::ICP_TEMPLATE,
                default => self::ICP_NONE,
            };
        }

        return $out;
    }

    /**
     * The ICP state of sub-products, by offer id: the best of its B2B, B2C and B2G profiles; `none` when it has none.
     *
     * @param  list<string>  $offerIds
     * @return array<string, string>
     */
    public static function icpByOffer(int $tenant, array $offerIds): array
    {
        $offerIds = array_values(array_unique(array_filter($offerIds, fn ($o) => $o !== null && $o !== '')));
        $out = [];
        foreach ($offerIds as $o) {
            $out[$o] = self::ICP_NONE;
        }
        if ($offerIds === [] || ! Schema::hasTable('g2g_icp_profiles')) {
            return $out;
        }

        $keyToOffer = [];
        foreach ($offerIds as $o) {
            foreach (['b2b', 'b2c', 'b2g'] as $model) {
                $keyToOffer[SubProductIcp::key($o, $model)] = $o;
            }
        }
        $best = [];
        foreach (DB::table('g2g_icp_profiles')->where('sub_institute_id', $tenant)->whereIn('key', array_keys($keyToOffer))->get(['key', 'status']) as $r) {
            $offer = $keyToOffer[$r->key] ?? null;
            if ($offer !== null) {
                $best[$offer] = self::better($best[$offer] ?? null, (string) $r->status);
            }
        }

        return $best + $out;
    }

    /**
     * Signals recorded for each offer, by offer id (this organisation's, real ones only, not the sample rows).
     *
     * @param  list<string>  $offerIds
     * @return array<string, int>
     */
    public static function signalsByOffer(int $tenant, array $offerIds): array
    {
        $offerIds = array_values(array_unique(array_filter($offerIds, fn ($o) => $o !== null && $o !== '')));
        if ($offerIds === [] || ! Schema::hasTable('g2g_company_opportunities')) {
            return [];
        }

        return DB::table('g2g_company_opportunities')->where('sub_institute_id', $tenant)->where('is_sample', 0)->whereIn('relevant_offer_id', $offerIds)
            ->selectRaw('relevant_offer_id as o, COUNT(*) as n')->groupBy('relevant_offer_id')->pluck('n', 'o')->map(fn ($n) => (int) $n)->all();
    }

    /** @param Collection<int, ProductMaster> $rows @return array<int, string> offer row id => offer id */
    public static function offerIds(Collection $rows): array
    {
        $ids = $rows->pluck('offer_row_id')->filter()->unique()->values()->all();
        if ($ids === [] || ! Schema::hasTable('g2g_product_offers')) {
            return [];
        }

        return DB::table('g2g_product_offers')->whereIn('id', $ids)->pluck('offer_id', 'id')->map(fn ($o) => (string) $o)->all();
    }

    private static function better(?string $current, string $candidate): string
    {
        $candidate = isset(self::ICP_RANK[$candidate]) ? $candidate : self::ICP_ARCHIVED;

        return $current === null || self::ICP_RANK[$candidate] > self::ICP_RANK[$current] ? $candidate : $current;
    }
}
