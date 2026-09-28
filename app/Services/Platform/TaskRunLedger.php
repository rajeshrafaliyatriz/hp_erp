<?php

namespace App\Services\Platform;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Stringable;
use Throwable;

/**
 * Writes the run ledger `ScheduleReader` has always been able to read.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHY start() AND finish() CANNOT SHARE AN IN-MEMORY VARIABLE
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * For a `runInBackground()` task, `before()` runs synchronously inside `schedule:run`,
 * then the command is handed to a detached shell process. `after()` only fires once THAT
 * process exits and shells out to `php artisan schedule:finish`, which re-boots
 * `routes/console.php` from scratch as a brand new PHP process. No object survives that
 * handoff — a static property would simply be unset in the new process. So the row itself
 * is the only channel between `before()` and `after()`: `start()` inserts one, and
 * `finish()` finds it again by `(task_key, status IS NULL)`, taking the most recently
 * started one. That is unambiguous because every scheduled task in `routes/console.php`
 * carries `withoutOverlapping()`, so a second run of the same task never starts while an
 * unfinished row for it still exists.
 *
 * ── NEVER LETS A LEDGER FAILURE TAKE THE TASK DOWN WITH IT ──────────────────
 *
 * Both methods swallow their own exceptions. A scheduled command that does real work
 * (escalating leave, draining events) must not fail BECAUSE the bookkeeping around it
 * failed — that would make the cure worse than the disease this table was built to expose.
 */
class TaskRunLedger
{
    private const TABLE = 'g2g_platform_task_runs';

    /**
     * Wire a scheduled event's `before`/`after` hooks to this ledger.
     *
     * Called from `routes/console.php` around every `Schedule::command(...)` entry.
     * `$event` is captured by the closures below via `use`, which is safe here even
     * though `after()` may run in a different process — `routes/console.php` is
     * re-executed from scratch in that process too, so the closure that runs there closes
     * over ITS OWN fresh `$event` instance, not a stale reference from another process.
     */
    public static function track(Event $event, string $taskKey): Event
    {
        return $event
            ->before(function () use ($taskKey) {
                self::start($taskKey);
            })
            ->after(function (Stringable $output) use ($event, $taskKey) {
                self::finish($taskKey, (int) ($event->exitCode ?? 0), (string) $output);
            });
    }

    /**
     * Every scheduled entry in `routes/console.php` runs with no `--tenant`, whether or
     * not the underlying command CAN take one — the two tenant-scoped commands still
     * process every eligible tenant in one pass when the scheduler invokes them, consulting
     * per-tenant overrides internally. So every hook registered via `track()` is estate-wide
     * by construction, and `$tenantId` here is only ever set by the "Run now" action, which
     * genuinely does target one tenant.
     */
    public static function start(string $taskKey, ?int $tenantId = null): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        try {
            DB::table(self::TABLE)->insert([
                'task_key' => $taskKey,
                'sub_institute_id' => $tenantId,
                // Explicit millisecond formatting, not a bare `now()` — the column is
                // DATETIME(3), but Carbon's default __toString() (what a raw Carbon
                // object collapses to going through the query builder) is
                // 'Y-m-d H:i:s' with no fractional part, which would silently round
                // every row to the second. Same pattern EventRecorder uses for
                // occurred_at/recorded_at.
                'started_at' => now()->format('Y-m-d H:i:s.v'),
                'host' => gethostname() ?: null,
            ]);
        } catch (Throwable) {
            // See class note: a ledger write must never be why a scheduled task fails.
        }
    }

    public static function finish(string $taskKey, int $exitCode, string $output = ''): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        try {
            $row = DB::table(self::TABLE)
                ->where('task_key', $taskKey)
                ->whereNull('status')
                ->orderByDesc('started_at')
                ->orderByDesc('id')
                ->first(['id', 'started_at']);

            // No matching "started" row is a real possibility — e.g. the table was
            // created after this run's before() hook had already skipped writing one.
            // Nothing to close out, so nothing is written rather than guessing a start
            // time this run never had.
            if ($row === null) {
                return;
            }

            $startedAt = Carbon::parse($row->started_at);
            $finishedAt = now();

            DB::table(self::TABLE)->where('id', $row->id)->update([
                'finished_at' => $finishedAt->format('Y-m-d H:i:s.v'),
                'status' => $exitCode === 0 ? 'ok' : 'failed',
                'exit_code' => $exitCode,
                'duration_ms' => $startedAt->diffInMilliseconds($finishedAt),
                'output_head' => $output === '' ? null : mb_substr(trim($output), 0, 2000),
            ]);
        } catch (Throwable) {
            // See class note.
        }
    }
}
