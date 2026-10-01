<?php

namespace App\Domain\Signals\Opportunities;

/**
 * A live web/news search API. An LLM cannot do this on its own, so daily company
 * research depends on one of these being configured.
 *
 * Implementations must use the provider's official API (no scraping of search result
 * pages) and return ONLY what the provider returned - never synthesised results.
 */
interface WebSearchProvider
{
    /** Short stable name, stored on the run ("tavily", "brave"). */
    public function name(): string;

    public function isConfigured(): bool;

    /**
     * @return array<int, array{url: string, title: string, excerpt: string, published_at: ?string}>
     *
     * @throws \RuntimeException on provider failure (message is not shown to users)
     */
    public function search(string $query, int $recencyDays, int $limit): array;
}
