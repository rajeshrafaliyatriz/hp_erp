<?php

namespace App\Domain\AgentGateway;

use App\Support\ColumnExists;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What an agent run cost, read from the metering rows its model call left (the same rule RunCost applies to a research run).
 *
 * The call is made with related_type = RELATED_TYPE and related_id = the run's id, so AiUsageMeter files its `ai_usage_events` row under the run and
 * prices it from the `ai_models` rate card (rupees by default). This reads the rows back, by type, id AND the run's organisation. The cost is NULL, not
 * 0, when a call that used tokens has no price, or when the calls were priced in two currencies: a total that silently leaves a call out would read as
 * cheaper than it was. A run whose calls used no tokens cost 0 in the platform's default currency, which is not the same thing as unknown.
 */
final class AgentRunCost
{
    public const RELATED_TYPE = 'g2g_agent_run';

    /** @return array{input_tokens: int, output_tokens: int, cost: ?float, currency: ?string} */
    public static function read(int $tenantId, int $runId): array
    {
        $empty = ['input_tokens' => 0, 'output_tokens' => 0, 'cost' => null, 'currency' => null];
        if (! Schema::hasTable('ai_usage_events')) {
            return $empty;
        }

        $columns = ColumnExists::all('ai_usage_events');
        if (in_array('estimated_cost', $columns, true) && in_array('cost_currency', $columns, true)) {
            $amountSql = 'COALESCE(estimated_cost, estimated_cost_usd)';
            $currencySql = "COALESCE(cost_currency, CASE WHEN estimated_cost_usd IS NOT NULL THEN 'USD' END)";
        } else {
            $amountSql = 'estimated_cost_usd';
            $currencySql = "CASE WHEN estimated_cost_usd IS NOT NULL THEN 'USD' END";
        }
        $used = 'input_tokens + output_tokens > 0';

        $sum = DB::table('ai_usage_events')
            ->where('related_type', self::RELATED_TYPE)->where('related_id', $runId)->where('sub_institute_id', $tenantId)
            ->selectRaw('COALESCE(SUM(input_tokens), 0) AS input_tokens')
            ->selectRaw('COALESCE(SUM(output_tokens), 0) AS output_tokens')
            ->selectRaw("COALESCE(SUM({$amountSql}), 0) AS cost")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$used} AND (({$amountSql}) IS NULL OR ({$currencySql}) IS NULL) THEN 1 ELSE 0 END), 0) AS unpriced")
            ->selectRaw("COUNT(DISTINCT CASE WHEN {$used} THEN {$currencySql} END) AS currencies")
            ->selectRaw("MIN(CASE WHEN {$used} THEN {$currencySql} END) AS currency")
            ->first();

        $out = ['input_tokens' => (int) $sum->input_tokens, 'output_tokens' => (int) $sum->output_tokens, 'cost' => null, 'currency' => null];
        if ((int) $sum->unpriced > 0 || (int) $sum->currencies > 1) {
            return $out;
        }
        if ((int) $sum->currencies === 0) {
            return ['cost' => 0.0, 'currency' => (string) config('ai.default_currency', 'INR')] + $out;
        }

        return ['cost' => round((float) $sum->cost, 4), 'currency' => (string) $sum->currency] + $out;
    }
}
