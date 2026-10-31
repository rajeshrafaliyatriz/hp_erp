<?php

namespace App\Domain\Signals\Opportunities;

use App\Domain\Portfolio\ProductOffer;
use App\Domain\Signals\Icp\IcpPack;
use App\Domain\Signals\Products\ProductCatalog;
use Illuminate\Support\Facades\Schema;

/** Stable identity for one selector scope and the saved product/ICP data the researcher will read. */
final class ResearchRunScope
{
    /** @param list<array{country:string,state:?string,city:?string}>|null $regions */
    public static function key(int $tenantId, string $product, ?string $offerId, string $customerModel, ?array $regions): string
    {
        if ($regions !== null) {
            usort($regions, fn ($a, $b) => json_encode($a) <=> json_encode($b));
        }
        $payload = [$product, $offerId, $customerModel, $regions, self::configuration($tenantId, $product, $offerId, $customerModel)];
        $json = json_encode(self::sort($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return hash('sha256', $json === false ? '' : $json);
    }

    /** @return array<string, mixed> */
    private static function configuration(int $tenantId, string $product, ?string $offerId, string $customerModel): array
    {
        $catalog = new ProductCatalog();
        $found = $catalog->icpFor($tenantId, $product, $customerModel);
        $icp = $found['icp']['config'] ?? null;
        $offer = null;

        if ($offerId !== null) {
            $child = $catalog->subProduct($tenantId, $product, $offerId, $customerModel);
            $offer = $child['offer'];
            if ($offer !== null && is_array($child['config'])) {
                $base = is_array($icp) ? $icp : $child['config'];
                $pack = new IcpPack((string) ($found['icp']['pack_key'] ?? $product), $base, $found['icp']['profile_id'] ?? null);
                $icp = $pack->withSubProduct($child['config'], (string) $offer->offer_id, (string) $offer->name)->config();
            }
        }

        $offers = [];
        if ($offer !== null) {
            $offers[] = $offer->getAttributes();
        } else {
            $definition = ProductCatalog::definitionsFor($tenantId)[$product] ?? [];
            if (Schema::hasTable('g2g_product_offers')) {
                $offerIds = array_values(array_filter((array) ($icp['offer_ids'] ?? []), 'is_string'));
                $query = ProductOffer::query()->forTenant($tenantId)->where(function ($q) use ($definition, $offerIds) {
                    $q->whereIn('parent_product', (array) ($definition['portfolio_names'] ?? []));
                    if ($offerIds !== []) $q->orWhereIn('offer_id', $offerIds);
                });
                $offers = $query->orderBy('offer_id')->get()->map(fn ($row) => $row->getAttributes())->all();
            }
        }

        $profile = Schema::hasTable('g2g_product_profiles')
            ? ProductProfile::where('sub_institute_id', $tenantId)->first()?->getAttributes()
            : null;

        return ['icp' => $icp, 'offers' => $offers, 'company_profile' => $profile];
    }

    private static function sort(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sort($item);
            }
        }

        return $value;
    }
}
