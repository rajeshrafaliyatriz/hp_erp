<?php

namespace App\Http\Controllers\Api\Gtm;

use App\Domain\Gtm\Agents\GtmAgentService;
use App\Domain\Gtm\GtmAiRunner;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** The five GTM agents: list, run, and read the run history of the caller's own organisation. */
class AgentController extends Controller
{
    use ResolvesApiIdentity;

    public function index(Request $request, GtmAgentService $agents, GtmAiRunner $ai): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];

        return response()->json(['status' => 1, 'data' => ['agents' => $agents->catalogue($t), 'ai_configured' => $ai->configured($t)]]);
    }

    public function run(Request $request, string $slug, GtmAgentService $agents): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $input = $request->input('input', []);
        $result = $agents->run($slug, $identity['sub_institute_id'], $identity['user_id'], is_array($input) ? $input : []);

        if ($result['ok']) {
            return response()->json(['status' => 1, 'data' => ['run_id' => $result['run_id']] + $result['data']]);
        }

        return response()->json(['status' => 0, 'code' => $result['code'] ?? 'error', 'message' => $result['message'] ?? 'The agent could not run.', 'run_id' => $result['run_id'] ?? null], $result['http']);
    }

    public function runs(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];

        $rows = DB::table('agentic_agent_runs as r')->join('agentic_agents as a', 'a.id', '=', 'r.agent_id')
            ->where('r.sub_institute_id', $t)->whereNull('r.deleted_at')->where('a.slug', 'like', 'gtm-%')->whereNull('a.sub_institute_id')
            ->when($request->filled('agent'), fn ($q) => $q->where('a.slug', (string) $request->input('agent')))
            ->orderByDesc('r.id')->limit(min(100, max(5, (int) $request->input('limit', 25))))
            ->get(['r.id', 'a.slug', 'a.name', 'r.status', 'r.error_message', 'r.duration_ms', 'r.tokens_used', 'r.started_at', 'r.created_by']);

        return response()->json(['status' => 1, 'data' => ['runs' => $rows]]);
    }

    public function runDetail(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $row = DB::table('agentic_agent_runs as r')->join('agentic_agents as a', 'a.id', '=', 'r.agent_id')
            ->where('r.id', $id)->where('r.sub_institute_id', $identity['sub_institute_id'])->where('a.slug', 'like', 'gtm-%')
            ->first(['r.id', 'a.slug', 'a.name', 'r.status', 'r.input', 'r.output', 'r.error_message', 'r.duration_ms', 'r.tokens_used', 'r.started_at']);
        if (! $row) {
            return response()->json(['status' => 0, 'message' => 'Run not found'], 404);
        }
        $row->input = json_decode($row->input ?? 'null', true);
        $row->output = json_decode($row->output ?? 'null', true);

        return response()->json(['status' => 1, 'data' => ['run' => $row]]);
    }
}
