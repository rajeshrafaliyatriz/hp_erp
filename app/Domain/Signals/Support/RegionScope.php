<?php

namespace App\Domain\Signals\Support;

/**
 * The geography one Generate Signals request is limited to: up to five places, each a country, a country + state/province, or a country + state +
 * city. Request-scoped: it never overrides who is asking (organisation, product, permissions).
 *
 * Pure (no database, no network). Two jobs:
 *  - turn what a client sent into a validated, normalised scope (or null = "All Regions", the existing behaviour), refusing anything inconsistent;
 *  - say, for one candidate and the text of the source it was read from, whether that source ITSELF names the requested place.
 *
 * Location is never inferred from a website's country domain, a news outlet's home city or the model's guess: only text that is in the source counts.
 */
final class RegionScope
{
    public const MAX_SELECTIONS = 5;
    public const MAX_NAME = 80;

    /**
     * @param list<array{country: string, state: ?string, city: ?string}> $selections  the places by canonical name: this shape is what runs and opportunities
     *                                                                                   store and are matched by, so it never gains fields
     * @param list<array<string, mixed>> $structured  the same places with the location catalogue's codes (country_code, state_code, city_id, verified, source)
     */
    private function __construct(private readonly array $selections, private readonly array $structured = [])
    {
    }

    /**
     * @param  mixed  $input  null / [] / 'all' = All Regions (returns null); otherwise a list of {country, state?, city?}
     *
     * @throws RegionInvalid with a message per problem
     */
    public static function fromInput(mixed $input): ?self
    {
        if ($input === null || $input === [] || $input === '' || $input === 'all') {
            return null;
        }
        if (! is_array($input) || ! array_is_list($input)) {
            throw new RegionInvalid(['regions: send a list of places, or nothing for All Regions']);
        }
        if (count($input) > self::MAX_SELECTIONS) {
            throw new RegionInvalid(['regions: choose at most ' . self::MAX_SELECTIONS . ' places']);
        }

        $problems = [];
        $out = [];
        $structured = [];
        $seen = [];
        $catalog = \App\Domain\Signals\Locations\LocationCatalog::shared();
        foreach ($input as $i => $item) {
            $n = $i + 1;
            if (! is_array($item)) {
                $problems[] = "region {$n}: must be an object with a country";

                continue;
            }
            // The location catalogue's own codes, when the client sent them (a place picked from the Country > State > City lists).
            $code = fn (string $k, string $pattern): ?string => isset($item[$k]) && is_string($item[$k]) && preg_match($pattern, trim($item[$k])) === 1 ? trim($item[$k]) : null;
            $countryCode = $code('country_code', '/^[A-Za-z]{2}$/');
            $country = self::clean($item['country'] ?? null, "region {$n} country", $problems, $countryCode === null);
            $state = self::clean($item['state'] ?? null, "region {$n} state/province", $problems, false);
            $city = self::clean($item['city'] ?? null, "region {$n} city", $problems, false);
            if ($country === null && $countryCode === null) {
                continue;
            }
            // State and city are free text and optional: a city may be given without a state.
            $cityId = isset($item['city_id']) && (is_int($item['city_id']) || (is_string($item['city_id']) && ctype_digit($item['city_id']))) ? (int) $item['city_id'] : null;
            $resolved = $catalog->resolve([
                'country' => $country, 'state' => $state, 'city' => $city,
                'country_code' => $countryCode, 'state_code' => $code('state_code', '/^[A-Za-z0-9._-]{1,10}$/'), 'city_id' => $cityId,
            ]);
            if ($resolved['problems'] !== []) {
                foreach ($resolved['problems'] as $p) {
                    $problems[] = "region {$n}: {$p}";
                }

                continue;
            }
            $sel = $resolved['selection'];
            $country = (string) $sel['country'];
            $state = $sel['state'];
            $city = $sel['city'];
            $key = mb_strtolower("{$country}|{$state}|{$city}");
            if (isset($seen[$key])) {
                continue;   // the same place twice is one place
            }
            $seen[$key] = true;
            $out[] = ['country' => $country, 'state' => $state, 'city' => $city];
            $structured[] = $sel;
        }
        if ($problems !== []) {
            throw new RegionInvalid($problems);
        }

        return $out === [] ? null : new self($out, $structured);
    }

    /** @param list<string> $problems */
    private static function clean(mixed $v, string $what, array &$problems, bool $required): ?string
    {
        if ($v === null || (is_string($v) && trim($v) === '')) {
            if ($required) {
                $problems[] = "{$what}: required";
            }

            return null;
        }
        if (! is_string($v)) {
            $problems[] = "{$what}: must be text";

            return null;
        }
        $v = trim((string) preg_replace('/\s+/u', ' ', $v));
        if (mb_strlen($v) < 2 || mb_strlen($v) > self::MAX_NAME || preg_match('/^[\p{L}\p{M}0-9][\p{L}\p{M}0-9 .,\'’()\-]*$/u', $v) !== 1) {
            $problems[] = "{$what}: 2 to " . self::MAX_NAME . ' characters, letters, digits, spaces and . , \' ( ) - only';

            return null;
        }

        return $v;
    }

    /** @return list<array{country: string, state: ?string, city: ?string}> */
    public function selections(): array
    {
        return $this->selections;
    }

    /**
     * The same places with the location catalogue's codes: country_code, state_code, city_id (null where the catalogue has none), verified, and source
     * (dataset | custom | legacy | unverified). A place the catalogue does not know is kept as typed with null codes: never converted to another country.
     *
     * @return list<array<string, mixed>>
     */
    public function structured(): array
    {
        return $this->structured !== [] ? $this->structured : array_map(fn ($s) => $s + ['country_code' => null, 'state_code' => null, 'city_id' => null, 'verified' => false, 'source' => 'unverified'], $this->selections);
    }

    /** "Surat, Gujarat, India; Kerala, India" */
    /**
     * The one country every selected place is in (lower case), or null when the places span several countries (or none is named). This is what a
     * search provider's country bias should follow: biasing to one country when the person asked for another country's places finds nothing.
     */
    public function singleCountry(): ?string
    {
        $countries = array_values(array_unique(array_map(fn ($s) => mb_strtolower(trim((string) ($s['country'] ?? ''))), $this->selections())));
        $countries = array_values(array_filter($countries, fn ($c) => $c !== ''));

        return count($countries) === 1 ? $countries[0] : null;
    }

    public function label(): string
    {
        return implode('; ', array_map(fn ($s) => implode(', ', array_filter([$s['city'], $s['state'], $s['country']])), $this->selections));
    }

    /** The most specific place of each selection: the city, else the state/province, else the country. @return list<string> */
    public function terms(): array
    {
        return array_values(array_unique(array_map(fn ($s) => $s['city'] ?? $s['state'] ?? $s['country'], $this->selections)));
    }

    /**
     * Does this request cover one of these places (a state / province, by name)? A state selection covers its own name; a city selection covers
     * its state. A country-only request covers none: it asks for the whole country, not for one state.
     *
     * @param  list<string>  $places
     */
    public function covers(array $places): bool
    {
        $wanted = array_map(fn ($p) => mb_strtolower(trim((string) $p)), $places);
        foreach ($this->selections as $s) {
            if ($s['state'] !== null && in_array(mb_strtolower($s['state']), $wanted, true)) {
                return true;
            }
        }

        return false;
    }

    /** @return 'country'|'state'|'city' */
    private static function level(array $s): string
    {
        return $s['city'] !== null ? 'city' : ($s['state'] !== null ? 'state' : 'country');
    }

    /**
     * A search query aimed at this scope: the ICP's own region words are taken out (they are somewhere else, or something wider) and the scope's
     * places are added. Never longer than a search provider accepts.
     *
     * @param  list<string>  $icpRegions  the ICP's saved regions (read only; never changed)
     */
    public function applyToQuery(string $q, array $icpRegions): string
    {
        foreach ($icpRegions as $r) {
            $r = trim((string) $r);
            if ($r !== '') {
                $q = (string) preg_replace('/(?<![\p{L}\p{N}])' . preg_quote($r, '/') . '(?![\p{L}\p{N}])/iu', ' ', $q);
            }
        }
        $q = (string) preg_replace(['/\(\s*(?:OR\s*)*\)/u', '/\(\s*OR\b/u', '/\bOR\s*\)/u', '/\bOR(?:\s+OR\b)+/u', '/^\s*OR\b/u', '/\bOR\s*$/u'], [' ', '(', ')', 'OR', ' ', ' '], $q);
        $q = trim((string) preg_replace('/\s+/u', ' ', $q));

        // A place the query already names is not added a second time.
        $terms = array_values(array_filter($this->terms(), fn ($t) => preg_match('/(?<![\p{L}\p{N}])' . preg_quote($t, '/') . '(?![\p{L}\p{N}])/iu', $q) !== 1));
        if ($terms === []) {
            return $q;
        }
        $quoted = array_map(fn ($t) => '"' . str_replace('"', '', $t) . '"', $terms);
        $where = count($quoted) === 1 ? $quoted[0] : '(' . implode(' OR ', $quoted) . ')';
        $max = 380 - mb_strlen($where) - 1;

        return trim(mb_substr($q, 0, max(20, $max)) . ' ' . $where);
    }

    /**
     * Does the SOURCE text itself name the requested place?
     *  - a city or state/province scope must be named in the source, or the candidate is outside the request;
     *  - a country scope that the source does not name is "unconfirmed" (kept, and said so): many local pages never write the country's name,
     *    and that is not evidence the organisation is elsewhere.
     *
     * @param  string  $sourceText  the text the supporting quote was found in (already case-normalised is fine)
     * @return array{status: 'confirmed'|'unconfirmed'|'outside', matched: ?string, level: ?string, basis: string}
     */
    public function evidence(?string $candidateLocation, string $sourceText): array
    {
        $text = ' ' . StructuredAi::normalise($sourceText) . ' ';
        $countryOnly = false;
        foreach ($this->selections as $s) {
            $term = $s['city'] ?? $s['state'] ?? $s['country'];
            $needle = StructuredAi::normalise($term);
            if ($needle !== '' && preg_match('/(?<![\p{L}\p{N}])' . preg_quote($needle, '/') . '(?![\p{L}\p{N}])/u', $text) === 1) {
                return ['status' => 'confirmed', 'matched' => $term, 'level' => self::level($s), 'basis' => 'source_text'];
            }
            $countryOnly = $countryOnly || self::level($s) === 'country';
        }

        return $countryOnly
            ? ['status' => 'unconfirmed', 'matched' => null, 'level' => 'country', 'basis' => 'not_stated_in_source']
            : ['status' => 'outside', 'matched' => null, 'level' => null, 'basis' => 'not_stated_in_source'];
    }
}
