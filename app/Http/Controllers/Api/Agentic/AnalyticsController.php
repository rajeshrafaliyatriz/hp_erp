<?php

namespace App\Http\Controllers\Api\Agentic;

use App\Http\Controllers\Api\Agentic\Concerns\ResolvesAgenticContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Agent Dashboard KPIs and the Analytics screen.
 *
 *   GET /api/agentic/analytics/dashboard   KPI tiles + sparklines + recent runs
 *   GET /api/agentic/analytics/overview    daily series, per-agent breakdown, tool usage
 *
 * Every number here is computed from agentic_agent_runs. The screens this
 * replaces drew their charts from a fixture file, so the trend lines moved
 * whether or not anything had run.
 */
class AnalyticsController extends Controller
{
    use ResolvesAgenticContext;

    /** Cap the window so a bad `days` value cannot ask for an unbounded scan. */
    private function windowDays(Request $request, int $default = 7): int
    {
        return min(max((int) $request->input('days', $default), 1), 90);
    }

    /**
     * A row per day across the whole window, including days with no runs.
     *
     * Gaps matter: a chart that skips empty days makes a quiet weekend look
     * like continuous activity.
     *
     * @return array<int, array<string, mixed>>
     */
    private function dailySeries(int $sid, int $days, ?int $agentId = null): array
    {
        $start = now()->subDays($days - 1)->startOfDay();

        $rows = DB::table('agentic_agent_runs')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->where('created_at', '>=', $start)
            ->when($agentId, fn ($q) => $q->where('agent_id', $agentId))
            ->select(
                DB::raw('DATE(created_at) as day'),
                DB::raw('COUNT(*) as total_runs'),
                DB::raw("SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successes"),
                DB::raw("SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as failures"),
                DB::raw('COALESCE(SUM(tokens_used), 0) as tokens'),
                DB::raw('COALESCE(SUM(cost), 0) as cost'),
                DB::raw('COALESCE(AVG(duration_ms), 0) as avg_duration_ms')
            )
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        $series = [];
        for ($offset = 0; $offset < $days; $offset++) {
            $date = now()->subDays($days - 1 - $offset)->toDateString();
            $row = $rows[$date] ?? null;

            $total = $row ? (int) $row->total_runs : 0;
            $successes = $row ? (int) $row->successes : 0;

            $series[] = [
                'date'            => $date,
                'total_runs'      => $total,
                'successes'       => $successes,
                'failures'        => $row ? (int) $row->failures : 0,
                'success_rate'    => $total > 0 ? round(($successes / $total) * 100, 1) : 0,
                'tokens'          => $row ? (int) $row->tokens : 0,
                'cost'            => $row ? round((float) $row->cost, 4) : 0,
                'avg_duration_ms' => $row ? (int) round((float) $row->avg_duration_ms) : 0,
            ];
        }

        return $series;
    }

    public function dashboard(Request $request)
    {
        $context = $this->agenticContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];
        $days = $this->windowDays($request);

        $agentCounts = DB::table('agentic_agents')
            ->where('sub_institute_id', $sid)->whereNull('deleted_at')
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')->pluck('total', 'status');

        $runTotals = DB::table('agentic_agent_runs')
            ->where('sub_institute_id', $sid)->whereNull('deleted_at')
            ->select(
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successes"),
                DB::raw("SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as failures"),
                DB::raw("SUM(CASE WHEN status IN ('running','pending') THEN 1 ELSE 0 END) as active"),
                DB::raw('COALESCE(SUM(tokens_used), 0) as tokens'),
                DB::raw('COALESCE(SUM(cost), 0) as cost'),
                DB::raw('COALESCE(AVG(duration_ms), 0) as avg_duration_ms')
            )
            ->first();

        $series = $this->dailySeries($sid, $days);
        $totalRuns = (int) $runTotals->total;
        $successes = (int) $runTotals->successes;

        $today = $series[count($series) - 1] ?? ['cost' => 0, 'total_runs' => 0];
        $yesterday = $series[count($series) - 2] ?? null;

        $recentRuns = DB::table('agentic_agent_runs as r')
            ->leftJoin('agentic_agents as a', 'a.id', '=', 'r.agent_id')
            ->where('r.sub_institute_id', $sid)->whereNull('r.deleted_at')
            ->orderByDesc('r.created_at')
            ->limit(8)
            ->get(['r.id', 'r.status', 'r.created_at', 'r.duration_ms', 'r.tokens_used', 'a.name as agent_name'])
            ->map(fn ($row) => [
                'id'          => (int) $row->id,
                'agent_name'  => $row->agent_name ?? 'Deleted agent',
                'status'      => $row->status,
                'duration_ms' => $row->duration_ms !== null ? (int) $row->duration_ms : null,
                'tokens_used' => $row->tokens_used !== null ? (int) $row->tokens_used : null,
                'created_at'  => $row->created_at,
            ])->all();

        return $this->ok('Dashboard fetched successfully', [
            'window_days' => $days,
            'agents' => [
                'total'    => (int) array_sum($agentCounts->all()),
                'deployed' => (int) ($agentCounts['deployed'] ?? 0),
                'draft'    => (int) ($agentCounts['draft'] ?? 0),
                'paused'   => (int) ($agentCounts['paused'] ?? 0),
                'archived' => (int) ($agentCounts['archived'] ?? 0),
            ],
            'runs' => [
                'total'           => $totalRuns,
                'successes'       => $successes,
                'failures'        => (int) $runTotals->failures,
                'active'          => (int) $runTotals->active,
                // null, not 0: a tenant with no runs has no success rate yet.
                'success_rate'    => $totalRuns > 0 ? round(($successes / $totalRuns) * 100, 1) : null,
                'total_tokens'    => (int) $runTotals->tokens,
                'total_cost'      => round((float) $runTotals->cost, 4),
                'avg_duration_ms' => (int) round((float) $runTotals->avg_duration_ms),
            ],
            'today' => [
                'runs' => $today['total_runs'],
                'cost' => $today['cost'],
                // Direction only when there is a prior day to compare against.
                'cost_change_pct' => $yesterday && $yesterday['cost'] > 0
                    ? round((($today['cost'] - $yesterday['cost']) / $yesterday['cost']) * 100, 1)
                    : null,
            ],
            'series'      => $series,
            'recent_runs' => $recentRuns,
        ]);
    }

    public function overview(Request $request)
    {
        $context = $this->agenticContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];
        $days = $this->windowDays($request, 30);
        $agentId = $this->activeFilter($request->input('agent_id'));
        $start = now()->subDays($days - 1)->startOfDay();

        $perAgent = DB::table('agentic_agent_runs as r')
            ->leftJoin('agentic_agents as a', 'a.id', '=', 'r.agent_id')
            ->where('r.sub_institute_id', $sid)->whereNull('r.deleted_at')
            ->where('r.created_at', '>=', $start)
            ->when($agentId, fn ($q) => $q->where('r.agent_id', $agentId))
            ->groupBy('r.agent_id', 'a.name')
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->limit(25)
            ->get([
                'r.agent_id',
                'a.name as agent_name',
                DB::raw('COUNT(*) as total_runs'),
                DB::raw("SUM(CASE WHEN r.status = 'success' THEN 1 ELSE 0 END) as successes"),
                DB::raw("SUM(CASE WHEN r.status = 'error' THEN 1 ELSE 0 END) as failures"),
                DB::raw('COALESCE(SUM(r.tokens_used), 0) as tokens'),
                DB::raw('COALESCE(SUM(r.cost), 0) as cost'),
                DB::raw('COALESCE(AVG(r.duration_ms), 0) as avg_duration_ms'),
            ])
            ->map(function ($row) {
                $total = (int) $row->total_runs;
                $successes = (int) $row->successes;

                return [
                    'agent_id'        => (int) $row->agent_id,
                    'agent_name'      => $row->agent_name ?? 'Deleted agent',
                    'total_runs'      => $total,
                    'successes'       => $successes,
                    'failures'        => (int) $row->failures,
                    'success_rate'    => $total > 0 ? round(($successes / $total) * 100, 1) : null,
                    'tokens'          => (int) $row->tokens,
                    'cost'            => round((float) $row->cost, 4),
                    'avg_duration_ms' => (int) round((float) $row->avg_duration_ms),
                ];
            })->all();

        $statusBreakdown = DB::table('agentic_agent_runs')
            ->where('sub_institute_id', $sid)->whereNull('deleted_at')
            ->where('created_at', '>=', $start)
            ->when($agentId, fn ($q) => $q->where('agent_id', $agentId))
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->get()
            ->map(fn ($row) => ['status' => $row->status, 'total' => (int) $row->total])
            ->all();

        $toolUsage = DB::table('agentic_tool_invocations')
            ->where('sub_institute_id', $sid)->whereNull('deleted_at')
            ->where('created_at', '>=', $start)
            ->when($agentId, fn ($q) => $q->where('agent_id', $agentId))
            ->select('tool', DB::raw('count(*) as total'))
            ->groupBy('tool')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => ['tool' => $row->tool, 'total' => (int) $row->total])
            ->all();

        return $this->ok('Analytics fetched successfully', [
            'window_days'      => $days,
            'series'           => $this->dailySeries($sid, $days, $agentId ? (int) $agentId : null),
            'per_agent'        => $perAgent,
            'status_breakdown' => $statusBreakdown,
            'tool_usage'       => $toolUsage,
        ]);
    }
}
