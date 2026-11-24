<?php

namespace App\Domain\Signals\Market;

/**
 * Which records count in market-signal metrics. A REPORTING classification only: it reads existing fields and writes nothing, so no schema
 * change and no data movement is needed, and `import_source` keeps its original value for provenance.
 *
 *   sample             is_sample = 1                         excluded from every metric
 *   data_quality_note  signal_kind = 'public_reporting'      excluded from market-signal metrics (a finding about an ingested file, not a market event)
 *   no_source_url      a market record with no source link   reported on its own line, never mixed into URL-based rates
 *   market_signal      everything else                       counted
 *
 * `origin` and `batch_label` describe where a market signal came from without changing what it is: a batch label such as
 * `demo_fixture_...` is kept as provenance and does not exclude a record. A record is only a fixture if `is_sample` says so.
 */
final class MarketCohort
{
    public const SAMPLE = 'sample';

    public const NOTE = 'data_quality_note';

    public const NO_URL = 'no_source_url';

    public const MARKET = 'market_signal';

    /**
     * @param  array<string, mixed>  $row  needs: is_sample, signal_kind, source_url, import_source (any may be null)
     * @return array{class: string, counts_in_market_metrics: bool, origin: string, batch_label: ?string}
     */
    public static function classify(array $row): array
    {
        $source = trim((string) ($row['import_source'] ?? ''));
        $origin = match (true) {
            $source === 'web-research' => 'web_research',
            $source === 'manual-entry' => 'manual',
            $source === '' => 'unlabelled',
            str_starts_with($source, 'ingestion:') => 'ingestion',
            str_starts_with($source, 'demo_fixture') => 'labelled_batch',
            default => 'imported',
        };
        $batch = $source !== '' && ! in_array($source, ['web-research', 'manual-entry'], true) ? $source : null;

        $class = match (true) {
            ! empty($row['is_sample']) => self::SAMPLE,
            (string) ($row['signal_kind'] ?? '') === 'public_reporting' => self::NOTE,
            trim((string) ($row['source_url'] ?? '')) === '' => self::NO_URL,
            default => self::MARKET,
        };

        return ['class' => $class, 'counts_in_market_metrics' => $class === self::MARKET, 'origin' => $origin, 'batch_label' => $batch];
    }

    /**
     * Counts per class, with the denominator stated.
     *
     * @param  iterable<array<string, mixed>>  $rows
     * @return array{total: int, by_class: array<string, int>, market_signals: int}
     */
    public static function summarise(iterable $rows): array
    {
        $by = [self::SAMPLE => 0, self::NOTE => 0, self::NO_URL => 0, self::MARKET => 0];
        $n = 0;
        foreach ($rows as $r) {
            $by[self::classify($r)['class']]++;
            $n++;
        }

        return ['total' => $n, 'by_class' => $by, 'market_signals' => $by[self::MARKET]];
    }
}
