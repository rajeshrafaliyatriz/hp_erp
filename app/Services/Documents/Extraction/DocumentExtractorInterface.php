<?php

namespace App\Services\Documents\Extraction;

interface DocumentExtractorInterface
{
    /** Which file extensions (lowercase, no dot) this extractor handles. */
    public function handles(string $extension): bool;

    /**
     * Plain text pulled from the file, or '' if it genuinely has none (e.g. a
     * scanned image with no text layer - the manager's OCR fallback picks
     * that up, this method does not throw for it).
     */
    public function extract(string $absolutePath): string;
}
