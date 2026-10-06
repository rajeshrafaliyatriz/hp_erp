<?php

namespace App\Http\Controllers\Api\Agentic;

use App\Http\Controllers\Api\Agentic\Concerns\ResolvesAgenticContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * The Reflection system: what is going wrong and what to do about it.
 *
 *   GET  /api/agentic/reflection                      insights + failure patterns + optimizations
 *   POST /api/agentic/reflection/analyse              run a fresh analysis
 *   PUT  /api/agentic/reflection/optimizations/{id}   apply / dismiss / reopen
 *
 * Failure patterns are derived from run errors at read time rather than stored.
 * A stored pattern goes stale the moment another run fails, and the screen this
 * replaces showed a fixed list that never changed no matter what the agents did.
 */
class ReflectionController extends Controller
{
    use ResolvesAgenticContext;

    /**
     * Error signatures, most specific first.
     *
     * Bucketing on a keyword keeps unrelated messages apart without needing the
     * executor to emit a structured error code, which nothing guarantees today.
     */
    private const PATTERNS = [
        ['key' => 'rate_limit',  'label' => 'API rate limiting',        'match' => ['rate limit', '429', 'too many requests'], 'impact' => 'high',   'hint' => 'Add request throttling and exponential backoff between tool calls.'],
        ['key' => 'timeout',     'label' => 'Execution timeouts',       'match' => ['timeout', 'timed out', 'deadline'],       'impact' => 'high',   'hint' => 'Raise the step timeout or split the prompt into smaller steps.'],
        ['key' => 'token_limit', 'label' => 'Token limit exceeded',     'match' => ['token', 'context length', 'max_tokens'],  'impact' => 'medium', 'hint' => 'Trim the system prompt or lower max tokens for this agent.'],
        ['key' => 'auth',        'label' => 'Authentication failures',  'match' => ['unauthorized', '401', 'forbidden', '403', 'api key'], 'impact' => 'high', 'hint' => 'Rotate the provider credential and re-test the connection.'],
        ['key' => 'tool_error',  'label' => 'Tool invocation errors',   'match' => ['tool', 'function call', 'invocation'],    'impact' => 'medium', 'hint' => 'Validate the tool payload before invoking it.'],
        ['key' => 'parse',       'label' => 'Malformed model output',   'match' => ['json', 'parse', 'unexpected token'],      'impact' => 'medium', 'hint' => 'Constrain the response format in the system prompt.'],
        ['key' => 'network',     'label' => 'Network / provider errors', 'match' => ['network', 'connection', '502', '503'],   'impact' => 'medium', 'hint' => 'Add a retry with jitter around provider calls.'],
    ];

    private function windowDays(Request $request): int
    {
        return min(max((int) $request->input('days', 7), 1), 90);
    }

    /**
     * Group failed runs into patterns.
     *
     * @return array<int, array<string, mixed>>
     */
    private function failurePatterns(int $sid, int $days): array
    {
        $start = now()->subDays($days - 1)->startOfDay();

        $failures = DB::table('agentic_agent_runs as r')
            ->leftJoin('agentic_agents as a', 'a.id', '=', 'r.agent_id')
            ->where('r.sub_institute_id', $sid)
            ->whereNull('r.deleted_at')
            ->where('r.status', 'error')
            ->where('r.created_at', '>=', $start)
            ->limit(2000)
            ->get(['r.id', 'r.error_message', 'a.name as agent_name']);

        $buckets = [];
        foreach ($failures as $failure) {
            $message = strtolower((string) $failure->error_message);
            $matched = null;

            foreach (self::PATTERNS as $pattern) {
                foreach ($pattern['match'] as $needle) {
                    if ($message !== '' && str_contains($message, $needle)) {
                        $matched = $pattern;
                        break 2;
                    }
                }
            }

            // Everything unrecognised lands in one honest bucket rather than
            // being forced into the nearest-looking pattern.
            $key = $matched['key'] ?? 'other';
            $label = $matched['label'] ?? 'Uncategorised failures';

            if (!isset($buckets[$key])) {
                $buckets[$key] = [
                    'key'             => $key,
                    'pattern'         => $label,
                    'impact'          => $matched['impact'] ?? 'low',
                    'recommendation'  => $matched['hint'] ?? 'Open the run trace to see the underlying error.',
                    'frequency'       => 0,
                    'affected_agents' => [],
                    'examples'        => [],
                ];
            }

            $buckets[$key]['frequency']++;

            $agentName = $failure->agent_name ?? 'Deleted agent';
            if (!in_array($agentName, $buckets[$key]['affected_agents'], true)) {
                $buckets[$key]['affected_agents'][] = $agentName;
            }
            if (count($buckets[$key]['examples']) < 3 && trim((string) $failure->error_message) !== '') {
                $buckets[$key]['examples'][] = mb_substr((string) $failure->error_message, 0, 200);
            }
        }

        $patterns = array_values($buckets);
        usort($patterns, fn ($a, $b) => $b['frequency'] <=> $a['frequency']);

        return $patterns;
    }

    /**
     * Headline metrics, each with the direction it moved against the previous
     * window of the same length.
     *
     * @return array<int, array<string, mixed>>
     */
    private function insights(int $sid, int $days): array
    {
        $current = $this->windowTotals($sid, now()->subDays($days - 1)->startOfDay(), now());
        $previous = $this->windowTotals(
            $sid,
            now()->subDays(($days * 2) - 1)->startOfDay(),
            now()->subDays($days)->endOfDay()
        );

        $rate = fn (array $w) => $w['total'] > 0 ? round(($w['successes'] / $w['total']) * 100, 1) : null;

        // Fewer failures is an improvement, so its direction is inverted.
        $direction = function (?float $now, ?float $before, bool $lowerIsBetter = false) {
            if ($now === null || $before === null || $now === $before) {
                return 'stable';
            }
            $up = $now > $before;

            return ($up xor $lowerIsBetter) ? 'up' : 'down';
        };

        return [
            [
                'metric'  => 'Success rate',
                'value'   => $rate($current) !== null ? $rate($current) . '%' : '—',
                'trend'   => $direction($rate($current), $rate($previous)),
                'insight' => $current['total'] === 0
                    ? 'No runs in this window yet.'
                    : $current['successes'] . ' of ' . $current['total'] . ' runs succeeded.',
            ],
            [
                'metric'  => 'Avg duration',
                'value'   => $current['avg_duration'] > 0 ? round($current['avg_duration'] / 1000, 1) . 's' : '—',
                'trend'   => $direction($current['avg_duration'], $previous['avg_duration'], true),
                'insight' => 'Mean wall-clock time per run over the window.',
            ],
            [
                'metric'  => 'Total cost',
                'value'   => '$' . number_format($current['cost'], 2),
                'trend'   => $direction($current['cost'], $previous['cost'], true),
                'insight' => number_format($current['tokens']) . ' tokens consumed.',
            ],
            [
                'metric'  => 'Failures',
                'value'   => (string) $current['failures'],
                'trend'   => $direction($current['failures'], $previous['failures'], true),
                'insight' => $current['failures'] === 0
                    ? 'No failed runs in this window.'
                    : 'Grouped into ' . count($this->failurePatterns($sid, $days)) . ' pattern(s).',
            ],
        ];
    }

    /** @return array{total:int, successes:int, failures:int, cost:float, tokens:int, avg_duration:float} */
    private function windowTotals(int $sid, $from, $to): array
    {
        $row = DB::table('agentic_agent_runs')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$from, $to])
            ->select(
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successes"),
                DB::raw("SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as failures"),
                DB::raw('COALESCE(SUM(cost), 0) as cost'),
                DB::raw('COALESCE(SUM(tokens_used), 0) as tokens'),
                DB::raw('COALESCE(AVG(duration_ms), 0) as avg_duration')
            )
            ->first();

        return [
            'total'        => (int) $row->total,
            'successes'    => (int) $row->successes,
            'failures'     => (int) $row->failures,
            'cost'         => (float) $row->cost,
            'tokens'       => (int) $row->tokens,
            'avg_duration' => (float) $row->avg_duration,
        ];
    }

    public function index(Request $request)
    {
        $context = $this->agenticContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];
        $days = $this->windowDays($request);

        $optimizations = DB::table('agentic_optimizations')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->when($this->activeFilter($request->input('status')), fn ($q, $status) => $q->where('status', $status))
            ->orderByRaw("FIELD(priority, 'high', 'medium', 'low')")
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn ($row) => [
                'id'                        => (int) $row->id,
                'title'                     => $row->title,
                'description'               => $row->description,
                'category'                  => $row->category,
                'priority'                  => $row->priority,
                'estimated_impact'          => $row->estimated_impact,
                'implementation_complexity' => $row->implementation_complexity,
                'affected_agents'           => $this->decodeList($row->affected_agents),
                'status'                    => $row->status,
                'applied_at'                => $row->applied_at,
                'created_at'                => $row->created_at,
            ])->all();

        $lastRun = DB::table('agentic_reflection_runs')
            ->where('sub_institute_id', $sid)->orderByDesc('id')->first();

        return $this->ok('Reflection fetched successfully', [
            'window_days'      => $days,
            'insights'         => $this->insights($sid, $days),
            'failure_patterns' => $this->failurePatterns($sid, $days),
            'optimizations'    => $optimizations,
            'last_analysis'    => $lastRun ? [
                'id'                    => (int) $lastRun->id,
                'runs_analysed'         => (int) $lastRun->runs_analysed,
                'failures_found'        => (int) $lastRun->failures_found,
                'patterns_found'        => (int) $lastRun->patterns_found,
                'optimizations_created' => (int) $lastRun->optimizations_created,
                'created_at'            => $lastRun->created_at,
            ] : null,
        ]);
    }

    /**
     * POST /reflection/analyse - the "Run New Analysis" button.
     *
     * Turns each detected failure pattern into an optimization suggestion,
     * skipping patterns that already have an open suggestion so repeated runs
     * do not pile up duplicates.
     */
    public function analyse(Request $request)
    {
        $context = $this->agenticContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];
        $days = $this->windowDays($request);

        $totals = $this->windowTotals($sid, now()->subDays($days - 1)->startOfDay(), now());
        $patterns = $this->failurePatterns($sid, $days);

        $existing = DB::table('agentic_optimizations')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->where('status', 'open')
            ->pluck('title')
            ->map(fn ($title) => strtolower($title))
            ->all();

        $created = 0;
        foreach ($patterns as $pattern) {
            $title = 'Reduce ' . strtolower($pattern['pattern']);
            if (in_array(strtolower($title), $existing, true)) {
                continue;
            }

            DB::table('agentic_optimizations')->insert([
                'sub_institute_id'          => $sid,
                'title'                     => $title,
                'description'               => $pattern['recommendation'],
                'category'                  => $pattern['key'] === 'token_limit' ? 'cost' : 'reliability',
                'priority'                  => $pattern['impact'],
                'estimated_impact'          => 'Addresses ' . $pattern['frequency'] . ' failure(s) in the last ' . $days . ' days',
                'implementation_complexity' => $pattern['key'] === 'auth' ? 'low' : 'medium',
                'affected_agents'           => json_encode($pattern['affected_agents']),
                'status'                    => 'open',
                'created_at'                => now(),
                'updated_at'                => now(),
            ]);
            $created++;
        }

        $reflectionId = DB::table('agentic_reflection_runs')->insertGetId([
            'sub_institute_id'      => $sid,
            'window_days'           => $days,
            'runs_analysed'         => $totals['total'],
            'failures_found'        => $totals['failures'],
            'patterns_found'        => count($patterns),
            'optimizations_created' => $created,
            'summary'               => json_encode([
                'success_rate' => $totals['total'] > 0 ? round(($totals['successes'] / $totals['total']) * 100, 1) : null,
                'cost'         => round($totals['cost'], 4),
                'tokens'       => $totals['tokens'],
            ]),
            'created_by'            => $context['user_id'],
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        DB::table('agentic_optimizations')
            ->where('sub_institute_id', $sid)
            ->whereNull('reflection_run_id')
            ->where('status', 'open')
            ->update(['reflection_run_id' => $reflectionId]);

        return $this->ok(
            $totals['total'] === 0
                ? 'No runs in the last ' . $days . ' days to analyse.'
                : 'Analysed ' . $totals['total'] . ' runs and found ' . count($patterns) . ' pattern(s).',
            [
                'id'                    => (int) $reflectionId,
                'runs_analysed'         => $totals['total'],
                'failures_found'        => $totals['failures'],
                'patterns_found'        => count($patterns),
                'optimizations_created' => $created,
            ]
        );
    }

    /** PUT /reflection/optimizations/{id} - Apply / Dismiss / Reopen. */
    public function updateOptimization(Request $request, $id)
    {
        $context = $this->agenticContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $validator = Validator::make($request->all(), [
            'status' => 'required|string|in:open,applied,dismissed',
        ]);
        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422);
        }

        $optimization = DB::table('agentic_optimizations')
            ->where('sub_institute_id', $sid)->where('id', (int) $id)->whereNull('deleted_at')->first();
        if (!$optimization) {
            return $this->fail('Optimization not found.', 404);
        }

        $status = $request->input('status');

        DB::table('agentic_optimizations')->where('id', $optimization->id)->update([
            'status'     => $status,
            'applied_at' => $status === 'applied' ? now() : null,
            'applied_by' => $status === 'applied' ? $context['user_id'] : null,
            'updated_at' => now(),
        ]);

        return $this->ok('Optimization marked as ' . $status, ['id' => (int) $optimization->id, 'status' => $status]);
    }
}
