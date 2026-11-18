<?php

namespace App\Domain\OrgAccounts;

/**
 * The one definition of "the same name" and "the same web domain" for Organisation 360 (slice 3, Docs/agentic-crm/slices/S03-organisation-360.md).
 *
 * Pure text functions, no database. Two organisations are the SAME on evidence only when these agree exactly; anything softer (token overlap) is only
 * ever a suggestion a person confirms.
 */
final class OrgNormalizer
{
    /** Words that say what kind of body it is, not which one. */
    private const STOP_WORDS = ['pvt', 'ltd', 'llp', 'inc', 'private', 'limited', 'the', 'of', 'and', 'school', 'schools'];

    /**
     * Hosts that belong to many organisations at once (social pages, free site builders, free mail). Two records that both list one of them
     * have shown nothing about being the same organisation, so a domain match on these is never evidence.
     */
    private const SHARED_HOSTS = [
        'facebook.com', 'fb.com', 'instagram.com', 'linkedin.com', 'twitter.com', 'x.com', 'youtube.com', 'youtu.be', 'wa.me', 'whatsapp.com',
        'google.com', 'sites.google.com', 'forms.gle', 'goo.gl', 'bit.ly', 'linktr.ee',
        'blogspot.com', 'wordpress.com', 'wixsite.com', 'weebly.com', 'business.site', 'godaddysites.com',
        'gmail.com', 'yahoo.com', 'yahoo.co.in', 'outlook.com', 'hotmail.com', 'rediffmail.com',
    ];

    /**
     * The distinguishing words of a name: lower-case, every run of characters that is not a letter, a digit or a combining mark becomes a
     * separator, the stop words go, duplicates go, the rest is sorted. Letters of every script stay (a Gujarati or Hindi name is a name), and
     * so do combining marks, because the vowel signs of those scripts are marks: dropping them would cut every word into pieces.
     *
     * @return list<string>
     */
    public static function tokens(?string $text): array
    {
        $text = trim((string) $text);
        if ($text === '') {
            return [];
        }

        $lower = mb_strtolower(mb_scrub($text), 'UTF-8');
        $words = preg_split('/[^\p{L}\p{N}\p{M}]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false) {
            return [];
        }

        $words = array_values(array_unique(array_diff($words, self::STOP_WORDS)));
        sort($words, SORT_STRING);

        return $words;
    }

    /** The comparable form of a name: its tokens joined by one space. '' means "no usable name" and is never a match. */
    public static function name(?string $name): string
    {
        return implode(' ', self::tokens($name));
    }

    /**
     * The host of a web address or bare domain: lower-case, no protocol, no login, no leading "www.", no port, path, query, fragment or trailing
     * dot. Null for empty input and for anything that is not a host name (no dot, odd characters), so "N/A" or "-" never becomes evidence.
     */
    public static function domain(?string $value): ?string
    {
        $v = mb_strtolower(trim(mb_scrub((string) $value)), 'UTF-8');
        if ($v === '') {
            return null;
        }

        $v = preg_replace('#^[a-z][a-z0-9+.\-]*://#', '', $v) ?? $v;
        $v = preg_replace('#^//#', '', $v) ?? $v;
        $v = preg_split('#[/?\#\\\\\s]#', $v, 2)[0];
        if (str_contains($v, '@')) {
            $v = substr($v, (int) strrpos($v, '@') + 1);
        }
        $v = preg_replace('/:\d*$/', '', $v) ?? $v;
        $v = rtrim($v, '.');
        if (str_starts_with($v, 'www.')) {
            $v = substr($v, 4);
        }

        if ($v === '' || preg_match('/^[\p{L}\p{N}\-]+(\.[\p{L}\p{N}\-]+)+$/u', $v) !== 1) {
            return null;
        }

        return $v;
    }

    /** True for a host that many unrelated organisations share (see SHARED_HOSTS); a subdomain of one counts too. */
    public static function isSharedHost(?string $domain): bool
    {
        if ($domain === null || $domain === '') {
            return false;
        }
        foreach (self::SHARED_HOSTS as $host) {
            if ($domain === $host || str_ends_with($domain, '.' . $host)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Jaccard overlap of two token lists: shared words over all distinct words. 0.0 when either list is empty, so a missing name resembles nothing.
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    public static function overlap(array $a, array $b): float
    {
        $a = array_unique($a);
        $b = array_unique($b);
        if ($a === [] || $b === []) {
            return 0.0;
        }

        $shared = count(array_intersect($a, $b));

        return $shared / (count($a) + count($b) - $shared);
    }
}
