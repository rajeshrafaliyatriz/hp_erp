<?php

namespace App\Domain\AI\Examples\Sources;

use App\Services\Ai\AiRequestScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The Agentic module's data sources and page examples. See SourceGroup for the rules every source follows.
 *
 * What is deliberately never returned: an agent's prompt or function text, its endpoint, headers or saved
 * configuration and secrets, a run's input, output or error text, a tool call's payload or response, and
 * the body of a message between agents. Sources return names, statuses, counts, times and durations only.
 *
 * Platform agents (no owner) are part of the library every organisation sees on its pages, so the agent
 * list includes them; runs, tool calls, workflows and optimisations are always the organisation's own.
 */
final class AgenticSources extends SourceGroup
{
    public function definitions(): array
    {
        $limit = self::limitArg();

        return [
            [
                'name' => 'agentic.agents',
                'module' => 'agentic_ai',
                'label' => 'Agents',
                'description' => 'Agents available to the organisation with their module, status, how many tools they have and how many runs, failed runs and the last run.',
                'arguments' => [
                    self::arg('status', 'string', 'draft, deployed or paused.'),
                    self::arg('module', 'string', 'Only agents of this module, for example LMS.'),
                    self::arg('origin', 'string', 'platform for the shared library, tenant for the organisation\'s own agents.'),
                    $limit,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $tenant = (int) $scope->selectedInstituteId;
                    $runs = "FROM agentic_agent_runs r WHERE r.agent_id = a.id AND r.sub_institute_id = {$tenant} AND r.deleted_at IS NULL";
                    $q = DB::table('agentic_agents as a')
                        ->where(function ($w) use ($tenant) {
                            $w->where('a.sub_institute_id', $tenant)->orWhereNull('a.sub_institute_id');
                        })
                        ->whereNull('a.deleted_at')
                        ->select(['a.id as agent_id', 'a.name as agent', 'a.origin', 'a.module', 'a.model', 'a.status'])
                        ->selectRaw('CASE WHEN a.tools IS NOT NULL AND JSON_VALID(a.tools) THEN JSON_LENGTH(a.tools) ELSE 0 END as tools_count')
                        ->selectRaw("(SELECT COUNT(*) {$runs}) as runs")
                        ->selectRaw("(SELECT COUNT(*) {$runs} AND r.status = 'error') as failed_runs")
                        ->selectRaw("(SELECT MAX(r.started_at) {$runs}) as last_run_at")
                        ->orderByDesc('a.id');

                    if (($s = $this->text($a, 'status')) !== null) {
                        $q->where('a.status', $s);
                    }
                    if (($m = $this->text($a, 'module')) !== null) {
                        $q->where('a.module', $m);
                    }
                    if (($o = $this->text($a, 'origin')) !== null) {
                        $q->where('a.origin', $o);
                    }

                    return $q;
                },
            ],
            [
                'name' => 'agentic.runs',
                'module' => 'agentic_ai',
                'label' => 'Agent runs',
                'description' => 'Recent agent runs with the agent, status, what started them, when they started and finished and how long they took. No inputs, outputs or error text.',
                'arguments' => [
                    self::arg('agent_id', 'integer', 'Only runs of this agent.'),
                    self::arg('status', 'string', 'success, error or running.'),
                    $limit,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $tenant = $scope->selectedInstituteId;
                    $q = DB::table('agentic_agent_runs as r')
                        ->leftJoin('agentic_agents as a', 'a.id', '=', 'r.agent_id')
                        ->where('r.sub_institute_id', $tenant)
                        ->whereNull('r.deleted_at')
                        ->select(['r.id as run_id', 'a.name as agent', 'r.status', 'r.trigger as started_by', 'r.started_at', 'r.completed_at', 'r.duration_ms'])
                        ->selectRaw('r.cost as run_cost')
                        ->orderByDesc('r.created_at')
                        ->orderByDesc('r.id');

                    if (($g = $this->int($a, 'agent_id')) !== null) {
                        $q->where('r.agent_id', $g);
                    }
                    if (($s = $this->text($a, 'status')) !== null) {
                        $q->where('r.status', $s);
                    }

                    return $q;
                },
            ],
            [
                'name' => 'agentic.tool_invocations',
                'module' => 'agentic_ai',
                'label' => 'Tool calls made by agents',
                'description' => 'Each time an agent used a tool: which agent, which tool, the outcome and when. No request or response content.',
                'arguments' => [
                    self::arg('tool', 'string', 'Only this tool, for example web_search.'),
                    self::arg('status', 'string', 'success or error.'),
                    $limit,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $q = DB::table('agentic_tool_invocations as i')
                        ->leftJoin('agentic_agents as a', 'a.id', '=', 'i.agent_id')
                        ->where('i.sub_institute_id', $scope->selectedInstituteId)
                        ->whereNull('i.deleted_at')
                        ->select(['i.id as invocation_id', 'a.name as agent', 'i.run_id', 'i.tool', 'i.status', 'i.created_at'])
                        ->orderByDesc('i.id');

                    if (($t = $this->text($a, 'tool')) !== null) {
                        $q->where('i.tool', $t);
                    }
                    if (($s = $this->text($a, 'status')) !== null) {
                        $q->where('i.status', $s);
                    }

                    return $q;
                },
            ],
            [
                'name' => 'agentic.analytics',
                'module' => 'agentic_ai',
                'label' => 'Agent performance',
                'description' => 'For each agent: runs, successes, failures, success rate, average duration, usage and cost.',
                'arguments' => [$limit],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    return DB::table('agentic_agent_runs as r')
                        ->leftJoin('agentic_agents as a', 'a.id', '=', 'r.agent_id')
                        ->where('r.sub_institute_id', $scope->selectedInstituteId)
                        ->whereNull('r.deleted_at')
                        ->groupBy('r.agent_id', 'a.name')
                        ->select(['r.agent_id', 'a.name as agent'])
                        ->selectRaw('COUNT(*) as runs')
                        ->selectRaw("SUM(CASE WHEN r.status = 'success' THEN 1 ELSE 0 END) as successes")
                        ->selectRaw("SUM(CASE WHEN r.status = 'error' THEN 1 ELSE 0 END) as failures")
                        ->selectRaw("ROUND(100 * SUM(CASE WHEN r.status = 'success' THEN 1 ELSE 0 END) / COUNT(*), 1) as success_rate_percent")
                        ->selectRaw('ROUND(AVG(r.duration_ms)) as avg_duration_ms')
                        ->selectRaw('SUM(COALESCE(r.tokens_used, 0)) as llm_usage')
                        ->selectRaw('ROUND(SUM(COALESCE(r.cost, 0)), 4) as total_cost')
                        ->orderByDesc('runs');
                },
            ],
            [
                'name' => 'agentic.workflows',
                'module' => 'agentic_ai',
                'label' => 'Multi-agent workflows',
                'description' => 'Workflows that chain agents, with their mode, status, number of steps, runs, messages between agents and the last run.',
                'arguments' => [$limit],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    return DB::table('agentic_workflows as w')
                        ->where('w.sub_institute_id', $scope->selectedInstituteId)
                        ->whereNull('w.deleted_at')
                        ->select(['w.id as workflow_id', 'w.name as workflow', 'w.mode', 'w.status'])
                        ->selectRaw('(SELECT COUNT(*) FROM agentic_workflow_steps s WHERE s.workflow_id = w.id AND s.sub_institute_id = w.sub_institute_id AND s.deleted_at IS NULL) as steps')
                        ->selectRaw('(SELECT COUNT(*) FROM agentic_workflow_runs r WHERE r.workflow_id = w.id AND r.sub_institute_id = w.sub_institute_id) as runs')
                        ->selectRaw('(SELECT COUNT(*) FROM agentic_messages m JOIN agentic_workflow_runs r2 ON r2.id = m.workflow_run_id WHERE r2.workflow_id = w.id AND m.sub_institute_id = w.sub_institute_id) as agent_messages')
                        ->selectRaw('(SELECT MAX(r.started_at) FROM agentic_workflow_runs r WHERE r.workflow_id = w.id AND r.sub_institute_id = w.sub_institute_id) as last_run_at')
                        ->orderByDesc('w.id');
                },
            ],
            [
                'name' => 'agentic.optimizations',
                'module' => 'agentic_ai',
                'label' => 'Reflection findings',
                'description' => 'Improvements the reflection analysis suggested for agents, with category, priority, expected impact, effort and whether each is open or applied.',
                'arguments' => [
                    self::arg('status', 'string', 'open or applied.'),
                    $limit,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $q = DB::table('agentic_optimizations as o')
                        ->leftJoin('agentic_reflection_runs as f', 'f.id', '=', 'o.reflection_run_id')
                        ->where('o.sub_institute_id', $scope->selectedInstituteId)
                        ->whereNull('o.deleted_at')
                        ->select(['o.id as optimization_id', 'o.title', 'o.category', 'o.priority', 'o.estimated_impact', 'o.implementation_complexity as effort', 'o.status', 'o.applied_at', 'f.runs_analysed', 'f.failures_found'])
                        ->orderByDesc('o.id');

                    if (($s = $this->text($a, 'status')) !== null) {
                        $q->where('o.status', $s);
                    }

                    return $q;
                },
            ],
        ];
    }

    public function pages(): array
    {
        return [
            '/module/agentic-ai/agent-dashboard' => [
                'sources' => ['agentic.agents', 'agentic.runs'],
                'purpose' => 'An overview of your agents, their status and their latest runs.',
                'action' => null,
            ],
            '/module/agentic-ai/create-agent' => [
                'sources' => [],
                'purpose' => 'A form to define a new agent: its module, model, tools and instructions.',
                'action' => null,
                'no_data_reason' => 'It is an entry form with no records of its own; the agents it creates are listed on Agent Dashboard and Agentic Library, and its prompt and configuration fields are never exposed.',
            ],
            '/module/agentic-ai/run-log' => [
                'sources' => ['agentic.runs', 'agentic.tool_invocations'],
                'purpose' => 'The history of agent runs and the tools each one used.',
                'action' => null,
            ],
            '/module/agentic-ai/analytics' => [
                'sources' => ['agentic.analytics', 'agentic.runs'],
                'purpose' => 'Success rate, speed and cost of agent runs, agent by agent.',
                'action' => null,
            ],
            '/module/agentic-ai/multi-agent' => [
                'sources' => ['agentic.workflows', 'agentic.agents'],
                'purpose' => 'Workflows in which several agents run in sequence and pass work to each other.',
                'action' => null,
            ],
            '/module/agentic-ai/reflection' => [
                'sources' => ['agentic.optimizations'],
                'purpose' => 'Patterns found in past failures and the improvements suggested for agents.',
                'action' => null,
            ],
            '/module/agentic-ai/agentic-library' => [
                'sources' => ['agentic.agents'],
                'purpose' => 'The library of ready-made and organisation-built agents to browse and reuse.',
                'action' => null,
            ],
        ];
    }
}
