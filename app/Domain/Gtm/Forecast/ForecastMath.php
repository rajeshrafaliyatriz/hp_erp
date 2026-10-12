<?php

namespace App\Domain\Gtm\Forecast;

use Illuminate\Support\Carbon;

/**
 * The arithmetic of a forecast, with no database in it.
 *
 * CURRENCY-SAFE. Money is summed in integer minor units (amount x 100) and only within one currency;
 * nothing here can add USD to INR. A deal without an amount, a currency or a close date cannot be
 * forecast and is COUNTED as excluded, never given a placeholder value.
 *
 * NO INVENTED PROBABILITIES. `weighted` uses the probability an administrator configured for the
 * deal's stage; a stage with none is left out of the weighted figure (and counted), and with no
 * probabilities at all `weighted` is null, not zero.
 *
 * CATEGORIES. A deal's category is its own override, else its stage's configured category, else
 * `pipeline`. Views are cumulative the usual way: pipeline = everything not omitted, best_case =
 * best_case + commit, commit = commit only.
 */
final class ForecastMath
{
    public const CATEGORIES = ['pipeline', 'best_case', 'commit', 'omitted'];

    /**
     * @param  list<array{id: int, stage: string, amount: ?string, currency: ?string, expected_close_date: ?string, forecast_category: ?string}>  $deals  open deals
     * @param  array<string, array{probability: ?float, category: string}>  $settings  keyed by stage
     * @return array<string, mixed>
     */
    public static function summarise(array $deals, array $settings, Carbon $from, Carbon $to): array
    {
        $periods = [];
        $excluded = ['no_close_date' => 0, 'no_amount' => 0, 'omitted' => 0, 'outside_window' => 0, 'no_probability' => 0];
        $overdue = ['count' => 0, 'value_by_currency' => []];
        $categoriesInUse = false;
        $anyProbability = false;

        foreach ($settings as $s) {
            if ($s['probability'] !== null) {
                $anyProbability = true;
            }
        }

        foreach ($deals as $d) {
            $category = self::category($d, $settings);
            if ($category === 'omitted') {
                $excluded['omitted']++;

                continue;
            }
            if (empty($d['expected_close_date'])) {
                $excluded['no_close_date']++;

                continue;
            }
            if ($d['amount'] === null || $d['amount'] === '' || empty($d['currency'])) {
                $excluded['no_amount']++;

                continue;
            }
            $cents = (int) round(((float) $d['amount']) * 100);
            $close = Carbon::parse($d['expected_close_date'])->startOfDay();

            if ($close->lt($from)) {
                $overdue['count']++;
                $overdue['value_by_currency'][$d['currency']] = ($overdue['value_by_currency'][$d['currency']] ?? 0) + $cents;

                continue;
            }
            if ($close->gt($to)) {
                $excluded['outside_window']++;

                continue;
            }

            if (($d['forecast_category'] ?? null) !== null || (($settings[$d['stage']]['category'] ?? 'pipeline') !== 'pipeline')) {
                $categoriesInUse = true;
            }

            $key = $close->format('Y-m').'|'.$d['currency'];
            $periods[$key] ??= ['period' => $close->format('Y-m'), 'currency' => $d['currency'], 'deal_count' => 0, 'pipeline' => 0, 'best_case' => 0, 'commit' => 0, 'weighted' => $anyProbability ? 0 : null];
            $p = &$periods[$key];
            $p['deal_count']++;
            $p['pipeline'] += $cents;
            if (in_array($category, ['best_case', 'commit'], true)) {
                $p['best_case'] += $cents;
            }
            if ($category === 'commit') {
                $p['commit'] += $cents;
            }
            $prob = $settings[$d['stage']]['probability'] ?? null;
            if ($prob === null) {
                if ($anyProbability) {
                    $excluded['no_probability']++;
                }
            } else {
                $p['weighted'] += (int) round($cents * $prob / 100);
            }
            unset($p);
        }

        ksort($periods);
        $rows = array_values(array_map(fn ($p) => self::money($p), $periods));

        $totals = [];
        foreach ($periods as $p) {
            $t = &$totals[$p['currency']];
            $t ??= ['currency' => $p['currency'], 'deal_count' => 0, 'pipeline' => 0, 'best_case' => 0, 'commit' => 0, 'weighted' => $anyProbability ? 0 : null];
            $t['deal_count'] += $p['deal_count'];
            foreach (['pipeline', 'best_case', 'commit'] as $k) {
                $t[$k] += $p[$k];
            }
            if ($anyProbability) {
                $t['weighted'] += $p['weighted'];
            }
            unset($t);
        }

        return [
            'periods' => $rows,
            'totals_by_currency' => array_values(array_map(fn ($t) => self::money($t), $totals)),
            'excluded' => $excluded,
            'overdue' => ['count' => $overdue['count'], 'value_by_currency' => array_map(fn ($c) => $c / 100, $overdue['value_by_currency'])],
            'weighted_available' => $anyProbability,
            'categories_in_use' => $categoriesInUse,
        ];
    }

    /** @param array{stage: string, forecast_category: ?string} $deal @param array<string, array{category: string}> $settings */
    public static function category(array $deal, array $settings): string
    {
        $own = $deal['forecast_category'] ?? null;
        if ($own !== null && in_array($own, self::CATEGORIES, true)) {
            return $own;
        }

        return $settings[$deal['stage']]['category'] ?? 'pipeline';
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function money(array $row): array
    {
        foreach (['pipeline', 'best_case', 'commit', 'weighted'] as $k) {
            if (isset($row[$k])) {
                $row[$k] = $row[$k] / 100;
            }
        }

        return $row;
    }
}
