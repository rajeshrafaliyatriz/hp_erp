<?php

namespace App\Http\Controllers\Documents;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use App\Services\Documents\DocumentAccess;
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

        if ($parentId !== null) {
            $parent = DB::table('document_folders')->where('id', $parentId)->whereNull('deleted_at')->first();

            if (!$parent || !DocumentAccess::canViewFolder($parent, $userId, $tenantId)) {
                return $this->notFound();
            }
        }

        $department = $this->callerDepartment($userId);

        $foldersQuery = DB::table('document_folders')->where('parent_id', $parentId)->whereNull('deleted_at');
        $folders = DocumentAccess::visibleFoldersTo($foldersQuery, $userId, $tenantId)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name', 'parent_id', 'owner_id', 'visibility', 'sort_order', 'created_at']);

        $documentsQuery = DB::table('document_library')->where('folder_id', $parentId)->whereNull('deleted_at');
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

        $query = DB::table('document_folders')->whereNull('deleted_at');
        $all = DocumentAccess::visibleFoldersTo($query, $userId, $tenantId)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name', 'parent_id', 'owner_id', 'visibility']);

        $byParent = $all->groupBy(fn ($f) => $f->parent_id ?? 0);

        $build = function ($parentId) use (&$build, $byParent) {
            return ($byParent->get($parentId ?? 0) ?? collect())->map(function ($folder) use (&$build) {
                return [
                    'id' => $folder->id,
                    'name' => $folder->name,
                    'parent_id' => $folder->parent_id,
                    'owner_id' => $folder->owner_id,
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

        $userId = (int) $identity['user_id'];
        $tenantId = (int) $identity['sub_institute_id'];

        $data = $request->validate([
            'name' => 'required|string|max:191',
            'parent_id' => 'nullable|integer',
            'visibility' => 'nullable|string|in:private,department,organization',
        ]);

        $parentId = $data['parent_id'] ?? null;

        if ($parentId !== null) {
            $parent = DB::table('document_folders')->where('id', $parentId)->whereNull('deleted_at')->first();

            if (!$parent || !DocumentAccess::canManageFolder($parent, $userId, $tenantId)) {
                return response()->json(['status' => 0, 'message' => 'That parent folder does not exist.'], 422);
            }
        }

        $id = DB::table('document_folders')->insertGetId([
            'sub_institute_id' => $tenantId,
            'owner_id' => $userId,
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

        $folder = DB::table('document_folders')->where('id', (int) $id)->whereNull('deleted_at')->first();

        if (!$folder || !DocumentAccess::canManageFolder($folder, $userId, $tenantId)) {
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
        $folderId = (int) $id;

        $folder = DB::table('document_folders')->where('id', $folderId)->whereNull('deleted_at')->first();

        if (!$folder || !DocumentAccess::canManageFolder($folder, $userId, $tenantId)) {
            return $this->notFound();
        }

        $data = $request->validate(['parent_id' => 'nullable|integer']);
        $newParentId = $data['parent_id'] ?? null;

        if ($newParentId !== null) {
            if ($newParentId === $folderId) {
                return response()->json(['status' => 0, 'message' => 'A folder cannot be moved into itself.'], 422);
            }

            $newParent = DB::table('document_folders')->where('id', $newParentId)->whereNull('deleted_at')->first();

            if (!$newParent || !DocumentAccess::canManageFolder($newParent, $userId, $tenantId)) {
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
        $folderId = (int) $id;

        $folder = DB::table('document_folders')->where('id', $folderId)->whereNull('deleted_at')->first();

        if (!$folder || !DocumentAccess::canManageFolder($folder, $userId, $tenantId)) {
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

        $userId = (int) $identity['user_id'];
        $tenantId = (int) $identity['sub_institute_id'];

        $data = $request->validate([
            'paths' => 'required|array|min:1',
            'paths.*' => 'required|string|max:1000',
            'parent_id' => 'nullable|integer',
        ]);

        $rootParentId = $data['parent_id'] ?? null;

        if ($rootParentId !== null) {
            $root = DB::table('document_folders')->where('id', $rootParentId)->whereNull('deleted_at')->first();

            if (!$root || !DocumentAccess::canManageFolder($root, $userId, $tenantId)) {
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

                $folderId = $existing ?: DB::table('document_folders')->insertGetId([
                    'sub_institute_id' => $tenantId,
                    'owner_id' => $userId,
                    'parent_id' => $parentId,
                    'name' => $segment,
                    'visibility' => 'private',
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
