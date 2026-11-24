<?php

namespace App\Domain\Intelligence\Detectors\Platform;

use App\Domain\Intelligence\Access\IntelligenceCaller;
use App\Domain\Intelligence\Coverage;
use App\Domain\Intelligence\DetectorResult;
use App\Domain\Intelligence\Evidence;
use App\Domain\Intelligence\Insight;
use App\Domain\Intelligence\Support\DetectionClock;
use App\Domain\Intelligence\Taxonomy;

/**
 * Scheduled tasks that fail, are stuck, never close, or a scheduler that has gone silent. Needs `view` on a Platform Services module row (PlatformAccess).
 *
 * SOURCE (verified: RecordScheduledTaskRun, TaskRunLedger, ScheduleReader): `g2g_platform_task_runs` (task_key, started_at, finished_at, status ok|failed|skipped|
 * NULL while open, sub_institute_id). The scheduled passes write NULL in `sub_institute_id` ("a scheduled command belongs to the installation, not a tenant") and
 * the screen's own run history is not tenant-filtered, so this detector reads exactly what the Scheduler screen shows this organisation: runs of THIS tenant
 * plus the installation-wide (NULL) runs, never another tenant's. `g2g_platform_scheduled_tasks` (per-tenant overrides, `disabled`) is read for this tenant only.
 *
 * WHAT IT DOES NOT CLAIM. The schedule itself lives in code (routes/console.php), not in the database, so the cadence of a task is unknown here: there is no
 * "overdue" or "never run" verdict per task. It reports only what the ledger proves: the latest run of a task failed; a task was skipped repeatedly and never
 * completed; a run was opened and never closed; nothing has started anywhere for a long time (the scheduler is not running). The output text and the host of a
 * run are never read.
 */
final class SchedulerReliabilityDetector extends PlatformDetector
{
    private const RUNS = 'g2g_platform_task_runs';
    private const OVERRIDES = 'g2g_platform_scheduled_tasks';
    private const LINK = '/platform-services/scheduler';
    private const MAX_TASKS = 500;
    private const MAX_NAMED = 5;

    public function id(): string
    {
        return 'platform.scheduler_reliability';
    }

    public function title(): string
    {
        return 'Scheduled task reliability';
    }

    public function surfaces(): array
    {
        return [Taxonomy::SURFACE_PLATFORM_SCHEDULER];
    }

    public function authorizes(IntelligenceCaller $caller): bool
    {
        return $caller->canAny(PlatformAccess::platformModuleLinks());
    }

    protected function assess(IntelligenceCaller $caller, DetectionClock $clock): DetectorResult
    {
        $asOf = $clock->asOf();
        if (! $this->canRead(self::RUNS, ['id', 'task_key', 'sub_institute_id', 'started_at', 'finished_at', 'status'])) {
            return DetectorResult::dataUnavailable($this, $asOf, 'The scheduled task run history is not available on this installation.');
        }

        $tenant = $caller->tenantId;
        $visible = fn () => $this->db->table(self::RUNS)->where(function ($q) use ($tenant) {
            $q->where('sub_institute_id', $tenant)->orWhereNull('sub_institute_id');
        });

        $days = $this->thresholds->int('scheduler_window_days');
        $windowStart = $clock->dateTimeDaysAgo($days);
        $ever = (int) $visible()->count();
        $latest = $ever > 0 ? (string) $visible()->max('started_at') : null;

        $windowRuns = (int) $visible()->where('started_at', '>=', $windowStart)->count();
        $windowCoverage = Coverage::of($windowRuns, null, "scheduled task runs recorded in the last {$days} days", $this->thresholds->int('scheduler_min_runs'));
        $ledgerCoverage = Coverage::of($ever, null, 'scheduled task runs ever recorded', 1);

        $insights = [];
        $today = $clock->today();

        // 1. Silence: runs exist, but nothing has started for a long time.
        $silentHours = $this->thresholds->int('scheduler_silent_hours');
        if ($latest !== null && $latest < $clock->hoursAgo($silentHours)) {
            $insights[] = new Insight(
                $this->key($caller, 'silent', $today), $this->id(), $this->domain(), 'scheduler', 'high',
                'No scheduled task has started recently',
                sprintf('The most recent scheduled task started on %s, more than %d hours ago.', substr($latest, 0, 10), $silentHours),
                'Everything the platform runs on a timer (event draining, reminders, escalations, recomputations) stops while the scheduler is not running.',
                [
                    Evidence::metric('Hours without any task starting (at least)', $silentHours, 'hours', self::RUNS),
                    Evidence::metric('Latest task start on record', substr($latest, 0, 16), null, self::RUNS),
                    Evidence::metric('Task runs ever recorded', $ever, 'runs'),
                ],
                $ledgerCoverage, $asOf, 'Check that the server runs the scheduler every minute.', self::LINK, 'rule_based', $this->audience(), $this->surfaces(), null,
            );
        }

        if (! $windowCoverage->sufficient && $insights === []) {
            return DetectorResult::insufficient($this, $windowCoverage, $asOf);
        }

        // 2. Per-task health inside the window.
        if ($windowCoverage->sufficient) {
            $openCutoff = $clock->hoursAgo($this->thresholds->int('scheduler_open_run_hours'));
            $groups = $visible()->where('started_at', '>=', $windowStart)
                ->selectRaw(
                    "task_key, COUNT(*) as n, SUM(CASE WHEN status = 'skipped' THEN 1 ELSE 0 END) as skipped, SUM(CASE WHEN status = 'ok' THEN 1 ELSE 0 END) as ok_runs, "
                    . 'SUM(CASE WHEN status IS NULL AND finished_at IS NULL AND started_at < ? THEN 1 ELSE 0 END) as open_runs, MAX(id) as last_id',
                    [$openCutoff]
                )
                ->groupBy('task_key')->orderBy('task_key')->limit(self::MAX_TASKS)->get();

            $lastStatus = [];
            if ($groups->isNotEmpty()) {
                foreach ($visible()->whereIn('id', $groups->pluck('last_id')->all())->get(['id', 'task_key', 'status']) as $row) {
                    $lastStatus[(string) $row->task_key] = $row->status;
                }
            }

            $failing = [];
            $stuck = [];
            $open = 0;
            $streak = $this->thresholds->int('scheduler_skip_streak');
            foreach ($groups as $g) {
                $key = (string) $g->task_key;
                if (($lastStatus[$key] ?? null) === 'failed') {
                    $failing[] = $key;
                }
                if ((int) $g->skipped >= $streak && (int) $g->ok_runs === 0) {
                    $stuck[] = $key;
                }
                $open += (int) $g->open_runs;
            }

            if ($failing !== [] || $stuck !== [] || $open > 0) {
                $evidence = [
                    Evidence::metric('Tasks whose latest run failed', count($failing), 'tasks', self::RUNS),
                    Evidence::metric("Tasks skipped {$streak}+ times and never completed", count($stuck), 'tasks'),
                    Evidence::metric('Runs started but never closed', $open, 'runs'),
                    Evidence::metric('Task runs in the window', $windowRuns, 'runs'),
                ];
                foreach (array_slice($failing, 0, self::MAX_NAMED) as $key) {
                    $evidence[] = Evidence::metric('Failing task: ' . self::safeKey($key), 'latest run failed');
                }
                foreach (array_slice($stuck, 0, self::MAX_NAMED) as $key) {
                    $evidence[] = Evidence::metric('Stuck task: ' . self::safeKey($key), 'skipped every time');
                }
                $insights[] = new Insight(
                    $this->key($caller, 'tasks', $today), $this->id(), $this->domain(), 'scheduler', $failing !== [] ? 'high' : 'attention',
                    sprintf('%d scheduled task(s) need attention', count($failing) + count($stuck) + ($open > 0 ? 1 : 0)),
                    sprintf('%d task(s) failed on their latest run, %d were skipped every time they were due, and %d run(s) were opened and never closed (last %d days).', count($failing), count($stuck), $open, $days),
                    'A failing or stuck scheduled task silently stops the work it exists to do. The run history shows what the ledger recorded, not why.',
                    $evidence, $windowCoverage, $asOf, 'Open the Scheduler screen, read the failing task\'s output and re-run it once the cause is fixed.', self::LINK, 'measured', $this->audience(), $this->surfaces(), null,
                );
            }
        }

        // 3. Tasks this organisation switched off (a deliberate setting, reported so it is not forgotten).
        if ($this->canRead(self::OVERRIDES, ['sub_institute_id', 'task_key', 'disabled'])) {
            $off = $this->db->table(self::OVERRIDES)->where('sub_institute_id', $tenant)->where('disabled', 1)->orderBy('task_key')->limit(self::MAX_TASKS)->pluck('task_key')->all();
            if ($off !== []) {
                $evidence = [Evidence::metric('Tasks switched off for this organisation', count($off), 'tasks', self::OVERRIDES)];
                foreach (array_slice($off, 0, self::MAX_NAMED) as $key) {
                    $evidence[] = Evidence::metric('Switched off: ' . self::safeKey($key), 'disabled here');
                }
                $insights[] = new Insight(
                    $this->key($caller, 'disabled', $today), $this->id(), $this->domain(), 'scheduler', 'info',
                    sprintf('%d scheduled task(s) are switched off for this organisation', count($off)),
                    sprintf('%d task(s) have an organisation setting that disables them.', count($off)),
                    'A switched-off task does not run for this organisation; check each one is still meant to be off.',
                    $evidence, Coverage::of(count($off), null, 'scheduled task settings for this organisation', 1), $asOf,
                    'Review the switched-off tasks on the Scheduler screen.', self::LINK, 'measured', $this->audience(), $this->surfaces(), null,
                );
            }
        }

        return DetectorResult::ok($this, $insights, $windowCoverage->sufficient ? $windowCoverage : $ledgerCoverage, $asOf);
    }
}
