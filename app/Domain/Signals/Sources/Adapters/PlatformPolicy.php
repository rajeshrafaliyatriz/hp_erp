<?php

namespace App\Domain\Signals\Sources\Adapters;

/**
 * PURE table of what the engine may do per platform. Social networks are never fetched or scraped: a post can only appear as a lead from a search
 * provider's results and must be verified on another readable source. Official APIs need the owner's own credentials.
 */
final class PlatformPolicy
{
    public const LEAD_HINT_ONLY = 'lead_hint_only';
    public const OFFICIAL_API = 'official_api';
    public const PUBLIC_FEED = 'public_feed';
    public const WEB_PAGE = 'web_page';

    private const LEAD_HOSTS = [
        'linkedin.com' => 'linkedin', 'instagram.com' => 'instagram', 'facebook.com' => 'facebook', 'fb.com' => 'facebook', 'fb.watch' => 'facebook',
        'x.com' => 'x', 'twitter.com' => 'x', 't.co' => 'x', 'tiktok.com' => 'tiktok',
    ];

    private const API_HOSTS = [
        'youtube.com' => 'youtube', 'youtu.be' => 'youtube', 'reddit.com' => 'reddit', 'redd.it' => 'reddit',
    ];

    /** @return array{platform:string,mode:string,may_fetch:bool,may_use_as_evidence:bool,reason:string} */
    public function forHost(string $host): array
    {
        $h = self::clean($host);
        if ($h !== '') {
            foreach (self::LEAD_HOSTS as $domain => $platform) {
                if ($h === $domain || str_ends_with($h, '.' . $domain)) {
                    return self::row($platform, self::LEAD_HINT_ONLY, false, false, 'Social network: never fetched or scraped. Only a lead from search results; verify on another readable source.');
                }
            }
            foreach (self::API_HOSTS as $domain => $platform) {
                if ($h === $domain || str_ends_with($h, '.' . $domain)) {
                    return self::row($platform, self::OFFICIAL_API, false, true, "Read only through the official API with the owner's credentials, never by fetching pages.");
                }
            }
            if ($h === 'googleapis.com' || str_ends_with($h, '.googleapis.com')) {
                return self::row('google_cse', self::OFFICIAL_API, false, true, "Programmable Search official API with the owner's credentials.");
            }
        }

        return self::row($h === '' ? 'web' : $h, self::WEB_PAGE, true, true, 'Ordinary web page: robots.txt is respected.');
    }

    /** A feed URL (RSS/Atom or a government tender feed) is a public feed, unless it sits on a lead-hint-only host. */
    public function forFeed(string $url): array
    {
        $base = $this->forUrl($url);
        if ($base['mode'] === self::LEAD_HINT_ONLY) {
            return $base;
        }

        return self::row($base['platform'], self::PUBLIC_FEED, true, true, 'Public RSS/Atom or tender feed.');
    }

    public function forUrl(string $url): array
    {
        return $this->forHost((string) parse_url(trim($url), PHP_URL_HOST));
    }

    private static function clean(string $host): string
    {
        $h = strtolower(trim($host));
        $h = (string) preg_replace('/:\d+$/', '', $h);
        $h = rtrim($h, '.');

        return (string) preg_replace('/^(www\.|m\.|mobile\.|in\.|amp\.|old\.|np\.)/', '', $h);
    }

    private static function row(string $platform, string $mode, bool $fetch, bool $evidence, string $reason): array
    {
        return ['platform' => $platform, 'mode' => $mode, 'may_fetch' => $fetch, 'may_use_as_evidence' => $evidence, 'reason' => $reason];
    }
}
