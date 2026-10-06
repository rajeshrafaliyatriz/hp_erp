<?php

namespace App\Domain\AI\Support;

use App\Domain\AI\Configuration\ResolvedAiConfiguration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Counts what the AI costs, and refuses the call that would exceed a quota.
 *
 * ── METERED AT ONE POINT, SO NOTHING CAN SKIP IT ───────────────────────────
 *
 * Every model call in this layer goes through `AiModelClient::complete()`, and that
 * method calls `guard()` before the request and `record()` after it. Metering in the
 * callers instead would mean each new caller has to remember — and the one that
 * forgets is invisible, because a missing row looks exactly like a call that never
 * happened.
 *
 * ── RECORDING NEVER BREAKS THE CALL ────────────────────────────────────────
 *
 * `record()` swallows its own failures into the log, for the same reason
 * `AiAuditLogger` does: losing a meter row is bad, losing a user's answer because the
 * meter table was locked is worse. `guard()` is the opposite — it must be allowed to
 * refuse — but a *failure to read* the quota is treated as "no quota", because a
 * database hiccup should not take AI down for an organisation that never set a limit.
 *
 * ── COST IS ONLY REPORTED WHEN IT IS KNOWN ─────────────────────────────────
 *
 * Rates come from `ai_models`, where they are null unless somebody filled them in.
 * A null rate produces a null cost, not a zero and not an estimate. Zero would read
 * as free; an estimate on a screen used to explain an invoice would be worse than
 * blank.
 */
final class AiUsageMeter
{
    public const OUTCOME_SUCCESS = 'success';

    public const OUTCOME_FAILED = 'failed';

    /** Refused by a quota — never reached the provider, so cost is zero and real. */
    public const OUTCOME_REFUSED = 'refused';

    /**
     * Record one call.
     *
     * @param  array<string, mixed>  $options  `related_type`, `related_id`, `user_id`,
     *                                         `outcome`, `error`, `finish_reason`.
     */
    public function record(
        string $moduleKey,
        ResolvedAiConfiguration $config,
        int|string|null $institute,
        int $inputTokens,
        int $outputTokens,
        ?int $latencyMs,
        array $options = []
    ): void {
        try {
            if (! Schema::hasTable('ai_usage_events')) {
                return;
            }

            DB::table('ai_usage_events')->insert([
                'ai_module' => $moduleKey,
                'provider' => $config->provider,
                'model' => $config->model,
                'source' => $config->source,
                'input_tokens' => max(0, $inputTokens),
                'output_tokens' => max(0, $outputTokens),
                'latency_ms' => $latencyMs,
                'estimated_cost_usd' => $this->cost(
                    $config->provider,
                    $config->model,
                    $inputTokens,
                    $outputTokens,
                    $institute
                ),
                'outcome' => $options['outcome'] ?? self::OUTCOME_SUCCESS,
                'finish_reason' => $options['finish_reason'] ?? null,
                'error' => isset($options['error']) ? mb_substr((string) $options['error'], 0, 2000) : null,
                'related_type' => $options['related_type'] ?? null,
                'related_id' => isset($options['related_id']) && is_numeric($options['related_id'])
                    ? (int) $options['related_id']
                    : null,
                'user_id' => $options['user_id'] ?? null,
                'sub_institute_id' => $institute,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable $exception) {
            // See the class note: a meter failure must not cost the caller its answer.
            Log::warning('[ai.usage] could not record a usage event', [
                'module' => $moduleKey,
                'provider' => $config->provider,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Refuse the call when a quota is already spent.
     *
     * Returns null when the call may proceed, or the reason it may not. A string
     * rather than an exception because the caller decides what a refusal looks like —
     * `AiModelClient` turns it into `AiQuotaExceededException`, and a future batch
     * caller might prefer to skip and carry on.
     */
    public function guard(string $moduleKey, int|string|null $institute): ?string
    {
        if ($institute === null || trim((string) $institute) === '') {
            return null;
        }

        try {
            $quota = $this->applicableQuota($moduleKey, $institute);

            if ($quota === null) {
                return null;
            }

            $used = $this->tokensUsed($institute, $quota->ai_module, (string) $quota->period);

            if ($used < (int) $quota->token_limit) {
                return null;
            }

            return sprintf(
                'The %s AI token quota for this organisation is spent: %s of %s tokens used this %s. '
                . 'Raise the limit under AI & Intelligence → Usage & Cost, or wait for the period to reset.',
                $quota->ai_module === null ? 'overall' : $quota->ai_module,
                number_format($used),
                number_format((int) $quota->token_limit),
                $quota->period
            );
        } catch (Throwable $exception) {
            // A quota that cannot be read is treated as absent. Failing closed would
            // take AI down for every organisation that never set a limit, which is
            // most of them, over a transient database error.
            Log::warning('[ai.usage] could not evaluate a quota; allowing the call', [
                'module' => $moduleKey,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The narrowest active quota that applies: this module's, else the organisation's.
     *
     * Two ordered lookups rather than one clever query, for the same reason
     * `AiConfigurationResolver` does it: this is the code read during an incident, and
     * two named steps are worth more then than a saved round trip.
     */
    private function applicableQuota(string $moduleKey, int|string $institute): ?object
    {
        if (! Schema::hasTable('ai_usage_quotas')) {
            return null;
        }

        foreach ([$moduleKey, null] as $scope) {
            $query = DB::table('ai_usage_quotas')
                ->where('sub_institute_id', $institute)
                ->where('status', 1);

            $scope === null
                ? $query->whereNull('ai_module')
                : $query->where('ai_module', $scope);

            $row = $query->first();

            if ($row !== null) {
                return $row;
            }
        }

        return null;
    }

    /** Tokens consumed in the current period. Refused calls are excluded — they cost nothing. */
    public function tokensUsed(int|string $institute, ?string $moduleKey, string $period): int
    {
        if (! Schema::hasTable('ai_usage_events')) {
            return 0;
        }

        $query = DB::table('ai_usage_events')
            ->where('sub_institute_id', $institute)
            ->where('outcome', '!=', self::OUTCOME_REFUSED)
            ->where('created_at', '>=', $this->periodStart($period));

        if ($moduleKey !== null) {
            $query->where('ai_module', $moduleKey);
        }

        return (int) $query->sum(DB::raw('input_tokens + output_tokens'));
    }

    /** The start of the current period. */
    public function periodStart(string $period): \Illuminate\Support\Carbon
    {
        return $period === 'day' ? now()->startOfDay() : now()->startOfMonth();
    }

    /**
     * What this call cost, if the catalogue knows the rate.
     *
     * Rates are per 1,000 tokens. Returns null rather than 0.0 when either rate is
     * missing — see the class note on why a blank beats a guess.
     */
    private function cost(
        string $provider,
        ?string $model,
        int $inputTokens,
        int $outputTokens,
        int|string|null $institute
    ): ?float {
        if ($model === null || ! Schema::hasTable('ai_models')) {
            return null;
        }

        $row = DB::table('ai_models')
            ->where('provider', $provider)
            ->where('model_id', $model)
            ->where(function ($inner) use ($institute) {
                $inner->whereNull('sub_institute_id');

                if ($institute !== null && trim((string) $institute) !== '') {
                    $inner->orWhere('sub_institute_id', $institute);
                }
            })
            // The organisation's own row wins, matching ModelCatalog's precedence.
            ->orderByRaw('sub_institute_id IS NULL ASC')
            ->first(['input_cost_per_1k', 'output_cost_per_1k']);

        if ($row === null || $row->input_cost_per_1k === null || $row->output_cost_per_1k === null) {
            return null;
        }

        return round(
            ($inputTokens / 1000) * (float) $row->input_cost_per_1k
                + ($outputTokens / 1000) * (float) $row->output_cost_per_1k,
            6
        );
    }
}
