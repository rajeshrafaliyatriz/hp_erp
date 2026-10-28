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

        // The main .env file is a fallback for ordinary local use only; in a verification run it is never read (see EnvFileFallback), so a
        // credential that was not approved for verification stays missing and the provider reports itself as not configured.
        if (\App\Support\EnvFileFallback::allowedHere() && ($key === '' || ! SearchProviderFactory::usableKey($key))) {
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
        if ($this->extraKeys() !== []) {
            return true;
        }

        return SearchProviderFactory::usableKey($this->key());
    }

    /** Further keys from TAVILY_API_KEYS (comma or space separated), template values and duplicates dropped. Never printed or logged. @return list<string> */
    private function extraKeys(): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', (string) config('signals.web_search.tavily_keys', ''), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $k) {
            $k = trim($k);
            if (SearchProviderFactory::usableKey($k) && ! in_array($k, $out, true)) {
                $out[] = $k;
            }
        }

        return $out;
    }

    /** @return list<string> every usable key, TAVILY_API_KEY first */
    public function keys(): array
    {
        $out = [];
        foreach (array_merge([$this->key()], $this->extraKeys()) as $k) {
            if (SearchProviderFactory::usableKey((string) $k) && ! in_array($k, $out, true)) {
                $out[] = $k;
            }
        }

        return $out ?: [$this->key()];
    }

    public function search(string $query, int $recencyDays, int $limit, array $options = []): array
    {
        $topic = ($options['topic'] ?? 'news') === 'general' ? 'general' : 'news';

        $payload = [
            'query' => $query,
            'topic' => $topic,
            'max_results' => max(1, min(20, $limit)),
            'search_depth' => 'basic',
            'include_answer' => false,
        ];

        // Tavily's `days` window applies to the news topic only. A general search (tenders, job posts,
        // organisation pages) takes a coarser `time_range` instead.
        if ($topic === 'news') {
            $payload['days'] = max(1, $recencyDays);
        } else {
            // These are Indian schools: without this, generic queries return Seattle school districts and dictionary pages.
            $payload['country'] = 'india';
            $payload['time_range'] = $recencyDays <= 1 ? 'day' : ($recencyDays <= 7 ? 'week' : ($recencyDays <= 31 ? 'month' : 'year'));
        }

        $domains = array_values(array_filter(array_map('strval', (array) ($options['include_domains'] ?? []))));
        if ($domains !== []) {
            $payload['include_domains'] = array_slice($domains, 0, 20);
        }

        \App\Domain\Signals\Run\RunBudget::takeOrFail(\App\Domain\Signals\Run\RunBudget::SEARCH);   // verification ceiling, at the request itself: one search, however many keys it tries

        $response = $this->post($payload);
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

    private function fingerprint(string $key): string
    {
        return substr(hash('sha256', $key), 0, 12);
    }

    /** @return array<string, int> key fingerprint => time until which that key is left alone (its limit is used up) */
    private function resting(): array
    {
        try {
            return array_filter((array) \Illuminate\Support\Facades\Cache::get('signals:tavily:rest', []), fn ($until) => (int) $until > time());
        } catch (\Throwable) {
            return [];
        }
    }

    private function rest(string $key, int $seconds): void
    {
        try {
            $r = $this->resting();
            $r[$this->fingerprint($key)] = time() + $seconds;
            \Illuminate\Support\Facades\Cache::put('signals:tavily:rest', $r, 86400 * 3);
        } catch (\Throwable) {
        }
    }

    private function wake(string $key): void
    {
        try {
            $r = $this->resting();
            unset($r[$this->fingerprint($key)]);
            \Illuminate\Support\Facades\Cache::put('signals:tavily:rest', $r, 86400 * 3);
        } catch (\Throwable) {
        }
    }

    /**
     * One search with automatic key failover. Keys whose limit is used up (HTTP 432/433), that are refused (401/403) or rate-limited (429) are left
     * alone for a while and the next key is used; the order is the key list's, starting from the first key that is not resting, so after the last key's
     * limit is used up the first key is tried again as soon as its rest ends (limits refill). When every key is resting, only the one whose rest ends
     * first is tried, so a day with every limit used up costs one request, not one per key. Any other error is raised as before, with no rotation.
     * At most one request per key; nothing here changes how many SEARCHES a run may make.
     *
     * @param  array<string, mixed>  $payload
     */
    private function post(array $payload): \Illuminate\Http\Client\Response
    {
        $keys = $this->keys();
        $rest = $this->resting();
        $order = array_values(array_filter(array_keys($keys), fn ($i) => ! isset($rest[$this->fingerprint($keys[$i])])));
        if ($order === []) {
            asort($rest);
            $soonest = array_key_first($rest);
            $order = [array_values(array_filter(array_keys($keys), fn ($i) => $this->fingerprint($keys[$i]) === $soonest))[0] ?? 0];
        }

        $last = null;
        foreach ($order as $i) {
            $response = Http::timeout((int) config('signals.web_search.timeout', 20))
                ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                ->withToken($keys[$i])
                ->acceptJson()->asJson()
                ->post('https://api.tavily.com/search', $payload);
            if ($response->successful()) {
                $this->wake($keys[$i]);

                return $response;
            }
            $status = $response->status();
            $hours = max(1, (int) config('signals.web_search.tavily_key_rest_hours', 6));
            $seconds = match (true) {
                in_array($status, [432, 433], true) => $hours * 3600,
                in_array($status, [401, 403], true) => 86400,
                $status === 429 => 60,
                default => 0,
            };
            if ($seconds === 0 || count($keys) < 2) {
                throw new \RuntimeException('Tavily returned HTTP ' . $status);
            }
            $this->rest($keys[$i], $seconds);
            \Illuminate\Support\Facades\Log::info('signals.tavily_key_rotated', ['key_number' => $i + 1, 'of' => count($keys), 'status' => $status]);
            $last = $status;
        }

        throw new \RuntimeException('Tavily returned HTTP ' . $last);
    }
}
