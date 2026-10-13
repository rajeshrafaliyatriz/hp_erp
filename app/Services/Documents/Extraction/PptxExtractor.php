<?php

namespace App\Services\Documents\Extraction;

use Throwable;
use ZipArchive;

/**
 * A .pptx is a ZIP of XML, same shape as .docx/.xlsx - this reads every
 * `ppt/slides/slideN.xml` in order and strips tags, rather than requiring
 * `phpoffice/phpword` - same "no new dependency for the one thing this app
 * needs out of a whole document-authoring library" reasoning as
 * DocxExtractor/ExcelExtractor.
 *
 * Each slide's own text runs live in `<a:t>` elements; stripping ALL tags
 * (not just unwrapping `<a:t>`) is deliberate and matches DocxExtractor's
 * own approach - simpler, and anything that isn't an `<a:t>` run in a
 * slide's XML is layout/formatting markup, not prose a search should match.
 */
class PptxExtractor implements DocumentExtractorInterface
{
    /** Cap so a 200-slide deck does not block the pipeline. */
    private const MAX_SLIDES = 200;

    public function handles(string $extension): bool
    {
        return strtolower($extension) === 'pptx';
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
            $parts = [];

            for ($i = 1; $i <= self::MAX_SLIDES; $i++) {
                $xml = $zip->getFromName("ppt/slides/slide{$i}.xml");

                if ($xml === false) {
                    // Slide numbering in a .pptx is not guaranteed contiguous
                    // (a deleted-then-saved slide can leave a gap), but two
                    // consecutive misses means the deck has genuinely ended -
                    // unlike ExcelExtractor's sheets, there is no "sheet1 is
                    // sometimes just named differently" case to special-case here.
                    if ($i > 1 && $zip->getFromName('ppt/slides/slide' . ($i - 1) . '.xml') === false) {
                        break;
                    }

                    continue;
                }

                // Same line/paragraph-break-to-space treatment as DocxExtractor,
                // so adjacent text runs on different lines of a slide don't fuse.
                $xml = preg_replace('/<\/a:p>/', ' ', $xml) ?? $xml;
                $parts[] = strip_tags($xml);
            }

            if ($parts === []) {
                return '';
            }

            $text = html_entity_decode(implode(' ', $parts));

            return trim(preg_replace('/\s+/', ' ', $text) ?? '');
        } catch (Throwable $e) {
            return '';
        } finally {
            $zip->close();
        }
    }
}
