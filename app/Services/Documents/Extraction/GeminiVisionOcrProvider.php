<?php

namespace App\Services\Documents\Extraction;

use App\Domain\AI\Support\AiModelClient;
use Throwable;

/**
 * OCR for a scan or photo with no text layer, via Gemini Vision.
 *
 * NOT a `tesseract` shell-out, deliberately: nothing in this codebase
 * references a tesseract binary and nothing evidences one being installed on
 * this server (see the Phase 1 investigation in this feature's plan) -
 * adding OCR by requiring new server infrastructure would make the feature
 * depend on an ops step nobody has signed up for. `AiModelClient` already
 * reaches Gemini for every other AI capability in this app; this is the same
 * call, with an inline image part instead of only text (see
 * `AiModelClient::complete()`'s `images` option).
 */
class GeminiVisionOcrProvider
{
    public function __construct(private readonly AiModelClient $client)
    {
    }

    public function handles(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'image/') || $mimeType === 'application/pdf';
    }

    /**
     * The text in the image/scan, or '' if nothing could be read.
     *
     * Never throws: a caller that cannot OCR this page is not worse off than
     * one with no OCR at all, and the pipeline job treats an empty result as
     * "stays without extracted text" rather than a failure.
     */
    public function extract(string $absolutePath, string $mimeType, int|string|null $institute): string
    {
        $bytes = @file_get_contents($absolutePath);

        if ($bytes === false || $bytes === '') {
            return '';
        }

        // Gemini's request body itself, base64-inflated - stay well clear of
        // the provider's upload ceiling for an inline (non-Files-API) part.
        if (strlen($bytes) > 18 * 1024 * 1024) {
            return '';
        }

        try {
            $completion = $this->client->complete(
                'document_classification',
                [
                    [
                        'role' => 'system',
                        'content' => 'You transcribe text from documents and photographs exactly as written. '
                            . 'Output ONLY the transcribed text, in reading order, with no commentary, no '
                            . 'markdown, and no translation. If the image has no legible text, output nothing.',
                    ],
                    ['role' => 'user', 'content' => 'Transcribe every word of text visible in this document.'],
                ],
                [
                    'max_tokens' => 4096,
                    'temperature' => 0,
                    'images' => [['mime_type' => $mimeType, 'data' => base64_encode($bytes)]],
                ],
                $institute
            );

            return trim($completion->text);
        } catch (Throwable $e) {
            return '';
        }
    }
}
