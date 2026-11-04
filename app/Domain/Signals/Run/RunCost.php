<?php

namespace App\Domain\Signals\Run;

use App\Domain\ProductMaster\ProductResolver;
use App\Domain\Signals\Opportunities\ResearchRun;
use App\Support\ColumnExists;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * What a research run cost, written onto the run when it finishes. (RunAccounting counts a run's stages; this counts its money and its
 * requests.)
 *
 * AI: the sum of the run's own `ai_usage_events` rows, found by `related_type` + `related_id` AND the run's organisation (StructuredAi files every
 * call a run makes under RunContext). A run that made no AI call stores 0, not NULL: the two mean different things, "cost nothing" and "not
 * known". The cost is NULL, not 0, when a call that used tokens has no price (AiUsageMeter leaves the cost empty until someone fills in the model's
 * rate): a total that silently leaves that call out would read as cheaper than it was. It is NULL too when the calls were priced in two currencies.
 * The total is kept with its currency (`ai_cost` + `ai_cost_currency`); a run that used no AI is 0 in the platform's default currency.
 *
 * Search: the number of requests the researcher sent to the search provider, given by the caller. Provider credits are not read: neither search
 * provider reports them in the code that exists, so `search_credits` stays NULL (unknown) rather than being estimated.
 *
 * Never throws into the run. A cost that cannot be read is logged and left as it was; the run's result is not touched.
 */
final class RunCost
{
    private const TABLE = 'g2g_research_runs';

    /** The column is a SMALLINT UNSIGNED. */
    private const MAX_SEARCH_REQUESTS = 65535;

    /**
     * Fill the cost columns the run's table has. Safe to call again: the numbers are re-read, never added to.
     *
     * @param  int|null  $searchRequests  requests sent to the search provider by this run; null leaves `search_requests` as it is
     */
    public static function fill(ResearchRun $run, ?int $searchRequests = null): void
    {
        try {
            $values = self::aiValues($run);
            if ($searchRequests !== null) {
                $values['search_requests'] = min(self::MAX_SEARCH_REQUESTS, max(0, $searchRequests));
            }
            // The cost columns exist only where migrations 2026_12_08_090200 and 2026_12_08_100200 have run (not yet on every host).
            $values = array_intersect_key($values, array_flip(ColumnExists::all(self::TABLE)));
            if ($values === []) {
                return;
            }

            ResearchRun::where('sub_institute_id', $run->sub_institute_id)->whereKey($run->id)->update($values);
            $run->forceFill($values);
            foreach (array_keys($values) as $column) {
                $run->syncOriginalAttribute($column);
            }
        } catch (\Throwable $e) {
            Log::warning('signals.run_cost_not_recorded', ['run' => $run->id, 'exception' => get_class($e), 'message' => mb_substr($e->getMessage(), 0, 200)]);
        }
    }

    /**
     * The Product Master row a run is for: the sub-product when the run was started for one offer, else the product. Null when the run has no
     * product value the master knows, or when the column or the master table does not exist on this host (the run is never held up by it).
     */
    public static function productMasterId(int $tenantId, ?string $productKey, ?string $offerId): ?int
    {
        if (! ColumnExists::has(self::TABLE, 'product_master_id')) {
            return null;
        }

        try {
            $id = (new ProductResolver())->forSignal($tenantId, $productKey, $offerId)?->id;

            return $id === null ? null : (int) $id;
        } catch (\Throwable $e) {
            Log::warning('signals.run_product_not_resolved', ['tenant' => $tenantId, 'exception' => get_class($e)]);

            return null;
        }
    }

    /** @return array<string, int|float|string|null> empty when the usage table does not exist: nothing was metered, so nothing is known */
    private static function aiValues(ResearchRun $run): array
    {
        if (! Schema::hasTable('ai_usage_events')) {
            return [];
        }

        // A call is priced in rupees (estimated_cost + cost_currency) or, on a host that has not run the pricing migration or for a call made by
        // the older meter, in dollars (estimated_cost_usd alone). Reading the dollar column as USD keeps both worth something.
        $columns = ColumnExists::all('ai_usage_events');
        if (in_array('estimated_cost', $columns, true) && in_array('cost_currency', $columns, true)) {
            $amountSql = 'COALESCE(estimated_cost, estimated_cost_usd)';
            $currencySql = "COALESCE(cost_currency, CASE WHEN estimated_cost_usd IS NOT NULL THEN 'USD' END)";
        } else {
            $amountSql = 'estimated_cost_usd';
            $currencySql = "CASE WHEN estimated_cost_usd IS NOT NULL THEN 'USD' END";
        }
        // Only a call that used tokens counts towards "unpriced" and towards the currency: a refused call's zero is a zero in any currency.
        $used = 'input_tokens + output_tokens > 0';

        $sum = DB::table('ai_usage_events')
            ->where('related_type', RunContext::RELATED_TYPE)
            ->where('related_id', $run->id)
            ->where('sub_institute_id', $run->sub_institute_id)
            ->selectRaw('COALESCE(SUM(input_tokens), 0) AS input_tokens')
            ->selectRaw('COALESCE(SUM(output_tokens), 0) AS output_tokens')
            ->selectRaw("COALESCE(SUM({$amountSql}), 0) AS cost")
            // An amount with no currency is no price either.
            ->selectRaw("COALESCE(SUM(CASE WHEN {$used} AND (({$amountSql}) IS NULL OR ({$currencySql}) IS NULL) THEN 1 ELSE 0 END), 0) AS unpriced")
            ->selectRaw("COUNT(DISTINCT CASE WHEN {$used} THEN {$currencySql} END) AS currencies")
            ->selectRaw("MIN(CASE WHEN {$used} THEN {$currencySql} END) AS currency")
            ->first();

        [$cost, $currency] = self::total($sum);

        return [
            'ai_input_tokens' => (int) $sum->input_tokens,
            'ai_output_tokens' => (int) $sum->output_tokens,
            'ai_cost' => $cost,
            'ai_cost_currency' => $currency,
        ];
    }

    /**
     * The run's cost and its currency, both null when the cost is not known: a call that used tokens has no price, or the calls were priced in
     * two currencies (adding rupees to dollars would be a made-up number). A run whose calls used no tokens cost 0, in the platform's default
     * currency, which is not the same thing as unknown.
     *
     * @return array{0: float|null, 1: string|null}
     */
    private static function total(object $sum): array
    {
        if ((int) $sum->unpriced > 0 || (int) $sum->currencies > 1) {
            return [null, null];
        }

        if ((int) $sum->currencies === 0) {
            return [0.0, (string) config('ai.default_currency', 'INR')];
        }

        return [round((float) $sum->cost, 4), (string) $sum->currency];
    }
}
