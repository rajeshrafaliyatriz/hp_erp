<?php

namespace App\Domain\Signals\Market;

use App\Domain\Signals\Support\SourceUrl;

/**
 * Finds duplicate signals by COMPANY + NORMALISED SOURCE URL. Pure and read-only: it takes rows and returns groups, and changes nothing.
 *
 * One article can legitimately support several companies ("the 10 best BI firms in Pune"), so a shared URL alone is never a duplicate. Two
 * rows are duplicates only when the URL key matches AND the company is the same organisation, which also catches name variants
 * ("Cactus" / "Cactus Communications", "MAHAPREIT" / "Mahatma Phule ... Ltd (MAHAPREIT)").
 *
 * Nothing here merges or deletes. A cleanup, if ever approved, starts from these groups.
 */
final class SignalDeduplicator
{
    /** Words that do not tell two organisations apart. */
    private const NOISE = ['ltd', 'limited', 'pvt', 'private', 'llp', 'inc', 'corp', 'corporation', 'co', 'the', 'of', 'and', 'for', 'in', 'india'];

    /** The URL identity used for duplicate detection (tracking parameters, www/m hosts, trailing slash and fragment ignored). */
    public static function urlKey(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '' || parse_url($url, PHP_URL_HOST) === null) {
            return null;
        }

        return SourceUrl::identityKey($url);
    }

    /** @return list<string> the significant lower-case words of a name, parentheses removed */
    private static function tokens(string $name): array
    {
        $name = mb_strtolower(preg_replace('/\([^)]*\)/u', ' ', $name) ?? $name);
        $words = preg_split('/[^\p{L}\p{N}]+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter($words, fn ($w) => ! in_array($w, self::NOISE, true)));
    }

    /** @return list<string> words found inside parentheses (an acronym is often given there) */
    private static function bracketed(string $name): array
    {
        preg_match_all('/\(([^)]*)\)/u', $name, $m);
        $out = [];
        foreach ($m[1] ?? [] as $inner) {
            foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($inner), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) {
                $out[] = $w;
            }
        }

        return $out;
    }

    /** True when two names plausibly name the same organisation. Deliberately conservative: when unsure it says no. */
    public static function sameCompany(string $a, string $b): bool
    {
        $ta = self::tokens($a);
        $tb = self::tokens($b);
        if ($ta === [] || $tb === []) {
            return false;
        }
        if ($ta === $tb) {
            return true;
        }
        // Every word of the shorter name is in the longer one ("Cactus" within "Cactus Communications"), and the shorter is a real word, not a stub.
        [$short, $long] = count($ta) <= count($tb) ? [$ta, $tb] : [$tb, $ta];
        if (array_diff($short, $long) === [] && mb_strlen(implode('', $short)) >= 4) {
            return true;
        }
        // An acronym given in brackets names the other entity: "Mahatma Phule ... Ltd (MAHAPREIT)" and "MAHAPREIT (Maharashtra Power ...)".
        $ba = self::bracketed($a);
        $bb = self::bracketed($b);
        foreach ($ba as $w) {
            if (mb_strlen($w) >= 4 && in_array($w, $tb, true)) {
                return true;
            }
        }
        foreach ($bb as $w) {
            if (mb_strlen($w) >= 4 && in_array($w, $ta, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{id: int|string, tenant?: int|string|null, company: string, source_url?: string|null}>  $rows
     * @return array{duplicates: list<array{url_key: string, ids: list<int|string>}>, shared_url_different_company: list<array{url_key: string, ids: list<int|string>}>}
     *         duplicates: same URL and same organisation (a cleanup candidate). shared_url_different_company: one URL, several organisations (NOT duplicates).
     */
    public static function groups(array $rows): array
    {
        $byUrl = [];
        foreach ($rows as $r) {
            $key = self::urlKey($r['source_url'] ?? null);
            if ($key === null) {
                continue;
            }
            $byUrl[($r['tenant'] ?? '') . '|' . $key][] = $r;
        }

        $dups = [];
        $shared = [];
        foreach ($byUrl as $k => $members) {
            if (count($members) < 2) {
                continue;
            }
            $urlKey = substr($k, strpos($k, '|') + 1);
            // Cluster the members of this URL into organisations.
            $clusters = [];
            foreach ($members as $m) {
                $placed = false;
                foreach ($clusters as &$c) {
                    if (self::sameCompany($c[0]['company'], $m['company'])) {
                        $c[] = $m;
                        $placed = true;
                        break;
                    }
                }
                unset($c);
                if (! $placed) {
                    $clusters[] = [$m];
                }
            }
            foreach ($clusters as $c) {
                if (count($c) > 1) {
                    $dups[] = ['url_key' => $urlKey, 'ids' => array_map(fn ($x) => $x['id'], $c)];
                }
            }
            if (count($clusters) > 1) {
                $shared[] = ['url_key' => $urlKey, 'ids' => array_map(fn ($x) => $x['id'], $members)];
            }
        }

        return ['duplicates' => $dups, 'shared_url_different_company' => $shared];
    }
}
