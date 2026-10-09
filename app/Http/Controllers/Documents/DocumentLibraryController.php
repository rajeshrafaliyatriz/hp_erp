<?php

namespace App\Http\Controllers\Documents;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessDocumentPipelineJob;
use App\Services\Documents\DocumentAccess;
use App\Services\Documents\DocumentDuplicator;
use App\Services\Documents\DocumentStorageService;
use App\Services\Documents\Extraction\TextExtractionManager;
use App\Services\Documents\Search\DocumentSearchService;
use App\Support\SubjectAuthority;
use Carbon\Carbon;
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

    /**
     * POST /api/departments-management/{id}/documents — file a document AS
     * the caller, tagged to department {id} regardless of the caller's own
     * department. Route-gated profile:admin,hr; HR_ELEVATED re-checked
     * inline, same belt-and-suspenders shape as trashVisible().
     *
     * Why this exists: `fileDocument()` has always tagged `department_id`
     * from the ACTOR's own department (`callerDepartment()`), unconditionally
     * - correct for every caller up to now, but wrong the moment an admin
     * views a department that is not their own and uploads something there:
     * without this override, the file would silently land under the admin's
     * OWN department instead of the one they were looking at.
     */
    public function storeForDepartment(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $actorId = (int) $identity['user_id'];
        $tenantId = (int) $identity['sub_institute_id'];

        if (!SubjectAuthority::userSatisfies($actorId, SubjectAuthority::HR_ELEVATED)) {
            return $this->notFound();
        }

        $departmentId = (int) $id;
        $exists = DB::table('hrms_departments')
            ->where('id', $departmentId)->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')->exists();

        if (!$exists) {
            return response()->json(['status' => 0, 'message' => 'That department does not exist.'], 422);
        }

        return $this->fileDocument($request, $actorId, $tenantId, $actorId, $departmentId);
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

        $this->recordAudit($row->id, (int) $identity['user_id'], $request, 'deleted');

        return response()->json(['status' => 1, 'message' => 'Document removed.']);
    }

    /**
     * POST /api/employees-management/{employee}/documents/{document}/restore
     * — undelete one of an employee's, admin-gated. Mirrors
     * `destroyForEmployee()`'s shape exactly: role gate at the route
     * (`profile:admin,hr`), tenant re-check here.
     */
    public function restoreForEmployee(Request $request, $employee, $document)
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
            ->whereNotNull('deleted_at')
            ->first();

        if (!$row) {
            return $this->notFound();
        }

        DB::table('document_library')->where('id', $row->id)->update([
            'deleted_by' => null,
            'deleted_at' => null,
            'updated_at' => now(),
        ]);

        $this->recordAudit($row->id, (int) $identity['user_id'], $request, 'restored_from_trash');

        return response()->json(['status' => 1, 'message' => 'Document restored.']);
    }

    /**
     * GET /api/documents/{id} — one document, in full.
     *
     * Everything the search/list rows omit for weight (subject, summary,
     * confidence, keywords, warnings, processing_error, processing_step) -
     * this is what the detail panel and the upload-progress poller both read.
     * Unlike `search()`, this does NOT filter on `processing_status = 'done'`
     * - a caller polling this endpoint right after upload needs to see
     * 'processing' and the live `processing_step`, not a 404 until the
     * background job finishes.
     */
    public function show(Request $request, $id)
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

        unset($row->extracted_text); // can be megabytes; never needed by the detail panel or the poller.

        return response()->json(['status' => 1, 'data' => $row]);
    }

    /**
     * GET /api/documents/{id}/history — this document's own version and
     * audit trail, newest first. Backs the "Versions" and "Audit" tabs.
     */
    public function history(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $department = $this->callerDepartment((int) $identity['user_id']);
        $row = DB::table('document_library')->where('id', (int) $id)->whereNull('deleted_at')->first(['id', 'sub_institute_id', 'owner_id', 'visibility', 'department_id', 'view_principals', 'created_by']);

        if (!$row || !DocumentAccess::canView($row, (int) $identity['user_id'], (int) $identity['sub_institute_id'], $department)) {
            return $this->notFound();
        }

        $entries = DB::table('document_library_history as h')
            ->leftJoin('tbluser as u', 'u.id', '=', 'h.created_by')
            ->where('h.document_id', $row->id)
            ->orderByDesc('h.created_at')
            ->orderByDesc('h.id')
            ->limit(100)
            ->get([
                'h.id', 'h.entry_type', 'h.version_number', 'h.storage_path', 'h.size', 'h.change_note',
                'h.mime_type', 'h.original_file_name',
                'h.action', 'h.details', 'h.ip_address', 'h.created_at',
                DB::raw("TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) as actor_name"),
            ]);

        return response()->json(['status' => 1, 'data' => $entries]);
    }

    /**
     * GET /api/documents/activity — every action recorded against any
     * document this caller may see, newest first. The global counterpart to
     * `history()`'s per-document feed.
     *
     * Scoped the same way `search()` is: joins document_library_history back
     * to document_library and applies DocumentAccess::visibleTo, so a
     * non-elevated caller sees activity on their own + organisation-visible
     * documents, and HR/admin see the whole tenant's.
     */
    public function activity(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $department = $this->callerDepartment((int) $identity['user_id']);

        $query = DB::table('document_library_history as h')
            ->join('document_library as d', 'd.id', '=', 'h.document_id')
            ->leftJoin('tbluser as u', 'u.id', '=', 'h.created_by')
            ->where('h.entry_type', 'audit')
            ->whereNull('d.deleted_at');

        // The access rule names bare columns (`sub_institute_id`, `owner_id`, `visibility`), which are
        // ambiguous once this query also joins `tbluser`. So it is applied to the library table alone,
        // inside a subquery, and the feed is limited to those documents - the same rule, unambiguously.
        $query->whereIn('d.id', DocumentAccess::visibleTo(
            DB::table('document_library')->select('id'),
            (int) $identity['user_id'],
            (int) $identity['sub_institute_id'],
            $department
        ));

        $entries = $query
            ->orderByDesc('h.created_at')
            ->orderByDesc('h.id')
            ->limit(100)
            ->get([
                'h.id', 'h.action', 'h.details', 'h.created_at',
                'd.id as document_id', 'd.title as document_title',
                DB::raw("TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) as actor_name"),
            ]);

        return response()->json(['status' => 1, 'data' => $entries]);
    }

    /**
     * GET /api/documents/{id}/related — other documents of the same type, in
     * the same department, this caller may also see. A coarse heuristic
     * (shared document_type + department_id), not a content-similarity
     * search - good enough to answer "what else is like this" without a
     * second AI call.
     */
    public function related(Request $request, $id)
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

        $query = DB::table('document_library')
            ->where('id', '!=', $row->id)
            ->where('processing_status', 'done')
            ->whereNull('deleted_at')
            ->when($row->document_type, fn ($q) => $q->where('document_type', $row->document_type))
            ->when($row->department_id, fn ($q) => $q->where('department_id', $row->department_id));

        DocumentAccess::visibleTo($query, (int) $identity['user_id'], (int) $identity['sub_institute_id'], $department);

        $related = $query
            ->orderByDesc('created_at')
            ->limit(6)
            ->get(['id', 'title', 'original_file_name', 'document_type', 'tags', 'created_at']);

        return response()->json(['status' => 1, 'data' => $related]);
    }

    /**
     * GET /api/documents/recent — documents THIS caller has actually opened
     * (preview or download — both routes through download(), see its own
     * docblock), newest-viewed first.
     *
     * Zero schema change: every preview-open already writes an audit row
     * (action='downloaded') via recordAudit() inside download() below. This
     * just reads that trail back, grouped to the latest view per document,
     * then re-applies DocumentAccess so a document the caller has since lost
     * visibility into silently drops off rather than erroring or leaking.
     */
    public function recent(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $userId = (int) $identity['user_id'];
        $tenantId = (int) $identity['sub_institute_id'];
        $department = $this->callerDepartment($userId);
        $limit = min(50, max(1, (int) $request->input('limit', 20)));

        // Headroom beyond $limit: some of the most-recently-viewed ids may
        // since have been soft-deleted or ACL-withdrawn and get filtered
        // out below - asking for more than we need up front means a caller
        // who genuinely has $limit worth of still-visible recent documents
        // actually gets $limit back, not fewer.
        $recent = DB::table('document_library_history')
            ->select('document_id', DB::raw('MAX(created_at) as last_viewed_at'))
            ->where('entry_type', 'audit')
            ->where('action', 'downloaded')
            ->where('created_by', $userId)
            ->groupBy('document_id')
            ->orderByDesc('last_viewed_at')
            ->limit($limit * 2)
            ->pluck('last_viewed_at', 'document_id');

        if ($recent->isEmpty()) {
            return response()->json(['status' => 1, 'data' => []]);
        }

        $query = DB::table('document_library')->whereIn('id', $recent->keys())->whereNull('deleted_at');
        DocumentAccess::visibleTo($query, $userId, $tenantId, $department);

        $rows = $query->get([
            'id', 'title', 'original_file_name', 'mime_type', 'size', 'category',
            'document_type', 'department_id', 'document_date', 'period_label',
            'visibility', 'owner_id', 'source_system', 'tags', 'created_at',
            'processing_status',
        ]);

        $starred = DB::table('document_library_stars')
            ->where('user_id', $userId)
            ->whereIn('document_id', $rows->pluck('id'))
            ->pluck('document_id')
            ->all();

        $data = $rows->map(function ($row) use ($recent, $starred) {
            $out = (array) $row;
            $out['snippet'] = null;
            $out['last_viewed_at'] = $recent[$row->id];
            $out['starred'] = in_array($row->id, $starred, true);

            return $out;
        })->sortByDesc('last_viewed_at')->take($limit)->values()->all();

        return response()->json(['status' => 1, 'data' => $data]);
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
            $request->only(['q', 'category', 'document_type', 'department_id', 'source_system', 'date_from', 'date_to', 'owner_id', 'owner_name', 'folder_id']),
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
            $this->recordAudit($row->id, (int) $identity['user_id'], $request, 'downloaded');

            return Storage::disk($storage->disk())->download($row->storage_path, $this->downloadName($row));
        } catch (\Throwable $caught) {
            report($caught);

            return response()->json(['status' => 0, 'message' => 'That document could not be fetched right now.'], 502);
        }
    }

    /**
     * PUT/PATCH /api/account/documents/{id} — correct one of mine.
     *
     * The only way to change a document's own description today was to
     * replace the FILE via `uploadVersion()`; nothing let a human fix a
     * wrong title or AI-guessed type. Owner-only, same shape as `destroy()`
     * (404 for both "doesn't exist" and "not yours" - never 403). Writes
     * only the fields actually present AND different from the current row,
     * so a no-op PATCH doesn't leave a vacuous audit entry.
     */
    public function update(Request $request, $id)
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

        $data = $request->validate([
            'title' => 'sometimes|string|max:191',
            'document_type' => 'sometimes|string|max:64',
            'category' => 'sometimes|string|in:personnel,organization',
            'subject' => 'sometimes|nullable|string|max:191',
            // Accepts '' as well as an int: the frontend sends '' for "move
            // to root" rather than relying on this app's API layer to have
            // turned an empty string into a real null on the way in.
            'folder_id' => 'sometimes|nullable',
        ]);

        if (array_key_exists('folder_id', $data)) {
            $data['folder_id'] = $data['folder_id'] !== '' && $data['folder_id'] !== null ? (int) $data['folder_id'] : null;
        }

        $changes = [];

        foreach ($data as $field => $value) {
            if ((string) ($row->{$field} ?? '') !== (string) $value) {
                $changes[$field] = $value;
            }
        }

        if (array_key_exists('folder_id', $changes) && $changes['folder_id'] !== null) {
            $folder = DB::table('document_folders')->where('id', $changes['folder_id'])->whereNull('deleted_at')->first();

            if (!$folder || !DocumentAccess::canManageFolder($folder, $userId, (int) $identity['sub_institute_id'])) {
                return response()->json(['status' => 0, 'message' => 'That folder does not exist.'], 422);
            }
        }

        if ($changes === []) {
            return response()->json(['status' => 1, 'message' => 'Nothing to update.']);
        }

        if (array_key_exists('title', $changes)) {
            // A human setting the title always wins, forever - the pipeline
            // must never overwrite it again. See the title_source migration's
            // docblock and ProcessDocumentPipelineJob::classify().
            $changes['title_source'] = 'user';
        }

        $changes['updated_by'] = $userId;
        $changes['updated_at'] = now();

        DB::table('document_library')->where('id', $row->id)->update($changes);

        $this->recordAudit($row->id, $userId, $request, 'updated', ['changed_fields' => array_keys($changes)]);

        return response()->json(['status' => 1, 'message' => 'Document updated.']);
    }

    /**
     * POST /api/account/documents/{id}/duplicate — Drive's "Make a copy."
     * {destination_folder_id?}. Gated on canView() (you can copy anything
     * shared with you, same as starring), not ownership - unlike update()/
     * destroy() above, a duplicate never touches the original, so there is
     * nothing here that requires owning it. See DocumentDuplicator's own
     * docblock for why the copy always lands private regardless of the
     * original's visibility.
     */
    public function duplicate(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $userId = (int) $identity['user_id'];
        $tenantId = (int) $identity['sub_institute_id'];
        $department = $this->callerDepartment($userId);

        $row = DB::table('document_library')->where('id', (int) $id)->whereNull('deleted_at')->first();

        if (!$row || !DocumentAccess::canView($row, $userId, $tenantId, $department)) {
            return $this->notFound();
        }

        $data = $request->validate(['destination_folder_id' => 'sometimes|nullable|integer']);
        $destinationFolderId = null;

        if (!empty($data['destination_folder_id'])) {
            $destinationFolderId = (int) $data['destination_folder_id'];
            $folder = DB::table('document_folders')->where('id', $destinationFolderId)->whereNull('deleted_at')->first();

            if (!$folder || !DocumentAccess::canManageFolder($folder, $userId, $tenantId, $department)) {
                return response()->json(['status' => 0, 'message' => 'That destination folder does not exist.'], 422);
            }
        }

        $duplicator = new DocumentDuplicator(new DocumentStorageService());
        $result = $duplicator->duplicateDocument($row, $userId, $destinationFolderId);

        if ($result === null) {
            return response()->json(['status' => 0, 'message' => 'The original file could not be found in storage.'], 422);
        }

        return response()->json(['status' => 1, 'message' => 'Document duplicated.', 'data' => $result]);
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

        $this->recordAudit($row->id, $userId, $request, 'deleted');

        return response()->json(['status' => 1, 'message' => 'Document removed.']);
    }

    /**
     * POST /api/account/documents/{id}/restore — undelete one of mine.
     *
     * A true undelete, not a new row - unlike `restoreVersion()` (which adds
     * a version pointing at old bytes, keeping history append-only), there is
     * nothing to preserve by NOT clearing `deleted_at` here: the row between
     * delete and restore was never visible or searchable, so there is no
     * "what it looked like while deleted" worth keeping a trace of.
     */
    public function restore(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $userId = (int) $identity['user_id'];

        $row = DB::table('document_library')
            ->where('id', (int) $id)
            ->where('owner_id', $userId)
            ->whereNotNull('deleted_at')
            ->first();

        if (!$row) {
            return $this->notFound();
        }

        DB::table('document_library')->where('id', $row->id)->update([
            'deleted_by' => null,
            'deleted_at' => null,
            'updated_at' => now(),
        ]);

        $this->recordAudit($row->id, $userId, $request, 'restored_from_trash');

        return response()->json(['status' => 1, 'message' => 'Document restored.']);
    }

    /**
     * GET /api/account/documents/trash — mine, deleted but not yet purged.
     *
     * `purge_at` is computed here, not stored - a denormalized column would
     * need a backfill every time the retention window changes;
     * `documents:purge-trash --days=N`'s own default is the one source of
     * truth for how long trash lasts, and trash lists are small enough that
     * computing it per-row costs nothing.
     */
    public function trash(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        return $this->trashFor((int) $identity['user_id'], (int) $identity['sub_institute_id'], false);
    }

    /**
     * GET /api/documents/trash — every trashed document in the tenant, for
     * HR/admin. Gated the same two ways every other admin-wide document view
     * already is in this controller: route-level `profile:admin,hr` AND a
     * row-level `SubjectAuthority::userSatisfies(..., HR_ELEVATED)` check
     * here (see `DocumentAccess`'s own docblock for why both, not one).
     */
    public function trashVisible(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $userId = (int) $identity['user_id'];

        if (!SubjectAuthority::userSatisfies($userId, SubjectAuthority::HR_ELEVATED)) {
            return $this->notFound();
        }

        return $this->trashFor($userId, (int) $identity['sub_institute_id'], true);
    }

    private function trashFor(int $userId, int $tenantId, bool $tenantWide)
    {
        $purgeDays = (int) config('documents.trash.purge_days', 30);

        $rows = DB::table('document_library')
            ->where('sub_institute_id', $tenantId)
            ->when(!$tenantWide, fn ($q) => $q->where('owner_id', $userId))
            ->whereNotNull('deleted_at')
            ->orderByDesc('deleted_at')
            ->get([
                'id', 'title', 'document_type', 'category', 'original_file_name',
                'mime_type', 'size', 'owner_id', 'deleted_at', 'created_at',
            ])
            ->map(function (object $row) use ($purgeDays) {
                $row->purge_at = Carbon::parse($row->deleted_at)->addDays($purgeDays)->toDateTimeString();

                return $row;
            });

        return response()->json(['status' => 1, 'data' => $rows]);
    }

    /**
     * POST /api/account/documents/{id}/versions — replace the file with a new
     * version, keeping the old one in `document_library_history` rather than
     * overwriting it in place. Owner-only, same reasoning as `destroy()`: an
     * action that changes what's actually in the file must not be reachable
     * by anyone who merely has view access to it.
     */
    public function uploadVersion(Request $request, $id)
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

        $allowedExtensions = (string) config('documents.allowed_extensions');
        $maxKb = (int) config('documents.max_upload_kb');

        $data = $request->validate([
            'document' => 'required|file|mimes:' . $allowedExtensions . '|max:' . $maxKb,
            'change_note' => 'nullable|string|max:255',
        ]);

        $file = $request->file('document');
        $storage = new DocumentStorageService();
        $stored = $storage->storeUpload($file, $userId);

        $this->writeNewVersion($row, $stored, $userId, $request, $data['change_note'] ?? 'New version uploaded', null);

        return response()->json(['status' => 1, 'message' => 'New version uploaded.', 'data' => ['id' => $row->id]]);
    }

    /**
     * POST /api/account/documents/{id}/versions/{historyId}/restore — make an
     * older version current again.
     *
     * This does NOT rewrite history in place (the table is append-only by
     * design — see its migration's docblock): restoring writes a NEW version
     * row pointing at the old file's bytes, the same way `git revert` adds a
     * commit rather than erasing one. Nothing about what actually happened is
     * ever lost, including the restore itself (it gets its own audit entry).
     */
    public function restoreVersion(Request $request, $id, $historyId)
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

        $target = DB::table('document_library_history')
            ->where('id', (int) $historyId)
            ->where('document_id', $row->id)
            ->where('entry_type', 'version')
            ->first();

        if (!$target || empty($target->storage_path)) {
            return $this->notFound();
        }

        if ((int) $target->version_number === (int) $row->current_version) {
            return response()->json(['status' => 0, 'message' => 'That is already the current version.'], 422);
        }

        $storage = new DocumentStorageService();

        if (!$storage->exists($target->storage_path)) {
            return response()->json([
                'status' => 0,
                'message' => 'That version\'s file is no longer available and cannot be restored.',
            ], 409);
        }

        $stored = [
            'storage_path' => $target->storage_path,
            'checksum_sha256' => $target->checksum_sha256,
            'size' => $target->size,
            'mime_type' => $target->mime_type ?: $row->mime_type,
            'original_file_name' => $target->original_file_name ?: $row->original_file_name,
        ];

        $changeNote = 'Restored from version ' . ($target->version_number ?? '?');
        $this->writeNewVersion($row, $stored, $userId, $request, $changeNote, (int) $target->version_number);

        return response()->json(['status' => 1, 'message' => 'Version restored.', 'data' => ['id' => $row->id]]);
    }

    /**
     * Shared by `uploadVersion()` and `restoreVersion()`: point the live row
     * at a (new or restored) file, record the version in history, re-extract
     * text synchronously so content search reflects what's current, and
     * re-run the same async pipeline a fresh upload gets — OCR/classification
     * for the new content, and a real `processing_step` the detail dialog's
     * progress indicator can poll exactly as it does for a first upload.
     *
     * @param  array{storage_path:string, checksum_sha256:string, size:int, mime_type:string, original_file_name:string}  $stored
     */
    private function writeNewVersion(object $row, array $stored, int $actorId, Request $request, string $changeNote, ?int $restoredFromVersion): void
    {
        $newVersion = (int) $row->current_version + 1;

        $extension = strtolower(pathinfo((string) $stored['original_file_name'], PATHINFO_EXTENSION));
        $extractor = new TextExtractionManager();
        $extractedText = null;

        if ($extension !== '' && $extractor->isTextBearing($extension)) {
            try {
                $local = tempnam(sys_get_temp_dir(), 'doc_ver_');
                file_put_contents($local, Storage::disk((new DocumentStorageService())->disk())->get($stored['storage_path']));
                $extractedText = $extractor->extract($local, $extension);
                @unlink($local);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        DB::table('document_library')->where('id', $row->id)->update([
            'storage_path' => $stored['storage_path'],
            'checksum_sha256' => $stored['checksum_sha256'],
            'size' => $stored['size'],
            'mime_type' => $stored['mime_type'],
            'original_file_name' => $stored['original_file_name'],
            'current_version' => $newVersion,
            'extracted_text' => ($extractedText !== null && $extractedText !== '') ? $extractedText : null,
            'processing_status' => 'done',
            'processing_step' => null,
            'processing_error' => null,
            'updated_at' => now(),
        ]);

        DB::table('document_library_history')->insert([
            'document_id' => $row->id,
            'entry_type' => 'version',
            'version_number' => $newVersion,
            'storage_path' => $stored['storage_path'],
            'checksum_sha256' => $stored['checksum_sha256'],
            'size' => $stored['size'],
            'mime_type' => $stored['mime_type'],
            'original_file_name' => $stored['original_file_name'],
            'change_note' => $changeNote,
            'created_by' => $actorId,
            'created_at' => now(),
        ]);

        $this->recordAudit($row->id, $actorId, $request, $restoredFromVersion !== null ? 'restored' : 'version_uploaded', array_filter([
            'version' => $newVersion,
            'from_version' => $restoredFromVersion,
        ], fn ($v) => $v !== null));

        ProcessDocumentPipelineJob::dispatch($row->id);
        $this->ensureQueueWorkerRunning();
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
    private function fileDocument(Request $request, int $subjectId, int $tenantId, int $actorId, ?int $departmentOverride = null)
    {
        $allowedExtensions = (string) config('documents.allowed_extensions');
        $maxKb = (int) config('documents.max_upload_kb');

        $data = $request->validate([
            'document' => 'required|file|mimes:' . $allowedExtensions . '|max:' . $maxKb,
            // Both optional - see "user should only click Upload": a title
            // falls back to the filename below, and an unset document_type
            // is filled in later by ProcessDocumentPipelineJob::classify(),
            // exactly the way it already fills one in when a human leaves
            // it blank. document_type's known-types list (config/documents.php)
            // is advisory only now, same as that config's own docblock
            // already says of the column itself ("what the upload form
            // offers, not what the database enforces") - any non-blank
            // string up to 64 chars is accepted, not just a configured key,
            // which is also what lets "Other" + a typed label (frontend)
            // store that label directly instead of the literal word "other".
            'title' => 'nullable|string|max:191',
            'document_type' => 'nullable|string|max:64',
            'category' => 'nullable|string|in:personnel,organization',
            'visibility' => 'nullable|string|in:private,department,organization',
            'folder_id' => 'nullable|integer',
        ]);

        $category = $data['category'] ?? 'personnel';
        $visibility = $data['visibility'] ?? 'private';

        $titleProvided = !empty(trim((string) ($data['title'] ?? '')));
        $documentType = !empty(trim((string) ($data['document_type'] ?? ''))) ? trim($data['document_type']) : null;

        $folderId = $data['folder_id'] ?? null;

        if ($folderId !== null) {
            // 422, not a silent null-fallback - a document aimed at a folder
            // the caller can't manage (wrong tenant, or someone else's
            // private folder) must not quietly land at the root instead.
            // Checked against the ACTOR, not the subject: when HR files on
            // an employee's behalf (storeForEmployee), it is HR's own
            // access to the destination folder that matters - the employee
            // being filed for may have no access to that folder at all.
            $folder = DB::table('document_folders')->where('id', $folderId)->whereNull('deleted_at')->first();
            $actorDepartment = $departmentOverride ?? $this->callerDepartment($actorId);

            if (!$folder || !DocumentAccess::canManageFolder($folder, $actorId, $tenantId, $actorDepartment)) {
                return response()->json(['status' => 0, 'message' => 'That folder does not exist.'], 422);
            }
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

        $department = $departmentOverride ?? $this->callerDepartment($subjectId);
        $principals = DocumentAccess::computePrincipals($visibility, $department, []);

        // Falls back to the file's own name, the same default this app's
        // upload dropzone already applied client-side before title became
        // optional - title_source records which happened, so
        // ProcessDocumentPipelineJob::classify() knows an AI-suggested
        // title is still free to fill this in (title_source !== 'user'),
        // while a deliberately-typed one never is.
        $title = $titleProvided
            ? trim((string) $data['title'])
            : mb_substr(pathinfo((string) $stored['original_file_name'], PATHINFO_FILENAME), 0, 191);

        $documentId = DB::table('document_library')->insertGetId([
            'sub_institute_id' => $tenantId,
            'owner_id' => $subjectId,
            'folder_id' => $folderId,
            'title' => $title,
            'title_source' => $titleProvided ? 'user' : 'filename',
            'original_file_name' => $stored['original_file_name'],
            'mime_type' => $stored['mime_type'],
            'size' => $stored['size'],
            'checksum_sha256' => $stored['checksum_sha256'],
            'storage_path' => $stored['storage_path'],
            'current_version' => 1,
            'category' => $category,
            'document_type' => $documentType,
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
            'mime_type' => $stored['mime_type'],
            'original_file_name' => $stored['original_file_name'],
            'change_note' => 'Uploaded',
            'created_by' => $actorId,
            'created_at' => now(),
        ]);

        $this->recordAudit($documentId, $actorId, $request, 'uploaded', [
            'subject_id' => $subjectId,
            'on_behalf' => $actorId !== $subjectId,
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
                // Redirecting the spawned worker's own stdout/stderr to NUL (not just
                // backgrounding it with /B) is what actually matters here: popen()'s
                // pipe stays connected to cmd.exe until the spawned process stops
                // writing to it, so with no redirection pclose() blocks for the
                // worker's entire run - which, before this fix, meant every upload's
                // HTTP response hung for 6-8+ seconds and the worker still never
                // reserved a single job (confirmed via jobs.attempts staying 0).
                // Running `php artisan queue:work` directly, unspawned, drains the
                // queue fine - this was purely a spawn-plumbing bug, not a pipeline one.
                pclose(popen("start \"\" /B php artisan queue:work --queue={$queueName},default --stop-when-empty > NUL 2>&1", 'r'));
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
                'mime_type', 'size', 'visibility', 'processing_status', 'processing_step',
                'source_system', 'document_date', 'created_at',
            ]);

        return response()->json([
            'status' => 1,
            'data' => $rows,
            'document_types' => config('documents.types'),
        ]);
    }

    /**
     * A durable, human-attributed note in `document_library_history` - the
     * `ProcessDocumentPipelineJob::audit()` counterpart for actions a person
     * (rather than the pipeline) took. Never allowed to fail the request it
     * is attached to.
     *
     * @param  array<string, mixed>  $details
     */
    private function recordAudit(int $documentId, int $actorId, Request $request, string $action, array $details = []): void
    {
        try {
            DB::table('document_library_history')->insert([
                'document_id' => $documentId,
                'entry_type' => 'audit',
                'action' => $action,
                'details' => $details !== [] ? json_encode($details) : null,
                'ip_address' => mb_substr((string) $request->ip(), 0, 45),
                'created_by' => $actorId,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
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
