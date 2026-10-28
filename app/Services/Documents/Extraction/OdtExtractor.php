<?php

namespace App\Services\Documents\Extraction;

use Throwable;
use ZipArchive;

/**
 * An .odt is a ZIP of XML, same shape as .docx - this reads the single
 * `content.xml` (OpenDocument keeps the whole body in one file, unlike
 * .pptx's one-file-per-slide) and strips tags. Same "no new dependency"
 * reasoning as every other extractor in this directory.
 */
class OdtExtractor implements DocumentExtractorInterface
{
    public function handles(string $extension): bool
    {
        return strtolower($extension) === 'odt';
    }

    public function extract(string $absolutePath): string
    {
        if (!class_exists(ZipArchive::class)) {
            return '';
        }

        $zip = new ZipArchive();

        if ($zip->open($absolutePath) !== true) {
            return '';
        }

        try {
            $xml = $zip->getFromName('content.xml');

            if ($xml === false) {
                return '';
            }

            // Paragraph/line/cell boundaries become a space before tags are
            // stripped, same reasoning as DocxExtractor's own `</w:p>`
            // handling - ODT's own paragraph and line-break elements.
            $xml = preg_replace('/<\/text:p>|<text:line-break\s*\/?>|<\/table:table-cell>/', ' ', $xml) ?? $xml;
            $text = strip_tags($xml);

            return trim(preg_replace('/\s+/', ' ', html_entity_decode($text)) ?? '');
        } catch (Throwable $e) {
            return '';
        } finally {
            $zip->close();
        }
    }
}
