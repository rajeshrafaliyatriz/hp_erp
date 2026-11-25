<?php

namespace App\Domain\Intelligence\Detectors\Platform;

use App\Domain\Intelligence\Access\IntelligenceCaller;
use App\Domain\Intelligence\Coverage;
use App\Domain\Intelligence\DetectorResult;
use App\Domain\Intelligence\Evidence;
use App\Domain\Intelligence\Insight;
use App\Domain\Intelligence\Support\DetectionClock;
use App\Domain\Intelligence\Taxonomy;

/**
 * AI usage health: failure share, calls refused by a quota, quotas near or over their limit, and cost concentration in one AI module. Needs `view` on
 * /ai/usage-cost (the row that guards the Usage & Cost screen).
 *
 * SOURCE (verified: the 2026_09_21 usage migration, the two 2026_12_08 cost migrations, AiUsageMeter, UsageController):
 *   ai_usage_events  ai_module, outcome (success | failed | refused), input_tokens, output_tokens, estimated_cost + cost_currency (the price in the rate's OWN
 *                    currency), estimated_cost_usd (written only when that currency is USD), created_at, sub_institute_id.
 *   ai_usage_quotas  ai_module (NULL = the whole organisation), period (day | month), token_limit, warn_at_percent, status, sub_institute_id.
 * Quota consumption is computed exactly as the product computes it (refused calls excluded; tokens = input + output; the period starts at the start of the day
 * or the month), so this detector and the Usage screen cannot disagree about the same quota.
 *
 * MONEY. Cost is reported per currency exactly as stored and is NEVER converted or added across currencies. A call with no recorded price is "not priced",
 * never zero cost. A column that is absent on this installation (the cost migrations arrive host by host) simply removes that metric. The error text, the
 * model name, the related record and the user of a call are never selected.
 */
final class AiUsageDetector extends PlatformDetector
{
    private const EVENTS = 'ai_usage_events';
    private const QUOTAS = 'ai_usage_quotas';
    private const LINK = '/ai/usage-cost';
    private const MAX_NAMED = 5;
    private const MAX_GROUPS = 1000;

    public function id(): string
    {
        return 'platform.ai_usage';
    }

    public function title(): string
    {
        return 'AI usage and cost';
    }

    public function surfaces(): array
    {
        return [Taxonomy::SURFACE_AI_USAGE_COST];
    }

    public function authorizes(IntelligenceCaller $caller): bool
    {
        return $caller->canAny([PlatformAccess::LINK_AI_USAGE_COST]);
    }

    protected function assess(IntelligenceCaller $caller, DetectionClock $clock): DetectorResult
    {
        $asOf = $clock->asOf();
        if (! $this->canRead(self::EVENTS, ['id', 'sub_institute_id', 'ai_module', 'outcome', 'input_tokens', 'output_tokens', 'created_at'])) {
            return DetectorResult::dataUnavailable($this, $asOf, 'AI usage records are not available on this installation.');
        }

        $tenant = $caller->tenantId;
        $days = $this->thresholds->int('ai_usage_window_days');
        $since = $clock->dateTimeDaysAgo($days);
        $events = fn () => $this->db->table(self::EVENTS)->where('sub_institute_id', $tenant)->where('created_at', '>=', $since);

        $totals = $events()->selectRaw(
            "COUNT(*) as calls, SUM(CASE WHEN outcome = 'failed' THEN 1 ELSE 0 END) as failed, SUM(CASE WHEN outcome = 'refused' THEN 1 ELSE 0 END) as refused, "
            . "SUM(CASE WHEN outcome != 'refused' THEN 1 ELSE 0 END) as reached"
        )->first();
        $reached = (int) ($totals->reached ?? 0);
        $failed = (int) ($totals->failed ?? 0);
        $refused = (int) ($totals->refused ?? 0);

        $callCoverage = Coverage::of($reached, null, "AI calls that reached a provider in the last {$days} days", $this->thresholds->int('ai_usage_min_events'));
        $insights = [];
        $today = $clock->today();

        // 1. Failure share (needs volume).
        if ($callCoverage->sufficient && $failed > 0) {
            $share = self::pct($failed, $reached);
            if ($share >= $this->thresholds->float('ai_usage_failure_pct')) {
                $byModule = $events()->where('outcome', 'failed')->selectRaw('ai_module as module, COUNT(*) as n')->groupBy('ai_module')->orderByDesc('n')->orderBy('module')->limit(self::MAX_NAMED)->get();
                $evidence = [
                    Evidence::metric('AI calls that reached a provider', $reached, 'calls', self::EVENTS),
                    Evidence::metric('Failed calls', $failed, 'calls'),
                    Evidence::metric('Share of calls that failed', $share, '%'),
                ];
                foreach ($byModule as $row) {
                    $evidence[] = Evidence::metric('Failed in module ' . self::safeKey($row->module, '(unnamed)'), (int) $row->n, 'calls');
                }
                $insights[] = new Insight(
                    $this->key($caller, 'failures', $today), $this->id(), $this->domain(), 'ai_usage', $share >= 2 * $this->thresholds->float('ai_usage_failure_pct') ? 'high' : 'attention',
                    sprintf('%s%% of AI calls failed', $share),
                    sprintf('%d of %d AI calls in the last %d days failed (refused calls are not counted).', $failed, $reached, $days),
                    'Failed calls are answers people did not get; a high share usually means a provider key, model or quota problem.',
                    $evidence, $callCoverage, $asOf, 'Open Usage & Cost, find the failing module and check its provider and model settings.', self::LINK, 'rule_based', $this->audience(), $this->surfaces(), null,
                );
            }
        }

        // 2. Calls refused by a quota (a limit is already stopping work; needs no volume).
        if ($refused > 0) {
            $insights[] = new Insight(
                $this->key($caller, 'refused', $today), $this->id(), $this->domain(), 'ai_usage', 'high',
                sprintf('%d AI call(s) were refused by a quota', $refused),
                sprintf('%d AI call(s) in the last %d days never reached a provider because a usage quota was exhausted.', $refused, $days),
                'A refused call is a request that was turned away: the feature behind it stopped working for that person until the quota resets or is raised.',
                [Evidence::metric('Calls refused by a quota', $refused, 'calls', self::EVENTS), Evidence::metric('Window', $days, 'days')],
                Coverage::of($refused, null, "AI calls refused by a quota in the last {$days} days", 1), $asOf,
                'Review the quotas on Usage & Cost.', self::LINK, 'measured', $this->audience(), $this->surfaces(), null,
            );
        }

        // 3. Quota proximity.
        $quotaChecked = 0;
        if ($this->canRead(self::QUOTAS, ['id', 'sub_institute_id', 'ai_module', 'period', 'token_limit', 'warn_at_percent', 'status'])) {
            $quotas = $this->db->table(self::QUOTAS)->where('sub_institute_id', $tenant)->where('status', 1)->orderBy('id')->limit(100)
                ->get(['ai_module', 'period', 'token_limit', 'warn_at_percent']);
            $quotaChecked = $quotas->count();
            if ($quotaChecked > 0) {
                $instant = $clock->instant();
                $starts = ['day' => $instant->startOfDay()->format('Y-m-d H:i:s'), 'month' => $instant->startOfMonth()->format('Y-m-d H:i:s')];
                $usedBy = [];
                foreach (array_unique($quotas->pluck('period')->all()) as $period) {
                    if (isset($starts[$period])) {
                        $usedBy[$period] = $this->db->table(self::EVENTS)->where('sub_institute_id', $tenant)->where('outcome', '!=', 'refused')->where('created_at', '>=', $starts[$period])
                            ->selectRaw('ai_module as module, SUM(input_tokens + output_tokens) as used')->groupBy('ai_module')->limit(self::MAX_GROUPS)->pluck('used', 'module')->all();
                    }
                }
                $near = [];
                $over = 0;
                foreach ($quotas as $q) {
                    $limit = (int) $q->token_limit;
                    if ($limit <= 0 || ! isset($usedBy[$q->period])) {
                        continue;
                    }
                    $used = $q->ai_module === null ? array_sum(array_map('intval', $usedBy[$q->period])) : (int) ($usedBy[$q->period][$q->ai_module] ?? 0);
                    $percent = (int) round($used / $limit * 100);
                    if ($percent >= 100) {
                        $over++;
                    }
                    if ($percent >= (int) $q->warn_at_percent) {
                        $near[] = [$q->ai_module === null ? 'whole organisation' : self::safeKey($q->ai_module, '(unnamed)'), (string) $q->period, $percent];
                    }
                }
                if ($near !== []) {
                    $evidence = [Evidence::metric('Active quotas', $quotaChecked, 'quotas', self::QUOTAS), Evidence::metric('At or past their warning level', count($near), 'quotas'), Evidence::metric('At or past 100%', $over, 'quotas')];
                    foreach (array_slice($near, 0, self::MAX_NAMED) as [$scope, $period, $percent]) {
                        $evidence[] = Evidence::metric("Quota {$scope} per {$period}", $percent, '% used');
                    }
                    $insights[] = new Insight(
                        $this->key($caller, 'quota', $today), $this->id(), $this->domain(), 'ai_usage', $over > 0 ? 'high' : 'attention',
                        sprintf('%d AI quota(s) are near or over their limit', count($near)),
                        sprintf('%d of %d active quota(s) have used at least their warning share of the limit this period; %d are at or over 100%%.', count($near), $quotaChecked, $over),
                        'A quota that is used up refuses further AI calls until the period resets or the limit is raised.',
                        $evidence, Coverage::of($quotaChecked, null, 'active AI usage quotas for this organisation', 1), $asOf,
                        'Raise the limit or reduce use before the quota refuses calls.', self::LINK, 'measured', $this->audience(), $this->surfaces(), null,
                    );
                }
            }
        }

        // 4. Cost concentration, per currency as stored.
        $concentration = $this->concentration($events, $this->thresholds->int('ai_usage_min_priced'), $this->thresholds->float('ai_usage_cost_concentration_pct'));
        if ($concentration !== []) {
            $evidence = [];
            foreach ($concentration as $c) {
                $evidence[] = Evidence::metric("Cost in {$c['cur']} (as stored, last {$days} days)", round($c['total'], 2), $c['cur'] === '(unknown currency)' ? null : $c['cur'], self::EVENTS);
                $evidence[] = Evidence::metric("Largest module in {$c['cur']}: " . self::safeKey($c['module'], '(unnamed)'), round($c['share'], 1), '% of cost');
                $evidence[] = Evidence::metric("Priced calls in {$c['cur']}", $c['n'], 'calls');
            }
            $insights[] = new Insight(
                $this->key($caller, 'cost_concentration', $today), $this->id(), $this->domain(), 'ai_usage', 'info',
                'AI cost is concentrated in one module',
                sprintf('In %d currency(ies), one AI module accounts for at least %d%% of the recorded cost over the last %d days. Currencies are never added together.', count($concentration), (int) $this->thresholds->float('ai_usage_cost_concentration_pct'), $days),
                'Spend that sits in one module is where a change of model, prompt or limit has the most effect. Calls with no recorded price are not part of these figures.',
                $evidence, Coverage::of(array_sum(array_column($concentration, 'n')), null, "priced AI calls in the last {$days} days", 1), $asOf,
                'Review the largest module\'s model and usage on Usage & Cost.', self::LINK, 'rule_based', $this->audience(), $this->surfaces(), null,
            );
        }

        if (! $callCoverage->sufficient && $insights === []) {
            return $quotaChecked > 0
                ? DetectorResult::ok($this, [], Coverage::of($quotaChecked, null, 'active AI usage quotas for this organisation', 1), $asOf)
                : DetectorResult::insufficient($this, $callCoverage, $asOf);
        }

        return DetectorResult::ok($this, $insights, $callCoverage->sufficient ? $callCoverage : $insights[0]->coverage, $asOf);
    }

    /**
     * Currencies where one module takes at least `$pct` of the cost, with enough priced calls and at least two modules with cost.
     *
     * @return list<array{cur: string, module: string, share: float, total: float, n: int}>
     */
    private function concentration(\Closure $events, int $minPriced, float $pct): array
    {
        if ($this->canRead(self::EVENTS, ['estimated_cost', 'cost_currency'])) {
            $rows = $events()->where('outcome', '!=', 'refused')->whereNotNull('estimated_cost')->whereNotNull('cost_currency')
                ->selectRaw('cost_currency as cur, ai_module as module, COUNT(*) as n, SUM(estimated_cost) as cost')->groupBy('cost_currency', 'ai_module')->limit(self::MAX_GROUPS)->get();
        } elseif ($this->canRead(self::EVENTS, ['estimated_cost_usd'])) {
            $rows = $events()->where('outcome', '!=', 'refused')->whereNotNull('estimated_cost_usd')
                ->selectRaw("'USD' as cur, ai_module as module, COUNT(*) as n, SUM(estimated_cost_usd) as cost")->groupBy('ai_module')->limit(self::MAX_GROUPS)->get();
        } else {
            return [];
        }

        $byCurrency = [];
        foreach ($rows as $row) {
            $cur = strtoupper(trim((string) $row->cur));
            $cur = preg_match('/^[A-Z]{3}$/', $cur) === 1 ? $cur : '(unknown currency)';
            $byCurrency[$cur][] = ['module' => (string) $row->module, 'n' => (int) $row->n, 'cost' => (float) $row->cost];
        }
        ksort($byCurrency);

        $out = [];
        foreach ($byCurrency as $cur => $modules) {
            $n = array_sum(array_column($modules, 'n'));
            $total = array_sum(array_column($modules, 'cost'));
            if ($n < $minPriced || count($modules) < 2 || $total <= 0) {
                continue;
            }
            usort($modules, static fn ($a, $b) => [$b['cost'], $a['module']] <=> [$a['cost'], $b['module']]);
            $share = $modules[0]['cost'] / $total * 100;
            if ($share >= $pct) {
                $out[] = ['cur' => $cur, 'module' => $modules[0]['module'], 'share' => $share, 'total' => $total, 'n' => $n];
            }
        }

        return $out;
    }
}
