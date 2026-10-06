<?php

namespace App\Http\Controllers\Api\Agentic;

use App\Http\Controllers\Api\Agentic\Concerns\ResolvesAgenticContext;
use App\Http\Controllers\Api\Agentic\Concerns\ValidatesAgentSchema;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Agent registry: the Agent Library, Agent Dashboard and Create Agent screens.
 *
 *   GET    /api/agentic/agents            list (search, status, module, sort, paginate)
 *   POST   /api/agentic/agents            create
 *   GET    /api/agentic/agents/{id}       one agent, with run stats and tools
 *   PUT    /api/agentic/agents/{id}       update
 *   PATCH  /api/agentic/agents/{id}/status  deploy / pause / archive
 *   POST   /api/agentic/agents/{id}/clone   duplicate as a draft
 *   DELETE /api/agentic/agents/{id}       soft delete
 *   GET    /api/agentic/agents/meta       tool catalogue + module options + status counts
 */
class AgentController extends Controller
{
    use ResolvesAgenticContext;
    use ValidatesAgentSchema;

    private const TABLE = 'agentic_agents';

    /**
     * The tool catalogue. Ids match what the agent detail screen renders a form
     * for, so adding a tool here without a form would show an enabled tool the
     * user cannot actually invoke.
     */
    public const TOOLS = [
        ['id' => 'knowledge_base',  'label' => 'Knowledge Base',      'description' => 'Query indexed documents and return grounded answers.'],
        ['id' => 'web_search',      'label' => 'Web Search',          'description' => 'Search the public web and summarise results.'],
        ['id' => 'email',           'label' => 'Email',               'description' => 'Compose and send email on the agent\'s behalf.'],
        ['id' => 'sql_query',       'label' => 'SQL Query',           'description' => 'Run a read query against reporting data.'],
        ['id' => 'data_viz',        'label' => 'Data Visualization',  'description' => 'Turn a result set into a chart configuration.'],
        ['id' => 'file_operations', 'label' => 'File Operations',     'description' => 'Upload, read and store files for the agent.'],
        ['id' => 'n8n',             'label' => 'n8n',                 'description' => 'Trigger an external n8n automation workflow.'],
    ];

    private const STATUSES = ['draft', 'deployed', 'paused', 'archived'];

    /** Which model names the create wizard offers. */
    public const MODELS = ['gpt-4', 'gpt-4o', 'gpt-4o-mini', 'gpt-3.5-turbo', 'claude-3-opus', 'claude-3-sonnet', 'gemini-1.5-pro'];

    private function rules(bool $creating): array
    {
        return [
            'name'          => ($creating ? 'required' : 'sometimes|required') . '|string|max:191',
            'description'   => 'nullable|string',
            'module'        => 'nullable|string|max:191',
            'sub_module'    => 'nullable|string|max:191',
            'role'          => 'nullable|string|max:191',
            'model'         => 'nullable|string|max:100',
            'temperature'   => 'nullable|numeric|min:0|max:2',
            'max_tokens'    => 'nullable|integer|min:100|max:8000',
            'system_prompt' => 'nullable|string',
            'tools'         => 'nullable',
            'status'        => 'nullable|string|in:' . implode(',', self::STATUSES),

            'icon'          => 'nullable|string|max:60',
            'function_text' => 'nullable|string',
            'workflow'      => 'nullable',
            'outputs'       => 'nullable',
            'cta_label'     => 'nullable|string|max:120',
            'cta_link'      => 'nullable|string|max:500',
            'cta_target'    => 'nullable|string|in:internal,external',

            'execution_mode'  => 'nullable|string|in:none,http',
            // A run posts to this, so it must be an absolute http(s) URL - a
            // relative path would resolve against the API host by accident.
            'endpoint_url'    => 'nullable|url|max:500',
            'endpoint_method' => 'nullable|string|in:GET,POST,PUT,PATCH',
            'endpoint_headers' => 'nullable|array',
            'endpoint_timeout' => 'nullable|integer|min:5|max:300',
            'input_schema'     => 'nullable|array',
            'config_schema'    => 'nullable|array',
            'launch_component' => 'nullable|string|max:60',
        ];
    }

    /**
     * Agents this tenant can see.
     *
     * Platform catalogue rows carry a NULL sub_institute_id and are visible to
     * everyone. `$ownedOnly` narrows to the tenant's own, which every write
     * path uses so the shared catalogue cannot be edited per tenant.
     */
    private function baseQuery(int $sid, bool $ownedOnly = false)
    {
        $query = DB::table(self::TABLE)->whereNull('deleted_at');

        if ($ownedOnly) {
            return $query->where('sub_institute_id', $sid);
        }

        return $query->where(function ($q) use ($sid) {
            $q->where('sub_institute_id', $sid)->orWhereNull('sub_institute_id');
        });
    }

    /**
     * A write missed the owned-rows scope: say whether the agent does not exist
     * or is catalogue content this tenant may only read, so the UI can explain
     * rather than showing a bare "not found".
     */
    private function readOnlyOrMissing(int $sid, int $id, string $verb)
    {
        if ($this->baseQuery($sid)->where('id', $id)->exists()) {
            return $this->fail(
                'This is a platform agent shared with every organisation, so it cannot be ' . $verb
                . ' here. Duplicate it to make your own editable copy.',
                403
            );
        }

        return $this->fail('Agent not found.', 404);
    }

    /** Shape one row for the client, decoding tools and attaching run stats. */
    private function present($row, array $stats = [], array $configured = []): array
    {
        $agentStats = $stats[$row->id] ?? ['total' => 0, 'success' => 0, 'last_run' => null];
        $total = (int) $agentStats['total'];
        $success = (int) $agentStats['success'];

        return [
            'id'            => (int) $row->id,
            'name'          => $row->name,
            'description'   => $row->description,
            'module'        => $row->module,
            'sub_module'    => $row->sub_module,
            'role'          => $row->role,
            'model'         => $row->model,
            'temperature'   => (float) $row->temperature,
            'max_tokens'    => (int) $row->max_tokens,
            'system_prompt' => $row->system_prompt,
            'tools'         => $this->decodeList($row->tools),
            'status'        => $row->status,

            // Catalogue content. `editable` is what the UI gates its actions on
            // rather than re-deriving the rule from origin in three places.
            'origin'        => $row->origin ?? 'tenant',
            'editable'      => ($row->origin ?? 'tenant') !== 'platform',
            'slug'          => $row->slug,
            'icon'          => $row->icon,
            'function_text' => $row->function_text,
            'workflow'      => $this->decodeList($row->workflow),
            'outputs'       => $this->decodeList($row->outputs),
            'cta_label'     => $row->cta_label,
            'cta_link'      => $row->cta_link,
            'cta_target'    => $row->cta_target ?? 'internal',

            // Endpoint headers are deliberately absent: they hold API keys.
            'execution_mode' => $row->execution_mode ?? 'none',
            'endpoint_url'   => $row->endpoint_url,
            'endpoint_method' => $row->endpoint_method ?? 'POST',
            'has_endpoint_headers' => !empty($row->endpoint_headers),

            // The typed contract. `input_schema` is the launch form,
            // `config_schema` the one-time setup, `launch_component` names a
            // bespoke screen for the few agents a generic form cannot express.
            'input_schema'     => $this->decodeSchema($row->input_schema ?? null),
            'config_schema'    => $this->decodeSchema($row->config_schema ?? null),
            'launch_component' => $row->launch_component ?? null,
            // False only when this agent asks for setup and this tenant has
            // not completed it - the UI blocks Launch on that.
            'configured'       => in_array((int) $row->id, $configured, true),
            'total_runs'    => $total,
            // 0 runs means unknown, not 0% - a never-run agent has not failed.
            'success_rate'  => $total > 0 ? round(($success / $total) * 100, 1) : null,
            'last_run_at'   => $agentStats['last_run'],
            'created_at'    => $row->created_at,
            'updated_at'    => $row->updated_at,
        ];
    }

    /**
     * Which of these agents this tenant has finished setting up.
     *
     * An agent with no config_schema needs no setup, so it counts as
     * configured; one that asks for credentials only counts once a config row
     * exists for this tenant. Resolved in a single query for the whole page.
     *
     * @return array<int, int>
     */
    private function configuredAgentIds(int $sid, $rows): array
    {
        $needsSetup = [];
        $ready = [];

        foreach ($rows as $row) {
            $id = (int) $row->id;
            $schema = $this->decodeSchema($row->config_schema ?? null);

            // Optional setup does not block a launch, so only a schema with a
            // required field can leave an agent unconfigured. A saved row
            // implies those fields were answered - ConfigController enforces it.
            if (!$this->schemaHasRequired($schema)) {
                $ready[] = $id;
            } else {
                $needsSetup[] = $id;
            }
        }

        if ($needsSetup === []) {
            return $ready;
        }

        $saved = DB::table('agentic_agent_configs')
            ->where('sub_institute_id', $sid)
            ->whereIn('agent_id', $needsSetup)
            ->pluck('agent_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_merge($ready, $saved);
    }

    /**
     * Run totals per agent, in one query rather than one per row.
     *
     * @param  array<int, int> $agentIds
     * @return array<int, array{total:int, success:int, last_run:?string}>
     */
    private function runStats(int $sid, array $agentIds): array
    {
        if ($agentIds === []) {
            return [];
        }

        $rows = DB::table('agentic_agent_runs')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->whereIn('agent_id', $agentIds)
            ->select(
                'agent_id',
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success"),
                DB::raw('MAX(created_at) as last_run')
            )
            ->groupBy('agent_id')
            ->get();

        $stats = [];
        foreach ($rows as $row) {
            $stats[(int) $row->agent_id] = [
                'total'    => (int) $row->total,
                'success'  => (int) $row->success,
                'last_run' => $row->last_run,
            ];
        }

        return $stats;
    }

    public function index(Request $request)
    {
        $context = $this->agenticContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $query = $this->baseQuery($sid);

        if ($search = $this->activeFilter($request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('module', 'like', "%{$search}%");
            });
        }
        if ($status = $this->activeFilter($request->input('status'))) {
            $query->where('status', $status);
        }
        if ($module = $this->activeFilter($request->input('module'))) {
            $query->where('module', $module);
        }
        if ($origin = $this->activeFilter($request->input('origin'))) {
            // 'platform' rows have no owner; 'tenant' rows are this tenant's.
            $origin === 'platform'
                ? $query->whereNull('sub_institute_id')
                : $query->where('sub_institute_id', $sid);
        }
        if ($tool = $this->activeFilter($request->input('tool'))) {
            // tools is a json array of ids; a LIKE on the quoted id is enough
            // here and keeps the query portable across MySQL versions.
            $query->where('tools', 'like', '%"' . $tool . '"%');
        }

        $allowedSorts = ['name', 'status', 'module', 'created_at', 'updated_at'];
        $sort = in_array($request->input('sort'), $allowedSorts, true) ? $request->input('sort') : 'created_at';
        $direction = strtolower((string) $request->input('direction')) === 'asc' ? 'asc' : 'desc';

        $total = (clone $query)->count();
        [$page, $perPage] = $this->paging($request);

        $rows = $query->orderBy($sort, $direction)->orderBy('id', 'desc')->forPage($page, $perPage)->get();
        $ids = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();
        $stats = $this->runStats($sid, $ids);
        $configured = $this->configuredAgentIds($sid, $rows);

        return $this->ok(
            'Agents fetched successfully',
            $rows->map(fn ($row) => $this->present($row, $stats, $configured))->all(),
            ['pagination' => $this->pagination($page, $perPage, $total)]
        );
    }

    public function show(Request $request, $id)
    {
        $context = $this->agenticContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $row = $this->baseQuery($sid)->where('id', (int) $id)->first();
        if (!$row) {
            return $this->fail('Agent not found.', 404);
        }

        $stats = $this->runStats($sid, [(int) $row->id]);
        $agent = $this->present($row, $stats, $this->configuredAgentIds($sid, collect([$row])));

        // Recent runs, so the detail panel opens on something useful.
        $agent['recent_runs'] = DB::table('agentic_agent_runs')
            ->where('sub_institute_id', $sid)
            ->where('agent_id', $row->id)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['id', 'status', 'input', 'output', 'duration_ms', 'tokens_used', 'cost', 'created_at'])
            ->all();

        $agent['tool_invocations'] = DB::table('agentic_tool_invocations')
            ->where('sub_institute_id', $sid)
            ->where('agent_id', $row->id)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['id', 'tool', 'status', 'created_at'])
            ->all();

        return $this->ok('Agent fetched successfully', $agent);
    }

    public function store(Request $request)
    {
        $context = $this->agenticContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $validator = Validator::make($request->all(), $this->rules(true));
        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422);
        }

        $name = trim((string) $request->input('name'));

        if ($this->baseQuery($sid)->where('name', $name)->exists()) {
            return $this->fail('An agent called "' . $name . '" already exists.', 422);
        }

        $tools = $this->decodeList($request->input('tools'));
        $unknown = array_diff($tools, array_column(self::TOOLS, 'id'));
        if ($unknown !== []) {
            return $this->fail('Unknown tool: ' . implode(', ', $unknown), 422);
        }

        $id = DB::table(self::TABLE)->insertGetId([
            'sub_institute_id' => $sid,
            'origin'           => 'tenant',
            'name'             => $name,
            'description'      => $request->input('description'),
            'module'           => $request->input('module'),
            'sub_module'       => $request->input('sub_module'),
            'role'             => $request->input('role'),
            'model'            => $request->input('model') ?: 'gpt-4',
            'temperature'      => $request->input('temperature', 0.7),
            'max_tokens'       => $request->input('max_tokens', 2000),
            'system_prompt'    => $request->input('system_prompt'),
            'tools'            => json_encode(array_values($tools)),
            // New agents start as drafts: deploying is a deliberate act.
            'status'           => $request->input('status', 'draft'),
            'created_by'       => $context['user_id'],
            'updated_by'       => $context['user_id'],
            'created_at'       => now(),
            'updated_at'       => now(),
        ] + $this->cataloguePayload($request));

        return $this->ok('Agent created successfully', ['id' => (int) $id]);
    }

    public function update(Request $request, $id)
    {
        $context = $this->agenticContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $existing = $this->baseQuery($sid, true)->where('id', (int) $id)->first();
        if (!$existing) {
            return $this->readOnlyOrMissing($sid, (int) $id, 'edited');
        }

        $validator = Validator::make($request->all(), $this->rules(false));
        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422);
        }

        $update = ['updated_by' => $context['user_id'], 'updated_at' => now()];

        foreach (['name', 'description', 'module', 'sub_module', 'role', 'model', 'system_prompt', 'status'] as $field) {
            if ($request->has($field)) {
                $value = $request->input($field);
                $update[$field] = is_string($value) ? trim($value) : $value;
            }
        }
        foreach (['temperature', 'max_tokens'] as $field) {
            if ($request->has($field)) {
                $update[$field] = $request->input($field);
            }
        }
        if ($request->has('tools')) {
            $tools = $this->decodeList($request->input('tools'));
            $unknown = array_diff($tools, array_column(self::TOOLS, 'id'));
            if ($unknown !== []) {
                return $this->fail('Unknown tool: ' . implode(', ', $unknown), 422);
            }
            $update['tools'] = json_encode(array_values($tools));
        }

        $update += $this->cataloguePayload($request);

        if (isset($update['name']) && $update['name'] !== $existing->name) {
            if ($this->baseQuery($sid)->where('name', $update['name'])->where('id', '!=', $existing->id)->exists()) {
                return $this->fail('Another agent already uses that name.', 422);
            }
        }

        DB::table(self::TABLE)->where('id', $existing->id)->update($update);

        return $this->ok('Agent updated successfully', ['id' => (int) $existing->id]);
    }

    /**
     * PATCH /agents/{id}/status - the Deploy / Pause / Archive control.
     *
     * Deploying is refused for an agent with no system prompt: it would start
     * accepting runs with no instructions and fail every one of them.
     */
    public function setStatus(Request $request, $id)
    {
        $context = $this->agenticContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $validator = Validator::make($request->all(), [
            'status' => 'required|string|in:' . implode(',', self::STATUSES),
        ]);
        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422);
        }

        $agent = $this->baseQuery($sid, true)->where('id', (int) $id)->first();
        if (!$agent) {
            return $this->readOnlyOrMissing($sid, (int) $id, 'changed');
        }

        $status = $request->input('status');

        if ($status === 'deployed' && trim((string) $agent->system_prompt) === '') {
            return $this->fail('Add a system prompt before deploying — an agent with no instructions cannot run.', 422);
        }

        DB::table(self::TABLE)->where('id', $agent->id)->update([
            'status'     => $status,
            'updated_by' => $context['user_id'],
            'updated_at' => now(),
        ]);

        return $this->ok('Agent ' . $status . ' successfully', ['id' => (int) $agent->id, 'status' => $status]);
    }

    /** Duplicate an agent as a fresh draft, so a proven config can be varied safely. */
    public function clone(Request $request, $id)
    {
        $context = $this->agenticContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $source = $this->baseQuery($sid)->where('id', (int) $id)->first();
        if (!$source) {
            return $this->fail('Agent not found.', 404);
        }

        $name = trim((string) $request->input('name')) ?: $source->name . ' (Copy)';
        if ($this->baseQuery($sid)->where('name', $name)->exists()) {
            return $this->fail('An agent called "' . $name . '" already exists.', 422);
        }

        $newId = DB::table(self::TABLE)->insertGetId([
            'sub_institute_id' => $sid,
            'name'             => $name,
            'description'      => $source->description,
            'module'           => $source->module,
            'sub_module'       => $source->sub_module,
            'role'             => $source->role,
            'model'            => $source->model,
            'temperature'      => $source->temperature,
            'max_tokens'       => $source->max_tokens,
            'system_prompt'    => $source->system_prompt,
            'tools'            => $source->tools,
            // The copy is a tenant agent even when the source was catalogue.
            'origin'           => 'tenant',
            'icon'             => $source->icon,
            'function_text'    => $source->function_text,
            'workflow'         => $source->workflow,
            'outputs'          => $source->outputs,
            'cta_label'        => $source->cta_label,
            'cta_link'         => $source->cta_link,
            'cta_target'       => $source->cta_target,
            'execution_mode'   => $source->execution_mode,
            'endpoint_url'     => $source->endpoint_url,
            'endpoint_method'  => $source->endpoint_method,
            'endpoint_headers' => $source->endpoint_headers,
            'endpoint_timeout' => $source->endpoint_timeout,
            'input_schema'     => $source->input_schema,
            'config_schema'    => $source->config_schema,
            'launch_component' => $source->launch_component,
            'status'           => 'draft',
            'created_by'       => $context['user_id'],
            'updated_by'       => $context['user_id'],
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        return $this->ok('Agent duplicated successfully', ['id' => (int) $newId, 'name' => $name]);
    }

    public function destroy(Request $request, $id)
    {
        $context = $this->agenticContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $agent = $this->baseQuery($sid, true)->where('id', (int) $id)->first();
        if (!$agent) {
            return $this->readOnlyOrMissing($sid, (int) $id, 'deleted');
        }

        // Runs are kept: deleting an agent must not erase the record of what it
        // did. They are read through the run list, which tolerates a gone agent.
        DB::table(self::TABLE)->where('id', $agent->id)->update([
            'deleted_by' => $context['user_id'],
            'deleted_at' => now(),
        ]);

        return $this->ok('Agent deleted successfully', ['id' => (int) $agent->id]);
    }

    /**
     * The catalogue and endpoint columns, taken only when the caller sent them.
     *
     * Absent keys are left alone so a partial edit cannot blank content the
     * form did not show. Endpoint headers are write-only: they can be set here
     * but never come back out of present().
     *
     * @return array<string, mixed>
     */
    private function cataloguePayload(Request $request): array
    {
        $data = [];

        foreach (['icon', 'function_text', 'cta_label', 'cta_link', 'cta_target', 'execution_mode', 'endpoint_url', 'endpoint_method'] as $field) {
            if ($request->has($field)) {
                $value = $request->input($field);
                $value = is_string($value) ? trim($value) : $value;
                $data[$field] = ($value === '' ) ? null : $value;
            }
        }

        foreach (['workflow', 'outputs'] as $field) {
            if ($request->has($field)) {
                $data[$field] = json_encode(array_values($this->decodeList($request->input($field))));
            }
        }

        if ($request->has('endpoint_headers')) {
            $headers = $request->input('endpoint_headers');
            $data['endpoint_headers'] = is_array($headers) && $headers !== [] ? json_encode($headers) : null;
        }

        // Schemas are stored normalised, so a malformed field definition is
        // rejected at write time rather than breaking every later render.
        foreach (['input_schema', 'config_schema'] as $schemaField) {
            if ($request->has($schemaField)) {
                $data[$schemaField] = json_encode($this->decodeSchema($request->input($schemaField)));
            }
        }

        if ($request->has('launch_component')) {
            $data['launch_component'] = $request->input('launch_component') ?: null;
        }

        if ($request->has('endpoint_timeout')) {
            $data['endpoint_timeout'] = (int) $request->input('endpoint_timeout');
        }

        // An http agent without a URL would fail on every run, so the mode
        // falls back rather than being saved in a state that cannot work.
        if (($data['execution_mode'] ?? null) === 'http') {
            $url = $data['endpoint_url'] ?? null;
            if (!$url) {
                $data['execution_mode'] = 'none';
            }
        }

        return $data;
    }

    /**
     * GET /agents/meta
     *
     * Everything the library filters and the create wizard need, in one call:
     * the tool catalogue, model list, distinct modules, and status counts.
     */
    public function meta(Request $request)
    {
        $context = $this->agenticContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $counts = $this->baseQuery($sid)
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $platform = $this->baseQuery($sid)->whereNull('sub_institute_id')->count();
        $own = $this->baseQuery($sid, true)->count();

        $modules = $this->baseQuery($sid)
            ->whereNotNull('module')->where('module', '!=', '')
            ->distinct()->orderBy('module')->pluck('module');

        return $this->ok('Agent metadata fetched successfully', [
            'tools'   => self::TOOLS,
            'models'  => self::MODELS,
            'modules' => $modules,
            'statuses' => self::STATUSES,
            'counts'  => [
                'total'    => (int) array_sum($counts->all()),
                'draft'    => (int) ($counts['draft'] ?? 0),
                'deployed' => (int) ($counts['deployed'] ?? 0),
                'paused'   => (int) ($counts['paused'] ?? 0),
                'archived' => (int) ($counts['archived'] ?? 0),
                'platform' => $platform,
                'tenant'   => $own,
            ],
        ]);
    }
}
