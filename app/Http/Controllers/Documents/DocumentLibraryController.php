<?php

namespace App\Http\Controllers\Documents;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessDocumentPipelineJob;
use App\Services\Documents\DocumentAccess;
use App\Services\Documents\DocumentStorageService;
use App\Services\Documents\Extraction\TextExtractionManager;
use App\Services\Documents\Search\DocumentSearchService;
use App\Support\SubjectAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * THE DOCUMENT LIBRARY — yours, and (for HR) everybody's, and (content-)
 * searchable either way.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHAT THIS REPLACES
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `EmployeeDocumentController` / `staff_document`. That table could carry a
 * title, a type id and a filename - nothing about what was actually written
 * inside a document, no visibility beyond "owner or any HR in the tenant",
 * no version history. `document_library` is what every document writer in
 * this codebase targets from here on - uploads, payslips, offer letters,
 * Form 16, resumes - and `staff_document` is frozen (kept readable, no
 * longer written to) once this ships. See the `document_library` migration's
 * docblock for the full reasoning.
 *
 * ── TWO ROUTES, ONE CONTROLLER, DIFFERENT SUBJECTS ─────────────────────────
 * Unchanged from `EmployeeDocumentController`: `/account/documents` has no id
 * parameter and resolves the subject from the token, so it cannot be an IDOR
 * by construction. `/employees-management/{id}/documents` takes an explicit
 * subject, gated `profile:admin,hr` at the route AND re-checked here against
 * the caller's own tenant - the role gate says who may ask, the tenant check
 * says whom they may ask about.
 *
 * ── DOWNLOADS ARE STREAMED, NOT LINKED ──────────────────────────────────────
 * The path comes off the ROW, never the request, and the bytes go through
 * this application, exactly as `EmployeeDocumentController::download()`
 * already does.
 */
class DocumentLibraryController extends Controller
{
    use ResolvesApiIdentity;

    /** GET /api/account/documents — my own. */
    public function mine(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        return $this->listFor((int) $identity['user_id'], (int) $identity['sub_institute_id']);
    }

    /** GET /api/employees-management/{id}/documents — somebody else's. */
    public function forEmployee(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $tenantId = (int) $identity['sub_institute_id'];

        if (!$this->employeeInTenant((int) $id, $tenantId)) {
            return $this->notFound();
        }

        return $this->listFor((int) $id, $tenantId);
    }

    /** POST /api/account/documents — file one of my own. */
    public function store(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        return $this->fileDocument(
            $request,
            (int) $identity['user_id'],
            (int) $identity['sub_institute_id'],
            (int) $identity['user_id']
        );
    }

    /**
     * POST /api/employees-management/{id}/documents — file one FOR an employee.
     *
     * Route-gated `profile:admin,hr`; the tenant check below is the second
     * lock, because a role alone would let HR in one organisation file
     * against an employee id belonging to another.
     */
    public function storeForEmployee(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $tenantId = (int) $identity['sub_institute_id'];

        if (!$this->employeeInTenant((int) $id, $tenantId)) {
            return $this->notFound();
        }

        return $this->fileDocument($request, (int) $id, $tenantId, (int) $identity['user_id']);
    }

    /** DELETE /api/employees-management/{employee}/documents/{document} */
    public function destroyForEmployee(Request $request, $employee, $document)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $tenantId = (int) $identity['sub_institute_id'];

        if (!$this->employeeInTenant((int) $employee, $tenantId)) {
            return $this->notFound();
        }

        $row = DB::table('document_library')
            ->where('id', (int) $document)
            ->where('owner_id', (int) $employee)
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->first();

        if (!$row) {
            return $this->notFound();
        }

        DB::table('document_library')->where('id', $row->id)->update([
            'deleted_by' => (int) $identity['user_id'],
            'deleted_at' => now(),
        ]);

        return response()->json(['status' => 1, 'message' => 'Document removed.']);
    }

    /**
     * GET /api/documents — search across every document this caller may see.
     *
     * Self-service and admin alike hit this one endpoint; `DocumentAccess`
     * decides what rows come back, so a non-elevated caller searching here
     * sees exactly their own + organisation-visible documents, same scope as
     * `mine()`, while HR/admin additionally sees the whole tenant.
     */
    public function search(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $department = $this->callerDepartment((int) $identity['user_id']);
        $page = max(1, (int) $request->input('page', 1));
        $perPage = min(100, max(1, (int) $request->input('per_page', 24)));

        $result = (new DocumentSearchService())->search(
            $request->only(['q', 'category', 'document_type', 'department_id', 'source_system', 'date_from', 'date_to', 'owner_id']),
            (int) $identity['sub_institute_id'],
            (int) $identity['user_id'],
            $department,
            $page,
            $perPage
        );

        return response()->json([
            'status' => 1,
            'data' => $result['data'],
            'meta' => ['total' => $result['total'], 'page' => $page, 'per_page' => $perPage],
            'document_types' => config('documents.types'),
        ]);
    }

    /**
     * GET /api/account/documents/{id}/download — stream one back.
     *
     * The path is read from the ROW, never from the request. A federated
     * index row (source_system set, storage_path null - see §4 of the plan)
     * has no file here at all; it redirects the caller to the owning
     * feature's own download endpoint instead of 404ing outright.
     */
    public function download(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $department = $this->callerDepartment((int) $identity['user_id']);
        $row = DB::table('document_library')->where('id', (int) $id)->whereNull('deleted_at')->first();

        if (!$row || !DocumentAccess::canView($row, (int) $identity['user_id'], (int) $identity['sub_institute_id'], $department)) {
            return $this->notFound();
        }

        if (empty($row->storage_path)) {
            if (!empty($row->source_system)) {
                return response()->json([
                    'status' => 0,
                    'message' => 'This document lives in ' . $row->source_system . '. Open it from there to download.',
                    'source_system' => $row->source_system,
                ], 409);
            }

            return response()->json([
                'status' => 0,
                'message' => 'That document is recorded but its file is missing.',
            ], 404);
        }

        $storage = new DocumentStorageService();

        if (!$storage->exists($row->storage_path)) {
            return response()->json([
                'status' => 0,
                'message' => 'That document is recorded but its file is missing.',
            ], 404);
        }

        try {
            return Storage::disk($storage->disk())->download($row->storage_path, $this->downloadName($row));
        } catch (\Throwable $caught) {
            report($caught);

            return response()->json(['status' => 0, 'message' => 'That document could not be fetched right now.'], 502);
        }
    }

    /** DELETE /api/account/documents/{id} — remove one of mine. */
    public function destroy(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $userId = (int) $identity['user_id'];

        $row = DB::table('document_library')
            ->where('id', (int) $id)
            ->where('owner_id', $userId)
            ->whereNull('deleted_at')
            ->first();

        if (!$row) {
            return $this->notFound();
        }

        DB::table('document_library')->where('id', $row->id)->update([
            'deleted_by' => $userId,
            'deleted_at' => now(),
        ]);

        return response()->json(['status' => 1, 'message' => 'Document removed.']);
    }

    /* ── shared ────────────────────────────────────────────────────────── */

    /**
     * Write the file, extract its text synchronously, and insert the row.
     *
     * Synchronous, not queued - Phase 1 does not depend on a queue worker
     * actually running in production (this codebase has none; see the
     * plan's "queue reality check"). Extraction of a PDF/DOCX/XLSX under the
     * 50MB cap is fast enough for the request cycle; AI classification and
     * OCR (which are not) are Phase 2's concern and run async once a worker
     * is provisioned.
     */
    private function fileDocument(Request $request, int $subjectId, int $tenantId, int $actorId)
    {
        $allowedExtensions = (string) config('documents.allowed_extensions');
        $maxKb = (int) config('documents.max_upload_kb');

        $data = $request->validate([
            'document' => 'required|file|mimes:' . $allowedExtensions . '|max:' . $maxKb,
            'title' => 'required|string|max:191',
            'document_type' => 'required|string|max:64',
            'category' => 'nullable|string|in:personnel,organization',
            'visibility' => 'nullable|string|in:private,department,organization',
        ]);

        $category = $data['category'] ?? 'personnel';
        $visibility = $data['visibility'] ?? 'private';

        $knownTypes = array_merge(
            array_keys(config('documents.types.personnel', [])),
            array_keys(config('documents.types.organization', []))
        );

        if (!in_array($data['document_type'], $knownTypes, true)) {
            return response()->json(['status' => 0, 'message' => 'That document type does not exist.'], 422);
        }

        $file = $request->file('document');
        $storage = new DocumentStorageService();
        $stored = $storage->storeUpload($file, $subjectId);

        $extension = strtolower($file->extension() ?: pathinfo($stored['original_file_name'], PATHINFO_EXTENSION));
        $extractor = new TextExtractionManager();
        $extractedText = '';

        if ($extractor->isTextBearing($extension)) {
            $extractedText = $extractor->extract($file->getRealPath(), $extension);
        }

        $department = $this->callerDepartment($subjectId);
        $principals = DocumentAccess::computePrincipals($visibility, $department, []);

        $documentId = DB::table('document_library')->insertGetId([
            'sub_institute_id' => $tenantId,
            'owner_id' => $subjectId,
            'title' => $data['title'],
            'original_file_name' => $stored['original_file_name'],
            'mime_type' => $stored['mime_type'],
            'size' => $stored['size'],
            'checksum_sha256' => $stored['checksum_sha256'],
            'storage_path' => $stored['storage_path'],
            'current_version' => 1,
            'category' => $category,
            'document_type' => $data['document_type'],
            'department_id' => $department,
            'extracted_text' => $extractedText !== '' ? $extractedText : null,
            'visibility' => $visibility,
            'view_principals' => $principals !== [] ? json_encode($principals) : null,
            // No AI pipeline yet (Phase 2) - a synchronously-extracted upload
            // is immediately searchable rather than waiting on a review step
            // that does not exist until classification does.
            'processing_status' => 'done',
            'created_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('document_library_history')->insert([
            'document_id' => $documentId,
            'entry_type' => 'version',
            'version_number' => 1,
            'storage_path' => $stored['storage_path'],
            'checksum_sha256' => $stored['checksum_sha256'],
            'size' => $stored['size'],
            'change_note' => 'Uploaded',
            'created_by' => $actorId,
            'created_at' => now(),
        ]);

        /*
         * OCR (if this is a scan with no text layer) and AI classification
         * happen asynchronously from here - the row above is already
         * 'done' and searchable on whatever synchronous extraction just
         * found, so this upload response does not wait on a network-bound
         * AI call. See ProcessDocumentPipelineJob's docblock.
         */
        ProcessDocumentPipelineJob::dispatch($documentId);
        $this->ensureQueueWorkerRunning();

        return response()->json([
            'status' => 1,
            'message' => 'Document uploaded.',
            'data' => ['id' => $documentId],
        ]);
    }

    /**
     * This deployment has no persistent `queue:work` process (see this
     * feature's plan - confirmed by this codebase's own prior art:
     * `TaskExecutionClassifier`'s comment on why it runs synchronously
     * instead, and `OpportunityController::ensureQueueWorkerRunning()`,
     * whose exact pattern this copies). Rather than leave an uploaded
     * document's classification queued forever until someone happens to run
     * `queue:work` by hand, a one-shot worker is spawned to drain it - a
     * stopgap until a real worker is provisioned, not a replacement for one.
     */
    private function ensureQueueWorkerRunning(): void
    {
        if (config('queue.default') === 'sync') {
            return;
        }

        try {
            $queueName = (string) config('documents.processing.queue', 'documents');

            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                pclose(popen("start /B php artisan queue:work --queue={$queueName},default --stop-when-empty", 'r'));
            } else {
                exec("php artisan queue:work --queue={$queueName},default --stop-when-empty > /dev/null 2>&1 &");
            }
        } catch (\Throwable) {
            // Ignore background spawn errors; a real worker or the scheduler picks it up.
        }
    }

    private function listFor(int $userId, int $tenantId)
    {
        $rows = DB::table('document_library')
            ->where('owner_id', $userId)
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->get([
                'id', 'title', 'document_type', 'category', 'original_file_name',
                'mime_type', 'size', 'visibility', 'processing_status',
                'source_system', 'document_date', 'created_at',
            ]);

        return response()->json([
            'status' => 1,
            'data' => $rows,
            'document_types' => config('documents.types'),
        ]);
    }

    private function callerDepartment(int $userId): ?int
    {
        $value = DB::table('tbluser')->where('id', $userId)->value('department_id');

        return $value ? (int) $value : null;
    }

    private function employeeInTenant(int $userId, int $tenantId): bool
    {
        return DB::table('tbluser')
            ->where('id', $userId)
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->exists();
    }

    private function downloadName(object $row): string
    {
        $extension = pathinfo((string) $row->original_file_name, PATHINFO_EXTENSION)
            ?: pathinfo((string) $row->storage_path, PATHINFO_EXTENSION);
        $title = trim((string) ($row->title ?? '')) ?: 'document';
        $safe = preg_replace('/[^A-Za-z0-9 _.-]/', '', $title) ?: 'document';

        return $extension ? $safe . '.' . $extension : $safe;
    }

    /** 404 for both "no such document" and "not yours" — never 403, which would confirm cross-tenant existence. */
    private function notFound()
    {
        return response()->json(['status' => 0, 'message' => 'That document was not found.'], 404);
    }
}
