<?php

namespace App\Domain\Gtm;

use App\Domain\Signals\Products\ProductCatalog;
use App\Domain\Signals\Support\RegionInvalid;
use App\Domain\Signals\Support\RegionScope;
use Illuminate\Http\Request;

/**
 * The list a prospecting account belongs to: a product, a customer type and a place. The product and type are the same two choices as in Company
 * Opportunities (b2b = customer acquisition, b2c = partner acquisition, b2g = government); the place is checked by the same location rules Market
 * Signals uses (RegionScope), so there is one region system, not two.
 *
 * "all" or an empty value means "no filter" when reading, and "not chosen" when writing: the callers that write require a real choice.
 * A product is checked against the organisation's own catalogue, so a key that does not exist is refused, never stored.
 *
 * The place on an account is exactly the one it was added under (a country, a state, or a city). When reading, choosing a country shows all of its
 * states' accounts and choosing a state shows only that state's, so Gujarat and Maharashtra accounts are never in each other's list.
 */
final class ProspectScope
{
    public const MODELS = ['b2b', 'b2c', 'b2g'];

    /**
     * The scope a request asks for. The place arrives as flat fields (region_country, region_state, region_city and, when it was picked from the
     * location lists, region_country_code, region_state_code, region_city_id) so it works in a query string too.
     *
     * @return array{product: ?string, model: ?string, region: ?array{country: string, state: ?string, city: ?string, key: string}}
     *
     * @throws \DomainException with a message that is safe to show
     */
    public static function fromRequest(Request $request, int $tenantId): array
    {
        $place = null;
        if (trim((string) $request->input('region_country', '')) !== '' || trim((string) $request->input('region_country_code', '')) !== '') {
            $place = array_filter([
                'country' => $request->input('region_country'), 'state' => $request->input('region_state'), 'city' => $request->input('region_city'),
                'country_code' => $request->input('region_country_code'), 'state_code' => $request->input('region_state_code'), 'city_id' => $request->input('region_city_id'),
            ], fn ($v) => $v !== null && $v !== '');
        }

        return self::fromInput($request->input('product'), $request->input('customer_model'), $tenantId, $place);
    }

    /**
     * @param  mixed  $region  null / 'all' (no place), or one place: ['country' => ..., 'state' => ?, 'city' => ?]
     * @return array{product: ?string, model: ?string, region: ?array{country: string, state: ?string, city: ?string, key: string}}
     *
     * @throws \DomainException with a message that is safe to show
     */
    public static function fromInput(mixed $product, mixed $model, int $tenantId, mixed $region = null): array
    {
        $productKey = null;
        $product = strtolower(trim((string) $product));
        if ($product !== '' && $product !== 'all') {
            $productKey = ProductCatalog::normalise($product, $tenantId);
            if ($productKey === null) {
                throw new \DomainException('Choose one of your products.');
            }
        }

        $modelKey = null;
        $model = strtolower(trim((string) $model));
        if ($model !== '' && $model !== 'all') {
            if (! in_array($model, self::MODELS, true)) {
                throw new \DomainException('Choose B2B (customers), B2C (partners) or B2G (government).');
            }
            $modelKey = $model;
        }

        return ['product' => $productKey, 'model' => $modelKey, 'region' => self::place($region)];
    }

    /** One place, validated by the same location rules Market Signals uses. Null means no place (All regions). */
    public static function place(mixed $region): ?array
    {
        if ($region === null || $region === '' || $region === 'all' || $region === []) {
            return null;
        }
        try {
            $scope = RegionScope::fromInput([$region]);
        } catch (RegionInvalid $e) {
            throw new \DomainException('Choose a valid region: ' . implode(' ', $e->problems));
        }
        $sel = $scope?->selections()[0] ?? null;
        if ($sel === null) {
            return null;
        }

        return [
            'country' => (string) $sel['country'], 'state' => $sel['state'] ?: null, 'city' => $sel['city'] ?: null,
            'key' => self::key($sel['country'], $sel['state'] ?? null, $sel['city'] ?? null),
        ];
    }

    /** A short fingerprint of a place; '' when there is none. The same place gives the same key, whatever the letter case. */
    public static function key(?string $country, ?string $state, ?string $city): string
    {
        if (trim((string) $country) === '') {
            return '';
        }

        return substr(md5(mb_strtolower(trim((string) $country) . '|' . trim((string) $state) . '|' . trim((string) $city))), 0, 16);
    }

    /** The place an existing account was filed under (null when it has none). */
    public static function regionOf(object $account): ?array
    {
        if (trim((string) ($account->region_country ?? '')) === '') {
            return null;
        }

        return [
            'country' => $account->region_country, 'state' => $account->region_state ?: null, 'city' => $account->region_city ?: null,
            'key' => self::key($account->region_country, $account->region_state, $account->region_city),
        ];
    }

    /** The scope an existing account belongs to. */
    public static function of(object $account): array
    {
        return ['product' => $account->product_key, 'model' => $account->customer_model, 'region' => self::regionOf($account)];
    }

    /** The columns to write on an account for this scope's place. */
    public static function regionColumns(array $scope): array
    {
        $r = $scope['region'] ?? null;

        return ['region_country' => $r['country'] ?? null, 'region_state' => $r['state'] ?? null, 'region_city' => $r['city'] ?? null, 'region_key' => $r['key'] ?? ''];
    }

    /** Is anything chosen at all? A contact import must stay inside the list when it is. */
    public static function isFiltered(array $scope): bool
    {
        return ($scope['product'] ?? null) !== null || ($scope['model'] ?? null) !== null || ($scope['region'] ?? null) !== null;
    }

    /** Apply a scope to a query on a table that has product_key and customer_model (and, for accounts, the region columns). */
    public static function apply($query, array $scope, string $table = 'gtm_accounts', bool $withRegion = true)
    {
        if ($scope['product'] !== null) {
            $query->where("{$table}.product_key", $scope['product']);
        }
        if ($scope['model'] !== null) {
            $query->where("{$table}.customer_model", $scope['model']);
        }
        $r = $scope['region'] ?? null;
        if ($withRegion && $r !== null) {
            $query->whereRaw("LOWER({$table}.region_country) = ?", [mb_strtolower($r['country'])]);
            if ($r['state'] !== null) {
                $query->whereRaw("LOWER({$table}.region_state) = ?", [mb_strtolower($r['state'])]);
            }
            if ($r['city'] !== null) {
                $query->whereRaw("LOWER({$table}.region_city) = ?", [mb_strtolower($r['city'])]);
            }
        }

        return $query;
    }

    /**
     * Signals (opportunities) in a scope: product and type are columns; the place is the place(s) the run was limited to, stored as JSON in
     * `region_scope` ([{"country":"India","state":"Gujarat","city":null}]). A signal from an "All regions" run has none, so it is not shown under
     * a chosen place.
     */
    public static function applyToSignals($query, array $scope, string $table = 'g2g_company_opportunities')
    {
        self::apply($query, $scope, $table, false);
        $r = $scope['region'] ?? null;
        if ($r !== null) {
            $like = function (string $field, string $value): string {
                $text = '"' . $field . '":' . json_encode($value, JSON_UNESCAPED_UNICODE);

                return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $text) . '%';
            };
            $query->where("{$table}.region_scope", 'like', $like('country', $r['country']));
            if ($r['state'] !== null) {
                $query->where("{$table}.region_scope", 'like', $like('state', $r['state']));
            }
            if ($r['city'] !== null) {
                $query->where("{$table}.region_scope", 'like', $like('city', $r['city']));
            }
        }

        return $query;
    }
}
