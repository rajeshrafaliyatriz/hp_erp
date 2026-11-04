<?php

namespace App\Domain\AI\Support;

use App\Domain\AI\Configuration\ResolvedAiConfiguration;
use App\Support\ColumnExists;
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
 *
 * ── THE PRICE IS TAKEN WHEN THE CALL IS MADE, IN THE RATE'S OWN CURRENCY ───
 *
 * The first row that has BOTH rates wins, the organisation's own row before the
 * platform's. An organisation row with blank rates is not a price, so it never hides a
 * platform default somebody did fill in. The row's `currency` travels with the amount
 * into `estimated_cost` / `cost_currency`; `estimated_cost_usd` is written only when
 * that currency is USD, so no consumer of the dollar column ever reads rupees as
 * dollars. A call made before a price existed stays unpriced — it is never repriced.
 *
 * ── A HOST THAT HAS NOT RUN THE PRICING MIGRATIONS BEHAVES AS IT ALWAYS DID ─
 *
 * `ai_models.currency` and the two cost columns of `ai_usage_events` arrive host by
 * host. Without `currency` a rate is read as US dollars; without the cost columns the
 * insert is the one this class has always made. This is on the path of every AI call
 * in the platform, so those column checks are made once per process (see hasColumn()),
 * and a pricing failure costs the row its price, never the row itself.
 */
final class AiUsageMeter
{
    public const OUTCOME_SUCCESS = 'success';

    public const OUTCOME_FAILED = 'failed';

    /** Refused by a quota — never reached the provider, so cost is zero and real. */
    public const OUTCOME_REFUSED = 'refused';

    /** The container key under which the per-process column lists live (see hasColumn()). */
    private const COLUMNS_KEY = 'ai.usage_meter.columns';

    /** How long a column that was MISSING is trusted to still be missing; a column that exists is never asked about again. */
    private const MISSING_COLUMN_RECHECK_SECONDS = 60;

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

            $price = $this->price(
                $config->provider,
                $config->model,
                $inputTokens,
                $outputTokens,
                $institute
            );

            $row = [
                'ai_module' => $moduleKey,
                'provider' => $config->provider,
                'model' => $config->model,
                'source' => $config->source,
                'input_tokens' => max(0, $inputTokens),
                'output_tokens' => max(0, $outputTokens),
                'latency_ms' => $latencyMs,
                // Dollars only: a rupee price must never be read as dollars by whoever still sums this column.
                'estimated_cost_usd' => $price !== null && $price['currency'] === 'USD' ? $price['amount'] : null,
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
            ];

            // Both or neither: an amount without its currency says nothing. A host that has not run the pricing
            // migration gets exactly the insert this class has always made.
            if ($this->hasColumn('ai_usage_events', 'estimated_cost') && $this->hasColumn('ai_usage_events', 'cost_currency')) {
                $row['estimated_cost'] = $price['amount'] ?? null;
                $row['cost_currency'] = $price['currency'] ?? null;
            }

            DB::table('ai_usage_events')->insert($row);
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
     * What this call cost, if the catalogue holds a complete rate for the model.
     *
     * Rates are per 1,000 tokens, in the row's currency. Returns null rather than 0 when
     * no row has both rates — see the class note on why a blank beats a guess. The
     * lookup itself excludes rows with a blank rate, so an organisation's half-filled
     * row falls through to the platform default instead of hiding it.
     *
     * A failure here is logged and answered with "unknown": the usage row, with its
     * tokens, is worth more than the price that could not be read.
     *
     * @return array{amount: float, currency: string}|null
     */
    private function price(
        string $provider,
        ?string $model,
        int $inputTokens,
        int $outputTokens,
        int|string|null $institute
    ): ?array {
        if ($model === null) {
            return null;
        }

        try {
            if (! Schema::hasTable('ai_models')) {
                return null;
            }

            // Before the currency migration a rate has no currency and is read as dollars, as it always was.
            $hasCurrency = $this->hasColumn('ai_models', 'currency');

            $row = DB::table('ai_models')
                ->where('provider', $provider)
                ->where('model_id', $model)
                ->where(function ($inner) use ($institute) {
                    $inner->whereNull('sub_institute_id');

                    if ($institute !== null && trim((string) $institute) !== '') {
                        $inner->orWhere('sub_institute_id', $institute);
                    }
                })
                ->whereNotNull('input_cost_per_1k')
                ->whereNotNull('output_cost_per_1k')
                // The organisation's own row wins, matching ModelCatalog's precedence.
                ->orderByRaw('sub_institute_id IS NULL ASC')
                ->orderBy('id')
                ->first($hasCurrency
                    ? ['input_cost_per_1k', 'output_cost_per_1k', 'currency']
                    : ['input_cost_per_1k', 'output_cost_per_1k']);

            if ($row === null) {
                return null;
            }

            $currency = $hasCurrency ? strtoupper(trim((string) $row->currency)) : 'USD';

            // A rate in an unreadable currency is an unknown price, not a dollar one.
            if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
                return null;
            }

            return [
                'amount' => round(
                    (max(0, $inputTokens) / 1000) * (float) $row->input_cost_per_1k
                        + (max(0, $outputTokens) / 1000) * (float) $row->output_cost_per_1k,
                    6
                ),
                'currency' => $currency,
            ];
        } catch (Throwable $exception) {
            Log::warning('[ai.usage] could not price a call; recording it unpriced', [
                'provider' => $provider,
                'model' => $model,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Whether a table has a column, asked of the database once per process (a queue worker
     * keeps its answer for its lifetime; a web request is its own process).
     *
     * Every AI call lands here, so the answer is kept. The lists live in the application
     * container rather than a static property: a static would outlive the application a
     * test boots and answer for the previous test's schema. A column that exists is never
     * asked about again; one that is missing is asked about again after a minute, so a
     * queue worker notices a migration run under it instead of leaving every call after
     * it unpriced until the next restart. A read that fails means "no such column" — the
     * behaviour this class had before the columns existed — never an exception.
     */
    private function hasColumn(string $table, string $column): bool
    {
        $cache = $this->columnCache();
        $known = $cache[$table] ?? null;

        if ($known !== null) {
            $present = in_array($column, $known['columns'], true);

            if ($present || $known['read_at'] > time() - self::MISSING_COLUMN_RECHECK_SECONDS) {
                return $present;
            }
        }

        try {
            $columns = array_map('strtolower', ColumnExists::all($table));
        } catch (Throwable) {
            $columns = [];
        }

        $cache[$table] = ['columns' => $columns, 'read_at' => time()];

        return in_array($column, $columns, true);
    }

    /** Forget which columns exist, for a process that has just run a migration (and for tests). */
    public function forgetColumns(): void
    {
        $this->columnCache()->exchangeArray([]);
    }

    private function columnCache(): \ArrayObject
    {
        if (! app()->bound(self::COLUMNS_KEY)) {
            app()->instance(self::COLUMNS_KEY, new \ArrayObject());
        }

        return app()->make(self::COLUMNS_KEY);
    }
}
