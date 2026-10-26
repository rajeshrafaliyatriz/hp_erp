<?php

namespace App\Domain\Signals\Support;

use Illuminate\Contracts\Cache\Repository;

/**
 * "This search was already paid for recently" memory. A search is remembered only AFTER it returned results: remembering it before it ran meant a
 * failed or empty search (a provider error, a timeout, a bad key) blocked the same search on the next run, so a run could find nothing at all.
 */
final class SearchCache
{
    public function __construct(private Repository $store, private int $hours)
    {
    }

    public function key(int $tenantId, string $query): string
    {
        return 'signals:q:' . $tenantId . ':' . md5($query);
    }

    public function enabled(): bool
    {
        return $this->hours > 0;
    }

    public function seen(int $tenantId, string $query): bool
    {
        return $this->enabled() && $this->store->has($this->key($tenantId, $query));
    }

    /** Call only with the results of a search that succeeded. Empty or failed searches are never remembered. */
    public function remember(int $tenantId, string $query, array $results): bool
    {
        if (! $this->enabled() || $results === []) {
            return false;
        }
        $this->store->put($this->key($tenantId, $query), 1, now()->addHours($this->hours));

        return true;
    }
}
