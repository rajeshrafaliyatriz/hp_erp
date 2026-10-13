<?php

namespace App\Jobs;

use App\Models\Idms\DocumentMaster;
use App\Services\Idms\DocumentAuditService;
use App\Services\Idms\DocumentStorageService;
use App\Services\Idms\IdmsClassificationService;
use App\Services\Idms\IdmsDuplicateDetector;
use App\Services\Idms\IdmsTextExtractor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Extract -> classify -> tag -> duplicate check -> ready_for_review.
 *
 * Idempotent (safe to retry) and never loses the file: any failure marks the
 * document `failed` and leaves the stored object alone. The deployment's queue
 * is currently `sync`, so this runs inside the upload request.
 */
class ProcessIdmsDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;

    public function __construct(public int $documentId, public int $userId)
    {
    }

    public function handle(
        DocumentStorageService $storage,
        IdmsTextExtractor $extractor,
        IdmsClassificationService $classifier,
        IdmsDuplicateDetector $duplicates
    ): void {
        $document = DocumentMaster::find($this->documentId);
        if (!$document) {
            return;
        }

        $document->update(['processing_status' => 'processing', 'processing_error' => null]);

        $tempPath = null;
        try {
            $ext = preg_replace('/[^A-Za-z0-9]/', '', pathinfo($document->original_file_name, PATHINFO_EXTENSION));
            $tempPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'idms_' . uniqid('', true) . ($ext ? ".{$ext}" : '');
            file_put_contents($tempPath, $storage->get($document->storage_path));

            $extraction = $extractor->extract($tempPath, $document->mime_type, $document->original_file_name, (int) $document->sub_institute_id);
            $document->extracted_text = $extraction['text'];

            $classification = $classifier->classify($extraction['text'], $document->original_file_name, (int) $document->sub_institute_id);
            $meta = $classification['metadata'];

            $document->document_type = $meta['document_type'] ?? 'General Correspondence';
            $document->category = $meta['category'] ?? 'Administrative';
            $document->department_id = $classification['department_id'] ?? $document->department_id;
            $document->subject = $meta['subject'] ?: pathinfo($document->original_file_name, PATHINFO_FILENAME);
            $document->document_date = $meta['document_date'] ?? null;
            $document->academic_year = $meta['academic_year'] ?? null;
            $document->people = $meta['people'] ?? [];
            $document->organization = $meta['organization'] ?? '';
            $document->project = $meta['project'] ?? null;
            $document->lifecycle_status = $meta['lifecycle_status'] ?? 'active';
            $document->summary = $meta['summary'] ?? '';
            $document->confidence = $meta['confidence'] ?? 0.50;
            $document->keywords = $meta['keywords'] ?? [];

            // AI tags start as "suggested"; the uploader accepts or rejects them.
            $document->syncTags(array_map(
                fn ($tag) => ['name' => trim((string) $tag), 'source' => 'ai', 'status' => 'suggested'],
                $meta['suggested_tags'] ?? []
            ));
            $document->recomputeViewPrincipals();

            // Duplicate detection runs as the real uploader so it only ever names documents they can see.
            $uploader = DB::table('tbluser')
                ->where('id', $this->userId)
                ->first(['id', 'user_profile_id', 'department_id', 'is_admin', 'sub_institute_id']);

            $warnings = $classification['warnings'] ?? [];
            if ($uploader) {
                $warnings = array_merge($warnings, $duplicates->detect($document, $uploader, (int) $document->sub_institute_id));
            }
            if ($extraction['used_ocr']) {
                $warnings[] = ['type' => 'ocr_applied', 'message' => 'Scanned text extracted via OCR'];
            }
            $document->warnings = $warnings;

            $document->processing_status = 'ready_for_review';
            $document->save();

            DocumentAuditService::log($document, 'pipeline_completed', $this->userId, [
                'confidence' => $document->confidence,
                'type' => $document->document_type,
            ]);
        } catch (Throwable $e) {
            Log::error("IDMS pipeline error for document #{$this->documentId}: " . $e->getMessage());
            $document->update(['processing_status' => 'failed', 'processing_error' => mb_substr($e->getMessage(), 0, 1000)]);
        } finally {
            if ($tempPath && file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error("IDMS pipeline job permanently failed for document #{$this->documentId}: " . $exception->getMessage());
        DocumentMaster::whereKey($this->documentId)->update([
            'processing_status' => 'failed',
            'processing_error' => mb_substr($exception->getMessage(), 0, 1000),
        ]);
    }
}
