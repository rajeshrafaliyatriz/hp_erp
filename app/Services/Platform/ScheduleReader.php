<?php

namespace App\Services\Platform;

use Cron\CronExpression;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * What this application runs on a timer.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * IT READS THE LIVE SCHEDULE, NOT A LIST SOMEBODY TYPED
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * The obvious implementation is an array of the six commands in `routes/console.php`.
 * It is also the one that goes wrong: the seventh entry gets added to the schedule and
 * not to the array, and the console then reports a complete picture that is missing the
 * task somebody is looking for. A monitoring screen that silently omits a row is worse
 * than no screen.
 *
 * So this resolves Laravel's own `Schedule` and walks `events()`. Whatever is actually
 * registered is what is reported, including anything a future route file adds.
 *
 * There is precedent for getting this wrong in this codebase. `app/Console/Kernel.php`
 * still exists, still looks authoritative, and its `schedule()` has NEVER RUN on
 * Laravel 11+ — three entries were written there and silently never registered, and
 * `events:project` was believed to be draining the store every five minutes while in
 * fact only running when somebody typed it. Reading the live schedule is what makes a
 * repeat of that visible rather than invisible.
 *
 * ── LAST RUN IS HONESTLY UNKNOWN, FOR NOW ───────────────────────────────────
 *
 * Nothing records a scheduled pass: no `onSuccess`/`onFailure` hook, no run ledger. So
 * `last_run_at` is null and `available.last_run` is false, rather than a fabricated
 * "never". Phase 3 adds `g2g_platform_task_runs` and a listener, and this class starts
 * answering it.
 *
 * ── NEXT RUN IS COMPUTED, NEVER STORED ──────────────────────────────────────
 *
 * A stored next-run goes stale the moment the expression changes, and a stale timestamp
 * is indistinguishable from a fresh one on screen. `dragonmantank/cron-expression` is
 * already a dependency (Laravel's scheduler uses it), so this costs nothing.
 */
class ScheduleReader
{
    private const RUNS = 'g2g_platform_task_runs';

    private const OVERRIDES = 'g2g_platform_scheduled_tasks';

    public function __construct(
        private readonly Application $app,
        private readonly PlatformRegistry $registry,
    ) {
    }

    /**
     * This tenant's overrides, keyed by catalogue task key.
     *
     * @return array<string, object>
     */
    private function overridesFor(int $tenantId): array
    {
        if (! Schema::hasTable(self::OVERRIDES)) {
            return [];
        }

        return DB::table(self::OVERRIDES)
            ->where('sub_institute_id', $tenantId)
            ->get()
            ->keyBy('task_key')
            ->all();
    }

    /** An override row's five fields as one expression. */
    private function expressionOf(object $override): string
    {
        return implode(' ', [
            $override->minute,
            $override->hour,
            $override->day,
            $override->month,
            $override->day_of_week,
        ]);
    }

    /**
     * The schedule, with `routes/console.php` guaranteed to have been loaded.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * WHY THIS IS NOT JUST AN INJECTED `Schedule`
     * ═══════════════════════════════════════════════════════════════════════
     *
     * It was, and it returned an EMPTY LIST over HTTP while working perfectly from the
     * command line — which is the worst shape a bug can take, because every CLI check
     * passes and the screen shows "0 scheduled tasks" as though that were the answer.
     *
     * `bootstrap/app.php` registers `routes/console.php` through `withRouting(commands:)`.
     * `ApplicationBuilder::withCommands()` only records the PATH, on an
     * `afterResolving(ConsoleKernel::class)` hook; the file itself is `require`d by
     * `Kernel::discoverCommands()`, which runs from `commands()` during the console
     * kernel's own `bootstrap()`. A web request does none of that, so `Schedule` is
     * real, injectable, and empty.
     *
     * Merely resolving the kernel is NOT enough — that registers the path and stops.
     * `bootstrap()` is the call that requires the file and defines the schedule. (The
     * first fix here resolved the kernel and nothing else, and still returned zero
     * tasks; it is an easy half-step to stop on.)
     *
     * `commandsLoaded` guards the kernel against doing this twice, so the cost is paid
     * once per request at most, and only on the two routes that read the schedule.
     */
    private function schedule(): Schedule
    {
        $this->app->make(ConsoleKernel::class)->bootstrap();

        return $this->app->make(Schedule::class);
    }

    /**
     * Every registered scheduled task.
     *
     * @return array<string, mixed>
     */
    public function tasks(?int $tenantId = null): array
    {
        $runs = $this->lastRuns();
        $hasLedger = Schema::hasTable(self::RUNS);
        $overrides = $tenantId === null ? [] : $this->overridesFor($tenantId);

        $tasks = [];

        foreach ($this->schedule()->events() as $event) {
            $key = $this->keyFor($event);
            $last = $runs[$key] ?? null;

            /*
             * Join the live schedule to the catalogue by COMMAND NAME.
             *
             * The schedule is the truth about what runs; the catalogue is the
             * truth about what a tenant may change. A scheduled event the
             * catalogue does not describe is still reported — it runs, so hiding
             * it would be the silent omission this class exists to prevent — it
             * simply cannot be overridden.
             */
            $declared = $this->registry->taskForCommand((string) $this->commandFor($event));
            $taskKey = $declared['key'] ?? null;
            $tenantScoped = $taskKey !== null && $this->registry->taskIsTenantScoped($taskKey);
            $override = $taskKey === null ? null : ($overrides[$taskKey] ?? null);

            $tasks[] = [
                'key' => $key,
                'command' => $this->commandFor($event),
                // The catalogue key, which is what a write addresses. Null for an
                // event nobody declared.
                'task_key' => $taskKey,
                'label' => $declared['task']['label'] ?? null,
                /*
                 * Whether this organisation may change it, and why not.
                 *
                 * Four of the six commands process every tenant in one pass, so a
                 * per-tenant schedule for them is not a thing that can exist. The
                 * screen says so rather than offering a control that would be
                 * accepted and ignored.
                 */
                'tenant_scoped' => $tenantScoped,
                'estate_reason' => $tenantScoped
                    ? null
                    : ($declared['task']['estate_reason'] ?? 'This task is not configurable per organisation.'),
                'overridden' => $override !== null,
                'disabled_here' => (bool) ($override->disabled ?? false),
                'override_updated_by' => $override->updated_by ?? null,
                'description' => $event->description,
                /*
                 * The EFFECTIVE expression — the tenant's override where there is
                 * one, and the shipped schedule otherwise.
                 *
                 * `shipped_expression` is kept beside it so the screen can show
                 * what was changed from. A row that only showed the override would
                 * leave somebody unable to tell whether they had drifted from the
                 * default or were still on it.
                 */
                'expression' => $override !== null
                    ? $this->expressionOf($override)
                    : $event->expression,
                'shipped_expression' => $event->expression,
                'schedule' => $this->fields(
                    $override !== null ? $this->expressionOf($override) : $event->expression
                ),
                'describes' => $this->describe(
                    $override !== null ? $this->expressionOf($override) : $event->expression
                ),
                'timezone' => $this->timezoneFor($event),
                /*
                 * Computed from the EFFECTIVE expression, and null when this
                 * organisation has switched the task off.
                 *
                 * Showing the shipped next-run beside an override would be a time
                 * the task will not run, which on an operations screen is worse
                 * than showing nothing.
                 */
                'next_run_at' => ($override !== null && $override->disabled)
                    ? null
                    : $this->nextRun(
                        $override !== null ? $this->expressionOf($override) : $event->expression,
                        $this->timezoneFor($event)
                    ),
                // These three are what make a stalled task diagnosable: a task that
                // cannot overlap and is stuck reports differently from one that is
                // simply due.
                'without_overlapping' => $event->withoutOverlapping,
                'on_one_server' => $event->onOneServer,
                'in_background' => $event->runInBackground,
                'last_run_at' => $last->finished_at ?? null,
                'last_run_status' => $last->status ?? null,
                'last_run_duration_ms' => isset($last->duration_ms) ? (int) $last->duration_ms : null,
                'available' => [
                    // False until the ledger exists AND has a row for this task. A
                    // ledger with no rows yet is still "we do not know".
                    'last_run' => $hasLedger && $last !== null,
                ],
                'source' => 'routes/console.php',
            ];
        }

        usort($tasks, fn ($a, $b) => strcmp($a['key'], $b['key']));

        return [
            'tasks' => $tasks,
            'summary' => [
                'total' => count($tasks),
                'failing' => count(array_filter($tasks, fn ($t) => $t['last_run_status'] === 'failed')),
                'never_run' => count(array_filter($tasks, fn ($t) => ! $t['available']['last_run'])),
                // How many of these this organisation could change at all. The
                // honest headline: most of them it cannot.
                'configurable' => count(array_filter($tasks, fn ($t) => $t['tenant_scoped'])),
                'overridden' => count(array_filter($tasks, fn ($t) => $t['overridden'])),
                'disabled_here' => count(array_filter($tasks, fn ($t) => $t['disabled_here'])),
            ],
            'ledger_installed' => $hasLedger,
            'queue' => $this->queue(),
        ];
    }

    /**
     * The queue, which is adjacent and estate-wide.
     *
     * `QUEUE_CONNECTION` defaults to `database` here, so `jobs` and `failed_jobs` are
     * real tables worth reporting — unlike LMS K-12, where the connection is `sync` and
     * they are always empty.
     *
     * `tenant_scoped: false` IS LOAD-BEARING. Neither table has a `sub_institute_id`,
     * so these counts are for the whole estate. The screen must label them that way; an
     * administrator reading "12 failed jobs" as their organisation's would be reading
     * somebody else's number.
     *
     * @return array<string, mixed>
     */
    private function queue(): array
    {
        $pending = Schema::hasTable('jobs') ? DB::table('jobs')->count() : null;
        $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : null;

        return [
            'connection' => (string) config('queue.default'),
            'pending' => $pending,
            'failed' => $failed,
            'tenant_scoped' => false,
        ];
    }

    /**
     * The most recent run of each task.
     *
     * @return array<string, object>
     */
    private function lastRuns(): array
    {
        if (! Schema::hasTable(self::RUNS)) {
            return [];
        }

        $rows = DB::table(self::RUNS)
            ->select('task_key', 'status', 'finished_at', 'duration_ms')
            ->orderByDesc('started_at')
            ->get();

        $latest = [];

        foreach ($rows as $row) {
            $latest[$row->task_key] ??= $row;
        }

        return $latest;
    }

    /**
     * A stable key for a scheduled event.
     *
     * The artisan command name where there is one (`events:project`), because that is
     * what an operator would type and what the run ledger records. A closure or exec
     * event has no such name, so it falls back to a hash of its expression and summary
     * — stable across deployments, and never colliding with a real command name.
     */
    private function keyFor(Event $event): string
    {
        $command = $this->commandFor($event);

        if ($command !== null) {
            return $command;
        }

        return 'event:' . substr(sha1($event->expression . '|' . $event->getSummaryForDisplay()), 0, 12);
    }

    /**
     * The artisan command a scheduled event runs, without the PHP binary and artisan
     * path Laravel prefixes onto it.
     */
    private function commandFor(Event $event): ?string
    {
        $command = $event->command;

        if (! is_string($command) || $command === '') {
            return null;
        }

        // Laravel builds '"php" "artisan" events:project'. Strip the two quoted
        // prefixes and any option arguments, leaving the command's own name.
        if (preg_match('/artisan[\'"]?\s+(?:[\'"])?([a-z0-9:_-]+)/i', $command, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /** @return array<string, string> */
    private function fields(string $expression): array
    {
        $parts = preg_split('/\s+/', trim($expression)) ?: [];

        return [
            'minute' => $parts[0] ?? '*',
            'hour' => $parts[1] ?? '*',
            'day' => $parts[2] ?? '*',
            'month' => $parts[3] ?? '*',
            'day_of_week' => $parts[4] ?? '*',
        ];
    }

    private function timezoneFor(Event $event): string
    {
        return (string) ($event->timezone ?? config('app.timezone', 'UTC'));
    }

    /**
     * When this will next run.
     *
     * Returns null rather than throwing on an expression the parser rejects: one
     * unparseable entry must not take down the page that would have shown you which
     * entry it was.
     */
    private function nextRun(string $expression, string $timezone): ?string
    {
        try {
            $next = (new CronExpression($expression))
                ->getNextRunDate('now', 0, false, $timezone);

            return $next->format(DATE_ATOM);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The expression as a sentence, for the common shapes.
     *
     * Deliberately partial. It covers what this application actually schedules and
     * returns null for anything else, rather than attempting a general cron-to-English
     * translator that would be wrong in ways nobody would notice — the raw expression is
     * shown beside it either way, so null costs a convenience and never the truth.
     */
    private function describe(string $expression): ?string
    {
        $fields = $this->fields($expression);

        if ($expression === '* * * * *') {
            return 'Every minute';
        }

        if (preg_match('#^\*/(\d+) \* \* \* \*$#', $expression, $m) === 1) {
            return 'Every ' . $m[1] . ' minutes';
        }

        if ($fields['hour'] === '*' && $fields['day'] === '*' && $fields['month'] === '*' && $fields['day_of_week'] === '*') {
            return $fields['minute'] === '0'
                ? 'Hourly, on the hour'
                : 'Hourly, at ' . $fields['minute'] . ' minutes past';
        }

        if (
            ctype_digit($fields['minute']) && ctype_digit($fields['hour'])
            && $fields['day'] === '*' && $fields['month'] === '*' && $fields['day_of_week'] === '*'
        ) {
            return sprintf('Daily at %02d:%02d', (int) $fields['hour'], (int) $fields['minute']);
        }

        return null;
    }
}
