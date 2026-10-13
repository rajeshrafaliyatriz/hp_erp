<?php

namespace App\Services\Documents\Extraction;

/**
 * .txt (and .rtf, stripped of its control words) - the one format that needs
 * no parsing at all. Found necessary because this environment's historic
 * offer-letter objects are plain-text stand-ins rather than real PDFs; a
 * production deployment writing real .txt attachments gets the same benefit.
 */
class PlainTextExtractor implements DocumentExtractorInterface
{
    public function handles(string $extension): bool
    {
        return in_array(strtolower($extension), ['txt', 'rtf'], true);
    }

    public function extract(string $absolutePath): string
    {
        $contents = @file_get_contents($absolutePath, false, null, 0, 2_000_000);

        if ($contents === false) {
            return '';
        }

        if (strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION)) === 'rtf') {
            // Strip RTF control words/groups rather than parsing them - good
            // enough for search indexing, not a rendering.
            $contents = preg_replace('/\\\\[a-z]+\d* ?|[{}]/i', ' ', $contents) ?? $contents;
        }

        return trim(preg_replace('/\s+/', ' ', $contents) ?? '');
    }
}
