<?php

namespace App\Domain\Gtm\Workflows;

use App\Domain\Gtm\GtmState;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the clock that drives the automations is really running, answered only from what the scheduler itself recorded and never guessed.
 *
 * An automation starts when the event runner (`events:react`, every 10 minutes) delivers the event it waits for, and its retries and timed checks come from
 * the GTM pass (`gtm:tick`, every 5 minutes). Both are registered in routes/console.php through TaskRunLedger, which writes one row per run into
 * g2g_platform_task_runs; the tick also leaves `last_tick_at` in gtm_system_state. This class only SELECTs those two places.
 *
 *   running  both were recorded and neither is late
 *   stale    something was recorded and it is late (three runs in a row missed): the scheduler has stopped, so automations wait
 *   unknown  nothing was recorded, a record is missing, or the table is not there. Never reported as running: no record is not good news.
 *
 * Times are installation-wide (a scheduled command belongs to the application, not an organisation), so nothing here is tenant data.
 */
final class WorkflowHealth
{
    public const EXPECTED_EVERY_MINUTES = 10;

    /** events:react runs every 10 minutes and gtm:tick every 5: three runs in a row missed is "not running" */
    public const EVENTS_STALE_AFTER_MINUTES = 30;

    public const TICK_STALE_AFTER_MINUTES = 15;

    /** the keys the run ledger uses: TaskRunLedger::track() writes the dotted one, the scheduler listener writes the command name */
    private const EVENTS_KEYS = ['events.react', 'events:react'];

    private const TICK_KEYS = ['gtm.tick', 'gtm:tick'];

    /** @return array{state: string, last_tick_at: ?string, last_events_at: ?string, expected_every_minutes: int, message: string} */
    public static function check(?CarbonInterface $now = null): array
    {
        $now ??= Carbon::now();
        $events = self::lastRun(self::EVENTS_KEYS);
        $tick = self::latest(self::lastRun(self::TICK_KEYS), self::lastTickState());

        $eventsAge = $events === null ? null : self::minutes($events, $now);
        $tickAge = $tick === null ? null : self::minutes($tick, $now);

        if ($events === null && $tick === null) {
            $state = 'unknown';
            $message = 'Not known: the scheduler has left no record here, so we cannot tell whether automations are being checked.';
        } elseif ($eventsAge !== null && $eventsAge > self::EVENTS_STALE_AFTER_MINUTES) {
            $state = 'stale';
            $message = 'The scheduler has not run for '.self::ago($events, $now).', so automations wait.';
        } elseif ($tickAge !== null && $tickAge > self::TICK_STALE_AFTER_MINUTES) {
            $state = 'stale';
            $message = 'The scheduler\'s timed pass has not run for '.self::ago($tick, $now).', so retries and timed checks wait.';
        } elseif ($events === null) {
            $state = 'unknown';
            $message = 'Not known: there is no record of the event check running, so we cannot tell whether automations are being checked.';
        } elseif ($tick === null) {
            $state = 'unknown';
            $message = 'Automations are checked about every '.self::EXPECTED_EVERY_MINUTES.' minutes (last check '.self::ago($events, $now).' ago), but there is no record of the timed pass, so we cannot be sure everything is running.';
        } else {
            $state = 'running';
            $message = 'Checked about every '.self::EXPECTED_EVERY_MINUTES.' minutes. Last check '.self::ago($events, $now).' ago.';
        }

        return ['state' => $state, 'last_tick_at' => $tick?->toIso8601String(), 'last_events_at' => $events?->toIso8601String(), 'expected_every_minutes' => self::EXPECTED_EVERY_MINUTES, 'message' => $message];
    }

    /** @param list<string> $keys */
    private static function lastRun(array $keys): ?Carbon
    {
        try {
            if (! Schema::hasTable('g2g_platform_task_runs')) {
                return null;
            }
            // a run that started and never reported (status null) did start; a skipped one did not run
            $at = DB::table('g2g_platform_task_runs')->whereIn('task_key', $keys)
                ->where(fn ($q) => $q->whereNull('status')->orWhereIn('status', ['ok', 'failed']))->max('started_at');

            return $at ? Carbon::parse((string) $at) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function lastTickState(): ?Carbon
    {
        try {
            if (! Schema::hasTable('gtm_system_state')) {
                return null;
            }
            $at = GtmState::get('last_tick_at');

            return $at ? Carbon::parse($at) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function latest(?Carbon $a, ?Carbon $b): ?Carbon
    {
        return $a === null ? $b : ($b === null ? $a : ($b->greaterThan($a) ? $b : $a));
    }

    private static function minutes(Carbon $at, CarbonInterface $now): float
    {
        return max(0, $now->getTimestamp() - $at->getTimestamp()) / 60;
    }

    /** "less than a minute", "3 minutes", "2 hours", "2 days" */
    private static function ago(Carbon $at, CarbonInterface $now): string
    {
        $seconds = max(0, $now->getTimestamp() - $at->getTimestamp());
        $unit = fn (int $n, string $word) => $n.' '.$word.($n === 1 ? '' : 's');

        return match (true) {
            $seconds < 60 => 'less than a minute',
            $seconds < 3600 => $unit(intdiv($seconds, 60), 'minute'),
            $seconds < 86400 => $unit(intdiv($seconds, 3600), 'hour'),
            default => $unit(intdiv($seconds, 86400), 'day'),
        };
    }
}
