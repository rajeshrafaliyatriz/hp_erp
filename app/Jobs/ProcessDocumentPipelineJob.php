<?php

namespace App\Jobs;

use App\Services\Documents\DocumentStorageService;
use App\Services\Documents\Extraction\GeminiVisionOcrProvider;
use App\Services\Documents\Extraction\TextExtractionManager;
use App\Services\Documents\Understanding\DocumentClassificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * OCR (when there is no text layer) + AI classification for one
 * `document_library` row, after the upload itself has already returned.
 *
 * ── WHY THIS RUNS AFTER THE RESPONSE, NOT BEFORE ────────────────────────────
 *
 * `DocumentLibraryController::fileDocument()` already extracts text for
 * PDF/DOCX/XLSX synchronously at upload time (Phase 1) - that is fast and
 * the document is searchable on its content the moment the request returns.
 * What THIS job adds is the slow part: an OCR call for a scan/photo with no
 * text layer, and an AI classification call, both of which are genuinely
 * network-bound and have no place blocking an upload response.
 *
 * ── THE DOCUMENT IS ALREADY USABLE BEFORE THIS RUNS, AND STAYS USABLE IF IT FAILS ──
 *
 * There is deliberately no "pending review" gate here - this job never holds
 * a document back from search once it has run; it only adds to a row that
 * is already live. If AI is unconfigured, out of quota, or this whole job
 * never runs because no worker is provisioned (see this feature's plan: this
 * deployment has no persistent `queue:work` process - `ensureQueueWorkerRunning()`
 * in `DocumentLibraryController` self-spawns a one-shot worker exactly as
 * `OpportunityController` already does), the document is still filed, still
 * searchable by its own title and whatever synchronous extraction already
 * found - classification is additive, not a precondition.
 *
 * ── document_type IS NEVER OVERWRITTEN ──────────────────────────────────────
 *
 * See `DocumentClassificationService`'s docblock. The uploader's own choice
 * always wins; a suggestion only fills the column in when it was never set.
 */
class ProcessDocumentPipelineJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;
    public int $timeout = 180;

    public function __construct(public readonly int $documentId)
    {
        $this->onQueue((string) config('documents.processing.queue', 'documents'));
    }

    public function handle(
        TextExtractionManager $extractor,
        GeminiVisionOcrProvider $ocr,
        DocumentClassificationService $classifier,
        DocumentStorageService $storage
    ): void {
        $document = DB::table('document_library')->where('id', $this->documentId)->whereNull('deleted_at')->first();

        if (!$document) {
            // Deleted or never existed by the time the worker got to it - not
            // a failure, nothing to process.
            return;
        }

        DB::table('document_library')->where('id', $document->id)->update(['processing_status' => 'processing']);

        $warnings = [];
        $extractedText = (string) ($document->extracted_text ?? '');

        try {
            $extractedText = $this->ensureText($document, $extractedText, $extractor, $ocr, $storage, $warnings);
            $this->duplicateCheck($document, $warnings);
            $this->classify($document, $extractedText, $classifier);

            DB::table('document_library')->where('id', $document->id)->update([
                'processing_status' => 'done',
                'processing_error' => null,
                'warnings' => $warnings !== [] ? json_encode($warnings) : null,
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);

            // Still 'done', not 'failed' - see this class's docblock. The row
            // already has whatever the synchronous upload-time extraction
            // produced; a pipeline error here is recorded, not a reason to
            // hide a document that is otherwise perfectly usable.
            DB::table('document_library')->where('id', $document->id)->update([
                'processing_status' => 'done',
                'processing_error' => mb_substr($e->getMessage(), 0, 500),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Text, filled in by OCR if there still isn't any and the file is a type
     * OCR can read. Returns the (possibly unchanged) text so the caller does
     * not have to re-read the row.
     */
    private function ensureText(
        object $document,
        string $existingText,
        TextExtractionManager $extractor,
        GeminiVisionOcrProvider $ocr,
        DocumentStorageService $storage,
        array &$warnings
    ): string {
        if (trim($existingText) !== '') {
            return $existingText;
        }

        if (empty($document->storage_path) || empty($document->mime_type)) {
            return $existingText;
        }

        if (!$ocr->handles((string) $document->mime_type)) {
            return $existingText;
        }

        if (!$storage->exists($document->storage_path)) {
            $warnings[] = 'file_missing_for_ocr';

            return $existingText;
        }

        $local = tempnam(sys_get_temp_dir(), 'doc_ocr_');

        try {
            file_put_contents($local, Storage::disk($storage->disk())->get($document->storage_path));
            $text = $ocr->extract($local, (string) $document->mime_type, (int) $document->sub_institute_id);
        } finally {
            @unlink($local);
        }

        if ($text === '') {
            $warnings[] = 'ocr_found_no_text';

            return $existingText;
        }

        DB::table('document_library')->where('id', $document->id)->update(['extracted_text' => $text]);

        return $text;
    }

    /** Same bytes filed twice in this tenant - flagged, never blocked (see DocumentAccess's reasoning elsewhere: this app surfaces, it does not refuse). */
    private function duplicateCheck(object $document, array &$warnings): void
    {
        if (empty($document->checksum_sha256)) {
            return;
        }

        $match = DB::table('document_library')
            ->where('sub_institute_id', $document->sub_institute_id)
            ->where('checksum_sha256', $document->checksum_sha256)
            ->where('id', '!=', $document->id)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->value('id');

        if ($match) {
            $warnings[] = 'possible_duplicate_of:' . $match;
        }
    }

    private function classify(object $document, string $extractedText, DocumentClassificationService $classifier): void
    {
        if (trim($extractedText) === '') {
            return;
        }

        $result = $classifier->classify($extractedText, (int) $document->sub_institute_id);

        if ($result['source'] === 'none') {
            return;
        }

        $update = [
            'subject' => $result['subject'],
            'keywords' => $result['keywords'] !== [] ? json_encode($result['keywords']) : null,
            'summary' => $result['summary'],
            'confidence' => $result['confidence'],
        ];

        // Fills in only when the uploader left it unset - see this class's
        // and DocumentClassificationService's docblocks.
        if (empty($document->document_type) && $result['document_type'] !== null) {
            $update['document_type'] = $result['document_type'];
        }

        DB::table('document_library')->where('id', $document->id)->update($update);
    }
}
