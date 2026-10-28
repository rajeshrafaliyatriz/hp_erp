<?php

namespace App\Services\Documents;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Per-person FOLDER sharing grants - `DocumentShareService`'s twin, one
 * level up the tree. Real/dynamic sharing: a document added to a shared
 * folder AFTER it was shared is visible to those people immediately (see
 * DocumentLibraryController::fileDocument()'s own call into
 * DocumentAccess::effectiveViewPrincipalsForDocument() at upload time) -
 * this service's own job is only what happens when the FOLDER's shares
 * themselves change, which has to reach every document already sitting
 * under it (recursively, folders nest).
 */
class DocumentFolderShareService
{
    public function share(int $folderId, int $viewerUserId, bool $canEdit, int $subInstituteId, int $actorId): void
    {
        DB::table('document_folder_shares')->updateOrInsert(
            ['folder_id' => $folderId, 'viewer_user_id' => $viewerUserId],
            ['sub_institute_id' => $subInstituteId, 'can_edit' => $canEdit, 'created_by' => $actorId, 'updated_at' => now(), 'created_at' => now()]
        );

        $this->syncFolderPrincipals($folderId);
    }

    public function unshare(int $folderId, int $shareId): bool
    {
        $deleted = (bool) DB::table('document_folder_shares')
            ->where('id', $shareId)->where('folder_id', $folderId)->delete();

        if ($deleted) {
            $this->syncFolderPrincipals($folderId);
        }

        return $deleted;
    }

    /** Who this folder has been shared with - the "People with access" list in the Share-folder dialog. */
    public function listShares(int $folderId): Collection
    {
        return DB::table('document_folder_shares as s')
            ->leftJoin('tbluser as viewer', 'viewer.id', '=', 's.viewer_user_id')
            ->where('s.folder_id', $folderId)
            ->orderBy('s.id')
            ->get([
                's.id', 's.viewer_user_id', 's.can_edit',
                DB::raw("TRIM(CONCAT_WS(' ', viewer.first_name, viewer.middle_name, viewer.last_name)) as viewer_name"),
            ]);
    }

    /** Does this user have an active edit-level share on this folder? */
    public function canEdit(int $folderId, int $userId): bool
    {
        return DB::table('document_folder_shares')
            ->where('folder_id', $folderId)
            ->where('viewer_user_id', $userId)
            ->where('can_edit', true)
            ->exists();
    }

    /**
     * Called after every share()/unshare() on this folder - walks the
     * folder's entire subtree (same depth-first, per-level shape
     * DocumentFolderCascadeService's own walk uses) and, for every document
     * found anywhere under it, recomputes and persists view_principals via
     * DocumentAccess::effectiveViewPrincipalsForDocument() - the single
     * source of truth both this and DocumentShareService::syncViewPrincipals()
     * call, so a folder-level change and a document-level change never
     * disagree about a document's final principals.
     */
    public function syncFolderPrincipals(int $folderId): void
    {
        $this->walk($folderId);
    }

    private function walk(int $folderId): void
    {
        $childFolders = DB::table('document_folders')->where('parent_id', $folderId)->whereNull('deleted_at')->get(['id']);

        foreach ($childFolders as $child) {
            $this->walk($child->id);
        }

        $documentIds = DB::table('document_library')->where('folder_id', $folderId)->whereNull('deleted_at')->pluck('id');

        foreach ($documentIds as $documentId) {
            DB::table('document_library')->where('id', $documentId)->update([
                'view_principals' => json_encode(DocumentAccess::effectiveViewPrincipalsForDocument($documentId)),
            ]);
        }
    }
}
