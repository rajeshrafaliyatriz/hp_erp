<?php

namespace App\Services\Documents;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Deleting a folder used to require emptying it first (`DocumentFolderController::
 * destroy()`'s own prior 422 "Empty this folder before deleting it."). This is
 * the real cascade: soft-deletes the folder and everything under it -
 * recursively, since folders nest - moving every document into Trash the same
 * way `DocumentLibraryController::destroy()` already does for a single one.
 *
 * Same tree-walk shape `DocumentDuplicator::duplicateFolder()` already uses
 * (one query per level via `parent_id`, PHP recursion rather than a SQL
 * recursive CTE - the `live` connection is MariaDB 10.1.48, which has no
 * `WITH RECURSIVE`), but deliberately UNFILTERED by visibility or per-document
 * ownership, unlike that method's own `DocumentAccess::visibleTo()`-scoped
 * copy-walk: the folder-level `canManageFolder()` check the controller already
 * runs is the only authorization gate for this cascade, matching how moving or
 * renaming a folder already works today - whoever can manage the folder can
 * act on everything inside it.
 */
class DocumentFolderCascadeService
{
    /**
     * Descendants only (not the folder itself) - what the confirm dialog
     * shows the user before they commit.
     *
     * @return array{folders: int, documents: int}
     */
    public function countContents(int $folderId): array
    {
        $folders = 0;
        $documents = 0;

        $this->walk($folderId, function () use (&$folders) {
            $folders++;
        }, function () use (&$documents) {
            $documents++;
        });

        return ['folders' => $folders, 'documents' => $documents];
    }

    /**
     * Soft-deletes every folder and document in the subtree, plus the root
     * folder itself, inside one transaction - a mid-walk failure must not be
     * able to leave a half-deleted tree. Writes the same audit-log shape
     * `DocumentLibraryController::recordAudit()` does, per document.
     *
     * @return array{folders: int, documents: int} descendants moved, not counting the root folder
     */
    public function cascadeDelete(int $folderId, int $actorId, Request $request): array
    {
        $folders = 0;
        $documents = 0;
        $ip = mb_substr((string) $request->ip(), 0, 45);
        $now = now();

        DB::transaction(function () use ($folderId, $actorId, $ip, $now, &$folders, &$documents) {
            $this->walk($folderId, function (object $folder) use ($actorId, $now, &$folders) {
                DB::table('document_folders')->where('id', $folder->id)->update([
                    'deleted_by' => $actorId,
                    'deleted_at' => $now,
                ]);
                $folders++;
            }, function (object $document) use ($actorId, $ip, $now, &$documents) {
                DB::table('document_library')->where('id', $document->id)->update([
                    'deleted_by' => $actorId,
                    'deleted_at' => $now,
                ]);

                DB::table('document_library_history')->insert([
                    'document_id' => $document->id,
                    'entry_type' => 'audit',
                    'action' => 'deleted',
                    'details' => json_encode(['via' => 'folder_cascade_delete']),
                    'ip_address' => $ip,
                    'created_by' => $actorId,
                    'created_at' => $now,
                ]);

                $documents++;
            });

            DB::table('document_folders')->where('id', $folderId)->update([
                'deleted_by' => $actorId,
                'deleted_at' => $now,
            ]);
        });

        return ['folders' => $folders, 'documents' => $documents];
    }

    /**
     * Visits every descendant folder and document under $folderId, depth-first,
     * one level at a time - $onFolder/$onDocument decide what "visit" means
     * (count, or soft-delete) so both callers above share this single walk.
     */
    private function walk(int $folderId, callable $onFolder, callable $onDocument): void
    {
        $childFolders = DB::table('document_folders')->where('parent_id', $folderId)->whereNull('deleted_at')->get();

        foreach ($childFolders as $child) {
            $onFolder($child);
            $this->walk($child->id, $onFolder, $onDocument);
        }

        $documents = DB::table('document_library')->where('folder_id', $folderId)->whereNull('deleted_at')->get();

        foreach ($documents as $document) {
            $onDocument($document);
        }
    }
}
