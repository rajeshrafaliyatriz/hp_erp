<?php

namespace App\Domain\Signals\Market;

/**
 * Honest market-signal metrics. Pure: takes plain arrays, returns a plain array, touches no database. Every share is a Ratio
 * {n, of, pct, ratio} where `ratio` is "n/N" and `pct` is null when N is 0, so no percentage ever hides its denominator.
 *
 * Samples, data-quality notes and rows with no source URL are counted on their own lines and left out of every market metric below
 * the cohort section. A batch label never excludes a row.
 */
final class MarketReport
{
    /** A run counts as successful when its status is 'success'. */
    public const SUCCESS_STATUS = 'success';

    /** @return array{n: int, of: int, pct: ?float, ratio: string} */
    public static function ratio(int $n, int $of): array
    {
        return ['n' => $n, 'of' => $of, 'pct' => $of > 0 ? round($n / $of * 100, 1) : null, 'ratio' => $n . '/' . $of];
    }

    /**
     * @param  list<array<string, mixed>>  $rows  opportunity rows
     * @param  list<array<string, mixed>>  $runs  research run rows
     * @return array<string, mixed>
     */
    public static function build(array $rows, array $runs): array
    {
        $cohort = MarketCohort::summarise($rows);
        $total = $cohort['total'];
        $market = array_values(array_filter($rows, fn ($r) => MarketCohort::classify($r)['counts_in_market_metrics']));
        $m = count($market);

        $cohortOut = ['denominator' => $total, 'by_class' => []];
        foreach ($cohort['by_class'] as $class => $n) {
            $cohortOut['by_class'][$class] = self::ratio($n, $total);
        }

        $maturity = [EvidenceMaturity::E0 => 0, EvidenceMaturity::E1 => 0, EvidenceMaturity::E2 => 0, EvidenceMaturity::E3 => 0, EvidenceMaturity::NOT_ASSESSED => 0];
        $quals = [];
        $noEvent = 0;
        $noPub = 0;
        $byProduct = [];
        foreach ($market as $r) {
            $maturity[EvidenceMaturity::fromStored(self::s($r['claim_level'] ?? null), self::s($r['fetch_level'] ?? null))]++;
            $q = trim((string) ($r['qualification'] ?? '')) ?: '(none)';
            $quals[$q] = ($quals[$q] ?? 0) + 1;
            $noEvent += trim((string) ($r['event_date'] ?? '')) === '' ? 1 : 0;
            $noPub += trim((string) ($r['source_published_at'] ?? '')) === '' ? 1 : 0;
            $k = (trim((string) ($r['product_key'] ?? '')) ?: '(none)') . ' / ' . (trim((string) ($r['customer_model'] ?? '')) ?: '(none)');
            $byProduct[$k] = ($byProduct[$k] ?? 0) + 1;
        }
        $maturityOut = [];
        foreach ($maturity as $level => $n) {
            $maturityOut[$level] = self::ratio($n, $m);
        }
        ksort($quals);
        ksort($byProduct);
        $qualsOut = array_map(fn ($n) => self::ratio($n, $m), $quals);
        $productOut = array_map(fn ($n) => self::ratio($n, $m), $byProduct);

        // Duplicates among market-cohort rows only.
        $dedupRows = array_map(fn ($r) => ['id' => $r['id'] ?? null, 'company' => (string) ($r['company'] ?? ''), 'source_url' => $r['source_url'] ?? null], $market);
        $groups = SignalDeduplicator::groups($dedupRows);
        $extra = 0;
        foreach ($groups['duplicates'] as $g) {
            $extra += count($g['ids']) - 1;
        }

        return [
            'cohort' => $cohortOut,
            'market' => [
                'denominator' => $m,
                'maturity' => $maturityOut,
                'qualification' => $qualsOut,
                'missing_event_date' => self::ratio($noEvent, $m),
                'missing_source_published_at' => self::ratio($noPub, $m),
                'duplicates' => [
                    'real_duplicate_groups' => $groups['duplicates'],
                    'shared_article_groups' => $groups['shared_url_different_company'],
                    'rows_beyond_first' => self::ratio($extra, $m),
                ],
                'by_product_customer_model' => $productOut,
            ],
            'runs' => self::runHealth($runs),
        ];
    }

    /** @param list<array<string, mixed>> $runs */
    private static function runHealth(array $runs): array
    {
        $n = count($runs);
        $status = [];
        $ok = 0;
        $zero = 0;
        foreach ($runs as $r) {
            $s = trim((string) ($r['status'] ?? '')) ?: '(none)';
            $status[$s] = ($status[$s] ?? 0) + 1;
            if ($s === self::SUCCESS_STATUS) {
                $ok++;
                $zero += (int) ($r['opportunities_new'] ?? 0) === 0 ? 1 : 0;
            }
        }
        ksort($status);

        return [
            'denominator' => $n,
            'status_counts' => array_map(fn ($c) => self::ratio($c, $n), $status),
            'failed_share' => self::ratio($status['failed'] ?? 0, $n),
            'zero_signal_share_of_successful' => self::ratio($zero, $ok),
        ];
    }

    private static function s(mixed $v): ?string
    {
        return $v === null ? null : (string) $v;
    }
}
