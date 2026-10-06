<?php

namespace App\Http\Controllers\Api\Agentic;

use App\Http\Controllers\Api\Agentic\Concerns\ResolvesAgenticContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Agent tool invocations - the six forms on the agent detail screen.
 *
 *   GET  /api/agentic/tools                      catalogue + per-tool usage counts
 *   GET  /api/agentic/tools/invocations          history (agent, tool, status)
 *   POST /api/agentic/tools/{tool}/invoke        record an invocation
 *   GET  /api/agentic/tools/invocations/{id}     one invocation with its payload
 *
 * Each tool declares its own payload shape, so a malformed call is a 422 naming
 * the field rather than a 500 from whatever consumes it downstream. The
 * invocation row is the audit record; performing the side effect (sending the
 * email, running the query) belongs to the integration that owns that system.
 */
class ToolController extends Controller
{
    use ResolvesAgenticContext;

    private const TABLE = 'agentic_tool_invocations';

    /**
     * tool => [label, validation rules for its payload].
     *
     * The keys mirror the tool ids on the agent record, so an agent can only
     * invoke what it was configured with.
     */
    private const TOOLS = [
        'knowledge' => [
            'label' => 'Knowledge Base',
            'agent_tool' => 'knowledge_base',
            'rules' => [
                'query_text'       => 'required|string|max:2000',
                'source'           => 'nullable|string|max:191',
                'response'         => 'nullable|string',
                'confidence_score' => 'nullable|numeric|min:0|max:1',
            ],
        ],
        'email' => [
            'label' => 'Email',
            'agent_tool' => 'email',
            'rules' => [
                'to_email' => 'required|email|max:191',
                'subject'  => 'required|string|max:191',
                'body'     => 'required|string',
            ],
        ],
        'web_search' => [
            'label' => 'Web Search',
            'agent_tool' => 'web_search',
            'rules' => [
                'query'         => 'required|string|max:500',
                'results'       => 'nullable|string',
                'source_engine' => 'nullable|string|max:100',
            ],
        ],
        'sql_exec' => [
            'label' => 'SQL Query',
            'agent_tool' => 'sql_query',
            'rules' => [
                'query'            => 'required|string',
                'execution_status' => 'nullable|string|max:50',
                'rows_affected'    => 'nullable|integer|min:0',
                'error_message'    => 'nullable|string',
            ],
        ],
        'visualization' => [
            'label' => 'Data Visualization',
            'agent_tool' => 'data_viz',
            'rules' => [
                'chart_type'       => 'required|string|max:50',
                'input_data'       => 'nullable|string',
                'generated_config' => 'nullable|string',
                'output_url'       => 'nullable|string|max:500',
            ],
        ],
        'file' => [
            'label' => 'File Operations',
            'agent_tool' => 'file_operations',
            'rules' => [
                'file_name'    => 'required|string|max:191',
                'file_type'    => 'nullable|string|max:100',
                'file_size'    => 'nullable|integer|min:0',
                'storage_path' => 'nullable|string|max:500',
                'uploaded_by'  => 'nullable|string|max:191',
            ],
        ],
    ];

    /**
     * A SQL tool that can run anything is a data-exfiltration hole, so the
     * recorded query is refused outright when it is not a read.
     */
    private const SQL_FORBIDDEN = [
        'insert', 'update', 'delete', 'drop', 'truncate', 'alter', 'create',
        'grant', 'revoke', 'replace', 'rename',
    ];

    public function catalogue(Request $request)
    {
        $context = $this->agenticContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $counts = DB::table(self::TABLE)
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->whereNull('deleted_at')
            ->select('tool', DB::raw('count(*) as total'))
            ->groupBy('tool')
            ->pluck('total', 'tool');

        $tools = [];
        foreach (self::TOOLS as $key => $config) {
            $tools[] = [
                'tool'       => $key,
                'label'      => $config['label'],
                'agent_tool' => $config['agent_tool'],
                'fields'     => array_keys($config['rules']),
                'required'   => array_keys(array_filter(
                    $config['rules'],
                    fn ($rule) => str_contains($rule, 'required')
                )),
                'invocations' => (int) ($counts[$key] ?? 0),
            ];
        }

        return $this->ok('Tool catalogue fetched successfully', $tools);
    }

    public function invocations(Request $request)
    {
        $context = $this->agenticContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $query = DB::table(self::TABLE . ' as t')
            ->leftJoin('agentic_agents as a', 'a.id', '=', 't.agent_id')
            ->where('t.sub_institute_id', $sid)
            ->whereNull('t.deleted_at');

        if ($agentId = $this->activeFilter($request->input('agent_id'))) {
            $query->where('t.agent_id', $agentId);
        }
        if ($tool = $this->activeFilter($request->input('tool'))) {
            $query->where('t.tool', $tool);
        }
        if ($status = $this->activeFilter($request->input('status'))) {
            $query->where('t.status', $status);
        }

        $total = (clone $query)->count();
        [$page, $perPage] = $this->paging($request);

        $rows = $query
            ->orderByDesc('t.created_at')
            ->forPage($page, $perPage)
            ->get(['t.id', 't.agent_id', 't.run_id', 't.tool', 't.status', 't.error_message', 't.created_at', 'a.name as agent_name']);

        return $this->ok(
            'Tool invocations fetched successfully',
            $rows->map(fn ($row) => [
                'id'            => (int) $row->id,
                'agent_id'      => (int) $row->agent_id,
                'agent_name'    => $row->agent_name ?? 'Deleted agent',
                'run_id'        => $row->run_id !== null ? (int) $row->run_id : null,
                'tool'          => $row->tool,
                'label'         => self::TOOLS[$row->tool]['label'] ?? $row->tool,
                'status'        => $row->status,
                'error_message' => $row->error_message,
                'created_at'    => $row->created_at,
            ])->all(),
            ['pagination' => $this->pagination($page, $perPage, $total)]
        );
    }

    public function showInvocation(Request $request, $id)
    {
        $context = $this->agenticContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $row = DB::table(self::TABLE)
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->where('id', (int) $id)
            ->whereNull('deleted_at')
            ->first();

        if (!$row) {
            return $this->fail('Tool invocation not found.', 404);
        }

        return $this->ok('Tool invocation fetched successfully', [
            'id'            => (int) $row->id,
            'agent_id'      => (int) $row->agent_id,
            'run_id'        => $row->run_id !== null ? (int) $row->run_id : null,
            'tool'          => $row->tool,
            'label'         => self::TOOLS[$row->tool]['label'] ?? $row->tool,
            'status'        => $row->status,
            'payload'       => json_decode((string) $row->payload, true),
            'response'      => json_decode((string) $row->response, true),
            'error_message' => $row->error_message,
            'created_at'    => $row->created_at,
        ]);
    }

    /**
     * POST /tools/{tool}/invoke
     *
     * Validates the payload against that tool's shape, checks the agent is
     * actually configured for it, and records the invocation.
     */
    public function invoke(Request $request, $tool)
    {
        $context = $this->agenticContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $config = self::TOOLS[$tool] ?? null;
        if (!$config) {
            return $this->fail('Unknown tool "' . $tool . '".', 404);
        }

        $validator = Validator::make($request->all(), array_merge(
            ['agent_id' => 'required|integer|min:1', 'run_id' => 'nullable|integer|min:1'],
            $config['rules']
        ));
        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422);
        }

        $agent = DB::table('agentic_agents')
            ->where('sub_institute_id', $sid)
            ->where('id', (int) $request->input('agent_id'))
            ->whereNull('deleted_at')
            ->first();

        if (!$agent) {
            return $this->fail('Agent not found.', 404);
        }

        $enabled = $this->decodeList($agent->tools);
        if (!in_array($config['agent_tool'], $enabled, true)) {
            return $this->fail(
                $agent->name . ' does not have the ' . $config['label'] . ' tool enabled. Add it on the agent first.',
                422
            );
        }

        if ($tool === 'sql_exec' && ($reason = $this->rejectUnsafeSql((string) $request->input('query')))) {
            return $this->fail($reason, 422);
        }

        $payload = [];
        foreach (array_keys($config['rules']) as $field) {
            if ($request->has($field)) {
                $payload[$field] = $request->input($field);
            }
        }

        $id = DB::table(self::TABLE)->insertGetId([
            'sub_institute_id' => $sid,
            'agent_id'         => $agent->id,
            'run_id'           => $request->input('run_id'),
            'tool'             => $tool,
            'payload'          => json_encode($payload),
            'response'         => $request->has('response') ? json_encode(['response' => $request->input('response')]) : null,
            'status'           => 'success',
            'created_by'       => $context['user_id'],
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        // A tool call inside a run belongs in that run's trace.
        if ($request->filled('run_id')) {
            $runId = (int) $request->input('run_id');
            $belongs = DB::table('agentic_agent_runs')
                ->where('sub_institute_id', $sid)->where('id', $runId)->whereNull('deleted_at')->exists();

            if ($belongs) {
                $sequence = (int) DB::table('agentic_run_tasks')
                    ->where('sub_institute_id', $sid)->where('run_id', $runId)->max('sequence');

                DB::table('agentic_run_tasks')->insert([
                    'sub_institute_id' => $sid,
                    'run_id'           => $runId,
                    'sequence'         => $sequence + 1,
                    'description'      => $config['label'] . ' invoked',
                    'status'           => 'success',
                    'tool'             => $tool,
                    'result'           => json_encode($payload),
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);
            }
        }

        return $this->ok($config['label'] . ' recorded successfully', ['id' => (int) $id]);
    }

    /** @return string|null the reason it was refused, or null when it is a read */
    private function rejectUnsafeSql(string $query): ?string
    {
        $normalised = strtolower(trim($query));

        if ($normalised === '') {
            return 'The query is empty.';
        }

        foreach (self::SQL_FORBIDDEN as $keyword) {
            if (preg_match('/\b' . $keyword . '\b/', $normalised)) {
                return 'Only read queries are allowed here — "' . strtoupper($keyword) . '" was found in the statement.';
            }
        }

        if (!str_starts_with($normalised, 'select') && !str_starts_with($normalised, 'with')) {
            return 'Only SELECT queries are allowed here.';
        }

        return null;
    }
}
