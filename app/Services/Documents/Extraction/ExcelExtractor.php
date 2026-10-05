<?php

namespace App\Services\Documents\Extraction;

use Throwable;
use ZipArchive;

/**
 * An .xlsx is a ZIP of XML. This reads the shared-string table plus each
 * sheet's inline strings and numeric cells, rather than requiring
 * `phpoffice/phpspreadsheet` - the same "no new dependency for one read"
 * reasoning as DocxExtractor. Good enough for search indexing (it does not
 * need to reconstruct formulas or formatting, only the words a person typed
 * into cells).
 */
class ExcelExtractor implements DocumentExtractorInterface
{
    /** Cap so one 50,000-row sheet does not block the pipeline. */
    private const MAX_CELLS = 20000;

    public function handles(string $extension): bool
    {
        return in_array(strtolower($extension), ['xlsx', 'csv'], true);
    }

    public function extract(string $absolutePath): string
    {
        if (strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION)) === 'csv') {
            return $this->extractCsv($absolutePath);
        }

        if (!class_exists(ZipArchive::class)) {
            return '';
        }

        $zip = new ZipArchive();

        if ($zip->open($absolutePath) !== true) {
            return '';
        }

        try {
            $shared = $this->sharedStrings($zip);
            $cells = 0;
            $parts = [];

            for ($i = 1; $i <= 30 && $cells < self::MAX_CELLS; $i++) {
                $sheetXml = $zip->getFromName("xl/worksheets/sheet{$i}.xml");

                if ($sheetXml === false) {
                    if ($i === 1) {
                        continue; // some workbooks start numbering differently
                    }
                    break;
                }

                [$sheetText, $sheetCells] = $this->readSheet($sheetXml, $shared, self::MAX_CELLS - $cells);
                $parts[] = $sheetText;
                $cells += $sheetCells;
            }

            return trim(preg_replace('/\s+/', ' ', implode(' ', $parts)) ?? '');
        } catch (Throwable $e) {
            return '';
        } finally {
            $zip->close();
        }
    }

    /** @return array<int, string> */
    private function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');

        if ($xml === false) {
            return [];
        }

        try {
            $doc = new \SimpleXMLElement($xml);
            $doc->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $values = [];

            foreach ($doc->xpath('//s:si') as $si) {
                $values[] = trim((string) $si);
            }

            return $values;
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * @param  array<int, string>  $shared
     * @return array{0: string, 1: int}
     */
    private function readSheet(string $xml, array $shared, int $budget): array
    {
        try {
            $doc = new \SimpleXMLElement($xml);
            $doc->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $values = [];
            $count = 0;

            foreach ($doc->xpath('//s:c') as $cell) {
                if ($count >= $budget) {
                    break;
                }

                $type = (string) ($cell['t'] ?? '');
                $raw = isset($cell->v) ? (string) $cell->v : (isset($cell->is) ? (string) $cell->is : '');

                if ($raw === '') {
                    continue;
                }

                $values[] = $type === 's' && isset($shared[(int) $raw]) ? $shared[(int) $raw] : $raw;
                $count++;
            }

            return [implode(' ', $values), $count];
        } catch (Throwable $e) {
            return ['', 0];
        }
    }

    private function extractCsv(string $absolutePath): string
    {
        $handle = @fopen($absolutePath, 'r');

        if (!$handle) {
            return '';
        }

        $rows = [];
        $count = 0;

        while (($row = fgetcsv($handle)) !== false && $count < 5000) {
            $rows[] = implode(' ', $row);
            $count++;
        }

        fclose($handle);

        return trim(implode(' ', $rows));
    }
}
