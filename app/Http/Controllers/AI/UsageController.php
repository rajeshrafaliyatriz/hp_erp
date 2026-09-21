<?php

namespace App\Http\Controllers\AI;

use App\Domain\AI\Configuration\AiModuleRegistry;
use App\Domain\AI\Support\AiAuditLogger;
use App\Domain\AI\Support\AiUsageMeter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Usage & Cost — what the AI spent, broken down, and the quota that bounds it.
 *
 * ── WHAT THIS REPLACES ─────────────────────────────────────────────────────
 *
 * The capability panel read `hpbrain_ai_executions`, a table another application
 * writes. Every call this AI layer made was therefore invisible to it: the meter read
 * zero while real model calls were being billed. `AiUsageMeter` now records at the
 * one choke point every call passes through, and this controller reports it.
 *
 * ── THREE BREAKDOWNS, BECAUSE THEY ANSWER THREE QUESTIONS ──────────────────
 *
 * By module — "what is Assessment AI costing us", which is the question that decides
 * whether a feature is worth its bill. By provider and model — "what are we paying
 * per model", which is the question that decides which model a capability should get.
 * By day — "is this trending up", which is the only one that catches a runaway before
 * the invoice does. One flat total answers none of them.
 *
 * ── COST IS BLANK WHEN IT IS NOT KNOWN ─────────────────────────────────────
 *
 * `ai_models` carries per-1,000-token rates and they are null unless an administrator
 * fills them in, so `estimated_cost_usd` is usually null and the screen shows tokens
 * with an empty money column. `cost_known` says so explicitly rather than leaving a
 * reader to wonder whether zero means free. A guessed rate on the screen somebody
 * uses to explain an invoice would be worse than a blank.
 */
class UsageController extends AiController
{
    public function __construct(
        private readonly AiUsageMeter $meter,
        private readonly AiModuleRegistry $modules,
        private readonly AiAuditLogger $audit,
    ) {
    }

    /** Periods a caller may ask for, and how far back each reaches. */
    private const WINDOWS = ['day' => 1, 'week' => 7, 'month' => 30, 'quarter' => 90];

    /**
     * Spend for this organisation over a window, broken down three ways.
     */
    public function summary(Request $request)
    {
        try {
            $institute = $this->scope($request)->selectedInstituteId;

            if (! Schema::hasTable('ai_usage_events')) {
                return $this->failure('Usage metering is not installed on this deployment.', 422);
            }

            $validated = $request->validate([
                'window' => ['nullable', 'string', Rule::in(array_keys(self::WINDOWS))],
            ]);

            $window = $validated['window'] ?? 'month';
            $since = now()->subDays(self::WINDOWS[$window]);

            return $this->success('Usage resolved.', [
                'sub_institute_id' => $institute,
                'window' => $window,
                'since' => $since->toDateTimeString(),
                'totals' => $this->totals($institute, $since),
                'by_module' => $this->byModule($institute, $since),
                'by_model' => $this->byModel($institute, $since),
                'by_day' => $this->byDay($institute, $since),
                'quotas' => $this->quotas($institute),
                // Named rather than hidden: those rows are real usage, written by
                // another application, and an administrator looking for a figure that
                // is not here should know where else it might be.
                'other_ledgers' => [
                    'hpbrain_ai_executions' => $this->foreignCount('hpbrain_ai_executions', $institute),
                    'ai_daily_used_api' => $this->foreignCount('ai_daily_used_api', $institute),
                ],
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /** The most recent calls, for when a total needs explaining. */
    public function events(Request $request)
    {
        try {
            $institute = $this->scope($request)->selectedInstituteId;

            $rows = DB::table('ai_usage_events')
                ->where('sub_institute_id', $institute)
                ->orderByDesc('id')
                ->limit($this->limit($request, 50, 200))
                ->get();

            return $this->success('Usage events resolved.', [
                'sub_institute_id' => $institute,
                'events' => $rows->map(fn ($row) => [
                    'id' => (int) $row->id,
                    'ai_module' => (string) $row->ai_module,
                    'module_label' => $this->modules->label((string) $row->ai_module),
                    'provider' => (string) $row->provider,
                    'model' => $row->model === null ? null : (string) $row->model,
                    'source' => $row->source === null ? null : (string) $row->source,
                    'input_tokens' => (int) $row->input_tokens,
                    'output_tokens' => (int) $row->output_tokens,
                    'latency_ms' => $row->latency_ms === null ? null : (int) $row->latency_ms,
                    'estimated_cost_usd' => $row->estimated_cost_usd === null
                        ? null
                        : (float) $row->estimated_cost_usd,
                    'outcome' => (string) $row->outcome,
                    'finish_reason' => $row->finish_reason === null ? null : (string) $row->finish_reason,
                    'error' => $row->error === null ? null : (string) $row->error,
                    'related_type' => $row->related_type === null ? null : (string) $row->related_type,
                    'related_id' => $row->related_id === null ? null : (int) $row->related_id,
                    'created_at' => $row->created_at === null ? null : (string) $row->created_at,
                ])->all(),
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /**
     * Set or clear a quota.
     *
     * A `token_limit` of 0 removes the quota rather than setting a limit of nothing.
     * A zero ceiling would refuse every call while reading, on screen, as "configured"
     * — the most confusing possible state for a control whose whole job is to be
     * predictable.
     */
    public function saveQuota(Request $request)
    {
        try {
            $scope = $this->scope($request);
            $institute = $scope->selectedInstituteId;

            $validated = $request->validate([
                // Null or absent means the whole organisation.
                'ai_module' => ['nullable', 'string', Rule::in($this->modules->keys())],
                'period' => ['required', 'string', Rule::in(['day', 'month'])],
                'token_limit' => ['required', 'integer', 'min:0', 'max:1000000000'],
                'warn_at_percent' => ['nullable', 'integer', 'min:1', 'max:99'],
            ]);

            $moduleKey = $validated['ai_module'] ?? null;

            $existing = DB::table('ai_usage_quotas')
                ->where('sub_institute_id', $institute)
                ->where('period', $validated['period'])
                ->when($moduleKey === null, fn ($q) => $q->whereNull('ai_module'))
                ->when($moduleKey !== null, fn ($q) => $q->where('ai_module', $moduleKey))
                ->first();

            if ((int) $validated['token_limit'] === 0) {
                if ($existing !== null) {
                    DB::table('ai_usage_quotas')->where('id', $existing->id)->delete();
                }

                $this->audit->record('ai.quota.removed', $scope, [
                    'related_type' => 'ai_usage_quotas',
                    'message' => sprintf(
                        'AI token quota removed for %s (%s).',
                        $moduleKey === null ? 'the whole organisation' : $this->modules->label($moduleKey),
                        $validated['period']
                    ),
                ]);

                return $this->success('Quota removed.', ['quotas' => $this->quotas($institute)]);
            }

            $payload = [
                'token_limit' => (int) $validated['token_limit'],
                'warn_at_percent' => $validated['warn_at_percent'] ?? 80,
                'status' => 1,
                'updated_at' => now(),
            ];

            if ($existing !== null) {
                DB::table('ai_usage_quotas')->where('id', $existing->id)->update($payload);
            } else {
                DB::table('ai_usage_quotas')->insert($payload + [
                    'sub_institute_id' => $institute,
                    'ai_module' => $moduleKey,
                    'period' => $validated['period'],
                    'created_by' => $scope->userId,
                    'created_at' => now(),
                ]);
            }

            $this->audit->record('ai.quota.set', $scope, [
                'related_type' => 'ai_usage_quotas',
                'message' => sprintf(
                    'AI token quota set to %s per %s for %s.',
                    number_format((int) $validated['token_limit']),
                    $validated['period'],
                    $moduleKey === null ? 'the whole organisation' : $this->modules->label($moduleKey)
                ),
            ]);

            return $this->success('Quota saved.', ['quotas' => $this->quotas($institute)]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /** The modules a quota can name, so the form does not invent keys. */
    public function options(Request $request)
    {
        try {
            $this->scope($request);

            return $this->success('Usage options resolved.', [
                'modules' => array_map(fn ($module) => [
                    'key' => $module['key'],
                    'label' => $module['label'],
                    'wired' => $module['wired'],
                ], $this->modules->all()),
                'periods' => ['day', 'month'],
                'windows' => array_keys(self::WINDOWS),
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    // ---------------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function totals(int|string $institute, $since): array
    {
        $row = $this->scoped($institute, $since)
            ->selectRaw('
                count(*) calls,
                sum(input_tokens) input_tokens,
                sum(output_tokens) output_tokens,
                sum(estimated_cost_usd) cost,
                sum(case when outcome = ? then 1 else 0 end) failed,
                sum(case when outcome = ? then 1 else 0 end) refused,
                sum(case when estimated_cost_usd is not null then 1 else 0 end) priced,
                avg(latency_ms) avg_latency
            ', [AiUsageMeter::OUTCOME_FAILED, AiUsageMeter::OUTCOME_REFUSED])
            ->first();

        $cost = $row->cost === null ? null : (float) $row->cost;
        $calls = (int) $row->calls;
        $priced = (int) $row->priced;

        return [
            'calls' => $calls,
            'input_tokens' => (int) $row->input_tokens,
            'output_tokens' => (int) $row->output_tokens,
            'total_tokens' => (int) $row->input_tokens + (int) $row->output_tokens,
            'failed' => (int) $row->failed,
            'refused' => (int) $row->refused,
            'avg_latency_ms' => $row->avg_latency === null ? null : (int) round((float) $row->avg_latency),
            'estimated_cost_usd' => $cost,

            /*
             * HOW MANY CALLS THAT FIGURE ACTUALLY COVERS.
             *
             * A rate is applied when a call is recorded, so a row written before
             * anybody filled in `ai_models.input_cost_per_1k` has a null cost forever
             * — correctly, because nobody knows what it cost. `SUM()` skips those
             * nulls, which means the total can silently describe a fraction of the
             * calls: two calls, 606 tokens, and a cost covering 303 of them.
             *
             * Reporting the count alongside is what stops that reading as the whole
             * bill. `cost_complete` is the only honest basis for a screen to present
             * the number without a caveat.
             */
            'calls_priced' => $priced,
            'cost_known' => $cost !== null,
            'cost_complete' => $calls > 0 && $priced === $calls,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function byModule(int|string $institute, $since): array
    {
        return $this->scoped($institute, $since)
            ->selectRaw('ai_module, count(*) calls, sum(input_tokens + output_tokens) tokens, sum(estimated_cost_usd) cost')
            ->groupBy('ai_module')
            ->orderByDesc('tokens')
            ->get()
            ->map(fn ($row) => [
                'ai_module' => (string) $row->ai_module,
                'module_label' => $this->modules->label((string) $row->ai_module),
                'calls' => (int) $row->calls,
                'tokens' => (int) $row->tokens,
                'estimated_cost_usd' => $row->cost === null ? null : (float) $row->cost,
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function byModel(int|string $institute, $since): array
    {
        return $this->scoped($institute, $since)
            ->selectRaw('provider, model, count(*) calls, sum(input_tokens + output_tokens) tokens, sum(estimated_cost_usd) cost')
            ->groupBy('provider', 'model')
            ->orderByDesc('tokens')
            ->get()
            ->map(fn ($row) => [
                'provider' => (string) $row->provider,
                'model' => $row->model === null ? null : (string) $row->model,
                'calls' => (int) $row->calls,
                'tokens' => (int) $row->tokens,
                'estimated_cost_usd' => $row->cost === null ? null : (float) $row->cost,
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function byDay(int|string $institute, $since): array
    {
        return $this->scoped($institute, $since)
            ->selectRaw('DATE(created_at) day, count(*) calls, sum(input_tokens + output_tokens) tokens, sum(estimated_cost_usd) cost')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn ($row) => [
                'day' => (string) $row->day,
                'calls' => (int) $row->calls,
                'tokens' => (int) $row->tokens,
                'estimated_cost_usd' => $row->cost === null ? null : (float) $row->cost,
            ])
            ->all();
    }

    /**
     * Every quota, with how much of it is already spent.
     *
     * The limit alone is not useful — "1,000,000 tokens a month" says nothing about
     * whether a feature is about to stop working. The consumed figure is what makes it
     * actionable, and it is read through the same method the guard uses so the screen
     * and the enforcement cannot disagree.
     *
     * @return array<int, array<string, mixed>>
     */
    private function quotas(int|string $institute): array
    {
        if (! Schema::hasTable('ai_usage_quotas')) {
            return [];
        }

        return DB::table('ai_usage_quotas')
            ->where('sub_institute_id', $institute)
            ->orderByRaw('ai_module IS NULL DESC')
            ->orderBy('ai_module')
            ->get()
            ->map(function ($row) use ($institute) {
                $used = $this->meter->tokensUsed($institute, $row->ai_module, (string) $row->period);
                $limit = (int) $row->token_limit;
                $percent = $limit > 0 ? (int) round($used / $limit * 100) : 0;

                return [
                    'id' => (int) $row->id,
                    'ai_module' => $row->ai_module === null ? null : (string) $row->ai_module,
                    'module_label' => $row->ai_module === null
                        ? 'Whole organisation'
                        : $this->modules->label((string) $row->ai_module),
                    'period' => (string) $row->period,
                    'token_limit' => $limit,
                    'tokens_used' => $used,
                    'percent_used' => $percent,
                    'warn_at_percent' => (int) $row->warn_at_percent,
                    // Both stated, because they lead to different actions: warning
                    // means look at it, exceeded means something is already refusing.
                    'warning' => $percent >= (int) $row->warn_at_percent && $percent < 100,
                    'exceeded' => $used >= $limit,
                    'status' => (int) $row->status,
                ];
            })
            ->all();
    }

    /**
     * A count from a ledger this layer does not write.
     *
     * Those tables key on a `tenant_id` string rather than `sub_institute_id`, so the
     * filter differs — reported rather than merged, because merging two meters with
     * different provenance into one number would make neither checkable.
     */
    private function foreignCount(string $table, int|string $institute): ?int
    {
        if (! Schema::hasTable($table)) {
            return null;
        }

        $query = DB::table($table);

        if (Schema::hasColumn($table, 'tenant_id')) {
            $query->where('tenant_id', (string) $institute);
        } elseif (Schema::hasColumn($table, 'sub_institute_id')) {
            $query->where('sub_institute_id', $institute);
        }

        return (int) $query->count();
    }

    /** @return \Illuminate\Database\Query\Builder */
    private function scoped(int|string $institute, $since)
    {
        return DB::table('ai_usage_events')
            ->where('sub_institute_id', $institute)
            ->where('created_at', '>=', $since);
    }
}
