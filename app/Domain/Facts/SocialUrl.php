<?php

namespace App\Domain\Facts;

/**
 * Reads a profile address on X, GitHub or LinkedIn (slice 4, the contact facts ledger). PURE: it parses text and never fetches anything. D6 of the slice:
 * no code of this domain requests linkedin.com (FactsNoNetworkTest scans for it); the address is a value to store and show, never a page to read.
 *
 * Ported from Comp AI's CRM research agent (MIT licence, copyright 2026 Comp AI: apps/agent/agent/lib/socials.ts `parseSocialUrl` and `extractSocialUrls`,
 * apps/agent/agent/lib/people.ts `slugFromProfileUrl`). What is left out is everything that talks to the network: the GitHub account read, the X check
 * through a search service, the candidate search. What differs on purpose:
 *   - LinkedIn IS accepted here (the original's parser refuses it because its LinkedIn handling lives elsewhere): a person's `/in/{slug}`, and a
 *     `/company/{slug}` or `/school/{slug}` for an organisation. A deeper path on a LinkedIn address (`/in/paula/details/experience`) is cut back to the
 *     profile, because the slug alone names it; on X and GitHub a deep link is still refused (that is where wrong handles come from).
 *   - The result says `kind`: `person` or `organization` for LinkedIn, `person` for the others.
 */
final class SocialUrl
{
    private const X_HOSTS = ['x.com', 'www.x.com', 'twitter.com', 'www.twitter.com', 'mobile.twitter.com'];

    private const GITHUB_HOSTS = ['github.com', 'www.github.com'];

    private const X_RESERVED = ['i', 'home', 'explore', 'search', 'settings', 'notifications', 'messages', 'intent', 'share', 'hashtag', 'status', 'login', 'signup', 'about', 'privacy', 'tos', 'compose'];

    private const GITHUB_RESERVED = ['orgs', 'organizations', 'features', 'about', 'pricing', 'topics', 'collections', 'sponsors', 'marketplace', 'settings', 'login', 'join', 'signup', 'enterprise', 'apps', 'explore', 'trending', 'security', 'readme', 'site', 'contact', 'search', 'new', 'notifications'];

    private const X_HANDLE = '/^[A-Za-z0-9_]{1,15}$/';

    private const GITHUB_HANDLE = '/^[A-Za-z0-9](?:[A-Za-z0-9]|-(?=[A-Za-z0-9])){0,38}$/';

    private const LINKEDIN_SECTIONS = ['in' => 'person', 'company' => 'organization', 'school' => 'organization'];

    private const LINKEDIN_SLUG = '/^[\p{L}\p{N}][\p{L}\p{N}_.\-]{1,99}$/u';

    /**
     * @return array{network: string, handle: string, url: string, kind: string}|null  network is x | github | linkedin; handle is lower case; url is the address to store
     */
    public static function parse(string $raw): ?array
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }
        $parts = parse_url(preg_match('#^https?://#i', $trimmed) ? $trimmed : 'https://' . $trimmed);
        if ($parts === false || ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $host = strtolower((string) $parts['host']);
        $segments = array_values(array_filter(explode('/', (string) ($parts['path'] ?? '')), fn ($s) => $s !== ''));

        if ($host === 'linkedin.com' || str_ends_with($host, '.linkedin.com')) {
            return self::linkedin($segments);
        }
        if (count($segments) !== 1) {
            return null;
        }
        $handle = ltrim(rawurldecode($segments[0]), '@');

        if (in_array($host, self::X_HOSTS, true)) {
            if (in_array(strtolower($handle), self::X_RESERVED, true) || ! preg_match(self::X_HANDLE, $handle)) {
                return null;
            }

            return ['network' => 'x', 'handle' => strtolower($handle), 'url' => 'https://x.com/' . $handle, 'kind' => 'person'];
        }

        if (in_array($host, self::GITHUB_HOSTS, true)) {
            if (in_array(strtolower($handle), self::GITHUB_RESERVED, true) || ! preg_match(self::GITHUB_HANDLE, $handle)) {
                return null;
            }

            return ['network' => 'github', 'handle' => strtolower($handle), 'url' => 'https://github.com/' . $handle, 'kind' => 'person'];
        }

        return null;
    }

    /**
     * Every profile address in some text (prose, citations): deduplicated, trailing punctuation dropped, deep links on X and GitHub ignored.
     *
     * @param  list<string>  $haystack
     * @return list<array{network: string, handle: string, url: string, kind: string}>
     */
    public static function extract(array $haystack): array
    {
        $found = [];
        foreach ($haystack as $chunk) {
            preg_match_all('#https?://(?:[a-z0-9-]+\.)*(?:x\.com|twitter\.com|github\.com|linkedin\.com)/[^\s"\'<>)\]},]+#i', (string) $chunk, $matches);
            foreach ($matches[0] as $match) {
                $profile = self::parse(rtrim($match, '.,;:!?'));
                if ($profile !== null && ! in_array($profile['url'], array_column($found, 'url'), true)) {
                    $found[] = $profile;
                }
            }
        }

        return $found;
    }

    /** The slug of a LinkedIn person address (`/in/{slug}`), else null. Port of `slugFromProfileUrl`. */
    public static function linkedinSlug(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $profile = self::parse($raw);

        return $profile !== null && $profile['network'] === 'linkedin' && $profile['kind'] === 'person' ? $profile['handle'] : null;
    }

    /**
     * @param  list<string>  $segments
     * @return array{network: string, handle: string, url: string, kind: string}|null
     */
    private static function linkedin(array $segments): ?array
    {
        if (count($segments) < 2 || ! isset(self::LINKEDIN_SECTIONS[strtolower($segments[0])])) {
            return null;
        }
        $section = strtolower($segments[0]);
        $slug = rawurldecode($segments[1]);
        if (! preg_match(self::LINKEDIN_SLUG, $slug)) {
            return null;
        }

        return ['network' => 'linkedin', 'handle' => mb_strtolower($slug), 'url' => 'https://www.linkedin.com/' . $section . '/' . $slug, 'kind' => self::LINKEDIN_SECTIONS[$section]];
    }
}
