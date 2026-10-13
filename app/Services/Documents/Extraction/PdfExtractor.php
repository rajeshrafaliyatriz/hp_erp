<?php

namespace App\Services\Documents\Extraction;

use Smalot\PdfParser\Parser;
use Throwable;

/** Text layer of a PDF, via smalot/pdfparser (already a dependency of this app). */
class PdfExtractor implements DocumentExtractorInterface
{
    public function handles(string $extension): bool
    {
        return strtolower($extension) === 'pdf';
    }

    public function extract(string $absolutePath): string
    {
        try {
            $parser = new Parser();
            $text = $parser->parseFile($absolutePath)->getText();

            return trim((string) $text);
        } catch (Throwable $e) {
            // A password-protected or malformed PDF is not a processing
            // failure for the whole document - it just has no extractable
            // text yet, same as a scan the OCR fallback will pick up.
            return '';
        }
    }
}
