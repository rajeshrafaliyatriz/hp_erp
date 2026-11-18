<?php

namespace App\Domain\Signals\Ingestion;

use App\Jobs\SyncDataConnectorJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Closes connector syncs that can no longer finish, so a dead worker never leaves a connector blocked.
 *
 * A connector refuses a new sync while one is `queued` or `running`. Without this, a worker that is killed mid-sync
 * (the queue then retries the job, which sees `running` and exits) or a queue nobody is draining would block that
 * connector forever. Same pattern as ResearchRunReaper: `updated_at` is the heartbeat, closing is a compare-and-set.
 *
 *  - RUNNING but silent: SyncDataConnectorJob writes to its sync row as it goes (the claim, the fetch result, and a
 *    heartbeat at each item and before each AI chunk; see SyncDataConnectorJob::heartbeat). No write for longer than the
 *    job timeout plus the ACTIVE queue connection's `retry_after`, plus a minute's grace, means the worker is gone. With the
 *    shipped values (database queue, timeout 900 s, retry_after 960 s) that is 1,020 s: by 960 s the queue itself has given
 *    up on the reservation, so a live job that is genuinely working has long since written again. Recovery needs no schema
 *    change. Where the active connection has no `retry_after` (sync, sqs), only the job timeout plus the grace is used.
 *  - QUEUED and never picked up: no worker has started it. A first attempt gets QUEUED_GRACE_SECONDS (long enough for a
 *    busy worker to reach it, short enough to surface a missing worker). A sync waiting to retry after a rate limit or an
 *    outage carries its release delay (at most 900 s) on top.
 *
 * What recovery does, and does not do:
 *  - It only closes. It never starts, retries or re-analyses anything, so it cannot duplicate ingestion or AI spend.
 *    A job that arrives later finds the sync no longer `queued` and exits; a job that was merely slow finds it already
 *    closed and cannot overwrite it (see SyncDataConnectorJob::finish).
 *  - It touches only a row that is still `queued`/`running` AND still past the cut-off at the moment of the update
 *    (atomic compare-and-set), so two schedulers, or a sweep racing a finishing job, can close a sync at most once and
 *    never a finished one.
 *  - History is kept: the row is updated, never deleted, and keeps its counters. It records why, in `error_message`.
 *  - The connector's own state is not changed to an error: a dead worker is an infrastructure fault, not a broken
 *    account. Only `last_error` is set (so the card explains it) and the next scheduled time is pushed out.
 */
final class DataConnectorSyncReaper
{
    public const QUEUED_GRACE_SECONDS = 600;
    public const RUNNING_GRACE_SECONDS = 60;
    /** Longest release delay SyncDataConnectorJob applies when it re-queues itself for a retry. */
    public const MAX_RETRY_DELAY_SECONDS = 900;

    public const WORKER_MESSAGE = 'This sync was queued but no queue worker started it. An administrator must start the worker (php artisan queue:work --queue=signals --tries=4 --timeout=900); then run Sync Now again.';
    public const INTERRUPTED_MESSAGE = 'This sync stopped responding (the queue worker was most likely stopped or lost). It was closed so the connector is not blocked. Anything already saved was kept; run Sync Now again.';

    /** Queue drivers whose config carries a `retry_after` (the seconds before an unfinished reserved job is handed out again). */
    private const RETRY_AFTER_DRIVERS = ['database', 'redis', 'beanstalkd'];

    /**
     * The queue the sync job actually runs on, and how long that queue waits before handing a reserved job to another worker.
     * Read from the ACTIVE connection (the job's own, else `queue.default`), never from the database connection by assumption.
     *
     * `retry_after` is null for a driver that has none in its config (sync, null, sqs: the visibility timeout lives on the
     * queue itself, not in this app), in which case only the job timeout and the grace period are used. `safe` is false when
     * the queue would re-deliver a still-working job (retry_after <= the job timeout): the claim in SyncDataConnectorJob still
     * stops a second worker from running it, but that is a misconfiguration to fix, and sweeps log it.
     *
     * @return array{connection: string, driver: string, retry_after: ?int, job_timeout: int, safe: bool}
     */
    public static function queueDiagnostics(): array
    {
        $job = new SyncDataConnectorJob(0, 0, 0, null);
        $connection = (string) ($job->connection ?: config('queue.default', 'sync'));
        $driver = (string) config("queue.connections.{$connection}.driver", '');
        $retryAfter = null;
        if (in_array($driver, self::RETRY_AFTER_DRIVERS, true)) {
            $configured = config("queue.connections.{$connection}.retry_after");
            $retryAfter = is_numeric($configured) ? (int) $configured : null;
        }

        return [
            'connection' => $connection,
            'driver' => $driver,
            'retry_after' => $retryAfter,
            'job_timeout' => (int) $job->timeout,
            'safe' => $retryAfter === null || $retryAfter > (int) $job->timeout,
        ];
    }

    /** Seconds without a write after which a RUNNING sync is considered lost. */
    public static function runningThresholdSeconds(): int
    {
        $queue = self::queueDiagnostics();

        return max($queue['job_timeout'], (int) $queue['retry_after']) + self::RUNNING_GRACE_SECONDS;
    }

    /**
     * sweep() for callers that are doing something else (listing connectors, accepting Sync Now, dispatching the schedule).
     * Recovery is housekeeping: if it fails, that caller must still work and the failure must be visible in the log. Nothing
     * is reported as recovered when it was not: whatever the sweep did not close stays `queued`/`running`, and the caller's own
     * "is a sync already pending" check then answers truthfully. Only the sweep is guarded here; the caller's own work is not.
     *
     * @return int number of syncs closed (0 when the sweep itself failed)
     */
    public static function sweepSafely(?int $tenantId = null): int
    {
        try {
            return self::sweep($tenantId);
        } catch (\Throwable $e) {
            Log::error('signals.connector_sync_sweep_failed', [
                'tenant' => $tenantId,
                'exception' => get_class($e),
                'message' => mb_substr($e->getMessage(), 0, 300),
            ]);

            return 0;
        }
    }

    /** @return int number of syncs closed */
    public static function sweep(?int $tenantId = null): int
    {
        $closed = 0;
        self::warnIfQueueUnsafe();
        $runningCutoff = now()->subSeconds(self::runningThresholdSeconds());
        $queuedCutoff = now()->subSeconds(self::QUEUED_GRACE_SECONDS);
        $retryingCutoff = now()->subSeconds(self::QUEUED_GRACE_SECONDS + self::MAX_RETRY_DELAY_SECONDS);

        $candidates = DataConnectorSync::query()
            ->when($tenantId !== null, fn ($q) => $q->where('sub_institute_id', $tenantId))
            ->where(function ($q) use ($runningCutoff, $queuedCutoff, $retryingCutoff) {
                $q->where(fn ($r) => $r->where('status', 'running')->where('updated_at', '<=', $runningCutoff))
                    ->orWhere(fn ($r) => $r->where('status', 'queued')->whereNull('error_message')->where('updated_at', '<=', $queuedCutoff))
                    ->orWhere(fn ($r) => $r->where('status', 'queued')->whereNotNull('error_message')->where('updated_at', '<=', $retryingCutoff));
            })
            ->orderBy('id')
            ->limit(200)
            ->get();

        foreach ($candidates as $sync) {
            if (self::recover($sync, $runningCutoff, $queuedCutoff, $retryingCutoff)) {
                $closed++;
            }
        }

        return $closed;
    }

    private static function recover(DataConnectorSync $candidate, $runningCutoff, $queuedCutoff, $retryingCutoff): bool
    {
        $wasRunning = $candidate->status === 'running';
        $message = $wasRunning ? self::INTERRUPTED_MESSAGE : self::WORKER_MESSAGE;
        // A sync that had already processed items before it was lost is reported as partial, not as a clean failure.
        $status = $wasRunning && (int) $candidate->records_processed > 0 ? 'partial' : 'failed';
        $attributes = [
            'status' => $status,
            'error_message' => mb_substr($message, 0, 500),
            'completed_at' => now(),
            'updated_at' => now(),
        ];
        if ($status === 'failed' && (int) $candidate->records_failed === 0) {
            $attributes['records_failed'] = max(1, (int) $candidate->records_fetched);
        }

        // Compare-and-set: the same row, still in the same state, still past the same cut-off. Whoever loses finds 0 rows.
        $claimed = DataConnectorSync::where('sub_institute_id', $candidate->sub_institute_id)
            ->whereKey($candidate->id)
            ->where('status', $candidate->status)
            ->where('updated_at', '<=', $wasRunning
                ? $runningCutoff
                : ($candidate->error_message === null ? $queuedCutoff : $retryingCutoff))
            ->update($attributes);
        if ($claimed !== 1) {
            return false;
        }

        // The sync row is closed from here on. Housekeeping around it must not turn a successful close into an error.
        try {
            $connector = DataConnector::where('sub_institute_id', $candidate->sub_institute_id)->whereKey($candidate->connector_id)->first();
            if ($connector) {
                $changes = ['last_sync_at' => now(), 'last_error' => mb_substr($message, 0, 500)];
                if ($connector->schedule_enabled) {
                    $changes['next_sync_at'] = now((string) ($connector->schedule_timezone ?: 'UTC'))
                        ->addMinutes(max(15, (int) ($connector->schedule_interval_minutes ?: 60)));
                }
                $connector->forceFill($changes)->save();
            }
            self::releaseLostLock($candidate);
        } catch (\Throwable $e) {
            Log::error('signals.connector_sync_recovery_cleanup_failed', [
                'tenant' => $candidate->sub_institute_id,
                'connector' => $candidate->connector_id,
                'sync' => $candidate->id,
                'exception' => get_class($e),
                'message' => mb_substr($e->getMessage(), 0, 300),
            ]);
        }

        Log::warning('signals.connector_sync_recovered', [
            'tenant' => $candidate->sub_institute_id,
            'connector' => $candidate->connector_id,
            'sync' => $candidate->id,
            'was' => $candidate->status,
            'now' => $status,
        ]);

        return true;
    }

    /**
     * Frees the per-connector lock of the sync that was just closed, and ONLY that sync's lock.
     *
     * The job records the lock's owner token under a key of its own sync id when it acquires the lock. Here that token is
     * restored and released with the ownership-checked `release()`: if the lock has since been taken by a newer sync (a
     * different token), it is left alone. Unlike `forceRelease()`, this can never free a newer sync's lock. With no token on
     * record (the job never got as far as taking the lock, or the cache was cleared) nothing is released and the lock simply
     * expires with its own TTL.
     */
    private static function releaseLostLock(DataConnectorSync $closed): void
    {
        $ownerKey = SyncDataConnectorJob::lockOwnerKey((int) $closed->id);
        $owner = Cache::get($ownerKey);
        if (is_string($owner) && $owner !== '') {
            Cache::restoreLock(SyncDataConnectorJob::lockName((int) $closed->sub_institute_id, (int) $closed->connector_id), $owner)->release();
        }
        Cache::forget($ownerKey);
    }

    /** Logs (at most once an hour) when the active queue would re-deliver a job that is still working. */
    private static function warnIfQueueUnsafe(): void
    {
        $queue = self::queueDiagnostics();
        if ($queue['safe']) {
            return;
        }
        try {
            if (! Cache::add('signals:connector-queue-retry-after-warned', 1, 3600)) {
                return;
            }
        } catch (\Throwable) {
            // a cache that cannot throttle the warning must not stop the sweep: warn every time instead
        }
        Log::warning('signals.connector_queue_retry_after_unsafe', $queue);
    }
}
