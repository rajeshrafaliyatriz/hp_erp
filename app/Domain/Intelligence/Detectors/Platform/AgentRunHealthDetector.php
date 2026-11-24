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
 * Agentic AI run health: failed-run share, stalled runs, deployed agents with no run, and the cost of a run. Needs `view` on one of the Agentic AI screens that
 * show runs and cost (PlatformAccess::agenticLinks()).
 *
 * SOURCE (verified: the 2026_08_03 migration, RunController, AgentController): `agentic_agent_runs` (agent_id, status pending|running|success|error|cancelled,
 * cost, created_at, deleted_at, sub_institute_id) and `agentic_agents` (status draft|deployed|paused|archived, deleted_at, sub_institute_id). Only THIS
 * organisation's rows are read: the shared catalogue agents (NULL organisation) are never counted as this organisation's agents. Prompts, inputs, outputs, error
 * messages and endpoints are never selected.
 *
 * COST. `cost` has no currency column, so it is reported as recorded with no currency attached and is never converted or combined with another source's cost.
 * A run with no recorded cost is "not priced", never zero. A rise is compared between the two halves of the window using the average over PRICED runs.
 *
 * "FAILED" is `error`; cancelled runs are neither failures nor successes and are left out of the share. "STALLED" is a run still pending or running after
 * `agentic_stalled_hours` (it may be genuinely long-running; the evidence says what was measured, not why).
 */
final class AgentRunHealthDetector extends PlatformDetector
{
    private const RUNS = 'agentic_agent_runs';
    private const AGENTS = 'agentic_agents';
    private const LINK = '/module/agentic-ai/run-log';

    public function id(): string
    {
        return 'agentic.run_health';
    }

    public function title(): string
    {
        return 'Agent run health';
    }

    public function surfaces(): array
    {
        return [Taxonomy::SURFACE_AGENTIC_RUN_LOG];
    }

    public function authorizes(IntelligenceCaller $caller): bool
    {
        return $caller->canAny(PlatformAccess::agenticLinks());
    }

    protected function assess(IntelligenceCaller $caller, DetectionClock $clock): DetectorResult
    {
        $asOf = $clock->asOf();
        if (! $this->canRead(self::RUNS, ['id', 'sub_institute_id', 'agent_id', 'status', 'cost', 'created_at', 'deleted_at'])) {
            return DetectorResult::dataUnavailable($this, $asOf, 'Agent run records are not available on this installation.');
        }

        $tenant = $caller->tenantId;
        $days = $this->thresholds->int('agentic_window_days');
        $since = $clock->dateTimeDaysAgo($days);
        $runs = fn () => $this->db->table(self::RUNS)->where('sub_institute_id', $tenant)->whereNull('deleted_at')->where('created_at', '>=', $since);

        $finished = (int) $runs()->whereIn('status', ['success', 'error'])->count();
        $failed = (int) $runs()->where('status', 'error')->count();
        $coverage = Coverage::of($finished, null, "finished agent runs in the last {$days} days", $this->thresholds->int('agentic_min_runs'));

        $insights = [];
        $today = $clock->today();

        // 1. Failed-run share (needs volume).
        if ($coverage->sufficient && $failed > 0) {
            $share = self::pct($failed, $finished);
            $limit = $this->thresholds->float('agentic_failure_pct');
            if ($share >= $limit) {
                $agentIds = $runs()->where('status', 'error')->selectRaw('agent_id, COUNT(*) as n')->groupBy('agent_id')->orderByDesc('n')->orderBy('agent_id')
                    ->limit(self::MAX_ENTITY_EVIDENCE)->pluck('agent_id')->all();
                $evidence = [
                    Evidence::metric('Finished agent runs', $finished, 'runs', self::RUNS),
                    Evidence::metric('Failed runs', $failed, 'runs'),
                    Evidence::metric('Share of finished runs that failed', $share, '%'),
                    ...$this->entities($caller, 'Agent with failed runs', 'agentic_agent', $agentIds),
                ];
                $insights[] = new Insight(
                    $this->key($caller, 'failed_share', $today), $this->id(), $this->domain(), 'agentic', $share >= 2 * $limit ? 'high' : 'attention',
                    sprintf('%s%% of agent runs failed', $share),
                    sprintf('%d of %d finished agent runs in the last %d days ended in an error (cancelled runs are not counted).', $failed, $finished, $days),
                    'A failing agent does not deliver the work it was deployed for, and every failed run still costs time and money.',
                    $evidence, $coverage, $asOf, 'Open the run log, read the failing agents\' errors and fix or pause them.', self::LINK, 'rule_based', $this->audience(), $this->surfaces(), null,
                );
            }
        }

        // 2. Stalled runs (needs no volume).
        $hours = $this->thresholds->int('agentic_stalled_hours');
        $stalled = (int) $runs()->whereIn('status', ['pending', 'running'])->where('created_at', '<', $clock->hoursAgo($hours))->count();
        if ($stalled > 0) {
            $insights[] = new Insight(
                $this->key($caller, 'stalled', $today), $this->id(), $this->domain(), 'agentic', 'attention',
                sprintf('%d agent run(s) have not finished after %d hours', $stalled, $hours),
                sprintf('%d run(s) from the last %d days are still pending or running more than %d hours after they were created.', $stalled, $days, $hours),
                'A run that never finishes holds its result back and usually means the agent or the service it calls has stopped responding.',
                [Evidence::metric("Runs pending or running after {$hours} hours", $stalled, 'runs', self::RUNS), Evidence::metric('Window', $days, 'days')],
                Coverage::of($stalled, null, "stalled agent runs in the last {$days} days", 1), $asOf, 'Open the run log and cancel or re-run the stalled runs.', self::LINK, 'rule_based', $this->audience(), $this->surfaces(), null,
            );
        }

        // 3. Deployed agents that did not run at all in the window.
        if ($this->canRead(self::AGENTS, ['id', 'sub_institute_id', 'status', 'deleted_at'])) {
            $deployed = fn () => $this->db->table(self::AGENTS . ' as a')->where('a.sub_institute_id', $tenant)->whereNull('a.deleted_at')->where('a.status', 'deployed');
            $deployedCount = (int) $deployed()->count();
            $agentCoverage = Coverage::of($deployedCount, null, 'agents deployed by this organisation', $this->thresholds->int('agentic_min_agents'));
            if ($agentCoverage->sufficient) {
                $idle = fn () => $deployed()->whereNotExists(function ($sub) use ($tenant, $since) {
                    $sub->from(self::RUNS . ' as r')->selectRaw('1')->whereColumn('r.agent_id', 'a.id')->where('r.sub_institute_id', $tenant)->whereNull('r.deleted_at')->where('r.created_at', '>=', $since);
                });
                $idleCount = (int) $idle()->count();
                if ($idleCount > 0) {
                    $ids = $idle()->orderBy('a.id')->limit(self::MAX_ENTITY_EVIDENCE)->pluck('a.id')->all();
                    $insights[] = new Insight(
                        $this->key($caller, 'idle', $today), $this->id(), $this->domain(), 'agentic', 'info',
                        sprintf('%d deployed agent(s) have not run in %d days', $idleCount, $days),
                        sprintf('%d of %d deployed agents have no run recorded in the last %d days.', $idleCount, $deployedCount, $days),
                        'An agent that is deployed but never used is either not needed or not reachable by the people it was built for.',
                        [Evidence::metric('Deployed agents', $deployedCount, 'agents', self::AGENTS), Evidence::metric('Deployed agents with no run in the window', $idleCount, 'agents'),
                            ...$this->entities($caller, 'Idle agent', 'agentic_agent', $ids)],
                        $agentCoverage, $asOf, 'Review whether each idle agent is still needed.', self::LINK, 'rule_based', $this->audience(), $this->surfaces(), null,
                    );
                }
            }
        }

        // 4. Cost per run: average over priced runs, the two halves of the window compared.
        $midpoint = $clock->dateTimeDaysAgo(intdiv($days, 2));
        $cost = $runs()->where('status', '!=', 'cancelled')->whereNotNull('cost')->selectRaw(
            'COUNT(*) as n, AVG(cost) as avg_cost, '
            . 'SUM(CASE WHEN created_at < ? THEN 1 ELSE 0 END) as n_first, SUM(CASE WHEN created_at < ? THEN cost ELSE 0 END) as sum_first, '
            . 'SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as n_second, SUM(CASE WHEN created_at >= ? THEN cost ELSE 0 END) as sum_second',
            [$midpoint, $midpoint, $midpoint, $midpoint]
        )->first();
        $pricedFirst = (int) ($cost->n_first ?? 0);
        $pricedSecond = (int) ($cost->n_second ?? 0);
        $half = max(1, intdiv($this->thresholds->int('agentic_min_runs'), 2));
        if ($pricedFirst >= $half && $pricedSecond >= $half && (float) $cost->sum_first > 0) {
            $avgFirst = (float) $cost->sum_first / $pricedFirst;
            $avgSecond = (float) $cost->sum_second / $pricedSecond;
            $rise = ($avgSecond - $avgFirst) / $avgFirst * 100;
            if ($rise >= $this->thresholds->float('agentic_cost_rise_pct')) {
                $insights[] = new Insight(
                    $this->key($caller, 'cost_rise', $today), $this->id(), $this->domain(), 'agentic', 'attention',
                    sprintf('Average cost per agent run rose %d%%', (int) round($rise)),
                    sprintf('The average recorded cost of a run in the second half of the last %d days is %d%% higher than in the first half.', $days, (int) round($rise)),
                    'A rising cost per run means agents are using more or dearer resources for the same kind of work.',
                    [
                        Evidence::metric('Average cost per run, earlier half (as recorded, no currency stored)', round($avgFirst, 4), null, self::RUNS),
                        Evidence::metric('Average cost per run, later half (as recorded, no currency stored)', round($avgSecond, 4)),
                        Evidence::metric('Priced runs, earlier half', $pricedFirst, 'runs'),
                        Evidence::metric('Priced runs, later half', $pricedSecond, 'runs'),
                        Evidence::metric('Average cost per run over the window (as recorded)', round((float) $cost->avg_cost, 4)),
                    ],
                    Coverage::of($pricedFirst + $pricedSecond, null, "agent runs with a recorded cost in the last {$days} days", 2 * $half), $asOf,
                    'Open Analytics and find the agents whose cost per run grew.', self::LINK, 'rule_based', $this->audience(), $this->surfaces(), null,
                );
            }
        }

        if (! $coverage->sufficient && $insights === []) {
            return DetectorResult::insufficient($this, $coverage, $asOf);
        }

        return DetectorResult::ok($this, $insights, $coverage->sufficient ? $coverage : $insights[0]->coverage, $asOf);
    }
}
