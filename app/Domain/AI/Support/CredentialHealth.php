<?php

namespace App\Domain\AI\Support;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Per-credential health, kept in the cache so a healthy request costs no database write.
 *
 * Tracked: failure_count, cooldown_until, last_error_type, last_error_at, last_used_at.
 * Keyed by `row:{ai_api_keys.id}` or, for environment keys, `env:{12 hex of sha256}` —
 * the key itself is never part of a cache key, log line or response. A cache outage
 * degrades to "everything healthy" rather than blocking AI.
 */
class CredentialHealth
{
    public static function fingerprint(int|string|null $id, string $apiKey): string
    {
        return $id !== null ? "row:{$id}" : 'env:' . substr(hash('sha256', $apiKey), 0, 12);
    }

    /** @return array{failure_count:int, cooldown_until:?int, last_error_type:?string, last_error_at:?int, last_used_at:?int} */
    public function state(string $fingerprint): array
    {
        try {
            $state = Cache::get($this->key($fingerprint));
        } catch (Throwable) {
            $state = null;
        }

        return [
            'failure_count' => (int) ($state['failure_count'] ?? 0),
            'cooldown_until' => $state['cooldown_until'] ?? null,
            'last_error_type' => $state['last_error_type'] ?? null,
            'last_error_at' => $state['last_error_at'] ?? null,
            'last_used_at' => $state['last_used_at'] ?? null,
        ];
    }

    public function isAvailable(string $fingerprint): bool
    {
        $until = $this->state($fingerprint)['cooldown_until'];

        return $until === null || $until <= now()->getTimestamp();
    }

    public function recordSuccess(string $fingerprint): void
    {
        $this->put($fingerprint, [
            'failure_count' => 0,
            'cooldown_until' => null,
            'last_used_at' => now()->getTimestamp(),
        ] + $this->state($fingerprint), 86400);
    }

    public function recordFailure(string $fingerprint, string $type, int $cooldownSeconds): void
    {
        $state = $this->state($fingerprint);
        $cooldownSeconds = max(1, $cooldownSeconds);

        $this->put($fingerprint, [
            'failure_count' => $state['failure_count'] + 1,
            'cooldown_until' => now()->getTimestamp() + $cooldownSeconds,
            'last_error_type' => $type,
            'last_error_at' => now()->getTimestamp(),
            'last_used_at' => now()->getTimestamp(),
        ], $cooldownSeconds + 86400);
    }

    /** @return 'healthy'|'cooldown'|'invalid' */
    public function status(string $fingerprint): string
    {
        $state = $this->state($fingerprint);

        if ($state['cooldown_until'] === null || $state['cooldown_until'] <= now()->getTimestamp()) {
            return 'healthy';
        }

        return $state['last_error_type'] === 'invalid_credential' ? 'invalid' : 'cooldown';
    }

    private function put(string $fingerprint, array $state, int $ttl): void
    {
        try {
            Cache::put($this->key($fingerprint), $state, $ttl);
        } catch (Throwable) {
            // Health is an optimisation; losing it must never fail a request.
        }
    }

    private function key(string $fingerprint): string
    {
        return "ai:cred-health:{$fingerprint}";
    }
}
