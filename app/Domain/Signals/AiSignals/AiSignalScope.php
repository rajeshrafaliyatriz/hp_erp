<?php

namespace App\Domain\Signals\AiSignals;

use App\Domain\Signals\Locations\LocationCatalog;
use App\Domain\Signals\Opportunities\CompanyOpportunity;
use App\Domain\Signals\Opportunities\ResearchRunScope;
use App\Domain\Signals\Products\ProductCatalog;
use App\Domain\Signals\Support\RegionInvalid;
use App\Domain\Signals\Support\RegionScope;
use App\Support\ColumnExists;

/**
 * Puts a delivered signal where the Signals screen's selectors can find it (its ICP type, its region, its product), from what the PRODUCER EXPLICITLY SENT
 * and nothing else. Pure of any model or network.
 *
 *   ICP type   from buyer.type, by the product catalogue's own definition of the types (ProductCatalog::synthesizeConfig): government bodies and
 *              public-sector organisations are B2G; an enterprise, a business, a school, a college, a university or an institution is B2B.
 *              Any other value, or none, gives NO ICP type. (A government-run school must be sent as "government" or "public_sector".)
 *   region     from buyer.country and/or buyer.state, checked against the location catalogue exactly as a person's own region selection is.
 *              A state alone is resolved to its country ONLY when the catalogue has exactly one country with a state of that name; "Punjab" (India
 *              and Pakistan) or "Georgia" is ambiguous and gives no region. Never from a website, a language or a guess.
 *   product    from business_fit, when exactly one product of the organisation has that business fit.
 * A value that cannot be established stays empty and the signal is unclassified: it is found under "All ICP types" and "All Regions", and it never
 * matches a selector it was not classified for.
 *
 * The scope is written AFTER the import, and only into columns that are still empty, so it can never change a signal's identity (the importer includes
 * the scope in the duplicate check: classifying before the import would turn a record sent with a type on one day and without it the next into two
 * signals) and can never overwrite a classification a person or an earlier run set.
 */
final class AiSignalScope
{
    /** buyer.type (the gate's own list) to the ICP type whose catalogue definition names that kind of buyer. Absent = not stated by the catalogue. */
    private const MODEL_BY_BUYER_TYPE = [
        'government' => 'b2g', 'public_sector' => 'b2g',
        'enterprise' => 'b2b', 'sme' => 'b2b', 'school' => 'b2b', 'school_group' => 'b2b', 'college' => 'b2b', 'university' => 'b2b', 'institutional' => 'b2b',
    ];

    /** @var array<string, list<string>>|null folded state name => country codes that have such a state */
    private static ?array $stateIndex = null;

    /** @return array{customer_model: ?string, region_scope: ?string, product_key: ?string} */
    public static function forRecord(array $record, int $tenantId): array
    {
        $buyer = is_array($record['buyer'] ?? null) ? $record['buyer'] : [];

        return [
            // What the producer says outright wins ("customer_model" / "icp_type"); otherwise it is inferred from buyer.type as before.
            'customer_model' => self::explicitModel($record['customer_model'] ?? ($record['icp_type'] ?? null)) ?? self::customerModel($buyer['type'] ?? null),
            'region_scope' => self::region($buyer),
            'product_key' => self::productKey(isset($record['business_fit']) && is_string($record['business_fit']) ? $record['business_fit'] : null, $tenantId),
        ];
    }

    /** The three ICP types by every spelling a producer is likely to use. "b2b_partner" is the screen's "B2B Partner Acquisition", which is stored as b2c. */
    private const MODEL_BY_NAME = [
        'b2b' => 'b2b', 'b2b_customer' => 'b2b', 'b2b_customer_acquisition' => 'b2b', 'customer_acquisition' => 'b2b',
        'b2c' => 'b2c', 'b2b_partner' => 'b2c', 'b2b_partner_acquisition' => 'b2c', 'partner' => 'b2c', 'partner_acquisition' => 'b2c',
        'b2g' => 'b2g', 'government' => 'b2g',
    ];

    /** The ICP type the record states explicitly (one of the three, in any listed spelling), or null when it states none or something else. Never guessed. */
    public static function explicitModel(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return self::MODEL_BY_NAME[strtolower(preg_replace('/[^a-z0-9]+/i', '_', trim($value)))] ?? null;
    }

    public static function customerModel(mixed $buyerType): ?string
    {
        if (! is_string($buyerType)) {
            return null;
        }

        return self::MODEL_BY_BUYER_TYPE[strtolower(str_replace([' ', '-'], '_', trim($buyerType)))] ?? null;
    }

    /** The stored region (the same JSON a region selection is stored as) or null. */
    public static function region(array $buyer): ?string
    {
        $state = self::text($buyer['state'] ?? null);
        $country = self::text($buyer['country'] ?? null);
        if ($state === null && $country === null) {
            return null;
        }
        $explicitCountry = $country !== null;
        if ($country === null) {
            $codes = self::countriesWithState((string) $state);
            if (count($codes) !== 1) {
                return null;   // unknown, or ambiguous between countries: not guessed
            }
            $country = (string) (LocationCatalog::shared()->country($codes[0])['name'] ?? '');
            if ($country === '') {
                return null;
            }
        }
        // Only a place the location catalogue VERIFIES is stored: the state must belong to the country. A state the catalogue does not have in that country
        // ("Karnataka" in the United States, a misspelling) is not kept; an explicitly named country alone still is, because that was stated outright.
        $catalog = LocationCatalog::shared();
        foreach (array_filter([['country' => $country] + ($state !== null ? ['state' => $state] : []), $explicitCountry && $state !== null ? ['country' => $country] : null]) as $place) {
            $resolved = $catalog->resolve($place);
            if ($resolved['problems'] !== [] || ($resolved['selection']['verified'] ?? false) !== true) {
                continue;
            }
            $canonical = array_filter(['country' => $resolved['selection']['country'], 'state' => $resolved['selection']['state']], fn ($v) => $v !== null);
            try {
                $region = RegionScope::fromInput([$canonical]);
            } catch (RegionInvalid) {
                continue;
            }
            if ($region !== null) {
                return ResearchRunScope::regionJson($region->selections());
            }
        }

        return null;
    }

    public static function productKey(?string $businessFit, int $tenantId): ?string
    {
        if ($businessFit === null || trim($businessFit) === '') {
            return null;
        }
        $keys = [];
        foreach (ProductCatalog::definitionsFor($tenantId) as $key => $definition) {
            if (($definition['business_fit'] ?? null) === $businessFit) {
                $keys[] = (string) $key;
            }
        }

        return count($keys) === 1 ? $keys[0] : null;
    }

    /**
     * Writes whatever of the scope is known into the signal's columns that are still empty. Each column is its own conditional update, so a value that is
     * already there (a person's, or an earlier delivery's) is never replaced.
     *
     * @param  array{customer_model: ?string, region_scope: ?string, product_key: ?string}  $scope
     * @return list<string> the columns that were filled
     */
    public static function fillGaps(int $tenantId, int $opportunityId, array $scope): array
    {
        $filled = [];
        foreach (['customer_model', 'region_scope', 'product_key'] as $column) {
            if ($scope[$column] === null || ! ColumnExists::has('g2g_company_opportunities', $column)) {
                continue;
            }
            $n = CompanyOpportunity::where('sub_institute_id', $tenantId)->whereKey($opportunityId)->whereNull($column)->update([$column => $scope[$column]]);
            if ($n === 1) {
                $filled[] = $column;
            }
        }

        return $filled;
    }

    private static function text(mixed $v): ?string
    {
        if (! is_string($v)) {
            return null;
        }
        $t = trim($v);

        return $t === '' || mb_strlen($t) > 80 ? null : $t;
    }

    /** @return list<string> ISO codes of the countries whose catalogue has a state with this name (read once per process) */
    private static function countriesWithState(string $state): array
    {
        if (self::$stateIndex === null) {
            $catalog = LocationCatalog::shared();
            $index = [];
            foreach ($catalog->countries() as $country) {
                if (! $country['has_states']) {
                    continue;
                }
                foreach ($catalog->states($country['code']) as $s) {
                    $index[LocationCatalog::fold($s['name'])][$country['code']] = $country['code'];
                }
            }
            self::$stateIndex = array_map('array_values', $index);
        }

        return self::$stateIndex[LocationCatalog::fold($state)] ?? [];
    }
}
