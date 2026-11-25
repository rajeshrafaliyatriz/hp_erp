<?php

namespace App\Domain\Signals\Sources\Adapters;

/** Reddit official OAuth2 (client credentials) + oauth.reddit.com/search. Config: enabled, client_id, client_secret, user_agent, max_results, max_requests. */
final class RedditSearchAdapter extends AbstractApiAdapter
{
    public function isConfigured(): bool
    {
        return $this->cfg('client_id') !== '' && $this->cfg('client_secret') !== '';
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
        $ua = $this->cfg('user_agent') !== '' ? $this->cfg('user_agent') : 'web:market-signals-research:v1.0 (read-only search)';
        $tok = $this->call(fn ($c) => $c->withBasicAuth($this->cfg('client_id'), $this->cfg('client_secret'))->withUserAgent($ua)->asForm()
            ->post('https://www.reddit.com/api/v1/access_token', ['grant_type' => 'client_credentials']));
        $token = is_array($tok) ? (string) ($tok['access_token'] ?? '') : '';
        if ($token === '') {
            $this->lastError ??= 'no_token';

            return [];
        }
        $t = $recencyDays <= 7 ? 'week' : ($recencyDays <= 31 ? 'month' : 'year');
        $json = $this->call(fn ($c) => $c->withToken($token)->withUserAgent($ua)->get('https://oauth.reddit.com/search', [
            'q' => $q, 'sort' => 'new', 't' => $t, 'limit' => $this->limit($limit), 'type' => 'link', 'raw_json' => 1,
        ]));
        if ($json === null) {
            return [];
        }
        $out = [];
        foreach ((array) ($json['data']['children'] ?? []) as $ch) {
            $d = (array) ($ch['data'] ?? []);
            $link = (string) ($d['permalink'] ?? '');
            if ($link === '' || ! str_starts_with($link, '/')) {
                continue;
            }
            $author = self::cut((string) ($d['author'] ?? ''), 60);
            $out[] = new MarketItem('https://www.reddit.com' . $link, self::cut((string) ($d['title'] ?? ''), 300), self::cut((string) ($d['selftext'] ?? ''), 600),
                isset($d['created_utc']) ? gmdate('Y-m-d', (int) $d['created_utc']) : null, 'reddit', 'reddit_api', $author === '' ? null : $author,
                isset($d['score']) ? (int) $d['score'] + (int) ($d['num_comments'] ?? 0) : null);
        }

        return $out;
    }
}
