<?php

namespace App\Domain\Signals\Support;

/**
 * The link a person gives as the source of an opportunity they add by hand. It can be from ANY website or platform (LinkedIn, Facebook, Instagram,
 * X, YouTube, a company site, a news article, a job portal...): the only requirements are that it is a real absolute http(s) address, and that it
 * cannot carry anything unsafe. It is stored as evidence the person supplied and is NEVER fetched, scraped or opened by the server, so no
 * address is "too private to reach": the checks are about meaning and safety of what gets stored and shown, not about network access.
 *
 * Pure: no network, no database.
 */
final class SourceUrl
{
    /** The column holds 1000 characters. */
    public const MAX_LENGTH = 1000;

    /** Query parameters that only say HOW a link was shared, on any site. */
    private const TRACKING = ['fbclid', 'gclid', 'dclid', 'msclkid', 'igshid', 'igsh', 'rcm', 'lipi', 'trk', 'trkinfo', 'mc_cid', 'mc_eid', 'mkt_tok', '_ga', 'ref_src', 'ref_url'];

    /**
     * More parameters that are share-tracking ON THESE SITES ONLY. The same names can identify the page elsewhere (a forum's ?t= is the thread, a
     * repository's ?ref= is the branch), so they are never dropped globally. Keyed by the host after www./m. is removed.
     */
    private const TRACKING_ON = [
        'youtube.com' => ['si', 'feature', 'pp', 't', 'start', 'ab_channel'],
        'x.com' => ['s', 't'],
        'facebook.com' => ['mibextid', 'ref', '__tn__', '__cft__', 'sfnsn', 'rdid', 'share_url'],
        'linkedin.com' => ['originalsubdomain', 'miniprofileurn'],
    ];

    /** Hostname => the name people know it by. Anything else is shown as its own hostname. */
    private const PLATFORMS = [
        'linkedin.com' => 'LinkedIn', 'lnkd.in' => 'LinkedIn',
        'facebook.com' => 'Facebook', 'fb.com' => 'Facebook', 'fb.watch' => 'Facebook', 'fb.me' => 'Facebook',
        'instagram.com' => 'Instagram', 'instagr.am' => 'Instagram',
        'x.com' => 'X (Twitter)', 'twitter.com' => 'X (Twitter)', 't.co' => 'X (Twitter)',
        'youtube.com' => 'YouTube', 'youtu.be' => 'YouTube',
    ];

    /**
     * @return array{ok: true, url: string}|array{ok: false, error: string}
     */
    public static function normalize(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return self::no('Add the link to the post or page it came from.');
        }
        if (preg_match('/[\x00-\x20\x7F]/', $raw) === 1) {
            return self::no('The link cannot contain spaces. Copy the full address from the address bar.');
        }
        $p = parse_url($raw);
        if ($p === false || ! isset($p['scheme'])) {
            return self::no('Enter a full web link starting with https://');
        }
        $scheme = strtolower((string) $p['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return self::no('Only http:// and https:// links are accepted.');
        }
        if (! isset($p['host']) || $p['host'] === '') {
            return self::no('Enter a full web link starting with https://');
        }
        if (isset($p['user']) || isset($p['pass'])) {
            return self::no('The link cannot contain a username or password.');
        }

        $host = strtolower(rtrim((string) $p['host'], '.'));
        if (str_starts_with($host, '[') || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::no('Use the website address (a name such as example.com), not a numeric IP address.');
        }
        if (preg_match('/[^\x00-\x7F]/', $host) === 1) {
            if (! function_exists('idn_to_ascii')) {
                return self::no('Use the ASCII form of the web address (for example the one starting xn--).');
            }
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (! is_string($ascii) || $ascii === '') {
                return self::no('That web address is not valid.');
            }
            $host = $ascii;
        }
        if (! str_contains($host, '.') || preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/', $host) !== 1) {
            return self::no('Enter a full web link starting with https://, with a real website name.');
        }

        // Characters that are not valid in an address as typed (accented or non-Latin letters copied from a page, quotes, angle brackets) are
        // percent-encoded, the way a browser would send them, so what is stored is a valid address.
        $enc = fn (string $s) => preg_replace_callback('/[^\x21-\x7E]|["<>\\\\^`{|}]/', fn ($m) => rawurlencode($m[0]), $s);
        $url = $scheme . '://' . $host . (isset($p['port']) ? ':' . (int) $p['port'] : '') . $enc((string) ($p['path'] ?? ''))
            . (isset($p['query']) && $p['query'] !== '' ? '?' . $enc((string) $p['query']) : '');
        if (strlen($url) > self::MAX_LENGTH) {
            return self::no('That link is too long (over ' . self::MAX_LENGTH . ' characters). Use the short share link instead.');
        }

        return ['ok' => true, 'url' => $url];
    }

    /** The platform's name for a known site (LinkedIn, Facebook, Instagram, X (Twitter), YouTube), else the site's own hostname (without "www."). Null when it is not an address. */
    public static function platform(string $url): ?string
    {
        $host = self::host($url);
        if ($host === null) {
            return null;
        }
        foreach (self::PLATFORMS as $domain => $label) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return $label;
            }
        }

        return preg_replace('/^www\./', '', $host);
    }

    /**
     * What makes two links the SAME post or page, for telling a duplicate. The same content shared two ways is one source (tracking parameters,
     * www / m. / mobile hosts, a trailing slash, #fragments and the short forms of X and YouTube links do not make a new one), while two different
     * pages that differ only by a query value (youtube.com/watch?v=A and ?v=B, a job portal's ?id=) are two. Paths keep their case: video and post
     * identifiers are case-sensitive.
     */
    public static function identityKey(string $url): string
    {
        $p = parse_url(trim($url));
        $host = strtolower((string) ($p['host'] ?? ''));
        $host = preg_replace('/^(www\.|m\.|mobile\.|amp\.|music\.)/', '', $host) ?? $host;
        $host = ['twitter.com' => 'x.com', 'fb.com' => 'facebook.com', 'youtu.be' => 'youtube.com'][$host] ?? $host;
        $path = '/' . trim((string) ($p['path'] ?? ''), '/');
        $path = (string) preg_replace('#/{2,}#', '/', $path);
        parse_str((string) ($p['query'] ?? ''), $params);
        // A short youtu.be/ID link is youtube.com/watch?v=ID.
        if (strtolower((string) ($p['host'] ?? '')) === 'youtu.be' && $path !== '/') {
            $params = ['v' => ltrim($path, '/')] + $params;
            $path = '/watch';
        }
        $kept = [];
        foreach ($params as $k => $v) {
            $key = strtolower((string) $k);
            if (is_array($v) || str_starts_with($key, 'utm_') || in_array($key, self::TRACKING, true) || in_array($key, self::TRACKING_ON[$host] ?? [], true)) {
                continue;
            }
            $kept[(string) $k] = (string) $v;
        }
        ksort($kept);

        return $host . ($path === '/' ? '' : $path) . ($kept === [] ? '' : '?' . http_build_query($kept));
    }

    private static function host(string $url): ?string
    {
        $host = parse_url(trim($url), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower(rtrim($host, '.')) : null;
    }

    /** @return array{ok: false, error: string} */
    private static function no(string $error): array
    {
        return ['ok' => false, 'error' => $error];
    }
}
