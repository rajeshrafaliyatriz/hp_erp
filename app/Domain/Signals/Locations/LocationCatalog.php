<?php

namespace App\Domain\Signals\Locations;

use Illuminate\Support\Str;

/**
 * Countries, states / provinces and cities, and which belongs to which.
 *
 * The data is a compact extract of the Countries States Cities Database (ODbL-1.0; see resources/data/locations/NOTICE.md), read from local files:
 * no external service is called at run time, and a country's cities are only read when that country is asked about.
 *
 *   countries.json        [{code, iso3, name, emoji, has_states}]
 *   states/<CC>.json      [{code, name, type}]
 *   cities/<CC>.json      {"<state code>": [{id, name}], "": [country-level cities]}
 *
 * resolve() is what makes a Region trustworthy: a state must belong to its country and a city to its state, by the database's own codes and
 * ids where they are given and by name otherwise. Nothing is invented and nothing is defaulted: a place this catalogue does not know stays
 * exactly as typed and is reported as not verified (never turned into India). Places that people added themselves (g2g_locations) and the
 * short seed list the application shipped with before this catalogue existed are still accepted, marked as such.
 *
 * No framework is needed to read the files, so this can be used in plain unit tests; the custom-places lookup quietly does nothing without a database.
 */
final class LocationCatalog
{
    /** Names other lists use for a country, to its ISO code. Only names that are the same country, never a guess. */
    private const ALIASES = [
        'usa' => 'US', 'u.s.' => 'US', 'u.s.a.' => 'US', 'united states of america' => 'US', 'uk' => 'GB', 'u.k.' => 'GB', 'great britain' => 'GB',
        'uae' => 'AE', 'czechia' => 'CZ', 'turkiye' => 'TR', 'myanmar (burma)' => 'MM', 'burma' => 'MM', 'cote d\'ivoire' => 'CI',
        'ivory coast' => 'CI', 'south korea' => 'KR', 'republic of korea' => 'KR', 'north korea' => 'KP', 'russian federation' => 'RU',
        'vietnam' => 'VN', 'viet nam' => 'VN', 'laos' => 'LA', 'syria' => 'SY', 'iran' => 'IR', 'bolivia' => 'BO', 'venezuela' => 'VE',
        'tanzania' => 'TZ', 'moldova' => 'MD', 'brunei' => 'BN', 'cape verde' => 'CV', 'cabo verde' => 'CV', 'eswatini' => 'SZ', 'swaziland' => 'SZ',
        'timor-leste' => 'TL', 'east timor' => 'TL', 'hong kong sar china' => 'HK', 'macao sar china' => 'MO', 'palestinian territories' => 'PS',
        'congo - kinshasa' => 'CD', 'congo - brazzaville' => 'CG', 'north macedonia' => 'MK', 'macedonia' => 'MK',
    ];

    private static ?self $shared = null;

    /** @var list<array{code: string, iso3: string, name: string, emoji: ?string, has_states: bool}>|null */
    private ?array $countries = null;
    /** @var array<string, array<string, mixed>> */
    private array $byCode = [];
    /** @var array<string, string> folded name or iso3 => code */
    private array $byName = [];
    /** @var array<string, list<array{code: string, name: string, type: ?string}>> */
    private array $states = [];
    /** @var array<string, array<string, list<array{id: int, name: string}>>> */
    private array $cities = [];

    public function __construct(private readonly ?string $dir = null)
    {
    }

    /** One catalogue per process, so the files are read once. */
    public static function shared(): self
    {
        return self::$shared ??= new self();
    }

    /** The data's own version and counts, from meta.json. @return array<string, mixed> */
    public function meta(): array
    {
        return $this->json('meta.json') ?? [];
    }

    // ── lookups ──────────────────────────────────────────────────────────────

    /** @return list<array{code: string, iso3: string, name: string, emoji: ?string, has_states: bool}> */
    public function countries(): array
    {
        $this->loadCountries();

        return $this->countries;
    }

    /** By ISO code, ISO alpha-3 code, or name (case, accents and spacing ignored). @return array{code: string, iso3: string, name: string, emoji: ?string, has_states: bool}|null */
    public function country(string $codeOrName): ?array
    {
        $this->loadCountries();
        $key = self::fold($codeOrName);
        if ($key === '') {
            return null;
        }
        $code = $this->byName[$key] ?? self::ALIASES[$key] ?? null;

        return $code !== null ? ($this->byCode[$code] ?? null) : null;
    }

    /** @return list<array{code: string, name: string, type: ?string}> */
    public function states(string $countryCode): array
    {
        $code = strtoupper($countryCode);
        if (! preg_match('/^[A-Z]{2}$/', $code) || ! isset($this->byCodeOrLoad()[$code])) {
            return [];
        }

        return $this->states[$code] ??= ($this->json("states/{$code}.json") ?? []);
    }

    /** @return array{code: string, name: string, type: ?string}|null */
    public function state(string $countryCode, string $codeOrName): ?array
    {
        $key = self::fold($codeOrName);
        foreach ($this->states($countryCode) as $s) {
            if (self::fold($s['code']) === $key || self::fold($s['name']) === $key) {
                return $s;
            }
        }

        return null;
    }

    /**
     * Cities of one state (or, with a null state, the ones listed directly under the country). Searching is server-side and bounded, so the browser
     * never receives a whole country.
     *
     * @return array{items: list<array{id: int, name: string}>, total: int, truncated: bool}
     */
    public function cities(string $countryCode, ?string $stateCode, ?string $query = null, int $limit = 50): array
    {
        $all = $this->cityMap($countryCode)[$stateCode ?? ''] ?? [];
        $needle = self::fold((string) $query);
        if ($needle !== '') {
            $starts = [];
            $contains = [];
            foreach ($all as $c) {
                $name = self::fold($c['name']);
                if (str_starts_with($name, $needle)) {
                    $starts[] = $c;
                } elseif (str_contains($name, $needle)) {
                    $contains[] = $c;
                }
            }
            $all = array_merge($starts, $contains);   // names that begin with what was typed come first
        }
        $limit = max(1, min(500, $limit));

        return ['items' => array_slice($all, 0, $limit), 'total' => count($all), 'truncated' => count($all) > $limit];
    }

    /** @return array{id: int, name: string}|null */
    public function city(string $countryCode, ?string $stateCode, string|int $nameOrId): ?array
    {
        $list = $this->cityMap($countryCode)[$stateCode ?? ''] ?? [];
        if (is_int($nameOrId) || (is_string($nameOrId) && ctype_digit($nameOrId))) {
            foreach ($list as $c) {
                if ($c['id'] === (int) $nameOrId) {
                    return $c;
                }
            }
        }
        $key = self::fold((string) $nameOrId);
        foreach ($list as $c) {
            if (self::fold($c['name']) === $key) {
                return $c;
            }
        }

        return null;
    }

    /** Does this country have any city data at all (under a state or directly)? */
    public function hasCities(string $countryCode): bool
    {
        return $this->cityMap($countryCode) !== [];
    }

    // ── validation ───────────────────────────────────────────────────────────

    /**
     * One place, checked and completed. Input keys: country, state, city (names) and/or country_code, state_code, city_id (the catalogue's own codes).
     *
     * @param  array<string, mixed>  $in
     * @return array{selection: array<string, mixed>, problems: list<string>}
     *   selection = country, state, city (canonical names, null when not chosen), country_code, state_code, city_id (null when unknown),
     *   verified (true when every chosen level is in the catalogue), source (dataset | custom | legacy | unverified)
     */
    public function resolve(array $in): array
    {
        $name = fn (string $k): ?string => isset($in[$k]) && is_string($in[$k]) && trim($in[$k]) !== '' ? trim($in[$k]) : null;
        $countryName = $name('country');
        $stateName = $name('state');
        $cityName = $name('city');
        $countryCode = $name('country_code');
        $stateCode = $name('state_code');
        $cityId = isset($in['city_id']) && (is_int($in['city_id']) || (is_string($in['city_id']) && ctype_digit($in['city_id']))) ? (int) $in['city_id'] : null;

        $problems = [];
        $out = ['country' => $countryName, 'state' => $stateName, 'city' => $cityName, 'country_code' => null, 'state_code' => null, 'city_id' => null, 'verified' => false, 'source' => 'unverified'];

        // 1. country
        $country = null;
        if ($countryCode !== null) {
            $country = preg_match('/^[A-Za-z]{2}$/', $countryCode) === 1 ? $this->country($countryCode) : null;
            if ($country === null) {
                return ['selection' => $out, 'problems' => ["country_code: '{$countryCode}' is not a country code in the location data."]];
            }
            if ($countryName !== null && ($byName = $this->country($countryName)) !== null && $byName['code'] !== $country['code']) {
                return ['selection' => $out, 'problems' => ["country: '{$countryName}' is not the country with code {$country['code']} ({$country['name']})."]];
            }
        } elseif ($countryName !== null) {
            $country = $this->country($countryName);
        }
        if ($country === null) {
            // A place this catalogue does not know (an older saved region, or one a person added). Kept as typed, never defaulted, and not verified.
            return ['selection' => $out, 'problems' => []];
        }
        $out['country'] = $country['name'];
        $out['country_code'] = $country['code'];
        $source = 'dataset';

        // 2. state / province: free text. A name the data knows for this country is completed with its code; anything else stays exactly as typed.
        $source = 'dataset';
        $state = null;
        if ($stateCode !== null) {
            $state = $this->state($country['code'], $stateCode);
            if ($state === null) {
                return ['selection' => $out, 'problems' => ["state_code: '{$stateCode}' is not a state code of {$country['name']}."]];
            }
        } elseif ($stateName !== null) {
            $state = $this->state($country['code'], $stateName);
        }
        if ($state !== null) {
            $out['state'] = $state['name'];
            $out['state_code'] = $state['code'];
        } elseif ($stateName !== null) {
            $out['state'] = $stateName;
            $source = 'manual';
        } else {
            $out['state'] = null;
        }

        // 3. city: free text too. Matched against the chosen state's cities only when that state is one the data knows.
        $city = null;
        if ($cityId !== null) {
            $city = $out['state_code'] !== null || ! $country['has_states'] ? $this->city($country['code'], $out['state_code'], $cityId) : null;
            if ($city === null) {
                return ['selection' => $out, 'problems' => ["city_id: {$cityId} is not a city of the chosen place."]];
            }
        } elseif ($cityName !== null && ($out['state_code'] !== null || ! $country['has_states'])) {
            $city = $this->city($country['code'], $out['state_code'], $cityName);
        }
        if ($city !== null) {
            $out['city'] = $city['name'];
            $out['city_id'] = $city['id'];
        } elseif ($cityName !== null) {
            $out['city'] = $cityName;
            $source = 'manual';
        } else {
            $out['city'] = null;
        }

        $out['source'] = $source;
        $out['verified'] = $source === 'dataset';

        return ['selection' => $out, 'problems' => []];
    }

    // ── files ────────────────────────────────────────────────────────────────

    private function loadCountries(): void
    {
        if ($this->countries !== null) {
            return;
        }
        $this->countries = $this->json('countries.json') ?? [];
        foreach ($this->countries as $c) {
            $this->byCode[$c['code']] = $c;
            $this->byName[self::fold($c['code'])] = $c['code'];
            if (($c['iso3'] ?? '') !== '') {
                $this->byName[self::fold($c['iso3'])] ??= $c['code'];
            }
        }
        foreach ($this->countries as $c) {
            $this->byName[self::fold($c['name'])] = $c['code'];   // a name wins over another country's alpha-3 look-alike
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function byCodeOrLoad(): array
    {
        $this->loadCountries();

        return $this->byCode;
    }

    /** @return array<string, list<array{id: int, name: string}>> */
    private function cityMap(string $countryCode): array
    {
        $code = strtoupper($countryCode);
        if (! preg_match('/^[A-Z]{2}$/', $code) || ! isset($this->byCodeOrLoad()[$code])) {
            return [];
        }

        return $this->cities[$code] ??= ($this->json("cities/{$code}.json") ?? []);
    }

    /** @return array<mixed>|null */
    private function json(string $relative): ?array
    {
        $file = ($this->dir ?? dirname(__DIR__, 4) . '/resources/data/locations') . '/' . $relative;
        if (! is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? $data : null;
    }

    /** Case, accents, punctuation spacing and repeated spaces ignored. */
    public static function fold(string $value): string
    {
        $v = mb_strtolower(Str::ascii(trim($value)));

        return trim((string) preg_replace('/\s+/', ' ', $v));
    }
}
