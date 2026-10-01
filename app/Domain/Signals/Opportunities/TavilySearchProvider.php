<?php

namespace App\Domain\Signals\Opportunities;

use Illuminate\Support\Facades\Http;

/** Tavily Search API (https://docs.tavily.com). Key: TAVILY_API_KEY. */
class TavilySearchProvider implements WebSearchProvider
{
    public function name(): string
    {
        return 'tavily';
    }

    public function key(): string
    {
        $key = trim((string) config('signals.web_search.tavily_key', ''));

        if (! app()->runningUnitTests() && ($key === '' || ! SearchProviderFactory::usableKey($key))) {
            $envPath = base_path('.env');
            if (file_exists($envPath)) {
                $content = @file_get_contents($envPath);
                if ($content !== false && preg_match('/^TAVILY_API_KEY\s*=\s*(.*)$/m', $content, $m)) {
                    $val = trim($m[1], " \t\n\r\0\x0B'\"");
                    if (SearchProviderFactory::usableKey($val)) {
                        return $val;
                    }
                }
            }
        }

        return $key;
    }

    public function isConfigured(): bool
    {
        return SearchProviderFactory::usableKey($this->key());
    }

    public function search(string $query, int $recencyDays, int $limit): array
    {
        $response = Http::timeout((int) config('signals.web_search.timeout', 20))
            ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
            ->withToken($this->key())
            ->acceptJson()->asJson()
            ->post('https://api.tavily.com/search', [
                'query' => $query,
                'topic' => 'news',
                'days' => max(1, $recencyDays),
                'max_results' => max(1, min(20, $limit)),
                'search_depth' => 'basic',
                'include_answer' => false,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Tavily returned HTTP ' . $response->status());
        }

        $out = [];
        foreach ((array) $response->json('results', []) as $r) {
            if (empty($r['url']) || empty($r['title'])) {
                continue;
            }
            $out[] = [
                'url' => (string) $r['url'],
                'title' => (string) $r['title'],
                'excerpt' => (string) ($r['content'] ?? ''),
                'published_at' => ! empty($r['published_date']) ? (string) $r['published_date'] : null,
            ];
        }

        return $out;
    }
}
