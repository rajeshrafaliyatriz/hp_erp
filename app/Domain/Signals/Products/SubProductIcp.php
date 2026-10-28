<?php

namespace App\Domain\Signals\Products;

use App\Domain\Portfolio\ProductOffer;
use App\Domain\Signals\Icp\IcpProfile;
use Illuminate\Support\Facades\Schema;

/**
 * The Individual ICP of one sub-product (one offer of the Product Portfolio): where it is stored, and what a run needs from it.
 *
 * It is stored on the offer (`g2g_product_offers.icp_config`) and, as the same settings, in `g2g_icp_profiles` under the key `sub_<offer id, lower-case>`.
 * Read the offer's own copy first, then the profile (the older lower-casing left some under a key with every capital letter turned into "_": that
 * one is used only when the ICP itself lists this offer). Pure reads; nothing is saved or changed here.
 */
final class SubProductIcp
{
    public static function key(string $offerId): string
    {
        return 'sub_' . preg_replace('/[^a-z0-9_-]/', '_', strtolower($offerId));
    }

    private static function legacyKey(string $offerId): string
    {
        return 'sub_' . strtolower(preg_replace('/[^a-z0-9_-]/', '_', $offerId));
    }

    /**
     * @param  iterable<ProductOffer>  $offers
     * @return array<string, array<string, mixed>|null> offer id => its ICP settings, or null when it has none
     */
    public static function configsFor(int $tenantId, iterable $offers): array
    {
        $out = [];
        $need = [];
        foreach ($offers as $o) {
            $id = (string) $o->offer_id;
            $c = $o->icp_config ?? null;
            if (is_string($c)) {
                $c = json_decode($c, true);
            }
            if (is_array($c) && $c !== []) {
                $out[$id] = $c;
            } else {
                $need[] = $id;
                $out[$id] = null;
            }
        }
        if ($need !== [] && Schema::hasTable('g2g_icp_profiles')) {
            $keys = [];
            foreach ($need as $id) {
                $keys[] = self::key($id);
                $keys[] = self::legacyKey($id);
            }
            $rows = IcpProfile::where('sub_institute_id', $tenantId)->whereIn('key', array_values(array_unique($keys)))->get()->keyBy('key');
            foreach ($need as $id) {
                $p = $rows->get(self::key($id));
                if ($p === null) {
                    $l = $rows->get(self::legacyKey($id));
                    $p = $l !== null && in_array($id, array_map('strval', (array) ($l->config['offer_ids'] ?? [])), true) ? $l : null;
                }
                $out[$id] = $p !== null ? (array) $p->config : null;
            }
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    public static function configFor(int $tenantId, ProductOffer $offer): ?array
    {
        return self::configsFor($tenantId, [$offer])[(string) $offer->offer_id] ?? null;
    }

    /** What a run still needs from this ICP (empty = it can be used). @param  array<string, mixed>|null  $config  @return list<string> */
    public static function problems(?array $config): array
    {
        if ($config === null || $config === []) {
            return ['no Individual ICP is saved for this sub-product'];
        }
        $out = [];
        if (trim((string) ($config['industry_label'] ?? '')) === '') {
            $out[] = 'its ICP does not say who the ideal customer is';
        }
        if (empty($config['triggers'])) {
            $out[] = 'its ICP lists no buying triggers';
        }
        if (empty($config['queries'])) {
            $out[] = 'its ICP has no search queries';
        }

        return $out;
    }
}
