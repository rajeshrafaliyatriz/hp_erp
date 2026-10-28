<?php

namespace App\Domain\Signals\Opportunities;

/**
 * Closes research runs that can no longer finish, so a screen never spins on a run that is dead.
 *
 *  - QUEUED and never started: the job is waiting but no worker has picked it up for `signals.queue_pickup_seconds`. The cause is almost always that
 *    no queue worker is running. The run is failed as `worker_not_running` with the command that fixes it. (The queued job itself is left alone; if a
 *    worker starts later it finds the run already closed and does not research.)
 *  - RUNNING but silent: a live run writes its progress as it goes (its `updated_at` is the heartbeat). One that has not for
 *    `signals.stale_run_minutes` was killed or lost, and is closed as `timed_out`.
 *
 * It only ever changes a run that is still `running`, never a finished one, and only closes it: nothing is started, retried or charged.
 */
final class ResearchRunReaper
{
    public const WORKER_MESSAGE = 'The run was queued but no queue worker started it. Start the worker (php artisan queue:work --queue=signals,default --tries=1 --timeout=900) and press Generate Signals again.';
    public const TIMEOUT_MESSAGE = 'The run stopped sending progress and was closed as timed out. Nothing it had already saved was removed. Press Generate Signals to try again.';

    /** @return int number of runs closed */
    public static function sweep(int $tenantId): int
    {
        $closed = 0;
        $pickup = max(30, (int) config('signals.queue_pickup_seconds', 180));
        $stale = max(1, (int) config('signals.stale_run_minutes', 15));

        $runs = ResearchRun::where('sub_institute_id', $tenantId)->where('status', 'running')->get();
        foreach ($runs as $run) {
            $silent = $run->updated_at ?? $run->started_at ?? $run->created_at;
            if ($silent === null) {
                continue;
            }
            if ($run->stage === 'queued' && $silent->lt(now()->subSeconds($pickup))) {
                self::close($run, 'worker_not_running', self::WORKER_MESSAGE);
                $closed++;
            } elseif ($run->stage !== 'queued' && $silent->lt(now()->subMinutes($stale))) {
                self::close($run, 'timed_out', self::TIMEOUT_MESSAGE);
                $closed++;
            }
        }

        return $closed;
    }

    private static function close(ResearchRun $run, string $code, string $message): void
    {
        // Compare-and-set: a run that finished a moment ago must not be overwritten.
        ResearchRun::where('id', $run->id)->where('status', 'running')->update([
            'status' => 'failed', 'stage' => 'failed', 'stage_message' => $message, 'error_code' => $code, 'error_message' => $message,
            'completed_at' => now(), 'duration_ms' => max(0, (int) round(($run->started_at ?? now())->diffInMilliseconds(now(), true))), 'updated_at' => now(),
        ]);
    }
}
