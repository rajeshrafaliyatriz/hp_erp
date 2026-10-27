<?php

namespace App\Domain\Signals\Support;

/**
 * The geography one Generate Signals request is limited to: up to five places, each a country, a country + state/province, or a country + state +
 * city. Request-scoped: it is never saved to a product or an ICP, and it never overrides who is asking (organisation, product, permissions).
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

    /** @param list<array{country: string, state: ?string, city: ?string}> $selections */
    private function __construct(private readonly array $selections)
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
        $seen = [];
        foreach ($input as $i => $item) {
            $n = $i + 1;
            if (! is_array($item)) {
                $problems[] = "region {$n}: must be an object with a country";

                continue;
            }
            $country = self::clean($item['country'] ?? null, "region {$n} country", $problems, true);
            $state = self::clean($item['state'] ?? null, "region {$n} state/province", $problems, false);
            $city = self::clean($item['city'] ?? null, "region {$n} city", $problems, false);
            if ($country === null) {
                continue;
            }
            if ($city !== null && $state === null) {
                $problems[] = "region {$n}: a city needs its state/province (choose country, then state, then city)";

                continue;
            }
            $key = mb_strtolower("{$country}|{$state}|{$city}");
            if (isset($seen[$key])) {
                continue;   // the same place twice is one place
            }
            $seen[$key] = true;
            $out[] = ['country' => $country, 'state' => $state, 'city' => $city];
        }
        if ($problems !== []) {
            throw new RegionInvalid($problems);
        }

        return $out === [] ? null : new self($out);
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

    /** "Surat, Gujarat, India; Kerala, India" */
    public function label(): string
    {
        return implode('; ', array_map(fn ($s) => implode(', ', array_filter([$s['city'], $s['state'], $s['country']])), $this->selections));
    }

    /** The most specific place of each selection: the city, else the state/province, else the country. @return list<string> */
    public function terms(): array
    {
        return array_values(array_unique(array_map(fn ($s) => $s['city'] ?? $s['state'] ?? $s['country'], $this->selections)));
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

        $quoted = array_map(fn ($t) => '"' . str_replace('"', '', $t) . '"', $this->terms());
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
