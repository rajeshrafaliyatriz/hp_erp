<?php

namespace App\Http\Controllers\Documents;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use App\Services\Documents\DocumentAccess;
use App\Services\Documents\Search\DocumentSearchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * "My Department Documents" — a plain employee's self-service view of their
 * OWN department's shared document space.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHY THIS IS A SEPARATE CONTROLLER, NOT A PARAMETER ON THE ADMIN ONE
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Every endpoint here takes NO department id from the request - it is always
 * derived server-side from the caller's own `tbluser.department_id` via
 * `callerDepartment()`. A client-supplied value, if one is ever sent, is
 * simply never read (not validated, not refused) - this is this codebase's
 * established self-service shape (`MyCertificationsController`,
 * `MyCapabilityController`, `MyRatingController`): "an endpoint that accepts
 * no department_id cannot be made to return somebody else's department."
 * Reusing `DocumentFolderController`'s/`DocumentLibraryController`'s own
 * admin-facing endpoints from a self-service screen (even with a client id
 * "ignored" on the frontend) would still be a tenant-wide-scoped endpoint
 * one keystroke away from leaking a colleague's department; this is a
 * genuinely different, narrower endpoint instead.
 *
 * Writes (upload, folder create/move/rename/delete) need NO new endpoints
 * here at all: the existing `/account/documents` and `/documents/folders`
 * routes already resolve `department_id` from `callerDepartment()`
 * unconditionally, so an employee's own upload through this screen already
 * tags correctly through those unchanged routes.
 */
class MyDepartmentDocumentsController extends Controller
{
    use ResolvesApiIdentity;

    /** GET /api/account/department-documents */
    public function index(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $userId = (int) $identity['user_id'];
        $tenantId = (int) $identity['sub_institute_id'];
        $department = $this->callerDepartment($userId);

        if ($department === null) {
            // Not an error - some employees simply have no department set.
            // An empty, well-formed result lets the screen say so plainly
            // rather than surfacing a 4xx for a perfectly valid account state.
            return response()->json([
                'status' => 1,
                'data' => [],
                'meta' => ['total' => 0, 'page' => 1, 'per_page' => 24],
                'document_types' => config('documents.types'),
                'department_id' => null,
            ]);
        }

        $page = max(1, (int) $request->input('page', 1));
        $perPage = min(100, max(1, (int) $request->input('per_page', 24)));

        // department_id is DELIBERATELY not in this list - see class docblock.
        $filters = $request->only(['q', 'category', 'document_type', 'source_system', 'date_from', 'date_to', 'folder_id']);
        $filters['department_id'] = $department;

        $result = (new DocumentSearchService())->search($filters, $tenantId, $userId, $department, $page, $perPage);

        return response()->json([
            'status' => 1,
            'data' => $result['data'],
            'meta' => ['total' => $result['total'], 'page' => $page, 'per_page' => $perPage],
            'document_types' => config('documents.types'),
            'department_id' => $department,
        ]);
    }

    /** GET /api/account/department-documents/folders?parent_id= */
    public function folderIndex(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $userId = (int) $identity['user_id'];
        $tenantId = (int) $identity['sub_institute_id'];
        $department = $this->callerDepartment($userId);

        if ($department === null) {
            return response()->json(['status' => 1, 'data' => ['folders' => [], 'documents' => []], 'department_id' => null]);
        }

        $parentId = $request->filled('parent_id') ? (int) $request->input('parent_id') : null;

        if ($parentId !== null) {
            $parent = DB::table('document_folders')->where('id', $parentId)->whereNull('deleted_at')->first();

            if (!$parent || !DocumentAccess::canViewFolder($parent, $userId, $tenantId, $department)) {
                return response()->json(['status' => 0, 'message' => 'That folder was not found.'], 404);
            }
        }

        $foldersQuery = DB::table('document_folders')->where('parent_id', $parentId)->whereNull('deleted_at')
            ->where('department_id', $department);
        $folders = DocumentAccess::visibleFoldersTo($foldersQuery, $userId, $tenantId, $department)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name', 'parent_id', 'owner_id', 'department_id', 'visibility', 'sort_order', 'created_at']);

        $documentsQuery = DB::table('document_library')->where('folder_id', $parentId)->whereNull('deleted_at')
            ->where('department_id', $department);
        $documents = DocumentAccess::visibleTo($documentsQuery, $userId, $tenantId, $department)
            ->orderByDesc('created_at')
            ->get([
                'id', 'title', 'document_type', 'category', 'original_file_name',
                'mime_type', 'size', 'visibility', 'processing_status', 'processing_step',
                'source_system', 'document_date', 'created_at',
            ]);

        return response()->json(['status' => 1, 'data' => ['folders' => $folders, 'documents' => $documents], 'department_id' => $department]);
    }

    /** GET /api/account/department-documents/folders/tree */
    public function folderTree(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $userId = (int) $identity['user_id'];
        $tenantId = (int) $identity['sub_institute_id'];
        $department = $this->callerDepartment($userId);

        if ($department === null) {
            return response()->json(['status' => 1, 'data' => [], 'department_id' => null]);
        }

        $query = DB::table('document_folders')->whereNull('deleted_at')->where('department_id', $department);
        $all = DocumentAccess::visibleFoldersTo($query, $userId, $tenantId, $department)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name', 'parent_id', 'owner_id', 'department_id', 'visibility']);

        $byParent = $all->groupBy(fn ($f) => $f->parent_id ?? 0);

        $build = function ($parentId) use (&$build, $byParent) {
            return ($byParent->get($parentId ?? 0) ?? collect())->map(function ($folder) use (&$build) {
                return [
                    'id' => $folder->id,
                    'name' => $folder->name,
                    'parent_id' => $folder->parent_id,
                    'owner_id' => $folder->owner_id,
                    'department_id' => $folder->department_id,
                    'visibility' => $folder->visibility,
                    'children' => $build($folder->id),
                ];
            })->values();
        };

        return response()->json(['status' => 1, 'data' => $build(null), 'department_id' => $department]);
    }

    private function callerDepartment(int $userId): ?int
    {
        $value = DB::table('tbluser')->where('id', $userId)->value('department_id');

        return $value ? (int) $value : null;
    }
}
