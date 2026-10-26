<?php

namespace App\Domain\Signals\Support;

use Illuminate\Contracts\Cache\Repository;

/**
 * "This search was already paid for recently" memory. A search is remembered only AFTER it returned results: remembering it before it ran meant a
 * failed or empty search (a provider error, a timeout, a bad key) blocked the same search on the next run, so a run could find nothing at all.
 *
 * Identity: two searches are the same only if everything that changes what the provider returns is the same: the normalised query text, the topic,
 * the freshness window in days, the set of included domains (order and case ignored) and the provider. Two registry sources that share a query
 * text but have different domains are therefore two searches. Callers that pass no options get the query-text identity they always had.
 */
final class SearchCache
{
    public function __construct(private Repository $store, private int $hours)
    {
    }

    /**
     * @param  array{topic?: ?string, days?: ?int, domains?: ?array<int, string>, provider?: ?string}  $options
     * @return array<string, mixed> the canonical description of the search that the key is a hash of
     */
    public static function identity(string $query, array $options = []): array
    {
        $domains = array_values(array_unique(array_filter(array_map(
            fn ($d) => preg_replace('/^www\./', '', mb_strtolower(trim((string) $d))),
            (array) ($options['domains'] ?? [])
        ))));
        sort($domains);

        return [
            'q' => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $query) ?? $query)),
            'topic' => isset($options['topic']) && $options['topic'] !== '' ? mb_strtolower((string) $options['topic']) : null,
            'days' => isset($options['days']) ? (int) $options['days'] : null,
            'domains' => $domains,
            'provider' => isset($options['provider']) && $options['provider'] !== '' ? mb_strtolower((string) $options['provider']) : null,
        ];
    }

    public function key(int $tenantId, string $query, array $options = []): string
    {
        return 'signals:q:' . $tenantId . ':' . md5((string) json_encode(self::identity($query, $options)));
    }

    public function enabled(): bool
    {
        return $this->hours > 0;
    }

    public function seen(int $tenantId, string $query, array $options = []): bool
    {
        return $this->enabled() && $this->store->has($this->key($tenantId, $query, $options));
    }

    /** Call only with the results of a search that succeeded. Empty or failed searches are never remembered. */
    public function remember(int $tenantId, string $query, array $results, array $options = []): bool
    {
        if (! $this->enabled() || $results === []) {
            return false;
        }
        $this->store->put($this->key($tenantId, $query, $options), 1, now()->addHours($this->hours));

        return true;
    }
}
