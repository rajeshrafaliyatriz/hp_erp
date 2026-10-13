<?php

namespace App\Domain\Signals\Opportunities;

/**
 * Chooses the research provider from SIGNALS_SEARCH_DRIVER. `none` (default) or an
 * unknown value yields null: research is then reported as "not configured", never
 * silently replaced by another provider or by invented results.
 */
class SearchProviderFactory
{
    public static function make(): ?WebSearchProvider
    {
        return match (strtolower((string) config('signals.web_search.driver'))) {
            'tavily' => new TavilySearchProvider(),
            'brave' => new BraveSearchProvider(),
            default => null,
        };
    }

    /** A blank key or an obvious template value ("YOUR_KEY") is not a credential. */
    public static function usableKey(string $key): bool
    {
        $key = trim($key);

        return $key !== '' && preg_match('/^(your[_\-\s]|<|changeme|change[_-]me|replace[_-]me|xxx+$)/i', $key) !== 1;
    }
}
