<?php

namespace App\Domain\Signals\Products;

use App\Domain\Portfolio\ProductOffer;
use App\Domain\Signals\Icp\IcpProfile;
use App\Domain\Signals\Icp\IcpProfileValidator;
use App\Domain\Signals\Icp\IcpProfileVersion;
use App\Domain\Signals\Support\RegionScope;
use App\Support\ColumnExists;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps ONE sub-product's ICP in step with the Region chosen on Company Opportunities.
 *
 * What "the matching ICP" is, in this application's own model:
 *   organisation (`sub_institute_id`) + product (`product_key`) + sub-product (the portfolio offer, `offer_id`) + customer model (b2b | b2c | b2g)
 * The ICP of a sub-product is stored per customer model in two places that SubProductIcp reads and the Portfolio screen writes together:
 * the offer's own map (`g2g_product_offers.icp_config`) and a profile row (`g2g_icp_profiles`, key `sub_<offer>[_<model>]`, unique per organisation).
 * This class writes the same two places the same way, so the Portfolio screen, research and this sync always see one ICP.
 *
 * The rules, enforced here and not in the screen:
 *  - Whole Product (no sub-product) never touches an ICP: a product has no ICP of its own, and "Whole Product" is not an ICP identity.
 *  - All Regions (no place chosen) never touches an ICP: nothing was selected to synchronise.
 *  - An existing ICP gets ONLY its `regions` replaced by the chosen places (a new version, the old one kept). Everything else in it is untouched.
 *  - No ICP yet: one is created from exactly what research already falls back to for that sub-product (ProductCatalog::synthesizeConfig, built from
 *    the offer's own data), with the chosen places. It is created only if it passes the same validation as any ICP; otherwise nothing is written and
 *    the reasons are returned. Nothing is invented here.
 *  - Another organisation's offer, another product's offer, and shared (organisation-less) catalogue offers are never written: a shared offer's
 *    map would be shared by every organisation.
 *  - Repeating the same request changes nothing.
 *
 * The caller runs this inside the transaction that also saves what the request needs (the run), so a failure later rolls the ICP change back too.
 */
final class SubProductIcpRegionSync
{
    public const CREATED = 'created';
    public const UPDATED = 'updated';
    public const UNCHANGED = 'unchanged';
    public const SKIPPED = 'skipped';

    public function __construct(private readonly IcpProfileValidator $validator = new IcpProfileValidator())
    {
    }

    /**
     * @return array{status: string, reason: ?string, icp: ?array{id: int, key: string, name: string, customer_model: string, offer_id: string, offer_name: string, regions: list<string>}, message: ?string}
     *
     * @throws RegionSyncRejected when the sub-product is not this organisation's, or its ICP cannot be created or changed validly (nothing is written)
     */
    public function sync(int $tenantId, ?int $userId, string $productKey, ?string $offerId, string $customerModel, ?RegionScope $region): array
    {
        if ($offerId === null || trim($offerId) === '') {
            return $this->skipped('whole_product');   // before anything is read: Whole Product has no ICP to look up
        }
        if ($region === null) {
            return $this->skipped('all_regions');
        }
        if (! in_array($customerModel, ['b2b', 'b2c', 'b2g'], true)) {
            throw new RegionSyncRejected('invalid_customer_model', 'Choose B2B, B2C, or B2G.');
        }
        if (! Schema::hasTable('g2g_icp_profiles') || ! Schema::hasTable('g2g_product_offers')) {
            throw new RegionSyncRejected('icp_storage_missing', 'ICP storage is not set up on the server, so the Region could not be saved to the ICP. Nothing was changed.');
        }

        $definition = ProductCatalog::definitionsFor($tenantId)[$productKey] ?? null;
        $found = $definition === null ? ['offer' => null, 'config' => null] : (new ProductCatalog())->subProduct($tenantId, $productKey, $offerId, $customerModel);
        /** @var ProductOffer|null $offer */
        $offer = $found['offer'];
        if ($offer === null) {
            throw new RegionSyncRejected('unknown_sub_product', 'That sub-product is not one of this product\'s products.');
        }
        if ($offer->sub_institute_id === null || (int) $offer->sub_institute_id !== $tenantId) {
            // A shared catalogue offer belongs to no single organisation; its ICP map would be shared by all of them.
            return $this->skipped('shared_sub_product');
        }

        $places = $region->terms();
        $locations = $this->locations($region);

        try {
            return DB::transaction(fn () => $this->apply($tenantId, $userId, $definition, $offer, $customerModel, $places, $locations, $found['config']));
        } catch (UniqueConstraintViolationException) {
            // A concurrent first request created the same ICP a moment ago: this one is then an update of it, not a second ICP.
            $again = (new ProductCatalog())->subProduct($tenantId, $productKey, $offerId, $customerModel);

            return DB::transaction(fn () => $this->apply($tenantId, $userId, $definition, $offer, $customerModel, $places, $locations, $again['config']));
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  list<string>  $places  the plain names research searches with (`regions`)
     * @param  list<array<string, mixed>>  $locations  the same places with the location catalogue's codes (`locations`)
     * @param  array<string, mixed>|null  $existing  the ICP the application reads for this sub-product and customer model, if any
     * @return array<string, mixed>
     */
    private function apply(int $tenantId, ?int $userId, array $definition, ProductOffer $offer, string $customerModel, array $places, array $locations, ?array $existing): array
    {
        $created = $existing === null || $existing === [];
        if ($created) {
            $config = $this->newConfig($definition, $offer, $customerModel);
        } else {
            $config = $existing;
            if ($this->samePlaces((array) ($config['regions'] ?? []), $places) && $this->sameLocations($config['locations'] ?? null, $locations)) {
                return $this->result(self::UNCHANGED, $this->icpRow($tenantId, $offer, $customerModel), $config, $offer, 'already_set');
            }
        }

        $before = $created ? [] : $this->validator->validate($config);
        $config['regions'] = $places;
        $config['locations'] = $locations;
        $problems = $this->validator->validate($config);
        // An older ICP may already have problems of its own; only what THIS change would add counts against it.
        $problems = $created ? $problems : array_values(array_diff($problems, $before));
        if ($created) {
            $problems = array_values(array_unique(array_merge($problems, SubProductIcp::problems($config, $customerModel))));
        }
        if ($problems !== []) {
            throw new RegionSyncRejected(
                'icp_incomplete',
                ($created ? 'No ICP exists for this sub-product yet and one could not be created from the data available' : 'The Region could not be saved to this sub-product\'s ICP')
                . ': ' . implode('; ', array_slice($problems, 0, 6)) . '. Complete the ICP in Product Portfolio, then try again. Nothing was changed.',
                $problems,
            );
        }

        $profile = $this->saveProfile($tenantId, $userId, $offer, $customerModel, $config, $created, $places);
        $this->saveOfferMap($offer, $customerModel, $config);

        return $this->result($created ? self::CREATED : self::UPDATED, $profile, $config, $offer, null);
    }

    /**
     * The ICP a sub-product without one gets: what research already builds for it at run time (ProductCatalog::synthesizeConfig, from the offer's
     * own name, description and trigger signals), made valid as a SAVED ICP without adding anything the offer does not say:
     *  - triggers are only the ones the offer itself lists (its `trigger_signals`), each with its own words as the phrase to look for. The run-time
     *    fallback invents four generic triggers when an offer lists none; here an offer with none cannot get an ICP, and the reason is returned;
     *  - the generic disqualifiers the run-time fallback adds are left out (they carry no phrases, and nobody wrote them for this offer);
     *  - settings the ICP validator does not know are dropped, and a query topic outside news/general becomes news.
     *
     * @return array<string, mixed>
     */
    private function newConfig(array $definition, ProductOffer $offer, string $customerModel): array
    {
        $config = (new ProductCatalog())->synthesizeConfig($definition, collect([$offer]), $customerModel, $offer);
        unset($config['sub_product'], $config['rotation_runs_per_day'], $config['disqualifiers']);   // research context / unknown setting / not the offer's own

        $own = [];
        $listed = is_array($offer->trigger_signals) ? $offer->trigger_signals : (is_string($offer->trigger_signals) ? explode(',', $offer->trigger_signals) : []);
        foreach ($listed as $signal) {
            $key = strtolower((string) preg_replace('/[^a-zA-Z0-9_]/', '_', trim((string) $signal)));
            if ($key !== '' && isset($config['triggers'][$key])) {
                $own[$key] = $config['triggers'][$key] + ['phrases' => [trim(str_replace('_', ' ', $key))]];
                if (empty($own[$key]['phrases'])) {
                    $own[$key]['phrases'] = [trim(str_replace('_', ' ', $key))];
                }
            }
        }
        $config['triggers'] = $own;   // empty = the offer lists none: SubProductIcp::problems() then refuses it, with the reason
        foreach ((array) ($config['queries'] ?? []) as $i => $query) {
            if (is_array($query) && ! in_array($query['topic'] ?? null, ['news', 'general'], true)) {
                $config['queries'][$i]['topic'] = 'news';
            }
        }
        $config['customer_model'] = $customerModel;
        $config['offer_ids'] = [(string) $offer->offer_id];
        $config['label'] = $offer->name . ' ' . strtoupper($customerModel) . ' ICP';

        return $config;
    }

    /** @param list<string> $places */
    private function saveProfile(int $tenantId, ?int $userId, ProductOffer $offer, string $customerModel, array $config, bool $created, array $places): IcpProfile
    {
        $key = SubProductIcp::key((string) $offer->offer_id, $customerModel);
        $profile = IcpProfile::where('sub_institute_id', $tenantId)->where('key', $key)->lockForUpdate()->first();
        $note = 'Region set from Market Signals: ' . implode('; ', $places);

        if ($profile === null) {
            // Created now, or the application read this ICP from the offer's own map and no profile row existed yet: the profile row is added so
            // both stores agree (the Portfolio screen writes both).
            $profile = IcpProfile::create([
                'sub_institute_id' => $tenantId, 'key' => $key, 'name' => (string) ($config['label'] ?? $offer->name),
                'product_line' => 'offer_' . preg_replace('/[^a-z0-9_]/', '_', strtolower((string) $offer->offer_id)) . '_' . $customerModel,
                'status' => 'active', 'is_default' => false, 'version' => 1,
                'config' => $config, 'created_by' => $userId, 'updated_by' => $userId,
            ]);
            $this->snapshot($profile, $userId, $created ? 'Created from Market Signals. ' . $note : $note);

            return $profile;
        }

        $profile->version = (int) $profile->version + 1;
        $profile->config = $config;
        $profile->updated_by = $userId;
        $profile->save();
        $this->snapshot($profile, $userId, $note);

        return $profile;
    }

    /** The offer's own multi-model map: only this model's entry changes. Tenant-owned offers only (checked by the caller). */
    private function saveOfferMap(ProductOffer $offer, string $customerModel, array $config): void
    {
        if (! ColumnExists::has('g2g_product_offers', 'icp_config')) {
            return;
        }
        $map = SubProductIcp::normalizeStoredConfig(is_array($offer->icp_config) ? $offer->icp_config : (is_string($offer->icp_config) ? json_decode($offer->icp_config, true) : null));
        $map[$customerModel] = $config;
        $offer->icp_config = $map;
        $offer->save();
    }

    private function icpRow(int $tenantId, ProductOffer $offer, string $customerModel): ?IcpProfile
    {
        return IcpProfile::where('sub_institute_id', $tenantId)->where('key', SubProductIcp::key((string) $offer->offer_id, $customerModel))->first();
    }

    private function snapshot(IcpProfile $p, ?int $userId, string $note): void
    {
        IcpProfileVersion::create([
            'sub_institute_id' => $p->sub_institute_id, 'icp_profile_id' => $p->id, 'version' => $p->version,
            'config' => $p->config, 'changed_by' => $userId, 'note' => mb_substr($note, 0, 255),
        ]);
    }

    /** Same places, whatever the order or capitalisation. @param  array<int, mixed>  $a  @param  list<string>  $b */
    private function samePlaces(array $a, array $b): bool
    {
        $norm = fn (array $list) => collect($list)->map(fn ($p) => mb_strtolower(trim((string) $p)))->filter()->unique()->sort()->values()->all();

        return $norm($a) === $norm($b);
    }

    /**
     * The structured places an ICP keeps: names plus the catalogue's codes, nothing that is not known (no null placeholders).
     *
     * @return list<array<string, mixed>>
     */
    private function locations(RegionScope $region): array
    {
        return array_map(
            fn (array $s) => array_filter(
                array_intersect_key($s, array_flip(['country', 'country_code', 'state', 'state_code', 'city', 'city_id', 'verified', 'source'])),
                fn ($v) => $v !== null,
            ),
            $region->structured(),
        );
    }

    /** Same structured places, whatever the order. A stored ICP with none (older data) is not the same: it gets them. @param  list<array<string, mixed>>  $new */
    private function sameLocations(mixed $stored, array $new): bool
    {
        if (! is_array($stored)) {
            return false;
        }
        $norm = fn (array $list) => collect($list)->map(fn ($p) => json_encode(collect((array) $p)->sortKeys()->all()))->sort()->values()->all();

        return $norm($stored) === $norm($new);
    }

    /** @return array<string, mixed> */
    private function skipped(string $reason): array
    {
        return ['status' => self::SKIPPED, 'reason' => $reason, 'icp' => null, 'message' => null];
    }

    /** @param  array<string, mixed>  $config  @return array<string, mixed> */
    private function result(string $status, ?IcpProfile $profile, array $config, ProductOffer $offer, ?string $reason): array
    {
        $regions = array_values(array_map('strval', (array) ($config['regions'] ?? [])));
        $name = (string) $offer->name;
        $where = implode('; ', $regions);
        $model = strtoupper((string) ($config['customer_model'] ?? ''));

        return [
            'status' => $status,
            'reason' => $reason,
            'icp' => $profile === null ? null : [
                'id' => (int) $profile->id, 'key' => (string) $profile->key, 'name' => (string) $profile->name,
                'customer_model' => (string) ($config['customer_model'] ?? ''), 'offer_id' => (string) $offer->offer_id, 'offer_name' => $name, 'regions' => $regions,
            ],
            'message' => match ($status) {
                self::CREATED => "Created the {$model} ICP for {$name} with Region: {$where}.",
                self::UPDATED => "Updated the Region of the {$model} ICP for {$name} to {$where}.",
                default => null,
            },
        ];
    }
}
