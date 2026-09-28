<?php

namespace App\Http\Controllers\AI;

use App\Domain\AI\Reports\ModuleDataSourceCatalog;
use App\Domain\AI\Support\AiAuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Tool agents for one module's AI Stack Automations tab — stored in G2G's OWN Agentic AI
 * tables, so every agent made here is also an ordinary agent in the Agentic AI module.
 *
 * WHY THIS, NOT LMS_K12's ENGINE
 *
 * LMS_K12's Automations tab drives an agent engine that runs inside its Next.js app with
 * a JSON-file store. G2G already has a real agent store — `agentic_agents` /
 * `agentic_agent_runs` — so the shared Automations screen is backed by it instead of by a
 * second, unrelated agent system. The response shapes are LMS_K12's `Agent` / `AgentRun`
 * exactly, because the screens that read them are LMS_K12's.
 *
 *   Agent.module          ← agentic_agents.sub_module  (the `ai_modules` key)
 *   agentic_agents.module = the parent product's own menu name (e.g. "LMS"), read from
 *                           tblmenumaster_g2g, so the Agentic AI screens group it correctly
 *   Agent.tools_allowed   ← agentic_agents.tools        (read-only data source names)
 *   Agent.status          ← deployed→active · draft · paused ; archived = soft delete
 *   AgentRun              ← agentic_agent_runs, one row per run, success / error
 *
 * WHAT AN AGENT MAY DO
 *
 * Run one of its allowed tools, and nothing else. Every tool is one of this module's
 * read-only `ModuleDataSourceCatalog` sources — an agent here can look at the module's
 * records for the caller's organisation and cannot change one.
 */
class AiToolAgentController extends AiController
{
    private const STATUS_TO_G2G = ['active' => 'deployed', 'draft' => 'draft', 'paused' => 'paused'];

    public function __construct(
        private readonly ModuleDataSourceCatalog $sources,
        private readonly AiAuditLogger $audit,
    ) {
    }

    public function index(Request $request)
    {
        try {
            $institute = $this->scope($request)->selectedInstituteId;

            $query = DB::table('agentic_agents')
                ->where('sub_institute_id', $institute)
                ->whereNull('deleted_at')
                ->whereIn('sub_module', $this->stackModuleKeys());

            if ($request->filled('module')) {
                $query->where('sub_module', (string) $request->query('module'));
            }

            if ($request->filled('status') && isset(self::STATUS_TO_G2G[(string) $request->query('status')])) {
                $query->where('status', self::STATUS_TO_G2G[(string) $request->query('status')]);
            }

            return $this->success('Agents resolved.', [
                'agents' => $query->orderByDesc('id')->get()->map(fn ($row) => $this->presentAgent($row))->all(),
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    public function store(Request $request)
    {
        try {
            $scope = $this->scope($request);
            $institute = $scope->selectedInstituteId;

            $data = $request->validate([
                'name' => 'required|string|max:191',
                'description' => 'nullable|string|max:2000',
                'module' => ['required', 'string', Rule::in($this->stackModuleKeys())],
                'tools_allowed' => 'required|array|min:1',
                'tools_allowed.*' => 'string|max:120',
                'instructions' => 'nullable|string|max:10000',
                'status' => ['nullable', Rule::in(['draft', 'active'])],
            ]);

            $allowed = array_column($this->sources->forModule($data['module']), 'name');
            $unknown = array_diff($data['tools_allowed'], $allowed);

            if ($unknown !== []) {
                return $this->failure('These tools are not read-only sources of this module: ' . implode(', ', $unknown) . '.', 422);
            }

            $id = (int) DB::table('agentic_agents')->insertGetId([
                'sub_institute_id' => $institute,
                'origin' => 'tenant',
                'slug' => Str::slug($data['name']) . '-' . Str::lower(Str::random(6)),
                'name' => trim($data['name']),
                'description' => $data['description'] ?? null,
                'module' => $this->productName($data['module']),
                'sub_module' => $data['module'],
                'role' => 'analyst',
                'system_prompt' => $data['instructions'] ?? null,
                'execution_mode' => 'none',
                'tools' => json_encode(array_values(array_unique($data['tools_allowed']))),
                'status' => self::STATUS_TO_G2G[$data['status'] ?? 'draft'],
                'created_by' => $scope->userId,
                'updated_by' => $scope->userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit->record('ai.tool_agent.created', $scope, [
                'related_type' => 'agentic_agents',
                'related_id' => $id,
                'message' => sprintf('Agent "%s" created for %s.', trim($data['name']), $data['module']),
            ]);

            return $this->success('Agent created.', ['agent' => $this->presentAgent($this->agent($id, $institute))], 201);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    public function setStatus(Request $request, int $id)
    {
        try {
            $scope = $this->scope($request);
            $row = $this->agent($id, $scope->selectedInstituteId);

            if ($row === null) {
                return $this->failure('That agent was not found.', 404);
            }

            $status = (string) $request->validate(['status' => ['required', Rule::in(['draft', 'active', 'paused', 'archived'])]])['status'];

            DB::table('agentic_agents')->where('id', $id)->update(
                $status === 'archived'
                    ? ['deleted_at' => now(), 'deleted_by' => $scope->userId, 'updated_at' => now()]
                    : ['status' => self::STATUS_TO_G2G[$status], 'updated_by' => $scope->userId, 'updated_at' => now()]
            );

            $this->audit->record('ai.tool_agent.status', $scope, [
                'related_type' => 'agentic_agents',
                'related_id' => $id,
                'message' => sprintf('Agent "%s" set to %s.', $row->name, $status),
            ]);

            $fresh = DB::table('agentic_agents')->where('id', $id)->first();

            return $this->success('Agent updated.', ['agent' => $this->presentAgent($fresh)]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /** Run one allowed tool, as the caller, and record the run in agentic_agent_runs. */
    public function run(Request $request, int $id)
    {
        try {
            $scope = $this->scope($request);
            $row = $this->agent($id, $scope->selectedInstituteId);

            if ($row === null) {
                return $this->failure('That agent was not found.', 404);
            }

            $data = $request->validate(['tool' => 'nullable|string|max:120', 'arguments' => 'nullable|array']);
            $tools = $this->decode($row->tools);
            $tool = $data['tool'] ?? ($tools[0] ?? null);
            $arguments = $data['arguments'] ?? [];
            $started = now();
            $clock = microtime(true);

            $denial = null;
            if ((string) $row->status !== 'deployed') {
                $denial = 'Denied: the agent is not active.';
            } elseif ($tool === null || ! in_array($tool, $tools, true)) {
                $denial = 'Denied: that tool is not on this agent\'s allow-list.';
            } elseif (! in_array($tool, array_column($this->sources->forModule((string) $row->sub_module), 'name'), true)) {
                $denial = 'Denied: that tool does not belong to this agent\'s module.';
            }

            $output = null;
            $error = $denial;

            if ($denial === null) {
                try {
                    $declared = array_column($this->sources->describe($tool)['arguments'] ?? [], 'key');
                    $result = $this->sources->run($tool, $scope, array_intersect_key($arguments, array_flip($declared)));
                    $output = [
                        'tool' => $tool,
                        'total' => $result['total'],
                        'truncated' => $result['truncated'],
                        // A run log is an audit record, not an export: the first rows only.
                        'rows' => array_slice($result['rows'], 0, 50),
                    ];
                } catch (Throwable $e) {
                    $error = $e->getMessage();
                }
            }

            $finished = now();
            $runId = (int) DB::table('agentic_agent_runs')->insertGetId([
                'sub_institute_id' => $scope->selectedInstituteId,
                'agent_id' => $id,
                'status' => $error === null ? 'success' : 'error',
                'trigger' => 'manual',
                'input' => json_encode(['tool' => $tool, 'arguments' => $arguments]),
                'output' => $output === null ? null : json_encode($output),
                'error_message' => $error,
                'duration_ms' => (int) round((microtime(true) - $clock) * 1000),
                'started_at' => $started,
                'completed_at' => $finished,
                'created_by' => $scope->userId,
                'created_at' => $started,
                'updated_at' => $finished,
            ]);

            return $this->success(
                $error === null ? 'Agent run completed.' : 'Agent run did not complete.',
                ['run' => $this->presentRun(DB::table('agentic_agent_runs')->where('id', $runId)->first(), $row)]
            );
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    public function runs(Request $request)
    {
        try {
            $institute = $this->scope($request)->selectedInstituteId;

            $query = DB::table('agentic_agent_runs as r')
                ->join('agentic_agents as a', 'a.id', '=', 'r.agent_id')
                ->where('r.sub_institute_id', $institute)
                ->whereNull('r.deleted_at')
                ->whereIn('a.sub_module', $this->stackModuleKeys());

            if ($request->filled('module')) {
                $query->where('a.sub_module', (string) $request->query('module'));
            }
            if ($request->filled('agent_id')) {
                $query->where('r.agent_id', (int) $request->query('agent_id'));
            }

            $rows = $query->orderByDesc('r.id')
                ->limit($this->limit($request, 50, 200))
                ->get(['r.*', 'a.name as agent_name_col', 'a.sub_module as agent_module']);

            return $this->success('Runs resolved.', [
                'runs' => $rows->map(fn ($run) => $this->presentRun($run, (object) [
                    'name' => $run->agent_name_col,
                    'sub_module' => $run->agent_module,
                    'sub_institute_id' => $run->sub_institute_id,
                ]))->all(),
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    // ------------------------------------------------------------------ internals

    /** @return array<int, string> Keys of the modules that have an AI Stack (a registry_keys row). */
    private function stackModuleKeys(): array
    {
        return DB::table('ai_modules')->whereNotNull('capabilities')->pluck('module_key')->map(fn ($k) => (string) $k)->all();
    }

    private function agent(int $id, int|string|null $institute): ?object
    {
        return DB::table('agentic_agents')
            ->where('id', $id)
            ->where('sub_institute_id', $institute)
            ->whereNull('deleted_at')
            ->whereIn('sub_module', $this->stackModuleKeys())
            ->first();
    }

    /** The top-level menu name above an AI Stack module — "LMS", "Talent Management", … */
    private function productName(string $module): ?string
    {
        $menuId = DB::table('ai_modules')->where('module_key', $module)->value('menu_id');

        for ($guard = 0; $menuId !== null && $guard < 6; $guard++) {
            $row = DB::table('tblmenumaster_g2g')->where('id', $menuId)->first(['id', 'parent_id', 'menu_name', 'level']);

            if ($row === null) {
                return null;
            }

            if ((int) $row->level === 1 || (int) $row->parent_id === 0) {
                return (string) $row->menu_name;
            }

            $menuId = $row->parent_id;
        }

        return null;
    }

    private function presentAgent(object $row): array
    {
        $status = match ((string) $row->status) {
            'deployed' => 'active',
            'paused' => 'paused',
            default => 'draft',
        };

        return [
            'id' => (string) $row->id,
            'name' => (string) $row->name,
            'description' => (string) ($row->description ?? ''),
            'module' => (string) $row->sub_module,
            'tenant_id' => (string) $row->sub_institute_id,
            'tools_allowed' => $this->decode($row->tools),
            'instructions' => (string) ($row->system_prompt ?? ''),
            'trigger' => 'manual',
            'status' => $row->deleted_at !== null ? 'archived' : $status,
            'created_by' => (string) ($row->created_by ?? ''),
            'created_by_name' => $this->userName($row->created_by) ?? '',
            'created_at' => (string) $row->created_at,
            'updated_at' => (string) $row->updated_at,
        ];
    }

    private function presentRun(object $run, object $agent): array
    {
        $input = json_decode((string) ($run->input ?? ''), true);
        $output = json_decode((string) ($run->output ?? ''), true);
        $error = $run->error_message === null ? null : (string) $run->error_message;
        $actor = $run->created_by === null ? null : DB::table('tbluser as u')
            ->leftJoin('tbluserprofilemaster as p', 'p.id', '=', 'u.user_profile_id')
            ->where('u.id', $run->created_by)
            ->first(['u.first_name', 'u.last_name', 'p.id as profile_id', 'p.name as profile_name']);

        return [
            'id' => (string) $run->id,
            'agent_id' => (string) $run->agent_id,
            'agent_name' => (string) ($agent->name ?? ''),
            'module' => (string) ($agent->sub_module ?? ''),
            'tenant_id' => (string) $run->sub_institute_id,
            'started_at' => (string) ($run->started_at ?? $run->created_at),
            'finished_at' => (string) ($run->completed_at ?? $run->updated_at),
            'duration_ms' => (int) ($run->duration_ms ?? 0),
            'acting_user_id' => (string) ($run->created_by ?? ''),
            'acting_user_name' => $actor === null ? '' : trim(($actor->first_name ?? '') . ' ' . ($actor->last_name ?? '')),
            'acting_profile_id' => $actor === null ? '' : (string) ($actor->profile_id ?? ''),
            'acting_profile_name' => $actor === null ? '' : (string) ($actor->profile_name ?? ''),
            'trigger' => (string) ($run->trigger ?? 'manual'),
            'input' => is_array($input) ? $input : [],
            'output' => is_array($output) ? $output : null,
            'tools_used' => is_array($input) && isset($input['tool']) && $run->status === 'success' ? [(string) $input['tool']] : [],
            'status' => $run->status === 'success' ? 'success' : (str_starts_with((string) $error, 'Denied:') ? 'denied' : 'failure'),
            'error' => $error,
        ];
    }

    private function userName(mixed $id): ?string
    {
        if ($id === null) {
            return null;
        }

        $row = DB::table('tbluser')->where('id', $id)->first(['first_name', 'last_name']);

        return $row === null ? null : (trim(($row->first_name ?? '') . ' ' . ($row->last_name ?? '')) ?: null);
    }

    /** @return array<int, string> */
    private function decode(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }
}
