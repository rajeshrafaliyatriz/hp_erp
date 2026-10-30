<?php

namespace App\Domain\Signals\Products;

use App\Domain\Portfolio\ProductOffer;
use App\Domain\Signals\Icp\IcpProfile;
use Illuminate\Support\Facades\Schema;

/**
 * The Individual ICP of one sub-product (one offer of the Product Portfolio): where it is stored, and what a run needs from it.
 *
 * It supports three independent ICP profiles per sub-product: B2B, B2C, and B2G.
 * It is stored on the offer (`g2g_product_offers.icp_config`) as a multi-model map and, as the same settings, in `g2g_icp_profiles`
 * under the keys `sub_<offer id, lower-case>_<model>`.
 * Read the offer's own copy first, then the profile (falling back to legacy un-suffixed keys for older B2B records).
 */
final class SubProductIcp
{
    public static function key(string $offerId, string $customerModel = 'b2b'): string
    {
        $base = 'sub_' . preg_replace('/[^a-z0-9_-]/', '_', strtolower($offerId));

        return $customerModel === 'b2b' ? $base : $base . '_' . $customerModel;
    }

    public static function baseKey(string $offerId): string
    {
        return 'sub_' . preg_replace('/[^a-z0-9_-]/', '_', strtolower($offerId));
    }

    private static function legacyKey(string $offerId): string
    {
        return 'sub_' . preg_replace('/[^a-z0-9_-]/', '_', strtolower($offerId));
    }

    private static function olderKey(string $offerId): string
    {
        return 'sub_' . strtolower(preg_replace('/[^a-z0-9_-]/', '_', $offerId));
    }

    /**
     * Normalizes stored icp_config to a dictionary of ['b2b' => ..., 'b2c' => ..., 'b2g' => ...].
     * Supports both multi-model maps and legacy single-object storage.
     *
     * @return array{b2b: array<string, mixed>|null, b2c: array<string, mixed>|null, b2g: array<string, mixed>|null}
     */
    public static function normalizeStoredConfig(?array $c): array
    {
        if ($c === null || $c === []) {
            return ['b2b' => null, 'b2c' => null, 'b2g' => null];
        }
        $isMulti = isset($c['b2b']) || isset($c['b2c']) || isset($c['b2g']);
        if ($isMulti) {
            return [
                'b2b' => isset($c['b2b']) && is_array($c['b2b']) && ! empty($c['b2b']) ? $c['b2b'] : null,
                'b2c' => isset($c['b2c']) && is_array($c['b2c']) && ! empty($c['b2c']) ? $c['b2c'] : null,
                'b2g' => isset($c['b2g']) && is_array($c['b2g']) && ! empty($c['b2g']) ? $c['b2g'] : null,
            ];
        }

        // Legacy single object:
        $model = isset($c['customer_model']) && in_array($c['customer_model'], ['b2b', 'b2c', 'b2g'], true) ? $c['customer_model'] : 'b2b';

        return [
            'b2b' => $model === 'b2b' ? $c : null,
            'b2c' => $model === 'b2c' ? $c : null,
            'b2g' => $model === 'b2g' ? $c : null,
        ];
    }

    /**
     * @param  iterable<ProductOffer>  $offers
     * @return array<string, array<string, mixed>|null> offer id => its ICP settings for the specified model, or null
     */
    public static function configsFor(int $tenantId, iterable $offers, string $customerModel = 'b2b'): array
    {
        $out = [];
        $need = [];
        foreach ($offers as $o) {
            $id = (string) $o->offer_id;
            $c = $o->icp_config ?? null;
            if (is_string($c)) {
                $c = json_decode($c, true);
            }
            $normalized = self::normalizeStoredConfig(is_array($c) ? $c : null);
            if (! empty($normalized[$customerModel])) {
                $out[$id] = $normalized[$customerModel];
            } else {
                $need[] = $id;
                $out[$id] = null;
            }
        }

        if ($need !== [] && Schema::hasTable('g2g_icp_profiles')) {
            $keys = [];
            foreach ($need as $id) {
                $keys[] = self::key($id, $customerModel);
                if ($customerModel === 'b2b') {
                    $keys[] = self::legacyKey($id);
                    $keys[] = self::olderKey($id);
                }
            }
            $rows = IcpProfile::where('sub_institute_id', $tenantId)->whereIn('key', array_values(array_unique($keys)))->get()->keyBy('key');
            foreach ($need as $id) {
                $p = $rows->get(self::key($id, $customerModel));
                if ($p === null && $customerModel === 'b2b') {
                    $p = $rows->get(self::legacyKey($id));
                    if ($p === null) {
                        $l = $rows->get(self::olderKey($id));
                        $p = $l !== null && in_array($id, array_map('strval', (array) ($l->config['offer_ids'] ?? [])), true) ? $l : null;
                    }
                }
                if ($p !== null) {
                    $stored = (array) $p->config;
                    $model = $stored['customer_model'] ?? 'b2b';
                    if ($model === $customerModel || ($customerModel === 'b2b' && ! in_array($model, ['b2c', 'b2g'], true))) {
                        $out[$id] = $stored;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Returns all configs for all 3 models for an offer.
     *
     * @return array{b2b: array<string, mixed>|null, b2c: array<string, mixed>|null, b2g: array<string, mixed>|null}
     */
    public static function allConfigsFor(int $tenantId, ProductOffer $offer): array
    {
        return [
            'b2b' => self::configFor($tenantId, $offer, 'b2b'),
            'b2c' => self::configFor($tenantId, $offer, 'b2c'),
            'b2g' => self::configFor($tenantId, $offer, 'b2g'),
        ];
    }

    /** @return array<string, mixed>|null */
    public static function configFor(int $tenantId, ProductOffer $offer, string $customerModel = 'b2b'): ?array
    {
        return self::configsFor($tenantId, [$offer], $customerModel)[(string) $offer->offer_id] ?? null;
    }

    /** What a run still needs from this ICP (empty = it can be used). @param  array<string, mixed>|null  $config  @return list<string> */
    public static function problems(?array $config, string $customerModel = 'b2b'): array
    {
        $label = strtoupper($customerModel);
        if ($config === null || $config === []) {
            return $customerModel === 'b2b'
                ? ['no Individual ICP is saved for this sub-product']
                : ["no Individual {$label} ICP is saved for this sub-product"];
        }
        $out = [];
        if (trim((string) ($config['industry_label'] ?? '')) === '') {
            $out[] = "its Individual {$label} ICP does not say who the ideal customer is";
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
