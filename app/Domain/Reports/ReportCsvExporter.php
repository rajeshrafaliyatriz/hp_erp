<?php

namespace App\Domain\Reports;

/**
 * Turns one report result + its own definition into a CSV string — the ONE
 * place this happens, shared by `ReportController::export()` (an interactive
 * download) and the scheduled/emailed report job (Phase 4), which both need
 * byte-identical output rather than two copies that can quietly drift.
 */
final class ReportCsvExporter
{
    public static function toCsvString(string $reportType, array $result, array $definition): string
    {
        [$header, $rows] = self::flatten($reportType, $result, $definition);

        $csv = implode(',', array_map(self::cell(...), $header)) . "\r\n";

        foreach ($rows as $row) {
            $csv .= implode(',', array_map(self::cell(...), $row)) . "\r\n";
        }

        return $csv;
    }

    /** @return array{0: array<int, string>, 1: array<int, array<int, mixed>>} */
    public static function flatten(string $reportType, array $result, array $definition = []): array
    {
        // "task_management.tasks.task_title" means nothing to whoever opens
        // this in Excel - the report's own column labels ("Task", "Status")
        // are what the builder already showed them, so the export should
        // read the same way.
        $labelFor = function (string $qualifiedKey) use ($definition): string {
            foreach ($definition['columns'] ?? [] as $col) {
                if ($qualifiedKey === "{$col['module']}.{$col['field']}") {
                    return $col['label'] ?? $qualifiedKey;
                }
            }

            return $qualifiedKey;
        };

        return match ($reportType) {
            // __primary_id is internal plumbing (reserved for a future
            // drill-down/open-record action), never something an exported
            // CSV should show a viewer - confirmed live: it was leaking as
            // a literal, confusing first column before this fix.
            'tabular' => [
                $result['rows'] === []
                    ? []
                    : array_map($labelFor, array_values(array_diff(array_keys($result['rows'][0]), ['__primary_id']))),
                array_map(fn ($row) => array_values(array_diff_key($row, ['__primary_id' => true])), $result['rows']),
            ],
            'summary' => [
                $result['groups'] === [] ? ['label', 'count'] : array_keys($result['groups'][0]),
                array_map('array_values', $result['groups']),
            ],
            'pivot' => [
                array_merge(['Row'], $result['column_labels'], ['Total']),
                array_map(
                    fn ($rowLabel, $i) => array_merge([$rowLabel], $result['matrix'][$i], [$result['row_totals'][$i]]),
                    $result['row_labels'],
                    array_keys($result['row_labels']),
                ),
            ],
            'chart' => [
                array_merge(['Label'], array_map(fn ($s) => $s['name'], $result['series'])),
                array_map(
                    fn ($label, $i) => array_merge([$label], array_map(fn ($s) => $s['data'][$i] ?? '', $result['series'])),
                    $result['labels'],
                    array_keys($result['labels']),
                ),
            ],
            default => [[], []],
        };
    }

    private static function cell(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n")
            ? '"' . str_replace('"', '""', $value) . '"'
            : $value;
    }
}
