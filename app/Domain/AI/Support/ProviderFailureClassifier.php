<?php

namespace App\Domain\AI\Support;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Decides what a provider failure means for credential failover.
 *
 * Built against Google's documented error envelope
 * (`error.status` = RESOURCE_EXHAUSTED / UNAVAILABLE / PERMISSION_DENIED /
 * INVALID_ARGUMENT / FAILED_PRECONDITION / NOT_FOUND, `error.details[]` carrying
 * QuotaFailure, RetryInfo and ErrorInfo), as parsed into `AiProviderHttpException`.
 *
 * - `quota`              → cool the credential down, try the next one.
 * - `invalid_credential` → long cooldown, try the next one (another key can work).
 * - `transient`          → short cooldown, try the next one, but bounded separately.
 * - `abort`              → request/model/config/network problem: another key cannot
 *                          fix it, so the original exception is rethrown untouched.
 */
final class ProviderFailureClassifier
{
    /** @return array{action:'rotate'|'abort', type:string, cooldown:int} */
    public function classify(Throwable $e): array
    {
        if (! $e instanceof AiProviderHttpException) {
            return $this->abort();
        }

        $http = $e->httpStatus;
        $status = strtoupper((string) $e->providerStatus);
        $reason = strtoupper((string) $e->reason);
        $text = strtolower((string) $e->providerMessage);

        if ($http === 429 || $status === 'RESOURCE_EXHAUSTED') {
            return $this->rotate('quota', $this->quotaCooldown($e));
        }

        if (str_contains($text, 'location is not supported')) {
            return $this->abort(); // regional restriction: same for every key
        }

        if (
            $http === 401 || $http === 403
            || $status === 'PERMISSION_DENIED' || $status === 'UNAUTHENTICATED'
            || $reason === 'API_KEY_INVALID'
            || ($http === 400 && preg_match('/api key not valid|api key expired|invalid api key|api_key_invalid/', $text))
            // Free tier unavailable / billing not enabled on this key's project.
            || $status === 'FAILED_PRECONDITION'
        ) {
            return $this->rotate('invalid_credential', (int) config('ai.failover.invalid_cooldown_seconds', 3600));
        }

        if ($http >= 500 || in_array($status, ['UNAVAILABLE', 'INTERNAL', 'DEADLINE_EXCEEDED'], true)) {
            return $this->rotate('transient', (int) config('ai.failover.transient_cooldown_seconds', 15));
        }

        return $this->abort(); // 400 INVALID_ARGUMENT, 404 model, and the rest
    }

    private function quotaCooldown(AiProviderHttpException $e): int
    {
        $min = max(1, (int) config('ai.failover.min_cooldown_seconds', 5));
        $max = max($min, (int) config('ai.failover.max_cooldown_seconds', 3600));

        // A per-day quota does not come back in a minute. Google resets daily quotas
        // at midnight Pacific, so wait exactly until then (capped at 24h).
        foreach ($e->quotaIds as $id) {
            if (stripos($id, 'PerDay') !== false) {
                $now = Carbon::now('America/Los_Angeles');
                $seconds = (int) $now->diffInSeconds($now->copy()->addDay()->startOfDay(), false);

                return max($min, min(86400, $seconds));
            }
        }

        if ($e->retryAfterSeconds !== null && $e->retryAfterSeconds > 0) {
            return min($max, max($min, $e->retryAfterSeconds));
        }

        return min($max, max($min, (int) config('ai.failover.quota_cooldown_seconds', 60)));
    }

    /** @return array{action:'rotate', type:string, cooldown:int} */
    private function rotate(string $type, int $cooldown): array
    {
        return ['action' => 'rotate', 'type' => $type, 'cooldown' => max(1, $cooldown)];
    }

    /** @return array{action:'abort', type:string, cooldown:int} */
    private function abort(): array
    {
        return ['action' => 'abort', 'type' => 'request_error', 'cooldown' => 0];
    }
}
