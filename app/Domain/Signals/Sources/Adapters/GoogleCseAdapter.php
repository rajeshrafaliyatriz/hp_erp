<?php

namespace App\Domain\Signals\Sources\Adapters;

/** Google Programmable Search JSON API. Config: enabled, api_key, cx, max_results, max_requests. */
final class GoogleCseAdapter extends AbstractApiAdapter
{
    public function isConfigured(): bool
    {
        return $this->cfg('api_key') !== '' && $this->cfg('cx') !== '';
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
        $json = $this->call(fn ($c) => $c->get('https://www.googleapis.com/customsearch/v1', [
            'key' => $this->cfg('api_key'), 'cx' => $this->cfg('cx'), 'q' => $q, 'num' => min(10, $this->limit($limit)), 'dateRestrict' => 'd' . max(1, $recencyDays),
        ]));
        if ($json === null) {
            return [];
        }
        $out = [];
        foreach ((array) ($json['items'] ?? []) as $it) {
            $link = (string) ($it['link'] ?? '');
            if (! preg_match('#^https?://#i', $link)) {
                continue;
            }
            $meta = (array) ($it['pagemap']['metatags'][0] ?? []);
            $date = self::day($meta['article:published_time'] ?? ($meta['og:updated_time'] ?? null));
            $out[] = new MarketItem($link, self::cut((string) ($it['title'] ?? ''), 300), self::cut((string) ($it['snippet'] ?? ''), 600), $date, 'google', 'google_cse');
        }

        return $out;
    }
}
