<?php

namespace App\Domain\Signals\Sources\Adapters;

/** YouTube Data API v3 search.list with the owner's API key. Config: enabled, api_key, max_results, max_requests. */
final class YouTubeSearchAdapter extends AbstractApiAdapter
{
    public function isConfigured(): bool
    {
        return $this->cfg('api_key') !== '';
    }

    /** @return list<MarketItem> */
    public function search(string $query, int $recencyDays, int $limit): array
    {
        $this->lastError = null;
        if (! $this->enabled()) {
            $this->lastError = 'not_configured';

            return [];
        }
        $q = trim($query);
        if ($q === '') {
            return [];
        }
        $json = $this->call(fn ($c) => $c->get('https://www.googleapis.com/youtube/v3/search', [
            'part' => 'snippet', 'type' => 'video', 'order' => 'date', 'q' => $q, 'maxResults' => $this->limit($limit),
            'publishedAfter' => gmdate('Y-m-d\TH:i:s\Z', time() - max(1, $recencyDays) * 86400), 'key' => $this->cfg('api_key'),
        ]));
        if ($json === null) {
            return [];
        }
        $out = [];
        foreach ((array) ($json['items'] ?? []) as $it) {
            $id = (string) ($it['id']['videoId'] ?? '');
            if (! preg_match('/^[A-Za-z0-9_-]{6,20}$/', $id)) {
                continue;
            }
            $s = (array) ($it['snippet'] ?? []);
            $channel = self::cut((string) ($s['channelTitle'] ?? ''), 120);
            $out[] = new MarketItem('https://www.youtube.com/watch?v=' . $id, self::cut((string) ($s['title'] ?? ''), 300), self::cut((string) ($s['description'] ?? ''), 600),
                self::day($s['publishedAt'] ?? null), 'youtube', 'youtube_api', $channel === '' ? null : $channel);
        }

        return $out;
    }
}
