<?php

namespace App\Domain\Signals\Scholar;

/**
 * Finding a school by NAME when it has no verified website. Pure: builds searches and classifies results; it fetches nothing and verifies nothing.
 *
 * A website is not a precondition for finding an opportunity. Without one, the school is searched by name and place (and its trust or district
 * when those are on file), through the same approved search provider as every other search. What comes back is evidence about that school only if
 * the existing identity checks say so: a result is never attached to a school here.
 *
 * A result host that looks like the school's own domain is a CANDIDATE official site. It stays unverified until the separate site-verification
 * step (OfficialSiteDiscovery) says otherwise; this class only keeps it apart from pages that merely mention the school.
 */
final class SchoolNameFallback
{
    public const CANDIDATE_OFFICIAL = 'candidate_official_site';
    public const THIRD_PARTY = 'third_party';
    public const AMBIGUOUS = 'ambiguous';

    /** Hosts that never are a school's own site, whatever their path says. */
    private const NOT_OFFICIAL = ['linkedin.com', 'facebook.com', 'instagram.com', 'youtube.com', 'x.com', 'twitter.com', 'justdial.com', 'schoolmykids.com',
        'careers360.com', 'shiksha.com', 'edustoke.com', 'wikipedia.org', 'naukri.com', 'indeed.com', 'glassdoor.co.in', 'timesofindia.indiatimes.com'];

    /**
     * Searches for one school: its name with its place, then the same with its trust or district when known, then each alias. Fewer than `$max` are
     * returned when fewer distinct searches exist; an account with no name gives none.
     *
     * @param  array{id?: int, name?: string, city?: ?string, state?: ?string, attributes?: mixed}  $account
     * @return list<array<string, mixed>>
     */
    public static function queries(array $account, int $days = 60, int $max = 2): array
    {
        $name = self::clean((string) ($account['name'] ?? ''));
        if ($name === '' || $max <= 0) {
            return [];
        }
        $attrs = is_array($account['attributes'] ?? null) ? $account['attributes'] : [];
        $city = self::clean((string) ($account['city'] ?? ''));
        $district = self::clean((string) ($attrs['district'] ?? ''));
        $trust = self::clean((string) ($attrs['trust'] ?? $attrs['trust_name'] ?? ''));
        $place = $city !== '' ? $city : ($district !== '' ? $district : self::clean((string) ($account['state'] ?? '')));

        $variants = [trim($name . ' ' . $place . ' school')];
        if ($trust !== '' || ($district !== '' && $district !== $city)) {
            $variants[] = trim($name . ' ' . ($trust !== '' ? $trust . ' ' : '') . ($district !== '' ? $district : $place));
        }
        foreach ((array) ($attrs['aliases'] ?? []) as $alias) {
            $alias = self::clean((string) $alias);
            if ($alias !== '' && mb_strtolower($alias) !== mb_strtolower($name)) {
                $variants[] = trim($alias . ' ' . $place);
            }
        }

        $out = [];
        $seen = [];
        foreach ($variants as $v) {
            $v = preg_replace('/\s+/', ' ', $v) ?? $v;
            $key = mb_strtolower($v);
            if ($v === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = ['q' => $v, 'topic' => 'general', 'days' => $days, 'source' => 'account_name', 'id' => 'account-name-' . (int) ($account['id'] ?? 0) . '-' . count($out),
                'account_id' => (int) ($account['id'] ?? 0)];
            if (count($out) >= $max) {
                break;
            }
        }

        return $out;
    }

    /**
     * Is this result probably the school's own site? Judged from the host and the school's name words only.
     *   candidate_official_site  the host carries the school's distinctive name words and is not a social, directory or job site
     *   third_party              a known social, directory or job host, or a host that shares none of the school's words
     *   ambiguous                the host shares only some of the school's words: kept for a person, never assigned
     *
     * @return array{class: string, host: string, verified: false}
     */
    public static function classify(string $url, string $schoolName): array
    {
        $host = preg_replace('/^www\./', '', mb_strtolower((string) parse_url($url, PHP_URL_HOST)));
        $base = ['host' => $host, 'verified' => false];
        if ($host === '') {
            return ['class' => self::THIRD_PARTY] + $base;
        }
        foreach (self::NOT_OFFICIAL as $bad) {
            if ($host === $bad || str_ends_with($host, '.' . $bad)) {
                return ['class' => self::THIRD_PARTY] + $base;
            }
        }
        $words = IdentityResolver::tokens($schoolName);
        if ($words === []) {
            return ['class' => self::AMBIGUOUS] + $base;
        }
        $flat = preg_replace('/[^a-z0-9]/', '', explode('.', $host)[0]) ?? '';
        $hit = array_filter($words, fn ($w) => mb_strlen($w) >= 3 && str_contains($flat, preg_replace('/[^a-z0-9]/', '', $w) ?? $w));
        if (count($hit) === count($words)) {
            return ['class' => self::CANDIDATE_OFFICIAL] + $base;
        }

        return ['class' => $hit === [] ? self::THIRD_PARTY : self::AMBIGUOUS] + $base;
    }

    /**
     * Does the school have any place to search with (city, district or state)? A name-only query for a common school name is ambiguous, so a
     * school without one is not name-searched while signals.icp.name_search_require_place is on, and the run counts it as `missing_context`.
     * No place is ever invented.
     */
    public static function hasPlace(array $account): bool
    {
        $attrs = is_array($account['attributes'] ?? null) ? $account['attributes'] : [];

        return self::clean((string) ($account['city'] ?? '')) !== '' || self::clean((string) ($attrs['district'] ?? '')) !== '' || self::clean((string) ($account['state'] ?? '')) !== '';
    }

    /** Which fields this school lacks for a better search, so the gap is reported and nothing is invented. @return list<string> */
    public static function missingFields(array $account): array
    {
        $attrs = is_array($account['attributes'] ?? null) ? $account['attributes'] : [];
        $missing = [];
        if (self::clean((string) ($account['name'] ?? '')) === '') {
            $missing[] = 'name';
        }
        if (self::clean((string) ($account['city'] ?? '')) === '' && self::clean((string) ($attrs['district'] ?? '')) === '') {
            $missing[] = 'city_or_district';
        }

        return $missing;
    }

    private static function clean(string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', str_replace('"', '', $s)) ?? $s);
    }
}
