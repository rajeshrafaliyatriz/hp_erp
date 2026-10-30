<?php

namespace App\Http\Controllers\Api\Crm;

use App\Domain\AI\Support\AiNotConfiguredException;
use App\Domain\AI\Support\AiQuotaExceededException;
use App\Domain\AI\Support\AiCredentialsExhaustedException;
use App\Domain\Crm\Ai\CrmDealCoachAgent;
use App\Domain\Crm\Ai\CrmOutreachAgent;
use App\Domain\Crm\Ai\CrmProspectingAgent;
use App\Domain\Gtm\GtmAiRunner;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Phase 4 CRM Automation: AI agent runs + automation rules.
 * All AI calls go through GtmAiRunner (module: gtm) via the agent classes.
 */
class CrmAgentController extends Controller
{
    use ResolvesApiIdentity;

    public function __construct(
        private readonly CrmProspectingAgent $prospecting,
        private readonly CrmOutreachAgent    $outreach,
        private readonly CrmDealCoachAgent   $dealCoach,
        private readonly GtmAiRunner         $runner,
    ) {
    }

    // -------------------------------------------------------------------------
    // CRM AI Chat — conversational interface over CRM data
    // -------------------------------------------------------------------------

    public function aiChat(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        $v = Validator::make($request->all(), [
            'message'              => 'required|string|max:2000',
            'conversation_history' => 'nullable|array|max:20',
            'context_type'         => 'nullable|string|in:lead,contact,organization,opportunity',
            'context_id'           => 'nullable|integer',
        ]);

        if ($v->fails()) {
            return response()->json(['status' => 0, 'message' => $v->errors()->first()], 422);
        }

        $tenantId   = $identity['sub_institute_id'];
        $userId     = $identity['user_id'];
        $message    = $request->input('message');
        $history    = $request->input('conversation_history', []);
        $ctxType    = $request->input('context_type');
        $ctxId      = $request->input('context_id');

        // Build CRM context summary for the AI
        $crmContext = $this->buildChatContext($tenantId, $ctxType, $ctxId);

        // Build messages for the AI
        $systemPrompt = <<<PROMPT
You are an AI assistant for a CRM system called G2G. You help sales and marketing teams understand their pipeline, leads, signals, and opportunities. Answer questions concisely and helpfully. When referring to records, mention their names. Do not hallucinate data — only use what is provided in the context.

Current CRM Context:
{$crmContext}

IMPORTANT: Always respond with valid JSON in this exact format:
{"reply": "<your answer as a string>"}
PROMPT;

        // Build messages array for GtmAiRunner::json()
        $aiMessages = [['role' => 'system', 'content' => $systemPrompt]];
        foreach ($history as $h) {
            if (isset($h['role'], $h['content']) && in_array($h['role'], ['user', 'assistant'], true)) {
                $aiMessages[] = ['role' => $h['role'], 'content' => (string) $h['content']];
            }
        }
        $aiMessages[] = ['role' => 'user', 'content' => $message];

        try {
            $result = $this->runner->json(
                $tenantId,
                $userId,
                'crm_chat',
                'crm_chat',   // subjectType
                null,          // subjectId
                $aiMessages,
                [],            // sources
                mb_substr($message, 0, 200),
            );
            return response()->json([
                'status'  => 1,
                'message' => 'OK',
                'data'    => [
                    'reply'   => $result['data']['reply'] ?? $result['data']['content'] ?? $result['data']['text'] ?? 'No response generated.',
                    'sources' => [],
                ],
            ]);
        } catch (AiNotConfiguredException) {
            return response()->json(['status' => 0, 'message' => 'AI is not configured for this account. Please set up an AI provider in Settings → AI Providers.'], 422);
        } catch (AiQuotaExceededException|AiCredentialsExhaustedException) {
            return response()->json(['status' => 0, 'message' => 'AI quota exceeded. Please try again later.'], 429);
        } catch (\Throwable $e) {
            return response()->json(['status' => 0, 'message' => 'AI chat failed: ' . $e->getMessage()], 500);
        }
    }

    private function buildChatContext(int $tenantId, ?string $ctxType, ?int $ctxId): string
    {
        $lines = [];

        // Pipeline summary
        $pipeline = DB::table('crm_opportunities')
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->selectRaw('sales_stage, COUNT(*) as cnt, SUM(amount) as total')
            ->groupBy('sales_stage')
            ->get();
        if ($pipeline->isNotEmpty()) {
            $lines[] = "Pipeline: " . $pipeline->map(fn($r) => "{$r->sales_stage}: {$r->cnt} deals")->implode(', ');
        }

        // Leads needing follow-up
        $overdue = DB::table('crm_leads')
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->where('converted', 0)
            ->whereNotNull('follow_up_due_date')
            ->where('follow_up_due_date', '<', now())
            ->count();
        if ($overdue > 0) {
            $lines[] = "Overdue follow-ups: {$overdue} leads";
        }

        // Specific entity context
        if ($ctxType && $ctxId) {
            $table = match($ctxType) {
                'lead'         => 'crm_leads',
                'contact'      => 'crm_contacts',
                'organization' => 'crm_organizations',
                'opportunity'  => 'crm_opportunities',
                default        => null,
            };
            if ($table) {
                $row = DB::table($table)->where('id', $ctxId)->where('sub_institute_id', $tenantId)->first();
                if ($row) {
                    $lines[] = "Currently viewing {$ctxType}: " . json_encode((array) $row, JSON_UNESCAPED_UNICODE);
                }
            }
        }

        return $lines ? implode("\n", $lines) : 'No specific context loaded.';
    }

    // -------------------------------------------------------------------------
    // Agent runners
    // -------------------------------------------------------------------------

    public function runProspecting(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        $v = Validator::make($request->all(), [
            'organization_id' => 'nullable|integer',
            'company_name'    => 'nullable|string|max:191',
        ]);

        if ($v->fails()) {
            return response()->json(['status' => 0, 'message' => $v->errors()->first()], 422);
        }

        if (! $request->input('organization_id') && ! $request->input('company_name')) {
            return response()->json(['status' => 0, 'message' => 'organization_id or company_name is required.'], 422);
        }

        try {
            $output = $this->prospecting->run(
                $identity['sub_institute_id'],
                $identity['user_id'],
                $request->input('organization_id') ? (int) $request->input('organization_id') : null,
                $request->input('company_name'),
            );
        } catch (AiNotConfiguredException) {
            return response()->json(['status' => 0, 'message' => 'AI is not configured for this account. Please set up an AI provider in Settings → AI Providers.'], 422);
        } catch (AiQuotaExceededException|AiCredentialsExhaustedException $e) {
            return response()->json(['status' => 0, 'message' => 'AI quota exceeded or credentials exhausted: ' . $e->getMessage()], 429);
        } catch (\RuntimeException $e) {
            return response()->json(['status' => 0, 'message' => 'AI agent error: ' . $e->getMessage()], 500);
        }

        $runId = $this->recordRun($identity, 'prospecting', 'Prospecting Agent', $request->only(['organization_id', 'company_name']), $output);

        return response()->json([
            'status'  => 1,
            'message' => 'Prospecting complete.',
            'data'    => array_merge(['run_id' => $runId], $output),
        ]);
    }

    public function runOutreach(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        $v = Validator::make($request->all(), [
            'lead_id'     => 'nullable|integer',
            'contact_id'  => 'nullable|integer',
            'campaign_id' => 'nullable|integer',
        ]);

        if ($v->fails()) {
            return response()->json(['status' => 0, 'message' => $v->errors()->first()], 422);
        }

        if (! $request->input('lead_id') && ! $request->input('contact_id')) {
            return response()->json(['status' => 0, 'message' => 'lead_id or contact_id is required.'], 422);
        }

        try {
            $output = $this->outreach->run(
                $identity['sub_institute_id'],
                $identity['user_id'],
                $request->input('lead_id')    ? (int) $request->input('lead_id')    : null,
                $request->input('contact_id') ? (int) $request->input('contact_id') : null,
                $request->input('campaign_id') ? (int) $request->input('campaign_id') : null,
            );
        } catch (AiNotConfiguredException) {
            return response()->json(['status' => 0, 'message' => 'AI is not configured for this account.'], 422);
        } catch (AiQuotaExceededException|AiCredentialsExhaustedException $e) {
            return response()->json(['status' => 0, 'message' => 'AI quota exceeded: ' . $e->getMessage()], 429);
        } catch (\RuntimeException $e) {
            return response()->json(['status' => 0, 'message' => 'AI agent error: ' . $e->getMessage()], 500);
        }

        $runId = $this->recordRun($identity, 'outreach', 'Outreach Agent', $request->only(['lead_id', 'contact_id', 'campaign_id']), $output);

        return response()->json([
            'status'  => 1,
            'message' => 'Outreach draft ready.',
            'data'    => array_merge(['run_id' => $runId], $output),
        ]);
    }

    public function runDealCoach(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        $v = Validator::make($request->all(), [
            'opportunity_id' => 'required|integer',
        ]);

        if ($v->fails()) {
            return response()->json(['status' => 0, 'message' => $v->errors()->first()], 422);
        }

        try {
            $output = $this->dealCoach->run(
                $identity['sub_institute_id'],
                $identity['user_id'],
                (int) $request->input('opportunity_id'),
            );
        } catch (AiNotConfiguredException) {
            return response()->json(['status' => 0, 'message' => 'AI is not configured for this account.'], 422);
        } catch (AiQuotaExceededException|AiCredentialsExhaustedException $e) {
            return response()->json(['status' => 0, 'message' => 'AI quota exceeded: ' . $e->getMessage()], 429);
        } catch (\RuntimeException $e) {
            return response()->json(['status' => 0, 'message' => 'AI agent error: ' . $e->getMessage()], 500);
        }

        $runId = $this->recordRun($identity, 'deal_coach', 'Deal Coach', $request->only(['opportunity_id']), $output);

        return response()->json([
            'status'  => 1,
            'message' => 'Deal coaching ready.',
            'data'    => array_merge(['run_id' => $runId], $output),
        ]);
    }

    // -------------------------------------------------------------------------
    // Run history
    // -------------------------------------------------------------------------

    public function getRuns(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        $perPage = max(1, min(100, (int) $request->input('per_page', 20)));
        $page    = max(1, (int) $request->input('page', 1));

        $query = DB::table('crm_agent_runs')
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->orderBy('created_at', 'desc');

        if ($agentType = $request->input('agent_type')) {
            $query->where('agent_type', $agentType);
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($from = $request->input('date_from')) {
            $query->where('created_at', '>=', $from);
        }

        if ($to = $request->input('date_to')) {
            $query->where('created_at', '<=', $to);
        }

        $total = (clone $query)->count();
        $rows  = $query->forPage($page, $perPage)->get();

        return response()->json([
            'status'  => 1,
            'message' => 'Agent runs.',
            'data'    => [
                'items'      => $rows->map(fn ($r) => $this->runResource($r))->all(),
                'pagination' => [
                    'current_page' => $page,
                    'last_page'    => (int) max(1, ceil($total / $perPage)),
                    'per_page'     => $perPage,
                    'total'        => $total,
                ],
            ],
        ]);
    }

    public function getRun(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        $row = DB::table('crm_agent_runs')
            ->where('id', $id)
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->first();

        if (! $row) {
            return response()->json(['status' => 0, 'message' => 'Run not found.'], 404);
        }

        return response()->json(['status' => 1, 'message' => 'Run.', 'data' => $this->runResource($row)]);
    }

    public function cancelRun(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        $affected = DB::table('crm_agent_runs')
            ->where('id', $id)
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->whereIn('status', ['running', 'awaiting_approval'])
            ->update(['status' => 'cancelled', 'updated_at' => now()]);

        if (! $affected) {
            return response()->json(['status' => 0, 'message' => 'Run not found or cannot be cancelled.'], 404);
        }

        return response()->json(['status' => 1, 'message' => 'Run cancelled.']);
    }

    // -------------------------------------------------------------------------
    // Automation rules
    // -------------------------------------------------------------------------

    public function getAutomationRules(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        $rows = DB::table('crm_automation_rules')
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->orderBy('name')
            ->get();

        return response()->json([
            'status'  => 1,
            'message' => 'Automation rules.',
            'data'    => $rows->map(fn ($r) => $this->ruleResource($r))->all(),
        ]);
    }

    public function storeAutomationRule(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        $v = Validator::make($request->all(), [
            'name'           => 'required|string|max:191',
            'trigger_module' => 'required|in:lead,contact,opportunity,campaign',
            'trigger_event'  => 'required|string|max:50',
            'actions'        => 'required|array|min:1',
        ]);

        if ($v->fails()) {
            return response()->json(['status' => 0, 'message' => $v->errors()->first()], 422);
        }

        $id = DB::table('crm_automation_rules')->insertGetId([
            'name'               => $request->input('name'),
            'description'        => $request->input('description'),
            'trigger_module'     => $request->input('trigger_module'),
            'trigger_event'      => $request->input('trigger_event'),
            'trigger_conditions' => $request->input('trigger_conditions') ? json_encode($request->input('trigger_conditions')) : null,
            'actions'            => json_encode($request->input('actions')),
            'is_active'          => 1,
            'run_count'          => 0,
            'sub_institute_id'   => $identity['sub_institute_id'],
            'created_by'         => $identity['user_id'],
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        $row = DB::table('crm_automation_rules')->where('id', $id)->first();

        return response()->json(['status' => 1, 'message' => 'Rule created.', 'data' => $this->ruleResource($row)], 201);
    }

    public function updateAutomationRule(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        $row = DB::table('crm_automation_rules')
            ->where('id', $id)
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->first();

        if (! $row) {
            return response()->json(['status' => 0, 'message' => 'Rule not found.'], 404);
        }

        $v = Validator::make($request->all(), [
            'name'           => 'sometimes|required|string|max:191',
            'trigger_module' => 'sometimes|required|in:lead,contact,opportunity,campaign',
            'trigger_event'  => 'sometimes|required|string|max:50',
            'actions'        => 'sometimes|required|array|min:1',
        ]);

        if ($v->fails()) {
            return response()->json(['status' => 0, 'message' => $v->errors()->first()], 422);
        }

        $payload = array_filter([
            'name'               => $request->input('name'),
            'description'        => $request->input('description'),
            'trigger_module'     => $request->input('trigger_module'),
            'trigger_event'      => $request->input('trigger_event'),
            'trigger_conditions' => $request->has('trigger_conditions') ? json_encode($request->input('trigger_conditions')) : null,
            'actions'            => $request->has('actions') ? json_encode($request->input('actions')) : null,
            'updated_at'         => now(),
        ], fn ($v) => $v !== null);

        DB::table('crm_automation_rules')->where('id', $id)->update($payload);

        $updated = DB::table('crm_automation_rules')->where('id', $id)->first();

        return response()->json(['status' => 1, 'message' => 'Rule updated.', 'data' => $this->ruleResource($updated)]);
    }

    public function toggleRule(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        $row = DB::table('crm_automation_rules')
            ->where('id', $id)
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->first();

        if (! $row) {
            return response()->json(['status' => 0, 'message' => 'Rule not found.'], 404);
        }

        $newState = $row->is_active ? 0 : 1;
        DB::table('crm_automation_rules')->where('id', $id)->update(['is_active' => $newState, 'updated_at' => now()]);

        return response()->json([
            'status'  => 1,
            'message' => $newState ? 'Rule activated.' : 'Rule deactivated.',
            'data'    => ['is_active' => (bool) $newState],
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function recordRun(array $identity, string $agentType, string $agentName, array $input, array $output): int
    {
        return (int) DB::table('crm_agent_runs')->insertGetId([
            'agent_type'       => $agentType,
            'agent_name'       => $agentName,
            'trigger_type'     => 'manual',
            'input_data'       => json_encode($input),
            'output_data'      => json_encode($output),
            'status'           => 'completed',
            'started_at'       => now(),
            'completed_at'     => now(),
            'sub_institute_id' => $identity['sub_institute_id'],
            'created_by'       => $identity['user_id'],
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    private function runResource(object $row): array
    {
        return [
            'id'             => (string) $row->id,
            'agentType'      => $row->agent_type,
            'agentName'      => $row->agent_name,
            'triggerType'    => $row->trigger_type,
            'triggerContext' => $row->trigger_context ? json_decode($row->trigger_context, true) : null,
            'inputData'      => $row->input_data ? json_decode($row->input_data, true) : null,
            'outputData'     => $row->output_data ? json_decode($row->output_data, true) : null,
            'status'         => $row->status,
            'errorMessage'   => $row->error_message,
            'tokensUsed'     => $row->tokens_used,
            'costUsd'        => $row->cost_usd !== null ? (float) $row->cost_usd : null,
            'startedAt'      => $row->started_at,
            'completedAt'    => $row->completed_at,
            'createdAt'      => $row->created_at,
        ];
    }

    private function ruleResource(object $row): array
    {
        return [
            'id'                => (string) $row->id,
            'name'              => $row->name,
            'description'       => $row->description,
            'triggerModule'     => $row->trigger_module,
            'triggerEvent'      => $row->trigger_event,
            'triggerConditions' => $row->trigger_conditions ? json_decode($row->trigger_conditions, true) : null,
            'actions'           => $row->actions ? json_decode($row->actions, true) : [],
            'isActive'          => (bool) $row->is_active,
            'runCount'          => (int) $row->run_count,
            'lastRunAt'         => $row->last_run_at,
        ];
    }
}
