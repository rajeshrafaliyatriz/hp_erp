<?php

namespace App\Domain\Signals\Market;

/**
 * Which sources are independent of each other, and what kind of source each is. Pure.
 *
 * Two sources are NOT independent when they share a registrable domain (news.example.com and www.example.com), or when they carry the same
 * text under different domains (a syndicated press release). Independence is what makes corroboration mean something.
 */
final class SourceIndependence
{
    private const SECOND_LEVEL = ['co', 'com', 'org', 'net', 'gov', 'edu', 'ac', 'nic', 'or', 'ne', 'ltd', 'plc', 'sch', 'res'];
    private const SOCIAL = ['instagram.com', 'facebook.com', 'x.com', 'twitter.com', 'reddit.com', 'quora.com', 'linkedin.com', 'youtube.com', 'youtu.be'];
    private const JOB_BOARDS = ['indeed.com', 'naukri.com', 'shine.com', 'timesjobs.com', 'glassdoor.com', 'shiksha.com'];

    /** Lower-case host without "www.", or '' when the URL has none. */
    public static function host(string $url): string
    {
        $h = parse_url(trim($url), PHP_URL_HOST);

        return is_string($h) ? preg_replace('/^www\./', '', strtolower($h)) : '';
    }

    /** example.com, example.co.in, example.gov.in: the part a site owner registers. */
    public static function registrable(string $host): string
    {
        $labels = array_values(array_filter(explode('.', strtolower($host)), 'strlen'));
        $n = count($labels);
        if ($n <= 2) {
            return implode('.', $labels);
        }
        $tld = $labels[$n - 1];
        if (strlen($tld) === 2 && in_array($labels[$n - 2], self::SECOND_LEVEL, true)) {
            return implode('.', array_slice($labels, -3));
        }

        return implode('.', array_slice($labels, -2));
    }

    /** social | job_board | government | pdf | web */
    public static function sourceType(string $url): string
    {
        $host = self::host($url);
        $reg = self::registrable($host);
        if (in_array($reg, self::SOCIAL, true)) {
            return 'social';
        }
        if (in_array($reg, self::JOB_BOARDS, true)) {
            return 'job_board';
        }
        if (preg_match('/(^|\.)(gov|nic)\.in$|\.gov$|^gem\.gov\.in$|\.edu$|\.ac\.in$|\.edu\.in$/', $host) === 1 || str_contains($host, 'eprocure') || str_contains($host, 'tender')) {
            return 'government';
        }
        if (preg_match('/\.pdf(\?|#|$)/i', (string) parse_url($url, PHP_URL_PATH)) === 1) {
            return 'pdf';
        }

        return 'web';
    }

    /**
     * Group items into independent sources. Each item needs url, and optionally title and text.
     *
     * @param  list<array{url: string, title?: ?string, text?: ?string}>  $items
     * @return list<int>  group number per item (same number = not independent of each other)
     */
    public static function groups(array $items): array
    {
        $byDomain = [];
        $byText = [];
        $out = [];
        $next = 0;
        foreach ($items as $i => $it) {
            $dom = self::registrable(self::host((string) ($it['url'] ?? '')));
            $fp = self::textFingerprint((string) ($it['title'] ?? ''), (string) ($it['text'] ?? ''));
            $g = null;
            if ($dom !== '' && isset($byDomain[$dom])) {
                $g = $byDomain[$dom];
            } elseif ($fp !== '' && isset($byText[$fp])) {
                $g = $byText[$fp];
            }
            if ($g === null) {
                $g = $next++;
            }
            if ($dom !== '') {
                $byDomain[$dom] ??= $g;
            }
            if ($fp !== '') {
                $byText[$fp] ??= $g;
            }
            $out[$i] = $g;
        }

        return array_values($out);
    }

    /** Number of independent sources among the items. */
    public static function count(array $items): int
    {
        return count(array_unique(self::groups($items)));
    }

    private static function textFingerprint(string $title, string $text): string
    {
        $t = mb_strtolower(trim($title) !== '' ? $title : $text);
        $t = trim(preg_replace('/[^a-z0-9]+/', ' ', $t) ?? '');

        return mb_strlen($t) >= 40 ? sha1(mb_substr($t, 0, 120)) : '';
    }
}
