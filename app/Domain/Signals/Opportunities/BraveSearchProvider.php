<?php

namespace App\Domain\Signals\Opportunities;

use Illuminate\Support\Facades\Http;

/** Brave Search API, news endpoint (https://api.search.brave.com). Key: BRAVE_SEARCH_API_KEY. */
class BraveSearchProvider implements WebSearchProvider
{
    public function name(): string
    {
        return 'brave';
    }

    public function isConfigured(): bool
    {
        return SearchProviderFactory::usableKey((string) config('signals.web_search.brave_key'));
    }

    public function search(string $query, int $recencyDays, int $limit): array
    {
        $freshness = $recencyDays <= 1 ? 'pd' : ($recencyDays <= 7 ? 'pw' : ($recencyDays <= 31 ? 'pm' : 'py'));

        $response = Http::timeout((int) config('signals.web_search.timeout', 20))
            ->withHeaders(['X-Subscription-Token' => (string) config('signals.web_search.brave_key')])
            ->acceptJson()
            ->get('https://api.search.brave.com/res/v1/news/search', [
                'q' => $query,
                'count' => max(1, min(20, $limit)),
                'freshness' => $freshness,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Brave returned HTTP ' . $response->status());
        }

        $out = [];
        foreach ((array) $response->json('results', []) as $r) {
            if (empty($r['url']) || empty($r['title'])) {
                continue;
            }
            $out[] = [
                'url' => (string) $r['url'],
                'title' => strip_tags((string) $r['title']),
                'excerpt' => strip_tags((string) ($r['description'] ?? '')),
                'published_at' => ! empty($r['page_age']) ? (string) $r['page_age'] : null,
            ];
        }

        return $out;
    }
}
