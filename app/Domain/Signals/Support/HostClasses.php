<?php

namespace App\Domain\Signals\Support;

/**
 * Exact-domain / subdomain matching of a URL's host against a list of domains. Pure.
 *
 * "reddit.com" matches reddit.com, www.reddit.com and old.reddit.com, and does NOT match notreddit.com, reddit.com.evil.example or a URL that
 * only mentions reddit.com in its path or query. The host is parsed, never searched for as a substring.
 */
final class HostClasses
{
    /**
     * Hosts whose pages a plain page request cannot read (login walls, scripted feeds, robots refusals). Their search results and snippets are
     * still kept; only the page fetch is skipped. Overridable with config signals.fetch.skip_hosts.
     */
    public const UNREADABLE_SOCIAL = ['instagram.com', 'facebook.com', 'x.com', 'twitter.com', 'reddit.com', 'quora.com'];

    /** Lower-case host without a leading "www.", or '' when the URL has none. */
    public static function host(string $url): string
    {
        $host = parse_url(trim($url), PHP_URL_HOST);

        return is_string($host) ? preg_replace('/^www\./', '', rtrim(mb_strtolower($host), '.')) : '';
    }

    /** @param  list<string>  $domains */
    public static function inDomains(string $url, array $domains): bool
    {
        $host = self::host($url);
        if ($host === '') {
            return false;
        }
        foreach ($domains as $d) {
            $d = preg_replace('/^www\./', '', mb_strtolower(trim((string) $d)));
            if ($d !== '' && ($host === $d || str_ends_with($host, '.' . $d))) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<string>|null  $domains  null = the built-in list */
    public static function isUnreadableSocial(string $url, ?array $domains = null): bool
    {
        return self::inDomains($url, $domains ?? self::UNREADABLE_SOCIAL);
    }
}
