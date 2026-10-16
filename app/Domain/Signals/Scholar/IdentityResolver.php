<?php

namespace App\Domain\Signals\Scholar;

/**
 * Is this organisation, found in a source, one of OUR target schools? Decided from the name, the place and the website together,
 * never from a few shared words. Pure.
 *
 *   target_exact     the same name AND the same place (or the same website). Safe to attach to the target account.
 *   target_possible  likely the same school but something is missing or only partly matches (the same name with no place stated, or a
 *                    name that contains the other's with the same place). A person must confirm; it is NEVER attached automatically.
 *   unrelated        everything else, including the same name in a DIFFERENT place (another branch of a group is another school)
 *                    and a name that merely shares a word ("Tapovan International School, Mahesana" vs "Tapovan Vidya Vihar, Ahmedabad").
 *
 * Generic words that most schools share ("school", "international", "public", "english", "academy" ...) carry no identity and are
 * ignored; words like "vidya", "vihar", "bharti" are NOT generic, because they separate one school from another.
 */
final class IdentityResolver
{
    public const EXACT = 'target_exact';
    public const POSSIBLE = 'target_possible';
    public const UNRELATED = 'unrelated';

    private const GENERIC = ['school', 'schools', 'international', 'public', 'high', 'english', 'medium', 'academy', 'the', 'of', 'and', 'sr', 'sec', 'secondary', 'senior', 'cbse', 'icse', 'campus', 'group', 'institute', 'institution', 'institutions', 'education', 'educational', 'trust', 'society', 'new', 'a'];
    private const PLACE_NOISE = ['gujarat', 'maharashtra', 'india', 'dadra', 'nagar', 'haveli', 'daman', 'diu', 'district', 'dist', 'city', 'and', 'the', 'of'];

    /**
     * @param  array{name: string, location?: ?string, website?: ?string}  $candidate
     * @param  iterable<array{id: int, name: string, city?: ?string, state?: ?string, website?: ?string, website_scope?: ?string, crm_ref?: ?string}>  $accounts
     * @return array{status: string, account_id: ?int, account_name: ?string, crm_ref: ?string, reasons: list<string>}
     */
    public static function resolve(array $candidate, iterable $accounts): array
    {
        $best = null;
        $bestRank = -1;
        $rank = [self::UNRELATED => 0, self::POSSIBLE => 1, self::EXACT => 2];

        foreach ($accounts as $a) {
            $r = self::compare($candidate, $a);
            if ($rank[$r['status']] > $bestRank) {
                $best = ['status' => $r['status'], 'account_id' => (int) $a['id'], 'account_name' => (string) $a['name'], 'crm_ref' => $a['crm_ref'] ?? null, 'reasons' => $r['reasons']];
                $bestRank = $rank[$r['status']];
            }
        }

        if ($best === null || $best['status'] === self::UNRELATED) {
            return ['status' => self::UNRELATED, 'account_id' => null, 'account_name' => null, 'crm_ref' => null,
                'reasons' => $best === null ? ['There are no target accounts to compare with.'] : ['Matches no target school by name, place and website.']];
        }

        return $best;
    }

    /** @return array{status: string, reasons: list<string>} */
    public static function compare(array $c, array $a): array
    {
        $cn = self::tokens((string) $c['name']);
        $an = self::tokens((string) $a['name']);
        if ($cn === [] || $an === []) {
            return ['status' => self::UNRELATED, 'reasons' => ['The name has no distinguishing words.']];
        }

        $sameName = implode('', $cn) === implode('', $an);
        $shared = array_values(array_intersect($cn, $an));
        $union = array_values(array_unique(array_merge($cn, $an)));
        $contains = $shared !== [] && (count($shared) === count($cn) || count($shared) === count($an));
        $jaccard = count($shared) / max(1, count($union));
        $related = $sameName || ($shared !== [] && ($contains || $jaccard >= 0.6));

        $cHost = self::host($c['website'] ?? null);
        $aHost = self::host($a['website'] ?? null);
        $sameSite = $cHost !== null && $aHost !== null && $cHost === $aHost;
        // A website that was only verified at GROUP level (the page names the organisation, not this branch) is shared by every branch of
        // the group, so it cannot identify one branch. Name and place must then agree as well.
        if ($sameSite && ($a['website_scope'] ?? 'campus') !== 'group') {
            return ['status' => self::EXACT, 'reasons' => ["Same website ({$cHost})."]];
        }

        $place = self::place((string) ($c['location'] ?? ''), (string) ($a['city'] ?? ''), (string) ($a['state'] ?? ''));

        if (! $related) {
            return ['status' => self::UNRELATED, 'reasons' => ['Different name.']];
        }
        if ($place === 'different') {
            return ['status' => self::UNRELATED, 'reasons' => [$sameName
                ? 'The same name in a different place: another school or branch.'
                : 'Part of the name matches but the place is different: a different school.']];
        }
        if ($sameName && $place === 'same') {
            return ['status' => self::EXACT, 'reasons' => ['Same name and same place.']];
        }
        if ($sameName) {
            return ['status' => self::POSSIBLE, 'reasons' => ['Same name, but the source states no place: confirm it is this school.']];
        }
        if ($place === 'same') {
            return ['status' => self::POSSIBLE, 'reasons' => ['The names overlap and the place is the same, but the names are not identical: confirm.']];
        }

        return ['status' => self::UNRELATED, 'reasons' => ['Only part of the name matches and the place is unknown: not enough to say it is this school.']];
    }

    /** @return list<string> the words that identify a school */
    public static function tokens(string $name): array
    {
        $name = mb_strtolower(preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $name) ?? $name);
        $out = [];
        foreach (preg_split('/\s+/u', trim($name)) ?: [] as $w) {
            if ($w !== '' && ! in_array($w, self::GENERIC, true)) {
                $out[] = $w;
            }
        }

        return $out;
    }

    /** same | different | unknown: do the candidate's stated location and the account's city/state refer to one place? */
    private static function place(string $location, string $city, string $state): string
    {
        $loc = self::placeWords($location);
        $acc = self::placeWords($city);
        if ($loc === [] || $acc === []) {
            return 'unknown';
        }
        // "Vasana, Ahmedabad" contains "ahmedabad"; "Panchmahal Godhra" contains "godhra". Compact forms catch "Gandhi Nagar" vs "Gandhinagar".
        if (array_intersect($loc, $acc) !== [] || str_contains(implode('', $loc), implode('', $acc)) || str_contains(implode('', $acc), implode('', $loc))) {
            return 'same';
        }

        return 'different';
    }

    /** @return list<string> */
    private static function placeWords(string $place): array
    {
        $place = mb_strtolower(preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $place) ?? $place);

        return array_values(array_filter(preg_split('/\s+/u', trim($place)) ?: [], fn ($w) => $w !== '' && ! in_array($w, self::PLACE_NOISE, true)));
    }

    private static function host(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }
        $h = parse_url(str_contains($url, '://') ? $url : 'https://' . $url, PHP_URL_HOST);

        return is_string($h) && $h !== '' ? preg_replace('/^www\./', '', strtolower($h)) : null;
    }
}
