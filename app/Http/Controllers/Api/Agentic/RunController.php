<?php

namespace App\Http\Controllers\Api\Agentic;

use App\Http\Controllers\Api\Agentic\Concerns\ResolvesAgenticContext;
use App\Http\Controllers\Api\Agentic\Concerns\ValidatesAgentSchema;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Agent runs and their traces - the Run Log screen and the Run button.
 *
 *   GET    /api/agentic/runs                 list (agent, status, search, paginate)
 *   POST   /api/agentic/agents/{id}/run      start a run
 *   GET    /api/agentic/runs/{id}            one run
 *   GET    /api/agentic/runs/{id}/trace      its ordered task list
 *   PUT    /api/agentic/runs/{id}            record the outcome of a run
 *   POST   /api/agentic/runs/{id}/tasks      append a trace step
 *   POST   /api/agentic/runs/{id}/cancel     stop a run that is still going
 *   DELETE /api/agentic/runs/{id}            soft delete
 *
 * A run row is the record of a request and its outcome. This controller owns
 * that record; whatever performs the inference reports back into it through PUT
 * /runs/{id} and POST /runs/{id}/tasks. That split is deliberate - it keeps the
 * audit trail intact whether the model call is made in-process, by a queue
 * worker, or by an external service.
 */
class RunController extends Controller
{
    use ResolvesAgenticContext;
    use ValidatesAgentSchema;

    private const TABLE = 'agentic_agent_runs';

    private const STATUSES = ['pending', 'running', 'success', 'error', 'cancelled'];

    private function baseQuery(int $sid)
    {
        return DB::table(self::TABLE)
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at');
    }

    private function present($row, array $agentNames = []): array
    {
        return [
            'id'            => (int) $row->id,
            'agent_id'      => (int) $row->agent_id,
            // Denormalised at read time so a deleted agent still reads sensibly.
            'agent_name'    => $agentNames[(int) $row->agent_id] ?? 'Deleted agent',
            'status'        => $row->status,
            'trigger'       => $row->trigger,
            'input'         => $row->input,
            'output'        => $row->output,
            'error_message' => $row->error_message,
            'duration_ms'   => $row->duration_ms !== null ? (int) $row->duration_ms : null,
            'tokens_used'   => $row->tokens_used !== null ? (int) $row->tokens_used : null,
            'cost'          => $row->cost !== null ? (float) $row->cost : null,
            'started_at'    => $row->started_at,
            'completed_at'  => $row->completed_at,
            'created_at'    => $row->created_at,
        ];
    }

    /** @param array<int,int> $agentIds */
    private function agentNames(int $sid, array $agentIds): array
    {
        if ($agentIds === []) {
            return [];
        }

        // Platform catalogue agents have a NULL owner, so scoping this to the
        // tenant alone would label every one of their runs "Deleted agent".
        return DB::table('agentic_agents')
            ->whereIn('id', $agentIds)
            ->where(function ($q) use ($sid) {
                $q->where('sub_institute_id', $sid)->orWhereNull('sub_institute_id');
            })
            ->pluck('name', 'id')
            ->all();
    }

    public function index(Request $request)
    {
        $context = $this->agenticContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $query = $this->baseQuery($sid);

        if ($agentId = $this->activeFilter($request->input('agent_id'))) {
            $query->where('agent_id', $agentId);
        }
        if ($status = $this->activeFilter($request->input('status'))) {
            $query->where('status', $status);
        }
        if ($search = $this->activeFilter($request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('input', 'like', "%{$search}%")
                    ->orWhere('output', 'like', "%{$search}%")
                    ->orWhere('error_message', 'like', "%{$search}%");
            });
        }
        if ($from = $this->activeFilter($request->input('from'))) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = $this->activeFilter($request->input('to'))) {
            $query->whereDate('created_at', '<=', $to);
        }

        $total = (clone $query)->count();
        [$page, $perPage] = $this->paging($request);

        $rows = $query->orderByDesc('created_at')->orderByDesc('id')->forPage($page, $perPage)->get();
        $names = $this->agentNames($sid, $rows->pluck('agent_id')->map(fn ($id) => (int) $id)->unique()->all());

        return $this->ok(
            'Runs fetched successfully',
            $rows->map(fn ($row) => $this->present($row, $names))->all(),
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
            return $this->fail('Run not found.', 404);
        }

        $names = $this->agentNames($sid, [(int) $row->agent_id]);
        $run = $this->present($row, $names);
        $run['tasks'] = $this->traceRows($sid, (int) $row->id);

        return $this->ok('Run fetched successfully', $run);
    }

    /** @return array<int, array<string, mixed>> */
    private function traceRows(int $sid, int $runId): array
    {
        return DB::table('agentic_run_tasks')
            ->where('sub_institute_id', $sid)
            ->where('run_id', $runId)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get()
            ->map(fn ($task) => [
                'id'          => (int) $task->id,
                'run_id'      => (int) $task->run_id,
                'sequence'    => (int) $task->sequence,
                'description' => $task->description,
                'status'      => $task->status,
                'tool'        => $task->tool,
                'result'      => $task->result,
                'error'       => $task->error,
                'duration_ms' => $task->duration_ms !== null ? (int) $task->duration_ms : null,
                'created_at'  => $task->created_at,
            ])
            ->all();
    }

    public function trace(Request $request, $id)
    {
        $context = $this->agenticContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        if (!$this->baseQuery($sid)->where('id', (int) $id)->exists()) {
            return $this->fail('Run not found.', 404);
        }

        return $this->ok('Trace fetched successfully', [
            'run_id' => (int) $id,
            'tasks'  => $this->traceRows($sid, (int) $id),
        ]);
    }

    /**
     * POST /agents/{id}/run
     *
     * Opens a run and its first trace step. Only a deployed agent may run: a
     * draft has not been reviewed and a paused one was stopped on purpose.
     */
    public function start(Request $request, $agentId)
    {
        $context = $this->agenticContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        // Platform catalogue agents have no owner but are runnable by everyone.
        $agent = DB::table('agentic_agents')
            ->where('id', (int) $agentId)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($sid) {
                $q->where('sub_institute_id', $sid)->orWhereNull('sub_institute_id');
            })
            ->first();

        if (!$agent) {
            return $this->fail('Agent not found.', 404);
        }
        if ($agent->status !== 'deployed') {
            return $this->fail('This agent is ' . $agent->status . '. Deploy it before running.', 422);
        }

        // Setup comes before work: an agent that needs credentials cannot do
        // anything useful without them, and failing here is clearer than
        // failing inside the endpoint call.
        $configSchema = $this->decodeSchema($agent->config_schema ?? null);
        $config = $this->tenantConfig($sid, (int) $agent->id);

        if ($missing = $this->missingConfig($configSchema, $config)) {
            return response()->json([
                'status'       => 0,
                'message'      => 'This agent needs to be configured first.',
                'needs_config' => true,
                'missing'      => $missing,
            ], 422);
        }

        // Typed inputs, validated against the agent's own schema. `input` is
        // still accepted so an agent with no schema keeps working as free text.
        $inputSchema = $this->decodeSchema($agent->input_schema ?? null);
        $submitted = $request->input('inputs');
        $inputs = [];

        if ($inputSchema !== []) {
            $result = $this->applySchema($inputSchema, is_array($submitted) ? $submitted : []);

            if ($result['errors'] !== []) {
                return response()->json([
                    'status'  => 0,
                    'message' => $this->summariseErrors($result['errors']),
                    'errors'  => $result['errors'],
                ], 422);
            }

            $inputs = $result['values'];
        } elseif (is_array($submitted)) {
            $inputs = $submitted;
        }

        // The run log shows one line per run, so typed inputs get a readable
        // summary rather than a raw JSON blob.
        $inputText = (string) $request->input('input', '');
        if ($inputText === '' && $inputs !== []) {
            $inputText = $this->describeInputs($inputSchema, $inputs);
        }

        $runId = DB::table(self::TABLE)->insertGetId([
            'sub_institute_id' => $sid,
            'agent_id'         => $agent->id,
            'status'           => 'running',
            'trigger'          => $this->activeFilter($request->input('trigger')) ?: 'manual',
            'input'            => $inputText,
            'started_at'       => now(),
            'created_by'       => $context['user_id'],
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        DB::table('agentic_run_tasks')->insert([
            'sub_institute_id' => $sid,
            'run_id'           => $runId,
            'sequence'         => 1,
            'description'      => 'Run queued for ' . $agent->name,
            'status'           => 'running',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        // An agent wired to a HuggingFace Space or an n8n webhook is called here
        // and the response recorded. Without an endpoint the run stays open for
        // an external executor to report into via PUT /runs/{id}.
        if (($agent->execution_mode ?? 'none') === 'http' && !empty($agent->endpoint_url)) {
            $this->dispatchToEndpoint($sid, (int) $runId, $agent, $inputText, $inputs, $config);

            $run = $this->baseQuery($sid)->where('id', $runId)->first();

            return $this->ok('Agent run completed', [
                'id'            => (int) $runId,
                'status'        => $run->status,
                'output'        => $run->output,
                'error_message' => $run->error_message,
            ]);
        }

        return $this->ok('Agent run started', ['id' => (int) $runId, 'status' => 'running']);
    }

    /**
     * Call the agent's configured endpoint and record the outcome on the run.
     *
     * Success or failure, everything is written back onto the run row so the
     * Run Log and the Reflection patterns see real data either way. A transport
     * failure is a failed run, not a 500: the caller asked to start a run, and
     * the run did start - it just did not succeed.
     */
    private function dispatchToEndpoint(int $sid, int $runId, $agent, string $input, array $inputs = [], array $config = []): void
    {
        $startedAt = microtime(true);
        $sequence = 1;

        $addTask = function (string $description, string $status, ?string $error = null, ?string $result = null) use ($sid, $runId, &$sequence) {
            $sequence++;
            DB::table('agentic_run_tasks')->insert([
                'sub_institute_id' => $sid,
                'run_id'           => $runId,
                'sequence'         => $sequence,
                'description'      => $description,
                'status'           => $status,
                'error'            => $error,
                'result'           => $result !== null ? mb_substr($result, 0, 4000) : null,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);
        };

        $headers = [];
        if (!empty($agent->endpoint_headers)) {
            $decoded = json_decode((string) $agent->endpoint_headers, true);
            if (is_array($decoded)) {
                $headers = array_map('strval', $decoded);
            }
        }

        $payload = [
            'agent_id'      => (int) $agent->id,
            'agent_name'    => $agent->name,
            'agent_slug'    => $agent->slug,
            'run_id'        => $runId,
            'input'         => $input,
            'system_prompt' => $agent->system_prompt,
            'model'         => $agent->model,
            'temperature'   => (float) $agent->temperature,
            'max_tokens'    => (int) $agent->max_tokens,
            'tools'         => $this->decodeList($agent->tools),
        ];

        // The typed answers travel as their own object AND flattened onto the
        // payload, because n8n webhooks and HuggingFace Spaces disagree about
        // where they look for fields.
        if ($inputs !== []) {
            $payload['inputs'] = $inputs;

            foreach ($inputs as $key => $value) {
                if (!array_key_exists($key, $payload)) {
                    $payload[$key] = $value;
                }
            }
        }

        // Saved setup (sheet ids, workspace ids, API keys) so the receiving
        // service knows which account it is acting on.
        if ($config !== []) {
            $payload['config'] = $config;
        }

        $method = strtoupper((string) ($agent->endpoint_method ?: 'POST'));
        $timeout = (int) ($agent->endpoint_timeout ?: 60);

        $addTask('Calling ' . parse_url((string) $agent->endpoint_url, PHP_URL_HOST), 'running');

        try {
            $client = Http::withHeaders($headers)->timeout($timeout)->acceptJson();

            $response = $method === 'GET'
                ? $client->get((string) $agent->endpoint_url, $payload)
                : $client->send($method, (string) $agent->endpoint_url, ['json' => $payload]);

            $duration = (int) round((microtime(true) - $startedAt) * 1000);
            $body = $response->body();

            if ($response->successful()) {
                $json = $response->json();

                // Accept the shapes these services actually return rather than
                // demanding a contract: {output}, {result}, {generated_text},
                // a bare string, or the whole object as a fallback.
                $output = null;
                if (is_array($json)) {
                    foreach (['output', 'result', 'response', 'generated_text', 'text', 'data'] as $key) {
                        if (array_key_exists($key, $json)) {
                            $output = is_scalar($json[$key]) ? (string) $json[$key] : json_encode($json[$key]);
                            break;
                        }
                    }
                    if ($output === null) {
                        $output = json_encode($json);
                    }
                } else {
                    $output = $body;
                }

                DB::table('agentic_agent_runs')->where('id', $runId)->update([
                    'status'       => 'success',
                    'output'       => mb_substr((string) $output, 0, 60000),
                    'duration_ms'  => $duration,
                    // Providers report usage under a few different names.
                    'tokens_used'  => is_array($json)
                        ? ($json['tokens_used'] ?? $json['total_tokens'] ?? data_get($json, 'usage.total_tokens'))
                        : null,
                    'completed_at' => now(),
                    'updated_at'   => now(),
                ]);

                DB::table('agentic_run_tasks')
                    ->where('sub_institute_id', $sid)
                    ->where('run_id', $runId)
                    ->where('status', 'running')
                    ->update(['status' => 'success', 'updated_at' => now()]);

                $addTask('Endpoint responded ' . $response->status(), 'success', null, mb_substr($body, 0, 2000));

                return;
            }

            // A non-2xx is the endpoint's answer, so its status and body become
            // the error text - which is what Reflection groups patterns from.
            $error = 'Endpoint returned ' . $response->status() . ': ' . mb_substr($body, 0, 500);
            $this->failRun($sid, $runId, $error, $duration);
            $addTask('Endpoint returned ' . $response->status(), 'error', $error);
        } catch (\Throwable $exception) {
            $duration = (int) round((microtime(true) - $startedAt) * 1000);
            // A sleeping HuggingFace Space surfaces here as a connect timeout.
            $error = $exception->getMessage();

            Log::warning('Agentic run dispatch failed', [
                'run_id'   => $runId,
                'agent_id' => $agent->id,
                'message'  => $error,
            ]);

            $this->failRun($sid, $runId, mb_substr($error, 0, 500), $duration);
            $addTask('Endpoint call failed', 'error', mb_substr($error, 0, 500));
        }
    }

    /**
     * This tenant's saved setup for an agent, secrets included.
     *
     * Only ever used to build the outbound call - it is never returned to the
     * browser, which is why the read endpoint lives in ConfigController and
     * reports secrets as set / not set instead.
     *
     * @return array<string, mixed>
     */
    private function tenantConfig(int $sid, int $agentId): array
    {
        $row = DB::table('agentic_agent_configs')
            ->where('sub_institute_id', $sid)
            ->where('agent_id', $agentId)
            ->first();

        if (!$row) {
            return [];
        }

        $values = [];
        if ($row->values) {
            $decoded = json_decode($row->values, true);
            $values = is_array($decoded) ? $decoded : [];
        }

        if ($row->secrets) {
            try {
                $secrets = json_decode(Crypt::decryptString($row->secrets), true);
                if (is_array($secrets)) {
                    $values = array_merge($values, $secrets);
                }
            } catch (\Throwable $exception) {
                // Unreadable ciphertext (rotated APP_KEY) behaves as unset, so
                // missingConfig() below asks the user to reconnect.
            }
        }

        return $values;
    }

    /**
     * Required config fields with no saved answer.
     *
     * @return array<int, string>
     */
    private function missingConfig(array $schema, array $config): array
    {
        $missing = [];

        foreach ($schema as $field) {
            if (!$field['required']) {
                continue;
            }

            $value = $config[$field['name']] ?? null;

            if ($value === null || $value === '' || $value === []) {
                $missing[] = $field['label'];
            }
        }

        return $missing;
    }

    /**
     * A one-line summary of typed inputs for the run log, using field labels
     * and leading with whichever field the agent marked required.
     */
    private function describeInputs(array $schema, array $inputs): string
    {
        $labels = [];
        foreach ($schema as $field) {
            $labels[$field['name']] = $field['label'];
        }

        $parts = [];
        foreach ($inputs as $key => $value) {
            if (is_bool($value)) {
                $value = $value ? 'yes' : 'no';
            } elseif (is_array($value)) {
                $value = implode(', ', array_map('strval', $value));
            }

            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }

            $parts[] = ($labels[$key] ?? $key) . ': ' . mb_substr($value, 0, 120);
        }

        return mb_substr(implode(' | ', $parts), 0, 1000);
    }

    /** Mark a run and its open trace steps as failed with one message. */
    private function failRun(int $sid, int $runId, string $error, int $duration): void
    {
        DB::table('agentic_agent_runs')->where('id', $runId)->update([
            'status'        => 'error',
            'error_message' => $error,
            'duration_ms'   => $duration,
            'completed_at'  => now(),
            'updated_at'    => now(),
        ]);

        DB::table('agentic_run_tasks')
            ->where('sub_institute_id', $sid)
            ->where('run_id', $runId)
            ->where('status', 'running')
            ->update(['status' => 'error', 'error' => $error, 'updated_at' => now()]);
    }

    /**
     * PUT /runs/{id} - record the outcome.
     *
     * Duration is computed from started_at when the caller does not supply one,
     * so a reporter that only knows "it finished" still produces a usable trace.
     */
    public function update(Request $request, $id)
    {
        $context = $this->agenticContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $run = $this->baseQuery($sid)->where('id', (int) $id)->first();
        if (!$run) {
            return $this->fail('Run not found.', 404);
        }

        $validator = Validator::make($request->all(), [
            'status'        => 'nullable|string|in:' . implode(',', self::STATUSES),
            'output'        => 'nullable|string',
            'error_message' => 'nullable|string',
            'tokens_used'   => 'nullable|integer|min:0',
            'cost'          => 'nullable|numeric|min:0',
            'duration_ms'   => 'nullable|integer|min:0',
        ]);
        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422);
        }

        $update = ['updated_at' => now()];

        foreach (['status', 'output', 'error_message', 'tokens_used', 'cost', 'duration_ms'] as $field) {
            if ($request->has($field)) {
                $update[$field] = $request->input($field);
            }
        }

        $terminal = in_array($request->input('status'), ['success', 'error', 'cancelled'], true);
        if ($terminal) {
            $update['completed_at'] = now();

            if (!$request->has('duration_ms') && $run->started_at) {
                $update['duration_ms'] = max(0, (int) round((microtime(true) - strtotime($run->started_at)) * 1000));
            }
        }

        DB::table(self::TABLE)->where('id', $run->id)->update($update);

        // Close the opening trace step so the trace does not sit at "running".
        if ($terminal) {
            DB::table('agentic_run_tasks')
                ->where('sub_institute_id', $sid)
                ->where('run_id', $run->id)
                ->where('status', 'running')
                ->update([
                    'status'     => $request->input('status') === 'success' ? 'success' : 'error',
                    'error'      => $request->input('error_message'),
                    'updated_at' => now(),
                ]);
        }

        return $this->ok('Run updated successfully', ['id' => (int) $run->id]);
    }

    /** POST /runs/{id}/tasks - append a trace step. */
    public function addTask(Request $request, $id)
    {
        $context = $this->agenticContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $run = $this->baseQuery($sid)->where('id', (int) $id)->first();
        if (!$run) {
            return $this->fail('Run not found.', 404);
        }

        $validator = Validator::make($request->all(), [
            'description' => 'required|string|max:500',
            'status'      => 'nullable|string|in:running,success,error',
            'tool'        => 'nullable|string|max:60',
            'result'      => 'nullable|string',
            'error'       => 'nullable|string',
            'duration_ms' => 'nullable|integer|min:0',
        ]);
        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422);
        }

        $sequence = (int) DB::table('agentic_run_tasks')
            ->where('sub_institute_id', $sid)
            ->where('run_id', $run->id)
            ->max('sequence');

        $taskId = DB::table('agentic_run_tasks')->insertGetId([
            'sub_institute_id' => $sid,
            'run_id'           => $run->id,
            'sequence'         => $sequence + 1,
            'description'      => $request->input('description'),
            'status'           => $request->input('status', 'success'),
            'tool'             => $request->input('tool'),
            'result'           => $request->input('result'),
            'error'            => $request->input('error'),
            'duration_ms'      => $request->input('duration_ms'),
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        return $this->ok('Trace step recorded', ['id' => (int) $taskId, 'sequence' => $sequence + 1]);
    }

    public function cancel(Request $request, $id)
    {
        $context = $this->agenticContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $run = $this->baseQuery($sid)->where('id', (int) $id)->first();
        if (!$run) {
            return $this->fail('Run not found.', 404);
        }
        if (!in_array($run->status, ['pending', 'running'], true)) {
            return $this->fail('This run already finished as ' . $run->status . '.', 422);
        }

        DB::table(self::TABLE)->where('id', $run->id)->update([
            'status'       => 'cancelled',
            'completed_at' => now(),
            'updated_at'   => now(),
        ]);

        DB::table('agentic_run_tasks')
            ->where('sub_institute_id', $sid)
            ->where('run_id', $run->id)
            ->where('status', 'running')
            ->update(['status' => 'error', 'error' => 'Cancelled by user', 'updated_at' => now()]);

        return $this->ok('Run cancelled', ['id' => (int) $run->id]);
    }

    public function destroy(Request $request, $id)
    {
        $context = $this->agenticContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $run = $this->baseQuery($sid)->where('id', (int) $id)->first();
        if (!$run) {
            return $this->fail('Run not found.', 404);
        }

        DB::table(self::TABLE)->where('id', $run->id)->update(['deleted_at' => now(), 'updated_at' => now()]);

        return $this->ok('Run deleted successfully', ['id' => (int) $run->id]);
    }
}
