<?php

namespace App\Services\Idms;

use App\Services\Documents\Extraction\GeminiVisionOcrProvider;
use App\Services\Documents\Extraction\TextExtractionManager;

/**
 * Text for an IDMS file, reusing the Document Library's extractors (PDF, DOCX,
 * XLSX, plain text) and its vision OCR for images and scanned PDFs. Never
 * throws for a file it cannot read: the document is still filed, just not yet
 * content-searchable.
 */
class IdmsTextExtractor
{
    public function __construct(
        private readonly TextExtractionManager $extractors,
        private readonly GeminiVisionOcrProvider $ocr,
    ) {
    }

    /** @return array{text: string, used_ocr: bool} */
    public function extract(string $absolutePath, string $mimeType, string $originalFileName, int $subInstituteId): array
    {
        $extension = strtolower(pathinfo($originalFileName, PATHINFO_EXTENSION));
        $text = trim($this->extractors->extract($absolutePath, $extension));

        $needsOcr = $this->extractors->isImage($extension)
            || ($extension === 'pdf' && mb_strlen($text) < 50);

        if ($needsOcr && $this->ocr->handles($mimeType)) {
            $ocrText = trim($this->ocr->extract($absolutePath, $mimeType, $subInstituteId));
            if (mb_strlen($ocrText) > mb_strlen($text)) {
                return ['text' => $ocrText, 'used_ocr' => true];
            }
        }

        return ['text' => $text, 'used_ocr' => false];
    }
}
