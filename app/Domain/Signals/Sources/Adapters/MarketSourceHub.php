<?php

namespace App\Domain\Signals\Sources\Adapters;

use App\Domain\Signals\Support\SourceUrl;

/**
 * Gathers candidates from official-API adapters and public feeds, de-duplicates them by SourceUrl::identityKey and tags each with the platform
 * policy. Items on lead-hint-only hosts are kept but flagged leadOnly (never evidence by themselves).
 */
final class MarketSourceHub
{
    /** @var callable(string):?string */
    private $fetcher;

    private PlatformPolicy $policy;

    /**
     * @param list<object> $adapters objects with search(string,int,int): list<MarketItem>
     * @param callable(string):?string $fetcher returns the feed XML body for a URL (the caller's safe fetcher), or null
     */
    public function __construct(
        private readonly array $adapters,
        private readonly FeedReader $reader,
        callable $fetcher,
        ?PlatformPolicy $policy = null,
        private readonly int $maxTotal = 100,
        private readonly int $recencyDays = 30,
        private readonly int $perQuery = 10,
    ) {
        $this->fetcher = $fetcher;
        $this->policy = $policy ?? new PlatformPolicy();
    }

    /**
     * @param list<string> $queries
     * @param list<string> $feeds
     * @return list<MarketItem>
     */
    public function gather(array $queries, array $feeds): array
    {
        $seen = [];
        $out = [];
        $add = function (MarketItem $i, bool $feed) use (&$seen, &$out): void {
            if (count($out) >= $this->maxTotal) {
                return;
            }
            $key = SourceUrl::identityKey($i->url);
            if ($key === '' || isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $p = $feed ? $this->policy->forFeed($i->url) : $this->policy->forUrl($i->url);
            $out[] = $i->withPolicy($p['mode'], $p['mode'] === PlatformPolicy::LEAD_HINT_ONLY);
        };

        foreach ($feeds as $url) {
            if (count($out) >= $this->maxTotal) {
                break;
            }
            $url = trim((string) $url);
            if ($url === '' || $this->policy->forUrl($url)['mode'] === PlatformPolicy::LEAD_HINT_ONLY) {
                continue; // a social URL is never fetched, even as a "feed"
            }
            try {
                $body = ($this->fetcher)($url);
            } catch (\Throwable) {
                continue;
            }
            if (! is_string($body) || $body === '') {
                continue;
            }
            foreach ($this->reader->parse($body, (string) parse_url($url, PHP_URL_HOST), 'feed') as $i) {
                $add($i, true);
            }
        }
        foreach ($queries as $q) {
            foreach ($this->adapters as $a) {
                if (count($out) >= $this->maxTotal) {
                    break 2;
                }
                try {
                    foreach ($a->search((string) $q, $this->recencyDays, $this->perQuery) as $i) {
                        if ($i instanceof MarketItem) {
                            $add($i, false);
                        }
                    }
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        return $out;
    }
}
