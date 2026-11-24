<?php

namespace App\Domain\Intelligence\Detectors\Hr;

use App\Domain\Intelligence\Access\IntelligenceCaller;
use App\Domain\Intelligence\Coverage;
use App\Domain\Intelligence\DetectorResult;
use App\Domain\Intelligence\Evidence;
use App\Domain\Intelligence\Insight;
use App\Domain\Intelligence\Support\DetectionClock;
use App\Domain\Intelligence\Support\PeopleScope;
use App\Domain\Intelligence\Taxonomy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

/**
 * TASK WORKFLOW: tasks that are past their due date, tasks that are blocked, and tasks that were reopened, for the people the caller may see.
 *
 * Sources: `task` (the task module's table) and, for reopened work only, `task_status_history`.
 *
 * DEFINITIONS ARE THE PRODUCT'S OWN, copied from App\Services\Dashboard\WorkforceMetricsService (overdueTasks / blockedTasks) and
 * App\Services\TaskManagement\TaskStatusTransitionService, so the dashboard tile and this insight cannot disagree about what a word means:
 *   - the due date is `task_date`;
 *   - OPEN is `UPPER(COALESCE(status,'PENDING')) <> 'COMPLETED'` (the module's own rule: anything not COMPLETED is open, a NULL status is PENDING);
 *   - OVERDUE is open AND `task_date` earlier than today;
 *   - BLOCKED is `UPPER(TRIM(COALESCE(status,''))) = 'ON HOLD'` (ON HOLD is the module's blocked state);
 *   - REOPENED is a `task_status_history` row with `is_reopen` set (a move from a terminal state back to an active one), inside a provisional window.
 * No status vocabulary is invented here.
 *
 * ONE DELIBERATE DIFFERENCE FROM THE DASHBOARD: the dashboard reads the task table one academic year (`SYEAR`) at a time, because the caller of that screen
 * names the year. An Intelligence caller carries no academic year, and guessing one would be an invention, so this detector counts across every year. An open
 * task past its due date is overdue whichever year it was filed under, but the figure can therefore be higher than the dashboard's for one year; the coverage
 * basis says "across all academic years". `task_status_history` is a projection that was NOT back-filled, so reopened work is only counted from when it began
 * to be recorded: the insight says "recorded".
 *
 * WHOSE TASKS: the assignee (`task_allocated_to`) must be an active person in the caller's scope (PeopleScope): an employee sees their own, a manager their
 * direct reports', a department head / HR executive their department's, HR and administrators the organisation's; executives and auditors see aggregates
 * only (no task references).
 *
 * It never reads a title, a description, a remark, a reply, a delay reason or any other free text, nor hours or skills.
 */
final class TaskWorkflowDetector extends AbstractHrDetector
{
    private const SELF_LINK = '/module/task-management/my-tasks';
    private const TEAM_LINK = '/module/task-management/task-management-dashboard';

    private const OPEN = "UPPER(COALESCE(t.status,'PENDING')) <> 'COMPLETED'";
    private const BLOCKED = "UPPER(TRIM(COALESCE(t.status,''))) = 'ON HOLD'";

    public function id(): string
    {
        return 'hr.task_workflow';
    }

    public function title(): string
    {
        return 'Task workflow';
    }

    public function surfaces(): array
    {
        return [Taxonomy::SURFACE_TASKS];
    }

    public function authorizes(IntelligenceCaller $caller): bool
    {
        return $caller->hasRole(...self::PEOPLE_ROLES);
    }

    protected function assess(IntelligenceCaller $caller, DetectionClock $clock): DetectorResult
    {
        $asOf = $clock->asOf();
        if (! $this->canRead('task', ['id', 'status', 'task_date', 'task_allocated_to', 'sub_institute_id', 'deleted_at'])) {
            return DetectorResult::dataUnavailable($this, $asOf, 'Task records are not available for this organisation.');
        }

        $self = $caller->scope() === 'self';
        $today = $clock->today();
        $base = fn (): Builder => $this->db->table('task as t')
            ->where('t.sub_institute_id', $caller->tenantId)
            ->whereNull('t.deleted_at')
            ->whereIn('t.task_allocated_to', PeopleScope::userIds($this->db, $caller));

        $total = (int) $base()->count();
        if ($self && $total === 0) {
            return DetectorResult::notApplicable($this, $asOf, 'No tasks are assigned to you.');
        }
        $minimum = $self ? 0 : $this->thresholds->int('tasks_min_assigned');
        $basis = 'tasks assigned to the people in scope, across all academic years';
        if ($total < $minimum) {
            return DetectorResult::insufficient($this, Coverage::of($total, null, $basis, $minimum), $asOf);
        }
        $coverage = new Coverage($total, null, $basis, true, null);

        $open = self::OPEN;
        $blocked = self::BLOCKED;
        $overdue = "t.task_date IS NOT NULL AND t.task_date < ? AND {$open}";
        $stats = $base()->selectRaw(
            "SUM(CASE WHEN {$open} THEN 1 ELSE 0 END) AS open_n,"
            . " SUM(CASE WHEN {$open} AND t.task_date IS NULL THEN 1 ELSE 0 END) AS undated,"
            . " SUM(CASE WHEN {$overdue} THEN 1 ELSE 0 END) AS overdue_n,"
            . " COUNT(DISTINCT CASE WHEN {$overdue} THEN t.task_allocated_to END) AS overdue_owners,"
            . " MIN(CASE WHEN {$overdue} THEN t.task_date END) AS oldest,"
            . " SUM(CASE WHEN {$blocked} THEN 1 ELSE 0 END) AS blocked_n,"
            . " COUNT(DISTINCT CASE WHEN {$blocked} THEN t.task_allocated_to END) AS blocked_owners",
            [$today, $today, $today],
        )->first();

        $openN = (int) ($stats->open_n ?? 0);
        $undated = (int) ($stats->undated ?? 0);
        $overdueN = (int) ($stats->overdue_n ?? 0);
        $overdueOwners = (int) ($stats->overdue_owners ?? 0);
        $blockedN = (int) ($stats->blocked_n ?? 0);
        $blockedOwners = (int) ($stats->blocked_owners ?? 0);
        $oldestDays = $stats->oldest ? (int) CarbonImmutable::parse((string) $stats->oldest, 'UTC')->diffInDays($clock->instant()) : null;
        $link = $self ? self::SELF_LINK : self::TEAM_LINK;
        $insights = [];

        if ($overdueN > 0) {
            $evidence = [
                Evidence::metric('Open tasks past their due date', $overdueN, 'tasks', 'task'),
                Evidence::metric('Open tasks in total', $openN, 'tasks', 'task'),
            ];
            if ($oldestDays !== null) {
                $evidence[] = Evidence::metric('Longest overdue', $oldestDays, 'days', 'task');
            }
            if ($undated > 0) {
                $evidence[] = Evidence::metric('Open tasks with no due date (not measured)', $undated, 'tasks', 'task');
            }
            $evidence[] = Evidence::window('Due before', '1000-01-01', $today);
            if ($caller->mayReferenceIndividuals()) {
                $refs = $base()->whereRaw($open)->whereNotNull('t.task_date')->where('t.task_date', '<', $today)
                    ->orderBy('t.task_date')->orderBy('t.id')->limit(10)->pluck('t.id');
                foreach ($refs as $id) {
                    $evidence[] = Evidence::entity('Task', 'task', (int) $id);
                }
            }
            $share = $this->pct($overdueN, $openN);
            $severity = $share >= $this->thresholds->float('tasks_overdue_pct') ? 'high' : 'attention';
            $insights[] = new Insight(
                key: $this->keyFor('overdue', $caller, $clock),
                detector: $this->id(), domain: $this->domain(), category: 'task_overdue', severity: $severity,
                title: $self ? 'Tasks of yours are past their due date' : 'Tasks are past their due date',
                whatHappened: $self
                    ? sprintf('%d of your open task(s) are past their due date.', $overdueN)
                    : sprintf('%d open task(s) assigned to %d person(s) are past their due date (%s%% of their open tasks).', $overdueN, $overdueOwners, rtrim(rtrim(number_format($share, 1), '0'), '.')),
                whyItMatters: 'A task past its due date is either still needed and late, or finished and not closed; either way the task list no longer says what is true.',
                evidence: $evidence, coverage: $coverage, asOf: $asOf,
                suggestedAction: $self ? 'Complete the tasks, or move their due date and say why.' : 'Open the task module and re-plan or close the overdue tasks with their owners.',
                deepLink: $link, confidence: Taxonomy::CONFIDENCE_RULE_BASED, audience: $caller->scope(),
                surfaces: $this->surfaces(), population: $overdueOwners,
            );
        }

        if ($blockedN > 0) {
            $evidence = [
                Evidence::metric('Tasks on hold', $blockedN, 'tasks', 'task'),
                Evidence::metric('Open tasks in total', $openN, 'tasks', 'task'),
            ];
            if ($caller->mayReferenceIndividuals()) {
                $refs = $base()->whereRaw($blocked)->orderBy('t.id')->limit(10)->pluck('t.id');
                foreach ($refs as $id) {
                    $evidence[] = Evidence::entity('Task', 'task', (int) $id);
                }
            }
            $insights[] = new Insight(
                key: $this->keyFor('blocked', $caller, $clock),
                detector: $this->id(), domain: $this->domain(), category: 'task_blocked', severity: 'attention',
                title: $self ? 'Tasks of yours are on hold' : 'Tasks are on hold',
                whatHappened: $self ? sprintf('%d of your task(s) are on hold.', $blockedN) : sprintf('%d task(s) assigned to %d person(s) are on hold.', $blockedN, $blockedOwners),
                whyItMatters: 'On hold is the task module\'s blocked state: the work is parked until someone removes what is in the way.',
                evidence: $evidence, coverage: $coverage, asOf: $asOf,
                suggestedAction: $self ? 'Resume the task, or ask for what it is waiting on.' : 'Ask the owners what each held task is waiting on and who can clear it.',
                deepLink: $link, confidence: Taxonomy::CONFIDENCE_MEASURED, audience: $caller->scope(),
                surfaces: $this->surfaces(), population: $blockedOwners,
            );
        }

        $reopened = $this->reopened($caller, $clock, $base, $coverage, $link);
        if ($reopened !== null) {
            $insights[] = $reopened;
        }

        return DetectorResult::ok($this, $insights, $coverage, $asOf);
    }

    /** Tasks moved from a finished state back to an active one inside the window, from the recorded status changes. */
    private function reopened(IntelligenceCaller $caller, DetectionClock $clock, \Closure $base, Coverage $coverage, string $link): ?Insight
    {
        if (! $this->canRead('task_status_history', ['task_id', 'sub_institute_id', 'is_reopen', 'occurred_at'])) {
            return null;
        }
        $days = $this->thresholds->int('tasks_reopen_window_days');
        $since = $clock->dateTimeDaysAgo($days);
        $self = $caller->scope() === 'self';
        $query = fn (): Builder => $this->db->table('task_status_history as h')
            ->join('task as t', 't.id', '=', 'h.task_id')
            ->where('h.sub_institute_id', $caller->tenantId)
            ->where('t.sub_institute_id', $caller->tenantId)
            ->whereNull('t.deleted_at')
            ->where('h.is_reopen', 1)
            ->where('h.occurred_at', '>=', $since)
            ->whereIn('t.task_allocated_to', PeopleScope::userIds($this->db, $caller));

        $row = $query()->selectRaw('COUNT(DISTINCT h.task_id) AS tasks_n, COUNT(DISTINCT t.task_allocated_to) AS owners_n')->first();
        $tasks = (int) ($row->tasks_n ?? 0);
        $owners = (int) ($row->owners_n ?? 0);
        if ($tasks === 0) {
            return null;
        }

        $evidence = [
            Evidence::metric(sprintf('Tasks reopened in the last %d days', $days), $tasks, 'tasks', 'task_status_history'),
            Evidence::window('Recorded status changes', substr($since, 0, 10), $clock->today()),
        ];
        if ($caller->mayReferenceIndividuals()) {
            foreach ($query()->groupBy('h.task_id')->orderBy('h.task_id')->limit(10)->pluck('h.task_id') as $id) {
                $evidence[] = Evidence::entity('Task', 'task', (int) $id);
            }
        }

        return new Insight(
            key: $this->keyFor('reopened', $caller, $clock),
            detector: $this->id(), domain: $this->domain(), category: 'task_reopened', severity: 'info',
            title: $self ? 'Tasks of yours were reopened' : 'Tasks were reopened',
            whatHappened: $self
                ? sprintf('%d of your task(s) were reopened after being completed in the last %d days (recorded changes only).', $tasks, $days)
                : sprintf('%d task(s) assigned to %d person(s) were reopened after being completed in the last %d days (recorded changes only).', $tasks, $owners, $days),
            whyItMatters: 'Work that comes back after completion is rework; seeing it early shows where the definition of done is unclear.',
            evidence: $evidence, coverage: $coverage, asOf: $clock->asOf(),
            suggestedAction: $self ? 'Check what was missing when the task was first closed.' : 'Ask the owners what was missing when the tasks were first closed.',
            deepLink: $link, confidence: Taxonomy::CONFIDENCE_MEASURED, audience: $caller->scope(),
            surfaces: $this->surfaces(), population: $owners,
        );
    }
}
