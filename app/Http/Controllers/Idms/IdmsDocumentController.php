<?php

namespace App\Http\Controllers\Idms;

use App\Http\Controllers\Controller;
use App\Http\Resources\Idms\DocumentResource;
use App\Jobs\ProcessIdmsDocumentJob;
use App\Models\Idms\DocumentHistory;
use App\Models\Idms\DocumentMaster;
use App\Policies\IdmsDocumentPolicy;
use App\Services\Idms\DocumentAuditService;
use App\Services\Idms\DocumentStorageService;
use App\Services\Idms\IdmsBrowseService;
use App\Services\Idms\IdmsSearchParser;
use App\Services\Idms\IdmsSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

/**
 * /api/v1 IDMS. Routes sit behind the `api.token` middleware.
 *
 * WHO IS CALLING comes only from the verified Sanctum token. A user id or
 * tenant sent in a header or the body is ignored: trusting one would let any
 * caller act as any user. Every query is tenant-scoped and goes through
 * DocumentMaster::visibleTo(); edit / download / share / delete go through
 * IdmsDocumentPolicy.
 */
class IdmsDocumentController extends Controller
{
    private const EXTENSIONS = 'pdf,doc,docx,xls,xlsx,ppt,pptx,rtf,txt,csv,jpg,jpeg,png,webp';

    public function __construct(
        private readonly DocumentStorageService $storage,
        private readonly IdmsSearchService $search,
        private readonly IdmsSearchParser $parser,
        private readonly IdmsBrowseService $browse,
        private readonly IdmsDocumentPolicy $policy,
    ) {
    }

    /** @return array{user: object, tenant: int}|null */
    private function actor(Request $request): ?array
    {
        $token = trim((string) ($request->bearerToken() ?: $request->input('token')));
        $access = $token !== '' ? PersonalAccessToken::findToken($token) : null;
        if (!$access) {
            return null;
        }

        $user = DB::table('tbluser')
            ->where('id', $access->tokenable_id)
            ->first(['id', 'first_name', 'last_name', 'user_profile_id', 'department_id', 'sub_institute_id', 'is_admin']);

        return ($user && $user->sub_institute_id) ? ['user' => $user, 'tenant' => (int) $user->sub_institute_id] : null;
    }

    private function fail(string $message, int $status): JsonResponse
    {
        return response()->json(['status' => 0, 'message' => $message], $status);
    }

    private function unauthenticated(): JsonResponse
    {
        return $this->fail('Your session has expired. Sign in again.', 401);
    }

    private function visible(array $ctx, $id, bool $trashed = false): ?DocumentMaster
    {
        $query = $trashed ? DocumentMaster::onlyTrashed() : DocumentMaster::query();

        return $query->visibleTo($ctx['user'], $ctx['tenant'])->find($id);
    }

    public function index(Request $request)
    {
        if (!($ctx = $this->actor($request))) {
            return $this->unauthenticated();
        }

        $page = $this->search->search($request->all(), $ctx['user'], $ctx['tenant']);

        return response()->json([
            'status' => 1,
            'data' => DocumentResource::collection($page->items()),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    /** Returns immediately with the id; heavy work is the pipeline job. Nothing is searchable until the uploader confirms. */
    public function store(Request $request)
    {
        if (!($ctx = $this->actor($request))) {
            return $this->unauthenticated();
        }

        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:' . self::EXTENSIONS . '|max:' . config('idms.max_upload_size_kb', 51200),
            'visibility' => 'nullable|in:private,department,organization',
        ]);
        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422);
        }

        $stored = $this->storage->storeUpload($request->file('file'));

        DB::beginTransaction();
        try {
            $document = DocumentMaster::create([
                'sub_institute_id' => $ctx['tenant'],
                'title' => mb_substr(pathinfo($stored['original_file_name'], PATHINFO_FILENAME), 0, 255),
                'original_file_name' => $stored['original_file_name'],
                'mime_type' => $stored['mime_type'],
                'size' => $stored['size'],
                'checksum_sha256' => $stored['checksum_sha256'],
                'storage_path' => $stored['storage_path'],
                'current_version' => 1,
                'owner_id' => $ctx['user']->id,
                'created_by' => $ctx['user']->id,
                'visibility' => $request->input('visibility', 'organization'),
                'department_id' => $ctx['user']->department_id ?: null,
                'processing_status' => 'pending',
            ]);
            $document->recomputeViewPrincipals();
            $document->save();

            DocumentAuditService::logVersion($document, 1, $stored['storage_path'], $stored['checksum_sha256'], $stored['size'], 'Initial upload', $ctx['user']->id);
            DocumentAuditService::log($document, 'upload', $ctx['user']->id, [
                'original_file_name' => $stored['original_file_name'],
                'size' => $stored['size'],
            ]);
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            report($e);

            return $this->fail('The document could not be stored.', 500);
        }

        ProcessIdmsDocumentJob::dispatch($document->id, (int) $ctx['user']->id);

        return response()->json([
            'status' => 1,
            'message' => 'Document uploaded and processing started.',
            'data' => ['id' => $document->id, 'title' => $document->title, 'processing_status' => $document->fresh()->processing_status],
        ], 201);
    }

    public function show($id, Request $request)
    {
        if (!($ctx = $this->actor($request))) {
            return $this->unauthenticated();
        }
        if (!($document = $this->visible($ctx, $id))) {
            return $this->fail('Document not found or access denied.', 404);
        }

        DocumentAuditService::log($document, 'view', $ctx['user']->id);

        return response()->json(['status' => 1, 'data' => new DocumentResource($document)]);
    }

    public function update($id, Request $request)
    {
        if (!($ctx = $this->actor($request))) {
            return $this->unauthenticated();
        }
        if (!($document = $this->visible($ctx, $id))) {
            return $this->fail('Document not found or access denied.', 404);
        }
        if (!$this->policy->update($ctx['user'], $document)) {
            return $this->fail('You are not allowed to edit this document.', 403);
        }

        $fields = $request->only([
            'title', 'document_type', 'category', 'department_id', 'subject', 'document_date',
            'academic_year', 'organization', 'project', 'lifecycle_status', 'summary', 'visibility', 'permissions',
        ]);

        $validator = Validator::make($fields, [
            'title' => 'sometimes|string|max:255',
            'visibility' => 'sometimes|in:private,department,organization',
            'lifecycle_status' => 'sometimes|in:active,expired,archived,filed',
            'permissions' => 'sometimes|array',
            'document_date' => 'sometimes|nullable|date',
        ]);
        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422);
        }

        // Changing who can see or do what is "share", not "edit".
        if ((array_key_exists('visibility', $fields) || array_key_exists('permissions', $fields)) && !$this->policy->share($ctx['user'], $document)) {
            return $this->fail('You are not allowed to change sharing on this document.', 403);
        }

        $before = $document->only(array_keys($fields));

        DB::beginTransaction();
        try {
            $document->fill($fields);
            if (isset($fields['visibility']) || isset($fields['permissions']) || isset($fields['department_id'])) {
                $document->recomputeViewPrincipals();
            }
            $document->save();
            DocumentAuditService::log($document, 'edit_metadata', $ctx['user']->id, ['before' => $before, 'after' => $fields]);
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            report($e);

            return $this->fail('The document could not be updated.', 500);
        }

        return response()->json(['status' => 1, 'message' => 'Document updated.', 'data' => new DocumentResource($document)]);
    }

    /** Publishes a reviewed document to search. */
    public function confirm($id, Request $request)
    {
        if (!($ctx = $this->actor($request))) {
            return $this->unauthenticated();
        }
        if (!($document = $this->visible($ctx, $id))) {
            return $this->fail('Document not found.', 404);
        }
        if (!$this->policy->update($ctx['user'], $document)) {
            return $this->fail('You are not allowed to publish this document.', 403);
        }
        if (!in_array($document->processing_status, ['ready_for_review', 'failed'], true)) {
            return $this->fail('This document is not waiting for review.', 409);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|string|max:255',
            'tags' => 'sometimes|array|max:100',
        ]);
        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422);
        }

        DB::beginTransaction();
        try {
            foreach (['title', 'document_type', 'academic_year', 'summary'] as $field) {
                if ($request->has($field)) {
                    $document->{$field} = $request->input($field);
                }
            }
            if ($request->filled('department_id')) {
                $document->department_id = (int) $request->input('department_id');
            }
            if (is_array($request->input('tags'))) {
                $document->syncTags($request->input('tags'));
            }
            $document->processing_status = 'done';
            $document->recomputeViewPrincipals();
            $document->save();
            DocumentAuditService::log($document, 'review_confirmed', $ctx['user']->id);
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            report($e);

            return $this->fail('The document could not be published.', 500);
        }

        return response()->json(['status' => 1, 'message' => 'Document published to search.', 'data' => new DocumentResource($document)]);
    }

    public function updateTags($id, Request $request)
    {
        if (!($ctx = $this->actor($request))) {
            return $this->unauthenticated();
        }
        if (!($document = $this->visible($ctx, $id))) {
            return $this->fail('Document not found.', 404);
        }
        if (!$this->policy->update($ctx['user'], $document)) {
            return $this->fail('You are not allowed to edit tags on this document.', 403);
        }

        $tags = $request->input('tags', []);
        if (!is_array($tags) || count($tags) > 100) {
            return $this->fail('Tags must be a list of at most 100.', 422);
        }

        DB::beginTransaction();
        try {
            $document->syncTags($tags); // tags, tag_names and tags_text change together
            $document->save();
            DocumentAuditService::log($document, 'tags_updated', $ctx['user']->id, ['tags' => $document->tags]);
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            report($e);

            return $this->fail('Tags could not be updated.', 500);
        }

        return response()->json(['status' => 1, 'message' => 'Tags updated.', 'tags' => $document->tags]);
    }

    public function preview($id, Request $request)
    {
        if (!($ctx = $this->actor($request))) {
            return $this->unauthenticated();
        }
        if (!($document = $this->visible($ctx, $id))) {
            return $this->fail('Document not found.', 404);
        }

        $url = $this->storage->getTemporaryUrl($document->preview_path ?: $document->storage_path, 20);
        DocumentAuditService::log($document, 'preview', $ctx['user']->id);

        return response()->json(['status' => 1, 'preview_url' => $url, 'mime_type' => $document->mime_type]);
    }

    public function download($id, Request $request)
    {
        if (!($ctx = $this->actor($request))) {
            return $this->unauthenticated();
        }
        if (!($document = $this->visible($ctx, $id))) {
            return $this->fail('Document not found.', 404);
        }
        if (!$this->policy->download($ctx['user'], $document)) {
            return $this->fail('You are not allowed to download this document.', 403);
        }

        DocumentAuditService::log($document, 'download', $ctx['user']->id);

        return response()->json([
            'status' => 1,
            'download_url' => $this->storage->getTemporaryUrl($document->storage_path, 15),
            'file_name' => $document->original_file_name,
        ]);
    }

    public function addVersion($id, Request $request)
    {
        if (!($ctx = $this->actor($request))) {
            return $this->unauthenticated();
        }

        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:' . self::EXTENSIONS . '|max:' . config('idms.max_upload_size_kb', 51200),
            'change_note' => 'nullable|string|max:500',
        ]);
        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422);
        }

        if (!($document = $this->visible($ctx, $id))) {
            return $this->fail('Document not found.', 404);
        }
        if (!$this->policy->update($ctx['user'], $document)) {
            return $this->fail('You are not allowed to add a version to this document.', 403);
        }

        $stored = $this->storage->storeUpload($request->file('file'));
        $number = $document->current_version + 1;

        DB::beginTransaction();
        try {
            $document->fill([
                'current_version' => $number,
                'storage_path' => $stored['storage_path'],
                'checksum_sha256' => $stored['checksum_sha256'],
                'size' => $stored['size'],
                'mime_type' => $stored['mime_type'],
                'original_file_name' => $stored['original_file_name'],
                'processing_status' => 'pending',
            ])->save();

            DocumentAuditService::logVersion(
                $document, $number, $stored['storage_path'], $stored['checksum_sha256'], $stored['size'],
                $request->input('change_note', 'Version ' . $number), $ctx['user']->id
            );
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            report($e);

            return $this->fail('The version could not be added.', 500);
        }

        ProcessIdmsDocumentJob::dispatch($document->id, (int) $ctx['user']->id);

        return response()->json([
            'status' => 1,
            'message' => 'Version uploaded.',
            'current_version' => $number,
            'data' => new DocumentResource($document->fresh()),
        ]);
    }

    public function getVersions($id, Request $request)
    {
        if (!($ctx = $this->actor($request))) {
            return $this->unauthenticated();
        }
        if (!($document = $this->visible($ctx, $id))) {
            return $this->fail('Document not found.', 404);
        }

        $versions = DocumentHistory::where('document_id', $document->id)
            ->where('entry_type', 'version')
            ->orderBy('version_number', 'desc')
            ->get();

        return response()->json(['status' => 1, 'versions' => $versions]);
    }

    /** Restore writes a NEW version row; history is never deleted. */
    public function restoreVersion($id, $versionNumber, Request $request)
    {
        if (!($ctx = $this->actor($request))) {
            return $this->unauthenticated();
        }
        if (!($document = $this->visible($ctx, $id))) {
            return $this->fail('Document not found.', 404);
        }
        if (!$this->policy->update($ctx['user'], $document)) {
            return $this->fail('You are not allowed to restore versions of this document.', 403);
        }

        $target = DocumentHistory::where('document_id', $document->id)
            ->where('entry_type', 'version')
            ->where('version_number', (int) $versionNumber)
            ->first();
        if (!$target) {
            return $this->fail('Version not found.', 404);
        }

        $number = $document->current_version + 1;

        DB::beginTransaction();
        try {
            $document->fill([
                'current_version' => $number,
                'storage_path' => $target->storage_path,
                'checksum_sha256' => $target->checksum_sha256,
                'size' => $target->size,
            ])->save();

            DocumentAuditService::logVersion(
                $document, $number, $target->storage_path, $target->checksum_sha256, (int) $target->size,
                "Restored from version {$versionNumber}", $ctx['user']->id
            );
            DocumentAuditService::log($document, 'version_restored', $ctx['user']->id, [
                'restored_from' => (int) $versionNumber,
                'new_version' => $number,
            ]);
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            report($e);

            return $this->fail('The version could not be restored.', 500);
        }

        return response()->json([
            'status' => 1,
            'message' => "Version {$versionNumber} restored as version {$number}.",
            'current_version' => $number,
        ]);
    }

    public function related($id, Request $request)
    {
        if (!($ctx = $this->actor($request))) {
            return $this->unauthenticated();
        }
        if (!($document = $this->visible($ctx, $id))) {
            return $this->fail('Document not found.', 404);
        }

        $query = DocumentMaster::visibleTo($ctx['user'], $ctx['tenant'])
            ->where('document_master.id', '!=', $document->id)
            ->where('processing_status', 'done');

        if (!empty($document->tag_names)) {
            $query->where(function ($q) use ($document) {
                foreach ($document->tag_names as $tag) {
                    $q->orWhereRaw('JSON_CONTAINS(tag_names, ?)', [json_encode($tag)]);
                }
            });
        } elseif ($document->department_id) {
            $query->where('department_id', $document->department_id);
        } else {
            $query->whereRaw('1 = 0');
        }

        return response()->json([
            'status' => 1,
            'related' => DocumentResource::collection($query->limit(6)->get()),
        ]);
    }

    public function destroy($id, Request $request)
    {
        if (!($ctx = $this->actor($request))) {
            return $this->unauthenticated();
        }
        if (!($document = $this->visible($ctx, $id))) {
            return $this->fail('Document not found.', 404);
        }
        if (!$this->policy->delete($ctx['user'], $document)) {
            return $this->fail('You are not allowed to delete this document.', 403);
        }

        DocumentAuditService::log($document, 'delete', $ctx['user']->id);
        $document->delete();

        return response()->json(['status' => 1, 'message' => 'Document moved to trash.']);
    }

    public function trash(Request $request)
    {
        if (!($ctx = $this->actor($request))) {
            return $this->unauthenticated();
        }

        $days = (int) config('idms.trash_retention_days', 30);
        $docs = DocumentMaster::onlyTrashed()
            ->visibleTo($ctx['user'], $ctx['tenant'])
            ->where('document_master.deleted_at', '>=', now()->subDays($days))
            ->orderByDesc('document_master.deleted_at')
            ->limit(200)
            ->get()
            ->filter(fn ($d) => $this->policy->delete($ctx['user'], $d))
            ->values();

        return response()->json(['status' => 1, 'retention_days' => $days, 'data' => DocumentResource::collection($docs)]);
    }

    public function restore($id, Request $request)
    {
        if (!($ctx = $this->actor($request))) {
            return $this->unauthenticated();
        }
        if (!($document = $this->visible($ctx, $id, true))) {
            return $this->fail('Document not found in trash.', 404);
        }
        if (!$this->policy->delete($ctx['user'], $document)) {
            return $this->fail('You are not allowed to restore this document.', 403);
        }

        $days = (int) config('idms.trash_retention_days', 30);
        if ($document->deleted_at && $document->deleted_at->lt(now()->subDays($days))) {
            return $this->fail("This document was deleted more than {$days} days ago and can no longer be restored.", 410);
        }

        $document->restore();
        DocumentAuditService::log($document, 'restore', $ctx['user']->id);

        return response()->json(['status' => 1, 'message' => 'Document restored.', 'data' => new DocumentResource($document)]);
    }

    /** Delete forever: only from the trash. */
    public function purge($id, Request $request)
    {
        if (!($ctx = $this->actor($request))) {
            return $this->unauthenticated();
        }
        if (!($document = $this->visible($ctx, $id, true))) {
            return $this->fail('Document not found in trash.', 404);
        }
        if (!$this->policy->delete($ctx['user'], $document)) {
            return $this->fail('You are not allowed to delete this document.', 403);
        }

        $this->storage->deleteAllFiles($document);
        $document->forceDelete();

        return response()->json(['status' => 1, 'message' => 'Document deleted forever.']);
    }

    public function parseSearch(Request $request)
    {
        if (!($ctx = $this->actor($request))) {
            return $this->unauthenticated();
        }

        return response()->json([
            'status' => 1,
            'data' => $this->parser->parse(mb_substr((string) $request->input('query', ''), 0, 300), $ctx['tenant']),
        ]);
    }

    public function tree(Request $request)
    {
        if (!($ctx = $this->actor($request))) {
            return $this->unauthenticated();
        }

        return response()->json(['status' => 1, 'data' => $this->browse->getTree($ctx['user'], $ctx['tenant'])]);
    }

    public function tags(Request $request)
    {
        if (!($ctx = $this->actor($request))) {
            return $this->unauthenticated();
        }

        return response()->json(['status' => 1, 'data' => $this->browse->getTagCloud($ctx['user'], $ctx['tenant'])]);
    }

    /** Admin only, and limited to this tenant's documents. A document's own trail is available to anyone who can see it. */
    public function audit(Request $request)
    {
        if (!($ctx = $this->actor($request))) {
            return $this->unauthenticated();
        }

        $documentId = $request->input('document_id');
        $isAdmin = in_array((int) $ctx['user']->is_admin, [1, 2], true);

        if ($documentId) {
            if (!$this->visible($ctx, $documentId) && !($isAdmin && DocumentMaster::withTrashed()->where('sub_institute_id', $ctx['tenant'])->whereKey($documentId)->exists())) {
                return $this->fail('Document not found.', 404);
            }
        } elseif (!$isAdmin) {
            return $this->fail('Only administrators can read the full audit log.', 403);
        }

        $query = DocumentHistory::query()
            ->where('entry_type', 'audit')
            ->whereIn('document_id', DocumentMaster::withTrashed()->where('sub_institute_id', $ctx['tenant'])->select('id'))
            ->orderByDesc('created_at');

        if ($documentId) {
            $query->where('document_id', (int) $documentId);
        }
        if ($request->filled('action')) {
            $query->where('action', (string) $request->input('action'));
        }

        $logs = $query->paginate(30);

        return response()->json([
            'status' => 1,
            'data' => $logs->items(),
            'pagination' => [
                'current_page' => $logs->currentPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'last_page' => $logs->lastPage(),
            ],
        ]);
    }
}
