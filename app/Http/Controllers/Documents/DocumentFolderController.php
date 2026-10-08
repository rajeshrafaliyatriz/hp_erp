<?php

namespace App\Http\Controllers\Documents;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use App\Services\Documents\DocumentAccess;
use App\Services\Documents\DocumentDuplicator;
use App\Services\Documents\DocumentStorageService;
use App\Support\SubjectAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * DRIVE-STYLE FOLDERS — create, browse, rename, move, delete, and the one
 * endpoint (`resolvePath`) that makes a recursive folder upload a single
 * round trip instead of N sequential folder-creates.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * THE TREE IS BUILT IN PHP, NOT SQL
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `tree()` mirrors `tblmenumasterG2gController::buildMenuTree()` exactly:
 * one query for every folder this caller may see, `groupBy('parent_id')`,
 * then a plain recursive walk. No recursive SQL CTE - there is no precedent
 * for one anywhere in this codebase, and the `live` connection's older
 * MariaDB is a reason to keep it that way.
 *
 * ── CYCLE SAFETY IS SERVER-SIDE, NOT JUST THE FRONTEND'S JOB ────────────────
 *
 * `move()` walks UP from the proposed new parent through its own ancestor
 * chain - if that walk ever reaches the folder being moved, the move is
 * rejected (422). The frontend ports the same `hasAncestor()` logic from
 * `department-list.tsx` for a responsive UI, but that check alone is not a
 * control; this is.
 */
class DocumentFolderController extends Controller
{
    use ResolvesApiIdentity;

    /** GET /api/documents/folders?parent_id= — one folder's direct contents: its subfolders and the documents inside it. Omitted/empty parent_id = root. */
    public function index(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $userId = (int) $identity['user_id'];
        $tenantId = (int) $identity['sub_institute_id'];
        $parentId = $request->filled('parent_id') ? (int) $request->input('parent_id') : null;
        $department = $this->callerDepartment($userId);

        if ($parentId !== null) {
            $parent = DB::table('document_folders')->where('id', $parentId)->whereNull('deleted_at')->first();

            if (!$parent || !DocumentAccess::canViewFolder($parent, $userId, $tenantId, $department)) {
                return $this->notFound();
            }
        }

        // An additional, advisory NARROWING filter - same treatment
        // DocumentSearchService::applyFilters() already gives documents: it
        // only restricts WHICH of the already-ACL-visible rows come back, it
        // never widens what a caller may see. The admin Department tab and
        // the self-service "My Department Documents" screen both pass this
        // to show one department's own folder space; the main /documents
        // page never passes it and sees exactly what it always has.
        $departmentFilter = $request->filled('department_id') ? (int) $request->input('department_id') : null;

        $foldersQuery = DB::table('document_folders')->where('parent_id', $parentId)->whereNull('deleted_at')
            ->when($departmentFilter, fn ($q) => $q->where('department_id', $departmentFilter));
        $folders = DocumentAccess::visibleFoldersTo($foldersQuery, $userId, $tenantId, $department)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name', 'parent_id', 'owner_id', 'department_id', 'visibility', 'sort_order', 'created_at']);

        $documentsQuery = DB::table('document_library')->where('folder_id', $parentId)->whereNull('deleted_at')
            ->when($departmentFilter, fn ($q) => $q->where('department_id', $departmentFilter));
        $documents = DocumentAccess::visibleTo($documentsQuery, $userId, $tenantId, $department)
            ->orderByDesc('created_at')
            ->get([
                'id', 'title', 'document_type', 'category', 'original_file_name',
                'mime_type', 'size', 'visibility', 'processing_status', 'processing_step',
                'source_system', 'document_date', 'created_at',
            ]);

        return response()->json(['status' => 1, 'data' => ['folders' => $folders, 'documents' => $documents]]);
    }

    /**
     * GET /api/documents/folders/tree — the whole visible tree in one call,
     * for the left-rail browser. See this class's docblock for why this is
     * one query + a PHP walk, not a recursive CTE.
     */
    public function tree(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $userId = (int) $identity['user_id'];
        $tenantId = (int) $identity['sub_institute_id'];
        $department = $this->callerDepartment($userId);

        // Same advisory narrowing as index() - see its comment.
        $departmentFilter = $request->filled('department_id') ? (int) $request->input('department_id') : null;

        $query = DB::table('document_folders')->whereNull('deleted_at')
            ->when($departmentFilter, fn ($q) => $q->where('department_id', $departmentFilter));
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

        return response()->json(['status' => 1, 'data' => $build(null)]);
    }

    /** POST /api/documents/folders — {name, parent_id?, visibility?}. */
    public function store(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        return $this->createFolder($request, (int) $identity['user_id'], (int) $identity['sub_institute_id'], null);
    }

    /**
     * POST /api/departments-management/{id}/documents/folders — file a
     * folder AS the caller, tagged to department {id} regardless of the
     * caller's own department. Route-gated profile:admin,hr; HR_ELEVATED
     * re-checked inline, same belt-and-suspenders shape as
     * DocumentLibraryController::storeForDepartment().
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

        return $this->createFolder($request, $actorId, $tenantId, $departmentId);
    }

    private function createFolder(Request $request, int $userId, int $tenantId, ?int $departmentOverride)
    {
        $data = $request->validate([
            'name' => 'required|string|max:191',
            'parent_id' => 'nullable|integer',
            'visibility' => 'nullable|string|in:private,department,organization',
        ]);

        $parentId = $data['parent_id'] ?? null;
        $department = $departmentOverride ?? $this->callerDepartment($userId);

        if ($parentId !== null) {
            $parent = DB::table('document_folders')->where('id', $parentId)->whereNull('deleted_at')->first();

            if (!$parent || !DocumentAccess::canManageFolder($parent, $userId, $tenantId, $department)) {
                return response()->json(['status' => 0, 'message' => 'That parent folder does not exist.'], 422);
            }
        }

        $id = DB::table('document_folders')->insertGetId([
            'sub_institute_id' => $tenantId,
            'owner_id' => $userId,
            'department_id' => $department,
            'parent_id' => $parentId,
            'name' => trim($data['name']),
            'visibility' => $data['visibility'] ?? 'private',
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['status' => 1, 'message' => 'Folder created.', 'data' => ['id' => $id]]);
    }

    /** PATCH /api/documents/folders/{id} — rename only, in v1. */
    public function update(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $userId = (int) $identity['user_id'];
        $tenantId = (int) $identity['sub_institute_id'];
        $department = $this->callerDepartment($userId);

        $folder = DB::table('document_folders')->where('id', (int) $id)->whereNull('deleted_at')->first();

        if (!$folder || !DocumentAccess::canManageFolder($folder, $userId, $tenantId, $department)) {
            return $this->notFound();
        }

        $data = $request->validate(['name' => 'required|string|max:191']);

        DB::table('document_folders')->where('id', $folder->id)->update([
            'name' => trim($data['name']),
            'updated_by' => $userId,
            'updated_at' => now(),
        ]);

        return response()->json(['status' => 1, 'message' => 'Folder renamed.']);
    }

    /** POST /api/documents/folders/{id}/move — {parent_id}. Server-side cycle guard - see this class's docblock. */
    public function move(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $userId = (int) $identity['user_id'];
        $tenantId = (int) $identity['sub_institute_id'];
        $department = $this->callerDepartment($userId);
        $folderId = (int) $id;

        $folder = DB::table('document_folders')->where('id', $folderId)->whereNull('deleted_at')->first();

        if (!$folder || !DocumentAccess::canManageFolder($folder, $userId, $tenantId, $department)) {
            return $this->notFound();
        }

        $data = $request->validate(['parent_id' => 'nullable|integer']);
        $newParentId = $data['parent_id'] ?? null;

        if ($newParentId !== null) {
            if ($newParentId === $folderId) {
                return response()->json(['status' => 0, 'message' => 'A folder cannot be moved into itself.'], 422);
            }

            $newParent = DB::table('document_folders')->where('id', $newParentId)->whereNull('deleted_at')->first();

            if (!$newParent || !DocumentAccess::canManageFolder($newParent, $userId, $tenantId, $department)) {
                return response()->json(['status' => 0, 'message' => 'That destination folder does not exist.'], 422);
            }

            if ($this->isDescendant($folderId, $newParentId)) {
                return response()->json(['status' => 0, 'message' => 'A folder cannot be moved into its own subfolder.'], 422);
            }
        }

        DB::table('document_folders')->where('id', $folder->id)->update([
            'parent_id' => $newParentId,
            'updated_by' => $userId,
            'updated_at' => now(),
        ]);

        return response()->json(['status' => 1, 'message' => 'Folder moved.']);
    }

    /**
     * POST /api/documents/folders/{id}/duplicate — Drive's "Make a copy" for
     * a whole folder tree. {destination_parent_id?}. Gated on canViewFolder()
     * (you can copy anything shared with you), not canManageFolder() - a
     * duplicate never touches the original. Same cycle guard move() already
     * has: a folder can't be duplicated into its own subtree either, since
     * the recursive walk below would otherwise copy the destination into
     * itself as it goes. See DocumentDuplicator::duplicateFolder() for how
     * contents the acting viewer can't see are silently skipped.
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
        $folderId = (int) $id;

        $folder = DB::table('document_folders')->where('id', $folderId)->whereNull('deleted_at')->first();

        if (!$folder || !DocumentAccess::canViewFolder($folder, $userId, $tenantId, $department)) {
            return $this->notFound();
        }

        $data = $request->validate(['destination_parent_id' => 'sometimes|nullable|integer']);
        $destinationParentId = null;

        if (!empty($data['destination_parent_id'])) {
            $destinationParentId = (int) $data['destination_parent_id'];

            if ($destinationParentId === $folderId) {
                return response()->json(['status' => 0, 'message' => 'A folder cannot be duplicated into itself.'], 422);
            }

            $destinationParent = DB::table('document_folders')->where('id', $destinationParentId)->whereNull('deleted_at')->first();

            if (!$destinationParent || !DocumentAccess::canManageFolder($destinationParent, $userId, $tenantId, $department)) {
                return response()->json(['status' => 0, 'message' => 'That destination folder does not exist.'], 422);
            }

            if ($this->isDescendant($folderId, $destinationParentId)) {
                return response()->json(['status' => 0, 'message' => 'A folder cannot be duplicated into its own subfolder.'], 422);
            }
        }

        $duplicator = new DocumentDuplicator(new DocumentStorageService());
        $result = $duplicator->duplicateFolder($folder, $userId, $tenantId, $department, $destinationParentId);

        return response()->json(['status' => 1, 'message' => 'Folder duplicated.', 'data' => $result]);
    }

    /**
     * DELETE /api/documents/folders/{id} — soft-delete, refusing (422) a
     * non-empty folder rather than orphaning its contents. See this table's
     * migration docblock for why v1 stops there (no cascading delete, no
     * folder-trash restore yet).
     */
    public function destroy(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $userId = (int) $identity['user_id'];
        $tenantId = (int) $identity['sub_institute_id'];
        $department = $this->callerDepartment($userId);
        $folderId = (int) $id;

        $folder = DB::table('document_folders')->where('id', $folderId)->whereNull('deleted_at')->first();

        if (!$folder || !DocumentAccess::canManageFolder($folder, $userId, $tenantId, $department)) {
            return $this->notFound();
        }

        $hasChildFolders = DB::table('document_folders')->where('parent_id', $folderId)->whereNull('deleted_at')->exists();
        $hasDocuments = DB::table('document_library')->where('folder_id', $folderId)->whereNull('deleted_at')->exists();

        if ($hasChildFolders || $hasDocuments) {
            return response()->json(['status' => 0, 'message' => 'Empty this folder before deleting it.'], 422);
        }

        DB::table('document_folders')->where('id', $folder->id)->update([
            'deleted_by' => $userId,
            'deleted_at' => now(),
        ]);

        return response()->json(['status' => 1, 'message' => 'Folder removed.']);
    }

    /**
     * POST /api/documents/folders/resolve-path — {paths: string[], parent_id?}.
     * Find-or-create every segment of every relative path (e.g.
     * "2024/Payroll" creates/finds "2024", then "Payroll" under it),
     * returning {path => folder_id} for all of them. One round trip for an
     * entire dropped directory tree, idempotent on retry (a repeated path
     * resolves to the same folder, never a duplicate).
     */
    public function resolvePath(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        return $this->resolveFolderPaths($request, (int) $identity['user_id'], (int) $identity['sub_institute_id'], null);
    }

    /**
     * POST /api/departments-management/{id}/documents/folders/resolve-path —
     * the resolve-path twin of storeForDepartment(): a recursive/zip folder
     * upload from the admin Department tab needs every auto-created folder
     * tagged to the department being viewed too, not just a single
     * POST .../folders call.
     */
    public function resolvePathForDepartment(Request $request, $id)
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

        return $this->resolveFolderPaths($request, $actorId, $tenantId, $departmentId);
    }

    private function resolveFolderPaths(Request $request, int $userId, int $tenantId, ?int $departmentOverride)
    {
        $data = $request->validate([
            'paths' => 'required|array|min:1',
            'paths.*' => 'required|string|max:1000',
            'parent_id' => 'nullable|integer',
            'visibility' => 'nullable|string|in:private,department,organization',
        ]);

        $rootParentId = $data['parent_id'] ?? null;
        $department = $departmentOverride ?? $this->callerDepartment($userId);

        if ($rootParentId !== null) {
            $root = DB::table('document_folders')->where('id', $rootParentId)->whereNull('deleted_at')->first();

            if (!$root || !DocumentAccess::canManageFolder($root, $userId, $tenantId, $department)) {
                return response()->json(['status' => 0, 'message' => 'That parent folder does not exist.'], 422);
            }
        }

        // Cache within this request - "2024" is found-or-created once even
        // if ten different paths all start with "2024/...".
        $resolved = []; // "parent_id:segment" => folder_id
        $result = [];

        foreach ($data['paths'] as $path) {
            $segments = array_values(array_filter(explode('/', str_replace('\\', '/', $path)), fn ($s) => trim($s) !== ''));
            $parentId = $rootParentId;

            foreach ($segments as $segment) {
                $segment = trim($segment);
                $cacheKey = ($parentId ?? 'root') . ':' . $segment;

                if (isset($resolved[$cacheKey])) {
                    $parentId = $resolved[$cacheKey];

                    continue;
                }

                $existing = DB::table('document_folders')
                    ->where('sub_institute_id', $tenantId)
                    ->where('owner_id', $userId)
                    ->where('parent_id', $parentId)
                    ->where('name', $segment)
                    ->whereNull('deleted_at')
                    ->value('id');

                // `$data['visibility']` was unconditionally 'private' here -
                // meaning every folder created by a recursive/zip upload was
                // invisible to anyone but its uploader regardless of the
                // caller's chosen visibility (or now, department). Fixed to
                // actually honor it, same default when omitted.
                $folderId = $existing ?: DB::table('document_folders')->insertGetId([
                    'sub_institute_id' => $tenantId,
                    'owner_id' => $userId,
                    'department_id' => $department,
                    'parent_id' => $parentId,
                    'name' => $segment,
                    'visibility' => $data['visibility'] ?? 'private',
                    'created_by' => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $resolved[$cacheKey] = $folderId;
                $parentId = $folderId;
            }

            $result[$path] = $parentId;
        }

        return response()->json(['status' => 1, 'data' => $result]);
    }

    /** Does walking UP from $candidateId's own parent chain ever reach $folderId? Ports department-list.tsx's hasAncestor() to PHP - the frontend's copy is for responsiveness, this one is the actual control. */
    private function isDescendant(int $folderId, int $candidateId, array $seen = []): bool
    {
        if (in_array($candidateId, $seen, true)) {
            return false; // pre-existing cycle in bad data - stop, don't loop forever.
        }

        $parentId = DB::table('document_folders')->where('id', $candidateId)->value('parent_id');

        if ($parentId === null) {
            return false;
        }

        if ((int) $parentId === $folderId) {
            return true;
        }

        return $this->isDescendant($folderId, (int) $parentId, [...$seen, $candidateId]);
    }

    private function callerDepartment(int $userId): ?int
    {
        $value = DB::table('tbluser')->where('id', $userId)->value('department_id');

        return $value ? (int) $value : null;
    }

    /** 404 for both "doesn't exist" and "not yours" — never 403, matching DocumentLibraryController's own convention. */
    private function notFound()
    {
        return response()->json(['status' => 0, 'message' => 'That folder was not found.'], 404);
    }
}
