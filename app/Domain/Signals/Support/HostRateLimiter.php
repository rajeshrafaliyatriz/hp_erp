<?php

namespace App\Domain\Signals\Support;

/**
 * A deterministic, in-process, per-host minimum interval between requests. It only ever DELAYS; it adds no retries and raises no budget.
 *
 * Wiring (not wired anywhere yet): create one instance per run and call `$limiter->wait($host)` immediately before each outbound request to
 * that host, e.g. in SafeUrlFetcher::fetch() just before Http::...->get($url), with $host from validate(). Pass the fetch's lower-cased host.
 *     $limiter = new HostRateLimiter((int) config('signals.ingestion.min_host_interval_ms', 1000));
 *
 * Clock and sleeper are injected (milliseconds) so tests never sleep. Defaults use hrtime() and usleep().
 * Memory is bounded: at most $maxHosts hosts are remembered; the oldest entry is dropped first.
 */
class HostRateLimiter
{
    /** @var array<string, int> host => ms timestamp of the last request */
    private array $last = [];

    /** @var \Closure(): int */
    private \Closure $clock;

    /** @var \Closure(int): void */
    private \Closure $sleeper;

    /**
     * @param  (callable(): int)|null  $clock  monotonic milliseconds
     * @param  (callable(int): void)|null  $sleeper  sleeps for the given milliseconds
     */
    public function __construct(private readonly int $minIntervalMs = 1000, ?callable $clock = null, ?callable $sleeper = null, private readonly int $maxHosts = 256)
    {
        $this->clock = $clock !== null ? \Closure::fromCallable($clock) : fn (): int => intdiv(hrtime(true), 1_000_000);
        $this->sleeper = $sleeper !== null ? \Closure::fromCallable($sleeper) : function (int $ms): void {
            usleep($ms * 1000);
        };
    }

    /** Blocks until $host may be requested again; returns the milliseconds slept (0 when no wait was needed). */
    public function wait(string $host): int
    {
        $host = strtolower(trim($host, '[]. '));
        $slept = 0;

        if ($this->minIntervalMs > 0 && isset($this->last[$host])) {
            $remaining = $this->minIntervalMs - (($this->clock)() - $this->last[$host]);
            if ($remaining > 0) {
                ($this->sleeper)($remaining);
                $slept = $remaining;
            }
        }

        unset($this->last[$host]);   // re-insert at the end so eviction order is least-recently-used
        $this->last[$host] = ($this->clock)();
        if (count($this->last) > max(1, $this->maxHosts)) {
            unset($this->last[array_key_first($this->last)]);
        }

        return $slept;
    }

    public function reset(): void
    {
        $this->last = [];
    }
}
