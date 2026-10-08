<?php

namespace App\Domain\Signals\Market;

/**
 * Turns an uploaded/command-line file into the list of records the importer takes.
 *
 * JSON: either a top-level array of records, or an object with a "records" array (and an
 * optional "source_label"). CSV: one record per row, header names as in
 * docs/signals-market-import-schema.md, with the nested parts flattened (buyer_name,
 * buyer_type, ..., score_buying_signal, ..., score_reasoning). Multi-value cells use "|".
 *
 * XLSX is not read in Phase 1 (no spreadsheet library is installed): save the sheet as CSV.
 */
final class MarketFileReader
{
    /** @return array{records: list<mixed>, source_label: ?string} */
    public function read(string $contents, string $extension): array
    {
        return match (strtolower($extension)) {
            'json' => $this->json($contents),
            'csv' => ['records' => $this->csv($contents), 'source_label' => null],
            'xlsx', 'xls' => throw new \InvalidArgumentException('Excel files are not read yet. Save the sheet as CSV (or send JSON) and import that.'),
            default => throw new \InvalidArgumentException('Unsupported file type. Use .json or .csv.'),
        };
    }

    /** @return array{records: list<mixed>, source_label: ?string} */
    private function json(string $contents): array
    {
        $data = json_decode(ltrim($contents, "\xEF\xBB\xBF"), true);

        if (! is_array($data)) {
            throw new \InvalidArgumentException('The file is not valid JSON.');
        }

        if (array_is_list($data)) {
            return ['records' => $data, 'source_label' => null];
        }

        if (! isset($data['records']) || ! is_array($data['records'])) {
            throw new \InvalidArgumentException('JSON must be an array of records, or an object with a "records" array.');
        }

        return ['records' => array_values($data['records']), 'source_label' => is_string($data['source_label'] ?? null) ? $data['source_label'] : null];
    }

    /** @return list<array<string, mixed>> */
    private function csv(string $contents): array
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, ltrim($contents, "\xEF\xBB\xBF"));
        rewind($handle);

        $header = fgetcsv($handle);
        if (! is_array($header) || $header === [null]) {
            throw new \InvalidArgumentException('The CSV file is empty.');
        }
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $header);

        $records = [];
        while (($row = fgetcsv($handle)) !== false) {
            if ($row === [null] || count(array_filter($row, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue;
            }
            $flat = [];
            foreach ($header as $i => $name) {
                $flat[$name] = isset($row[$i]) && trim($row[$i]) !== '' ? trim($row[$i]) : null;
            }
            $records[] = $this->nest($flat);
        }
        fclose($handle);

        return $records;
    }

    /**
     * @param  array<string, ?string>  $flat
     * @return array<string, mixed>
     */
    private function nest(array $flat): array
    {
        $record = [];
        $buyer = [];
        $scores = [];

        foreach ($flat as $key => $value) {
            if (str_starts_with($key, 'buyer_') && $key !== 'buyer_segment') {
                $buyer[substr($key, 6)] = $value;
            } elseif (str_starts_with($key, 'score_')) {
                $scores[substr($key, 6)] = $value;
            } else {
                $record[$key] = $value;
            }
        }

        if (isset($buyer['aliases'])) {
            $buyer['aliases'] = array_values(array_filter(array_map('trim', explode('|', (string) $buyer['aliases']))));
        }
        $record['buyer'] = $buyer;
        if (array_filter($scores, fn ($v) => $v !== null) !== []) {
            $record['scores'] = $scores;
        }

        return $record;
    }
}
