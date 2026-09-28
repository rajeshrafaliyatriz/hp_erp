<?php

namespace App\Http\Controllers\AI;

use App\Domain\AI\Support\AiAuditLogger;
use App\Domain\AI\Support\SchemaCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * What one G2G module's AI has done, and what is holding it back — the backend of the
 * shared (LMS_K12-universal) AI Stack's Usage & Cost, Guardrails and Activity tabs.
 *
 * SAME CONTRACT AS LMS_K12, G2G'S OWN TABLES
 *
 * The response shapes are LMS_K12's `AiModuleController` exactly, because the screens
 * that read them are LMS_K12's screens, unmodified. What each section reads is G2G's:
 *
 *   conversations  ai_conversations.module_key + ai_conversation_turns   (same idea)
 *   generation     ai_usage_events, for the module's `ai_modules.registry_keys` —
 *                  G2G meters per AI consumer, not per template, so this is the
 *                  module's consumers' metered calls, not LMS's generation requests
 *   reports        ai_generated_reports.module_key (created with the Templates build)
 *   provider       ai_api_keys for the module's consumers, + ai_daily_used_api
 *   refusals       ai_usage_events whose outcome is `refused` or `failed`
 *   activity       ai_audit_logs rows under `module.<key>.*`, written by recordActivity
 *
 * Every figure is measured or null-with-a-reason; nothing is estimated or sampled.
 *
 * TENANT SCOPE IS NEVER A PARAMETER. `$scope->selectedInstituteId` comes from the
 * caller's Sanctum token via AiContextHydrator. The module key is the only URL input,
 * and it cannot widen access — at worst it names a module with no rows.
 */
class AiModuleController extends AiController
{
    private const RECENT = 25;

    /** @var array<string, array<string, mixed>> */
    private array $identityCache = [];

    public function __construct(
        private readonly AiAuditLogger $audit,
        private readonly SchemaCache $schema,
    ) {
    }

    public function usage(Request $request, string $module)
    {
        try {
            $institute = $this->scope($request)->selectedInstituteId;

            return $this->success('Module usage resolved.', [
                'module' => $this->moduleIdentity($module, $institute),
                'conversations' => $this->conversationUsage($module, $institute),
                'generation' => $this->generationUsage($module, $institute),
                'reports' => $this->reportUsage($module, $institute),
                'provider' => $this->providerUsage($module, $institute),
                'recent_turns' => $this->recentTurns($module, $institute),
                'daily' => $this->dailySeries($module, $institute),
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    public function guardrails(Request $request, string $module)
    {
        try {
            $institute = $this->scope($request)->selectedInstituteId;
            $identity = $this->moduleIdentity($module, $institute);

            return $this->success('Module guardrails resolved.', [
                'module' => $identity,
                'capabilities' => $identity['capabilities'],
                'review' => $this->reviewPosture($module, $institute),
                'refusals' => $this->refusals($module, $institute),
                'refusal_counts' => $this->refusalCounts($module, $institute),
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    // ------------------------------------------------------------------ identity

    private function moduleIdentity(string $module, int|string|null $institute): array
    {
        $cacheKey = $module . '|' . (string) $institute;

        if (isset($this->identityCache[$cacheKey])) {
            return $this->identityCache[$cacheKey];
        }

        if (! $this->schema->hasTable('ai_modules')) {
            return $this->identityCache[$cacheKey] = ['key' => $module, 'label' => $module, 'registered' => false, 'capabilities' => []];
        }

        $row = DB::table('ai_modules')
            ->where('module_key', $module)
            ->where(fn ($q) => $q->where('sub_institute_id', $institute)->orWhereNull('sub_institute_id'))
            ->orderByRaw('sub_institute_id IS NULL ASC')
            ->first();

        if ($row === null) {
            return $this->identityCache[$cacheKey] = ['key' => $module, 'label' => $module, 'registered' => false, 'capabilities' => []];
        }

        $capabilities = [];
        $decoded = json_decode((string) ($row->capabilities ?? ''), true);

        foreach (is_array($decoded) ? $decoded : [] as $key => $enabled) {
            $capabilities[(string) $key] = (bool) $enabled;
        }

        return $this->identityCache[$cacheKey] = [
            'key' => (string) $row->module_key,
            'label' => (string) $row->label,
            'registered' => true,
            'status' => (int) $row->status,
            'scope' => $row->sub_institute_id === null ? 'platform' : 'institute',
            'capabilities' => $capabilities,
        ];
    }

    /** @return array<int, string> The module's AiModuleRegistry consumer keys. */
    private function registryKeys(string $module, int|string|null $institute): array
    {
        if (! $this->schema->hasTable('ai_modules') || ! $this->schema->hasColumn('ai_modules', 'registry_keys')) {
            return [];
        }

        $raw = DB::table('ai_modules')
            ->where('module_key', $module)
            ->where(fn ($q) => $q->where('sub_institute_id', $institute)->orWhereNull('sub_institute_id'))
            ->orderByRaw('sub_institute_id IS NULL ASC')
            ->value('registry_keys');

        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    // ------------------------------------------------------------------ usage

    private function conversationUsage(string $module, int|string|null $institute): array
    {
        if (! $this->schema->hasTable('ai_conversations')) {
            return ['available' => false, 'reason' => 'ai_conversations is not on this estate.'];
        }

        $conversations = DB::table('ai_conversations')
            ->where('sub_institute_id', $institute)
            ->where('module_key', $module);

        $totals = (clone $conversations)
            ->selectRaw('count(*) total, coalesce(sum(turn_count), 0) turns, count(distinct user_id) users, max(last_turn_at) last_activity, min(created_at) first_activity')
            ->first();

        $byStatus = (clone $conversations)
            ->selectRaw('status, count(*) c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->map(fn ($c) => (int) $c)
            ->all();

        $turnStats = ['available' => false];

        if ($this->schema->hasTable('ai_conversation_turns')) {
            $ids = (clone $conversations)->pluck('id');

            if ($ids->isEmpty()) {
                $turnStats = ['available' => true, 'total' => 0, 'avg_duration_ms' => null, 'max_duration_ms' => null, 'by_status' => [], 'by_intent' => []];
            } else {
                // G2G records the answer's latency on the assistant turn, and a failed
                // answer as an assistant turn carrying `error`. G2G classifies no intent,
                // so `by_intent` is honestly empty rather than invented.
                $answers = DB::table('ai_conversation_turns')
                    ->whereIn('conversation_id', $ids)
                    ->where('role', 'assistant');

                $aggregate = (clone $answers)
                    ->selectRaw('count(*) total, avg(latency_ms) avg_ms, max(latency_ms) max_ms')
                    ->first();

                $turnStats = [
                    'available' => true,
                    'total' => (int) ($aggregate->total ?? 0),
                    'avg_duration_ms' => $aggregate->avg_ms === null ? null : (int) round((float) $aggregate->avg_ms),
                    'max_duration_ms' => $aggregate->max_ms === null ? null : (int) $aggregate->max_ms,
                    'by_status' => (clone $answers)
                        ->selectRaw("case when error is null then 'answered' else 'failed' end s, count(*) c")
                        ->groupBy('s')
                        ->pluck('c', 's')
                        ->map(fn ($c) => (int) $c)
                        ->all(),
                    'by_intent' => [],
                ];
            }
        }

        return [
            'available' => true,
            'total' => (int) ($totals->total ?? 0),
            'turns_recorded_on_conversation' => (int) ($totals->turns ?? 0),
            'distinct_users' => (int) ($totals->users ?? 0),
            'first_activity' => $totals->first_activity ?? null,
            'last_activity' => $totals->last_activity ?? null,
            'by_status' => $byStatus,
            'turn_detail' => $turnStats,
        ];
    }

    /**
     * Metered calls made by this module's AI consumers.
     *
     * `ai_usage_events.ai_module` is an AiModuleRegistry key, and this module's consumers
     * are listed on its `ai_modules.registry_keys` — that is the only link between a
     * metered call and a menu module that G2G has.
     */
    private function generationUsage(string $module, int|string|null $institute): array
    {
        if (! $this->schema->hasTable('ai_usage_events')) {
            return ['available' => false, 'reason' => 'ai_usage_events is not on this estate.'];
        }

        $keys = $this->registryKeys($module, $institute);

        if ($keys === []) {
            return [
                'available' => true,
                'total' => 0,
                'by_status' => [],
                'tokens' => $this->emptyTokens('This module has no AI consumer of its own, so nothing is generated through it.'),
            ];
        }

        $events = DB::table('ai_usage_events')
            ->where('sub_institute_id', $institute)
            ->whereIn('ai_module', $keys);

        $total = (int) (clone $events)->count();

        $byStatus = (clone $events)
            ->selectRaw('outcome, count(*) c')
            ->groupBy('outcome')
            ->pluck('c', 'outcome')
            ->map(fn ($c) => (int) $c)
            ->all();

        if ($total === 0) {
            return [
                'available' => true,
                'total' => 0,
                'by_status' => [],
                'tokens' => $this->emptyTokens(
                    'No metered call has been recorded for ' . implode(', ', $keys)
                    . '. Its generator still calls its provider directly, outside the metering layer.'
                ),
            ];
        }

        $aggregate = (clone $events)
            ->selectRaw(
                "sum(case when outcome = 'success' then 1 else 0 end) outputs,"
                . ' sum(input_tokens) prompt_tokens, sum(output_tokens) completion_tokens,'
                . ' sum(estimated_cost_usd) recorded_cost, avg(latency_ms) avg_latency'
            )
            ->first();

        $prompt = $aggregate->prompt_tokens === null ? null : (int) $aggregate->prompt_tokens;
        $completion = $aggregate->completion_tokens === null ? null : (int) $aggregate->completion_tokens;
        $recorded = $aggregate->recorded_cost === null ? null : (float) $aggregate->recorded_cost;
        $rate = $this->modelRate($module, $institute);

        [$cost, $source, $reason] = $this->resolveCost($prompt, $completion, $recorded, $rate);

        return [
            'available' => true,
            'total' => $total,
            'by_status' => $byStatus,
            'tokens' => [
                'outputs' => (int) ($aggregate->outputs ?? 0),
                // G2G has no per-output review flag on metered calls.
                'reviewed' => 0,
                'prompt_tokens' => $prompt,
                'completion_tokens' => $completion,
                'avg_latency_ms' => $aggregate->avg_latency === null ? null : (int) round((float) $aggregate->avg_latency),
                'rate' => $rate,
                'cost' => $cost,
                'cost_source' => $source,
                'cost_reason' => $reason,
            ],
        ];
    }

    /** @return array{0: float|null, 1: string, 2: string|null} */
    private function resolveCost(?int $prompt, ?int $completion, ?float $recorded, ?array $rate): array
    {
        if ($recorded !== null && $recorded > 0) {
            return [$recorded, 'recorded', null];
        }

        if ($prompt === null && $completion === null) {
            return [null, 'unavailable', 'No token counts are recorded for this module, so cost cannot be computed.'];
        }

        if ($rate === null || ($rate['input_per_1k'] === null && $rate['output_per_1k'] === null)) {
            return [null, 'unavailable', 'No price is configured for this module\'s model, so tokens are reported without a money figure.'];
        }

        $cost = (($prompt ?? 0) / 1000) * (float) ($rate['input_per_1k'] ?? 0)
            + (($completion ?? 0) / 1000) * (float) ($rate['output_per_1k'] ?? 0);

        return [round($cost, 6), 'computed', null];
    }

    private function emptyTokens(string $reason): array
    {
        return [
            'outputs' => 0, 'reviewed' => 0, 'prompt_tokens' => null, 'completion_tokens' => null,
            'avg_latency_ms' => null, 'rate' => null, 'cost' => null,
            'cost_source' => 'unavailable', 'cost_reason' => $reason,
        ];
    }

    /** The first active credential bound to one of this module's consumers. */
    private function moduleCredential(string $module, int|string|null $institute): ?object
    {
        $keys = $this->registryKeys($module, $institute);

        if ($keys === [] || ! $this->schema->hasTable('ai_api_keys') || ! $this->schema->hasColumn('ai_api_keys', 'ai_module')) {
            return null;
        }

        return DB::table('ai_api_keys')
            ->whereIn('ai_module', $keys)
            ->where('status', 1)
            ->where(fn ($q) => $q->where('sub_institute_id', $institute)->orWhereNull('sub_institute_id'))
            ->orderByRaw('sub_institute_id IS NULL ASC')
            ->orderByDesc('id')
            ->first();
    }

    private function modelRate(string $module, int|string|null $institute): ?array
    {
        $binding = $this->moduleCredential($module, $institute);

        if ($binding === null || ! $this->schema->hasTable('ai_models')) {
            return null;
        }

        $model = DB::table('ai_models')
            ->where('provider', $binding->api_type)
            ->when(($binding->model ?? '') !== '', fn ($q) => $q->where('model_id', $binding->model))
            ->where(fn ($q) => $q->where('sub_institute_id', $institute)->orWhereNull('sub_institute_id'))
            ->orderByRaw('sub_institute_id IS NULL ASC')
            ->first();

        if ($model === null) {
            return null;
        }

        return [
            'provider' => (string) $model->provider,
            'model_id' => (string) $model->model_id,
            'label' => (string) $model->label,
            'input_per_1k' => $model->input_cost_per_1k === null ? null : (float) $model->input_cost_per_1k,
            'output_per_1k' => $model->output_cost_per_1k === null ? null : (float) $model->output_cost_per_1k,
        ];
    }

    private function reportUsage(string $module, int|string|null $institute): array
    {
        if (! $this->schema->hasTable('ai_generated_reports')) {
            return ['available' => false, 'reason' => 'No report has been built on this estate yet — ai_generated_reports is created with the Templates report builder.'];
        }

        $reports = DB::table('ai_generated_reports')
            ->where('sub_institute_id', $institute)
            ->where('module_key', $module);

        $aggregate = (clone $reports)
            ->selectRaw('count(*) total, coalesce(sum(row_count), 0) rows_reported, max(created_at) last_created')
            ->first();

        return [
            'available' => true,
            'total' => (int) ($aggregate->total ?? 0),
            'rows_reported' => (int) ($aggregate->rows_reported ?? 0),
            'last_created' => $aggregate->last_created ?? null,
            'by_tool' => (clone $reports)
                ->selectRaw('source_tool, count(*) c')
                ->groupBy('source_tool')
                ->orderByDesc('c')
                ->limit(10)
                ->get()
                ->map(fn ($row) => ['tool' => $row->source_tool ?? 'unknown', 'count' => (int) $row->c])
                ->all(),
        ];
    }

    private function providerUsage(string $module, int|string|null $institute): array
    {
        if (! $this->schema->hasTable('ai_api_keys')) {
            return ['available' => false, 'reason' => 'ai_api_keys is not on this estate.'];
        }

        $binding = $this->moduleCredential($module, $institute);
        $calls = null;

        if ($binding !== null && $this->schema->hasTable('ai_daily_used_api')) {
            $calls = DB::table('ai_daily_used_api')
                ->where('parent_id', $binding->id)
                ->orderByDesc('date')
                ->limit(14)
                ->get()
                ->map(fn ($row) => ['date' => (string) $row->date, 'count' => (int) $row->count])
                ->all();
        }

        return [
            'available' => true,
            'bound' => $binding !== null,
            'provider' => $binding->api_type ?? null,
            'model' => $binding->model ?? null,
            'daily_limit' => $binding === null || $binding->api_limit === null ? null : (int) $binding->api_limit,
            'scope' => $binding === null ? null : ($binding->sub_institute_id === null ? 'platform' : 'institute'),
            'daily_calls' => $calls,
        ];
    }

    /** The module's most recent questions, each with how its answer ended. */
    private function recentTurns(string $module, int|string|null $institute): array
    {
        if (! $this->schema->hasTable('ai_conversations') || ! $this->schema->hasTable('ai_conversation_turns')) {
            return [];
        }

        return DB::table('ai_conversation_turns as u')
            ->join('ai_conversations as c', 'c.id', '=', 'u.conversation_id')
            ->leftJoin('ai_conversation_turns as a', function ($join) {
                $join->on('a.conversation_id', '=', 'u.conversation_id')
                    ->whereRaw('a.turn_index = u.turn_index + 1')
                    ->where('a.role', '=', 'assistant');
            })
            ->where('c.sub_institute_id', $institute)
            ->where('c.module_key', $module)
            ->where('u.role', 'user')
            ->orderByDesc('u.id')
            ->limit(self::RECENT)
            ->get(['u.id', 'u.content', 'u.created_at', 'c.user_id', 'c.session_key', 'a.id as answer_id', 'a.error', 'a.latency_ms'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'question' => mb_strimwidth((string) $row->content, 0, 160, '…'),
                'intent' => null,
                'confidence' => null,
                'status' => $row->answer_id === null ? 'unanswered' : ($row->error === null ? 'answered' : 'failed'),
                'duration_ms' => $row->latency_ms === null ? null : (int) $row->latency_ms,
                'user_id' => $row->user_id === null ? null : (int) $row->user_id,
                'conversation' => (string) $row->session_key,
                'created_at' => $row->created_at,
            ])
            ->all();
    }

    private function dailySeries(string $module, int|string|null $institute): array
    {
        if (! $this->schema->hasTable('ai_conversations') || ! $this->schema->hasTable('ai_conversation_turns')) {
            return [];
        }

        return DB::table('ai_conversation_turns as t')
            ->join('ai_conversations as c', 'c.id', '=', 't.conversation_id')
            ->where('c.sub_institute_id', $institute)
            ->where('c.module_key', $module)
            ->where('t.role', 'user')
            ->where('t.created_at', '>=', now()->subDays(30))
            ->selectRaw('date(t.created_at) day, count(*) turns')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn ($row) => ['date' => (string) $row->day, 'turns' => (int) $row->turns])
            ->all();
    }

    // ------------------------------------------------------------------ guardrails

    private function reviewPosture(string $module, int|string|null $institute): array
    {
        if (! $this->schema->hasTable('ai_templates')) {
            return ['available' => false, 'reason' => 'ai_templates is not on this estate.'];
        }

        $aggregate = DB::table('ai_templates')
            ->where('module_key', $module)
            ->where(fn ($q) => $q->where('sub_institute_id', $institute)->orWhereNull('sub_institute_id'))
            ->selectRaw(
                'count(*) total,'
                . ' sum(case when requires_review = 1 then 1 else 0 end) requires_review,'
                . ' sum(case when allow_as_evidence = 1 then 1 else 0 end) allowed_as_evidence,'
                . " sum(case when status = 'published' then 1 else 0 end) published"
            )
            ->first();

        return [
            'available' => true,
            'templates' => (int) ($aggregate->total ?? 0),
            'published' => (int) ($aggregate->published ?? 0),
            'requires_review' => (int) ($aggregate->requires_review ?? 0),
            'allowed_as_evidence' => (int) ($aggregate->allowed_as_evidence ?? 0),
        ];
    }

    /** Calls this module's consumers made that a rule or a quota refused, or that failed. */
    private function refusals(string $module, int|string|null $institute): array
    {
        $keys = $this->registryKeys($module, $institute);

        if ($keys === [] || ! $this->schema->hasTable('ai_usage_events')) {
            return [];
        }

        return DB::table('ai_usage_events')
            ->where('sub_institute_id', $institute)
            ->whereIn('ai_module', $keys)
            ->whereIn('outcome', ['refused', 'failed'])
            ->orderByDesc('id')
            ->limit(self::RECENT)
            ->get(['id', 'ai_module', 'outcome', 'error', 'provider', 'model', 'user_id', 'related_type', 'created_at'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'reference' => 'usage-' . $row->id,
                'template_key' => null,
                'purpose' => $row->related_type ?? $row->ai_module,
                'status' => (string) $row->outcome,
                'reason' => $row->error,
                'provider' => $row->provider,
                'model' => $row->model,
                'requested_by' => $row->user_id === null ? null : (int) $row->user_id,
                'requested_by_role' => null,
                'created_at' => $row->created_at,
            ])
            ->all();
    }

    private function refusalCounts(string $module, int|string|null $institute): array
    {
        $keys = $this->registryKeys($module, $institute);

        if ($keys === [] || ! $this->schema->hasTable('ai_usage_events')) {
            return [];
        }

        return DB::table('ai_usage_events')
            ->where('sub_institute_id', $institute)
            ->whereIn('ai_module', $keys)
            ->whereIn('outcome', ['refused', 'failed'])
            ->selectRaw('outcome, count(*) c')
            ->groupBy('outcome')
            ->orderByDesc('c')
            ->get()
            ->map(fn ($row) => ['status' => (string) $row->outcome, 'count' => (int) $row->c])
            ->all();
    }

    // ------------------------------------------------------------------ activity ledger

    public function recordActivity(Request $request, string $module)
    {
        try {
            $scope = $this->scope($request);
            $institute = $scope->selectedInstituteId;

            $data = $request->validate([
                'operation' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_.-]+$/'],
                'operation_label' => 'nullable|string|max:120',
                'capability' => 'nullable|string|max:40',
                'status' => 'required|string|in:completed,failed,denied,skipped',
                'message' => 'nullable|string|max:2000',
                'subject_entity_key' => 'nullable|string|max:100',
                'subject_id' => 'nullable|integer|min:1',
                'subject_label' => 'nullable|string|max:150',
                'reference' => 'nullable|string|max:120',
                'template_id' => 'nullable|integer|min:1',
                'prompt_id' => 'nullable|integer|min:1',
                'agent_id' => 'nullable|string|max:60',
                'agent_name' => 'nullable|string|max:120',
                'agent_run_id' => 'nullable|string|max:60',
                'workflow' => 'nullable|string|max:120',
                'tool' => 'nullable|string|max:120',
                'result' => 'nullable|array',
            ]);

            $used = [];

            foreach (['template_id' => 'template', 'prompt_id' => 'prompt'] as $field => $slot) {
                if (! isset($data[$field])) {
                    continue;
                }

                $row = $this->resolveTemplate((int) $data[$field], $module, $institute);

                if ($row === null) {
                    return $this->failure("That {$slot} does not exist for the {$module} module, so the activity was not recorded.", 422);
                }

                $used[$slot] = $row;
            }

            if (($data['agent_id'] ?? null) !== null || ($data['agent_name'] ?? null) !== null) {
                $used['agent'] = [
                    'id' => $data['agent_id'] ?? null,
                    'name' => $data['agent_name'] ?? null,
                    'run_id' => $data['agent_run_id'] ?? null,
                    'source' => 'agentic_ai',
                ];
            }

            foreach (['workflow', 'tool'] as $field) {
                if (($data[$field] ?? null) !== null) {
                    $used[$field] = $data[$field];
                }
            }

            $id = $this->audit->record("module.{$module}.{$data['operation']}", $scope, [
                'actor_label' => $this->actorLabel($scope->userId),
                'subject_entity_key' => $data['subject_entity_key'] ?? null,
                'subject_id' => $data['subject_id'] ?? null,
                'related_type' => isset($used['template']) || isset($used['prompt']) ? 'ai_templates' : null,
                'related_id' => $used['template']['id'] ?? $used['prompt']['id'] ?? null,
                'outcome' => match ($data['status']) {
                    'completed' => 'success',
                    'denied' => 'rejected',
                    default => 'failure',
                },
                'message' => $data['message'] ?? ($data['operation_label'] ?? $data['operation']),
                'payload' => [
                    'module' => $module,
                    'operation' => $data['operation'],
                    'operation_label' => $data['operation_label'] ?? null,
                    'capability' => $data['capability'] ?? null,
                    'status' => $data['status'],
                    'subject_label' => $data['subject_label'] ?? null,
                    'reference' => $data['reference'] ?? null,
                    'used' => $used,
                    'result' => $data['result'] ?? null,
                ],
            ]);

            return $this->success(
                $id === null ? 'The activity could not be recorded.' : 'Activity recorded.',
                ['recorded' => $id !== null, 'id' => $id],
                $id === null ? 202 : 201
            );
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    public function activity(Request $request, string $module)
    {
        try {
            $institute = $this->scope($request)->selectedInstituteId;

            if (! $this->schema->hasTable('ai_audit_logs')) {
                return $this->success('No ledger on this estate.', [
                    'module' => $this->moduleIdentity($module, $institute),
                    'available' => false,
                    'reason' => 'ai_audit_logs is not on this estate.',
                    'total' => 0,
                    'entries' => [],
                    'by_operation' => [],
                ]);
            }

            $prefix = "module.{$module}.";

            $query = DB::table('ai_audit_logs')
                ->where(fn ($q) => $q->where('sub_institute_id', $institute)->orWhereNull('sub_institute_id'))
                ->where('event_type', 'like', $prefix . '%');

            if ($request->filled('operation')) {
                $query->where('event_type', $prefix . $request->input('operation'));
            }

            if ($request->filled('outcome')) {
                $query->where('outcome', $request->input('outcome'));
            }

            if ($request->filled('subject_id')) {
                $query->where('subject_id', (int) $request->input('subject_id'));
            }

            return $this->success('Module activity resolved.', [
                'module' => $this->moduleIdentity($module, $institute),
                'available' => true,
                'total' => (int) (clone $query)->count(),
                'entries' => (clone $query)
                    ->orderByDesc('id')
                    ->limit($this->limit($request))
                    ->get()
                    ->map(fn ($row) => $this->presentEntry($row, $prefix))
                    ->all(),
                'by_operation' => (clone $query)
                    ->selectRaw('event_type, outcome, count(*) c')
                    ->groupBy('event_type', 'outcome')
                    ->get()
                    ->map(fn ($row) => [
                        'operation' => substr((string) $row->event_type, strlen($prefix)),
                        'outcome' => (string) $row->outcome,
                        'count' => (int) $row->c,
                    ])
                    ->all(),
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    private function presentEntry(object $row, string $prefix): array
    {
        $payload = $row->payload ? json_decode((string) $row->payload, true) : null;
        $payload = is_array($payload) ? $payload : [];

        return [
            'id' => (int) $row->id,
            'operation' => substr((string) $row->event_type, strlen($prefix)),
            'operation_label' => $payload['operation_label'] ?? null,
            'capability' => $payload['capability'] ?? null,
            'status' => $payload['status'] ?? ($row->outcome === 'success' ? 'completed' : 'failed'),
            'outcome' => $row->outcome,
            'message' => $row->message,
            'actor_id' => $row->actor_id === null ? null : (int) $row->actor_id,
            'actor_label' => $row->actor_label,
            'subject_entity_key' => $row->subject_entity_key,
            'subject_id' => $row->subject_id === null ? null : (int) $row->subject_id,
            'subject_label' => $payload['subject_label'] ?? null,
            'reference' => $payload['reference'] ?? null,
            'used' => is_array($payload['used'] ?? null) ? $payload['used'] : [],
            'result' => $payload['result'] ?? null,
            'created_at' => $row->created_at,
        ];
    }

    private function resolveTemplate(int $id, string $module, int|string|null $institute): ?array
    {
        if (! $this->schema->hasTable('ai_templates')) {
            return null;
        }

        $row = DB::table('ai_templates')
            ->where('id', $id)
            ->where('module_key', $module)
            ->where(fn ($q) => $q->where('sub_institute_id', $institute)->orWhereNull('sub_institute_id'))
            ->first();

        return $row === null ? null : [
            'id' => (int) $row->id,
            'key' => (string) $row->template_key,
            'name' => (string) $row->name,
            'kind' => (string) ($row->kind ?? 'prompt'),
            'version' => (int) ($row->version ?? 1),
            'status' => (string) $row->status,
        ];
    }

    /** G2G's people live in `tbluser`. */
    private function actorLabel(int|string|null $userId): ?string
    {
        if ($userId === null || ! $this->schema->hasTable('tbluser')) {
            return null;
        }

        $row = DB::table('tbluser')->where('id', $userId)->first(['first_name', 'last_name']);

        if ($row === null) {
            return null;
        }

        $name = trim(((string) ($row->first_name ?? '')) . ' ' . ((string) ($row->last_name ?? '')));

        return $name === '' ? null : $name;
    }
}
