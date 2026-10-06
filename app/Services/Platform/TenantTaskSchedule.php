<?php

namespace App\Services\Platform;

use Cron\CronExpression;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Which organisations a scheduled task should run for, right now.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * THIS IS THE HALF THAT MAKES THE OVERRIDE TABLE REAL
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * LMS K-12 has the table and the screen and stops there: nothing anywhere reads
 * `platform_scheduled_tasks`, and its own migration says `last_run_at` is
 * "written by the dispatcher" when no dispatcher exists. A configuration table
 * nothing consults is a preference nobody's software honours.
 *
 * So the two tenant-scoped commands ask this class who they are running for, and
 * a tenant that switched the task off is genuinely skipped.
 *
 * ── HOW A PER-TENANT CRON WORKS WITHOUT A PER-TENANT SCHEDULER ──────────────
 *
 * Laravel's scheduler has one entry per command, not one per tenant, and it
 * cannot grow one per organisation without the schedule itself becoming
 * tenant-aware. It does not need to. The command runs on the SHIPPED frequency —
 * `leave:escalate` hourly — and each tenant's own expression decides whether this
 * particular pass is theirs.
 *
 * That works because the console offers no granularity finer than the shipped
 * frequency: an hourly command can honour any per-tenant expression down to the
 * hour, and nothing lets a tenant ask for less than that. If a shipped frequency
 * is ever made coarser than what the console allows, this becomes a lie — which
 * is why `isDue()` compares against the command's own cadence rather than
 * assuming a minute.
 */
class TenantTaskSchedule
{
    private const OVERRIDES = 'g2g_platform_scheduled_tasks';

    public function __construct(private readonly PlatformRegistry $registry)
    {
    }

    /**
     * Filter a list of tenant ids to those this task should run for now.
     *
     * A tenant with no override is always included — no row means "follow the
     * schedule the application ships with", and the application has just decided
     * it is time.
     *
     * @param  array<int, int>  $tenantIds
     * @return array<int, int>
     */
    public function dueTenants(string $taskKey, array $tenantIds, ?\DateTimeInterface $now = null): array
    {
        if ($tenantIds === [] || ! Schema::hasTable(self::OVERRIDES)) {
            return $tenantIds;
        }

        /*
         * A task that is not tenant-scoped has no per-tenant answer to give. The
         * API refuses to store a row for one, so this should never find any — but
         * a row written by a migration or by hand must not silently change
         * behaviour for a command that cannot act on it.
         */
        if (! $this->registry->taskIsTenantScoped($taskKey)) {
            return $tenantIds;
        }

        $overrides = DB::table(self::OVERRIDES)
            ->where('task_key', $taskKey)
            ->whereIn('sub_institute_id', $tenantIds)
            ->get()
            ->keyBy('sub_institute_id');

        if ($overrides->isEmpty()) {
            return $tenantIds;
        }

        return array_values(array_filter(
            $tenantIds,
            function (int $tenantId) use ($overrides, $now) {
                $override = $overrides->get($tenantId);

                if ($override === null) {
                    return true;
                }

                // Switched off for this organisation. The clearest case, and the
                // one somebody is most likely to be relying on.
                if ($override->disabled) {
                    return false;
                }

                return $this->isDue($override, $now);
            }
        ));
    }

    /**
     * Whether a tenant's own expression fires in the current window.
     *
     * ── THE WINDOW, AND WHY IT IS NOT AN EXACT MATCH ────────────────────────
     *
     * `CronExpression::isDue()` asks whether the expression matches THIS MINUTE.
     * A scheduled command does not start on the exact second of the minute it was
     * due — the scheduler ticks, the process boots, other tasks run first — so an
     * exact-minute test drops runs at random depending on how busy the box was.
     *
     * So this asks a wider question: did the expression fire at any point since
     * the previous run of the shipped schedule? An hourly command therefore
     * catches anything due in the last hour, and a tenant whose expression names
     * minute 17 is served by the pass that starts at 17:00:04.
     *
     * An unparseable expression returns FALSE — the tenant is skipped rather than
     * swept on a schedule nobody can read. The console validates on write, so a
     * bad expression here means somebody wrote the row by hand, and guessing on
     * their behalf is worse than not running.
     */
    private function isDue(object $override, ?\DateTimeInterface $now): bool
    {
        $expression = implode(' ', [
            $override->minute,
            $override->hour,
            $override->day,
            $override->month,
            $override->day_of_week,
        ]);

        try {
            $cron = new CronExpression($expression);
            $now = $now ? \Illuminate\Support\Carbon::instance(\Carbon\Carbon::parse($now)) : now();

            // The window is the shipped cadence. Hourly commands look back an
            // hour; see the note above on why this is not a minute.
            $previous = $cron->getPreviousRunDate($now, 0, true);

            return $previous->getTimestamp() > $now->copy()->subHour()->getTimestamp();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Whether a task is switched off for a tenant, ignoring timing.
     *
     * For a command that sweeps in one pass and only needs to know who to leave
     * out, rather than who is due now.
     */
    public function isDisabledFor(string $taskKey, int $tenantId): bool
    {
        if (! Schema::hasTable(self::OVERRIDES)) {
            return false;
        }

        return DB::table(self::OVERRIDES)
            ->where('task_key', $taskKey)
            ->where('sub_institute_id', $tenantId)
            ->where('disabled', true)
            ->exists();
    }
}
