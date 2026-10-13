<?php

namespace App\Services\Documents\Extraction;

use Throwable;
use ZipArchive;

/**
 * A .docx is a ZIP of XML. This reads `word/document.xml` and strips tags,
 * rather than requiring `phpoffice/phpword` - no new composer dependency for
 * the one file this app needs out of a whole document-authoring library.
 * Same approach the reference implementation (next_lms_erp's IDMS) used.
 */
class DocxExtractor implements DocumentExtractorInterface
{
    public function handles(string $extension): bool
    {
        return strtolower($extension) === 'docx';
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
            $xml = $zip->getFromName('word/document.xml');

            if ($xml === false) {
                return '';
            }

            // Paragraph/line breaks become a space before tags are stripped,
            // so "firstword" and "secondword" in adjacent runs don't fuse
            // into "firstwordsecondword".
            $xml = preg_replace('/<\/w:p>|<w:br\s*\/?>/', ' ', $xml) ?? $xml;
            $text = strip_tags($xml);

            return trim(preg_replace('/\s+/', ' ', html_entity_decode($text)) ?? '');
        } catch (Throwable $e) {
            return '';
        } finally {
            $zip->close();
        }
    }
}
