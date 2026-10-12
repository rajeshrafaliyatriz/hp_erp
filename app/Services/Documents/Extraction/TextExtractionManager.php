<?php

namespace App\Services\Documents\Extraction;

/**
 * Picks the right extractor for a file and never lets extraction fail the
 * upload. A document whose text could not be pulled is still filed - it is
 * just not yet content-searchable, which is a worse search experience, not a
 * broken one.
 */
class TextExtractionManager
{
    /** @var DocumentExtractorInterface[] */
    private array $extractors;

    public function __construct()
    {
        $this->extractors = [
            new PdfExtractor(),
            new DocxExtractor(),
            new PptxExtractor(),
            new OdtExtractor(),
            new ExcelExtractor(),
            new PlainTextExtractor(),
            // Last: the heuristic byte-scan is the fallback of last resort
            // for the legacy binary formats none of the structured
            // extractors above handle (doc/xls/ppt) - ordering doesn't
            // change behavior here (handles() is format-exclusive across
            // every extractor in this list), just reads as "try the real
            // parsers first" for a future reader.
            new LegacyOfficeExtractor(),
        ];
    }

    /** Plain text for this file, or '' if nothing could be pulled (including: no extractor handles it). */
    public function extract(string $absolutePath, string $extension): string
    {
        foreach ($this->extractors as $extractor) {
            if ($extractor->handles($extension)) {
                return $extractor->extract($absolutePath);
            }
        }

        return '';
    }

    /** Whether this extension has text to extract at all (images do not; OCR is a separate concern). */
    public function isTextBearing(string $extension): bool
    {
        foreach ($this->extractors as $extractor) {
            if ($extractor->handles($extension)) {
                return true;
            }
        }

        return false;
    }

    /** Whether this extension is an image, so the OCR fallback is the only route to text. */
    public function isImage(string $extension): bool
    {
        return in_array(strtolower($extension), ['jpg', 'jpeg', 'png', 'webp'], true);
    }
}
