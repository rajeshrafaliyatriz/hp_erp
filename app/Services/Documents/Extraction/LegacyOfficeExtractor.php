<?php

namespace App\Services\Documents\Extraction;

use Throwable;

/**
 * Legacy .doc/.xls/.ppt (pre-2007 Office) store their content in the binary
 * OLE2 Compound File Binary Format - a genuinely different format from
 * their modern ZIP-of-XML successors (DocxExtractor/PptxExtractor/
 * OdtExtractor), and not something a regex/tag-strip over the raw file can
 * parse the way those do. A full, correct read means walking the CFB
 * directory structure to find the right stream per format (WordDocument /
 * Workbook / PowerPoint Document) - real, substantial new work, and
 * exactly what `phpoffice/phpword` exists for.
 *
 * Instead, this takes the same stance ExcelExtractor's own docblock states
 * explicitly: "does not need to reconstruct formulas or formatting, only
 * the words a person typed." These formats store most user-typed text as
 * either UTF-16LE (Windows' native wide-char encoding - printable byte,
 * then a 0x00 high byte, repeated) or, in older/simpler cases, plain 8-bit
 * text - both interspersed with binary structure bytes. A heuristic scan
 * for runs of either shape recovers most of the actual prose without
 * parsing any container structure at all. It won't preserve reading order
 * across the whole document and can pick up occasional noise from embedded
 * metadata strings (font names, a GUID that happens to look printable), but
 * it turns "permanently zero searchability" into "genuinely findable by
 * the words actually in the file" - no new dependency, no binary-format
 * parser to maintain, same trade-off this directory already made for
 * spreadsheets.
 */
class LegacyOfficeExtractor implements DocumentExtractorInterface
{
    /**
     * Never read more than this many bytes - these files can be large, and
     * this is a heuristic pass over raw bytes, not a structural parse that
     * needs the whole file to produce a correct result.
     */
    private const MAX_BYTES = 10 * 1024 * 1024;

    /** A run shorter than this is almost always incidental binary noise, not real prose. */
    private const MIN_RUN_LENGTH = 4;

    /** Stop collecting once the extracted text is already this long - plenty for search indexing, and bounds how long a very text-heavy legacy file can hold the pipeline. */
    private const MAX_OUTPUT_LENGTH = 200000;

    public function handles(string $extension): bool
    {
        return in_array(strtolower($extension), ['doc', 'xls', 'ppt'], true);
    }

    public function extract(string $absolutePath): string
    {
        try {
            $handle = @fopen($absolutePath, 'rb');

            if (!$handle) {
                return '';
            }

            $bytes = fread($handle, self::MAX_BYTES);
            fclose($handle);

            if ($bytes === false || $bytes === '') {
                return '';
            }

            // Order matters only for which half of the cap gets used first on
            // an extreme file - both are collected regardless.
            $combined = trim(implode(' ', array_filter([
                $this->scanUtf16LeRuns($bytes),
                $this->scanAsciiRuns($bytes),
            ])));

            return mb_strlen($combined) > self::MAX_OUTPUT_LENGTH
                ? mb_substr($combined, 0, self::MAX_OUTPUT_LENGTH)
                : $combined;
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * A run of (printable byte, 0x00) pairs IS the UTF-16LE encoding of a
     * printable-ASCII string - matching 4+ repetitions directly, then
     * stripping the interspersed nulls, reconstructs the original text
     * without needing a manual byte-by-byte walk. A real word boundary
     * (space, tab, CR/LF) inside the run - itself just another such pair,
     * since space (0x20) is in the printable range this matches - survives
     * the null-strip intact, so multi-word phrases stay one readable run
     * instead of fragmenting per word.
     */
    private function scanUtf16LeRuns(string $bytes): string
    {
        if (preg_match_all('/(?:[\x20-\x7E]\x00){' . self::MIN_RUN_LENGTH . ',}/', $bytes, $matches) === false) {
            return '';
        }

        $runs = array_map(fn (string $run) => str_replace("\x00", '', $run), $matches[0] ?? []);

        return trim(preg_replace('/\s+/', ' ', implode(' ', $runs)) ?? '');
    }

    /**
     * The 8-bit counterpart - older/simpler legacy documents (and some
     * embedded structures even in otherwise-Unicode files, e.g. older .xls
     * cell text) store plain single-byte text with no interspersed nulls,
     * which the UTF-16LE scan above cannot match at all (two consecutive
     * printable bytes read as one UTF-16LE pair never has a 0x00 high byte).
     */
    private function scanAsciiRuns(string $bytes): string
    {
        if (preg_match_all('/[\x20-\x7E]{' . self::MIN_RUN_LENGTH . ',}/', $bytes, $matches) === false) {
            return '';
        }

        return trim(preg_replace('/\s+/', ' ', implode(' ', $matches[0] ?? [])) ?? '');
    }
}
