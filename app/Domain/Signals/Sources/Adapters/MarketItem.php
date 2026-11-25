<?php

namespace App\Domain\Signals\Sources\Adapters;

/**
 * One market-signal candidate found by an adapter (feed, official API, search). Immutable. A candidate is NOT evidence by itself: the hub tags it with the
 * platform policy and items from lead-hint-only hosts carry leadOnly=true.
 */
final class MarketItem
{
    public const PLATFORMS = ['web', 'feed', 'youtube', 'reddit', 'google', 'gov_feed'];

    public function __construct(
        public readonly string $url,
        public readonly string $title,
        public readonly string $excerpt,
        public readonly ?string $publishedAt,
        public readonly string $platform,
        public readonly string $origin,
        public readonly ?string $author = null,
        public readonly ?int $engagement = null,
        public readonly ?string $policyMode = null,
        public readonly bool $leadOnly = false,
    ) {
    }

    public function withPolicy(string $mode, bool $leadOnly): self
    {
        return new self($this->url, $this->title, $this->excerpt, $this->publishedAt, $this->platform, $this->origin, $this->author, $this->engagement, $mode, $leadOnly);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'title' => $this->title,
            'excerpt' => $this->excerpt,
            'published_at' => $this->publishedAt,
            'platform' => $this->platform,
            'origin' => $this->origin,
            'author' => $this->author,
            'engagement' => $this->engagement,
            'policy_mode' => $this->policyMode,
            'lead_only' => $this->leadOnly,
        ];
    }
}
