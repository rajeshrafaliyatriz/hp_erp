<?php

namespace App\Listeners;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Writes `g2g_platform_task_runs`.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHY ONE LISTENER AND NOT SIX `->onSuccess()` CALLS
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * The obvious way to record runs is to chain `->onSuccess(...)->onFailure(...)` onto
 * each entry in `routes/console.php`. That is six edits, and — more to the point — it
 * is six edits that must be remembered for the SEVENTH task somebody adds next year.
 * The failure is silent: the new task simply never appears to have run, and the console
 * reports it as unknown forever.
 *
 * Laravel already emits `ScheduledTaskStarting`, `ScheduledTaskFinished`,
 * `ScheduledTaskFailed` and `ScheduledTaskSkipped` for every scheduled task. Listening
 * once covers everything registered now and everything registered later, which is the
 * property that matters.
 *
 * ── IT NEVER THROWS ─────────────────────────────────────────────────────────
 *
 * Every method swallows its own failures. This is instrumentation: a ledger that can
 * break the job it is documenting is worse than no ledger, and a full disk or a missing
 * table must not be able to stop the event store draining. `AuditLog::record()` in this
 * codebase takes the same position for the same reason.
 *
 * ── SKIPPED IS RECORDED, AND IT MATTERS ─────────────────────────────────────
 *
 * `withoutOverlapping()` skips a task whose previous run is still going. A task that is
 * skipped every time is a task that is permanently stuck, and without this event it
 * looks identical to one that is simply not due. That is exactly the shape of failure
 * this whole console exists to surface.
 */
class RecordScheduledTaskRun
{
    private const TABLE = 'g2g_platform_task_runs';

    /** Run ids by task key, so `finished` can close the row `starting` opened. */
    private static array $open = [];

    public function starting(ScheduledTaskStarting $event): void
    {
        $this->guard(function () use ($event) {
            $key = $this->keyFor($event->task);

            $id = DB::table(self::TABLE)->insertGetId([
                'task_key' => $key,
                // NULL: a scheduled command belongs to the installation, not a tenant.
                'sub_institute_id' => null,
                'started_at' => now()->format('Y-m-d H:i:s.v'),
                'host' => gethostname() ?: null,
            ]);

            self::$open[$key] = $id;
        });
    }

    public function finished(ScheduledTaskFinished $event): void
    {
        $this->close($event->task, $event->runtime, 'ok', 0);
    }

    public function failed(ScheduledTaskFailed $event): void
    {
        $this->close($event->task, null, 'failed', 1);
    }

    public function skipped(ScheduledTaskSkipped $event): void
    {
        $this->guard(function () use ($event) {
            $key = $this->keyFor($event->task);

            // A skip has no duration and no exit code — it did not run. Recorded as its
            // own status rather than folded into 'failed': a skip is the scheduler
            // working as configured, and calling it a failure would cry wolf on every
            // overlapping long job.
            DB::table(self::TABLE)->insert([
                'task_key' => $key,
                'sub_institute_id' => null,
                'started_at' => now()->format('Y-m-d H:i:s.v'),
                'finished_at' => now()->format('Y-m-d H:i:s.v'),
                'status' => 'skipped',
                'host' => gethostname() ?: null,
                'output_head' => 'Skipped: the previous run had not finished.',
            ]);
        });
    }

    private function close(ScheduledEvent $task, ?float $runtime, string $status, int $exitCode): void
    {
        $this->guard(function () use ($task, $runtime, $status, $exitCode) {
            $key = $this->keyFor($task);
            $id = self::$open[$key] ?? null;

            $values = [
                'finished_at' => now()->format('Y-m-d H:i:s.v'),
                'status' => $status,
                'exit_code' => $exitCode,
                'duration_ms' => $runtime === null ? null : (int) round($runtime * 1000),
                'output_head' => $this->output($task),
            ];

            if ($id !== null) {
                DB::table(self::TABLE)->where('id', $id)->update($values);
                unset(self::$open[$key]);

                return;
            }

            // No open row — `starting` did not fire, which happens when the task is run
            // directly rather than through the scheduler. Recorded as a complete row
            // rather than dropped: a run that happened is worth more than a tidy pair.
            DB::table(self::TABLE)->insert($values + [
                'task_key' => $key,
                'sub_institute_id' => null,
                'started_at' => now()->format('Y-m-d H:i:s.v'),
                'host' => gethostname() ?: null,
            ]);
        });
    }

    /**
     * The same key `ScheduleReader` derives, so the two agree without translation.
     *
     * Duplicating that logic would be two parsers of one string, and the one that
     * drifts is the one that makes the console silently report nothing for a task.
     */
    private function keyFor(ScheduledEvent $task): string
    {
        $command = $task->command;

        if (is_string($command) && preg_match('/artisan[\'"]?\s+(?:[\'"])?([a-z0-9:_-]+)/i', $command, $matches) === 1) {
            return $matches[1];
        }

        return 'event:' . substr(sha1($task->expression . '|' . $task->getSummaryForDisplay()), 0, 12);
    }

    /**
     * The first part of whatever the task wrote, where it was sent to a file.
     *
     * Bounded to 2,000 characters: a chatty command must not be able to fill the table,
     * and the first lines are where the useful part of an error is.
     */
    private function output(ScheduledEvent $task): ?string
    {
        $path = $task->output ?? null;

        if (! is_string($path) || $path === '' || $path === '/dev/null' || ! is_file($path)) {
            return null;
        }

        try {
            $contents = (string) file_get_contents($path, false, null, 0, 4000);
        } catch (Throwable) {
            return null;
        }

        $contents = trim($contents);

        return $contents === '' ? null : mb_substr($contents, 0, 2000);
    }

    /**
     * Runs the write, and swallows anything it throws.
     *
     * Also checks the table exists, so a deployment that has not run the migration
     * degrades to recording nothing rather than failing every scheduled task on the box.
     */
    private function guard(callable $write): void
    {
        try {
            if (! Schema::hasTable(self::TABLE)) {
                return;
            }

            $write();
        } catch (Throwable) {
            // Deliberately silent. See the class note.
        }
    }
}
