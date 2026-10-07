<?php

namespace App\Services\Documents;

use Illuminate\Support\Facades\DB;

/**
 * Drive's "Make a copy" — an independent storage object and a fresh
 * document_library/document_folders row, not a reference to the original.
 *
 * `DocumentStorageService::adopt()` already does exactly the file-bytes half
 * of this (built for federated-document adoption, structurally identical to
 * "duplicate this object"); this class is the row-shape half on top of it.
 *
 * Visibility always resets to 'private' on the copy - a duplicate does not
 * inherit the original's sharing, matching Drive's own behavior and keeping
 * "I made a copy to edit privately" actually private.
 */
class DocumentDuplicator
{
    public function __construct(private DocumentStorageService $storage)
    {
    }

    /**
     * $prefixTitle is false when this document is being carried along as
     * part of a folder duplication - matching Explorer, only the top-level
     * item being duplicated gets renamed; everything nested inside keeps its
     * original name.
     *
     * @return array{id:int}|null  null when the source object is missing from storage
     */
    public function duplicateDocument(object $source, int $actorId, ?int $destinationFolderId, bool $prefixTitle = true): ?array
    {
        $copy = $this->storage->adopt(
            $source->storage_path,
            $actorId,
            pathinfo($source->storage_path, PATHINFO_EXTENSION) ?: null
        );

        if ($copy === null) {
            return null;
        }

        $documentId = DB::table('document_library')->insertGetId([
            'sub_institute_id' => $source->sub_institute_id,
            'owner_id' => $actorId,
            'folder_id' => $destinationFolderId,
            'title' => $prefixTitle ? ('Copy of ' . $source->title) : $source->title,
            'title_source' => $source->title_source,
            'original_file_name' => $source->original_file_name,
            'mime_type' => $source->mime_type,
            'size' => $copy['size'],
            'checksum_sha256' => $copy['checksum_sha256'],
            'storage_path' => $copy['storage_path'],
            'current_version' => 1,
            'category' => $source->category,
            'document_type' => $source->document_type,
            'department_id' => $source->department_id,
            'document_date' => $source->document_date,
            'period_label' => $source->period_label,
            'subject' => $source->subject,
            'tags' => $source->tags,
            'keywords' => $source->keywords,
            // Identical bytes -> identical content, so the AI-derived fields
            // (OCR text, summary, keywords above) carry straight over rather
            // than re-running extraction/reasoning on a byte-for-byte copy.
            'extracted_text' => $source->extracted_text,
            'summary' => $source->summary,
            'confidence' => $source->confidence,
            'visibility' => 'private',
            'view_principals' => null,
            'processing_status' => $source->processing_status,
            'source_system' => $source->source_system,
            'created_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('document_library_history')->insert([
            'document_id' => $documentId,
            'entry_type' => 'version',
            'version_number' => 1,
            'storage_path' => $copy['storage_path'],
            'size' => $copy['size'],
            'checksum_sha256' => $copy['checksum_sha256'],
            'created_by' => $actorId,
            'created_at' => now(),
        ]);

        return ['id' => $documentId];
    }

    /**
     * Recursively duplicates a folder's visible contents for the acting
     * viewer - same tree-walk shape DocumentFolderController::tree() already
     * uses (one query per level via parent_id, not a recursive CTE). Items
     * the acting viewer cannot see are silently skipped, never surfaced as
     * an error - narrowing what gets copied, never widening what they may
     * read, same philosophy as every other DocumentAccess call site.
     *
     * @return array{folder_id:int, folders_copied:int, documents_copied:int}
     */
    public function duplicateFolder(object $source, int $actorId, int $tenantId, ?int $departmentId, ?int $destinationParentId, bool $isTopLevel = true): array
    {
        $newFolderId = DB::table('document_folders')->insertGetId([
            'sub_institute_id' => $source->sub_institute_id,
            'owner_id' => $actorId,
            'department_id' => $source->department_id,
            'parent_id' => $destinationParentId,
            'name' => $isTopLevel ? ('Copy of ' . $source->name) : $source->name,
            'visibility' => 'private',
            'created_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $foldersCopied = 1;
        $documentsCopied = 0;

        $childFoldersQuery = DB::table('document_folders')->where('parent_id', $source->id)->whereNull('deleted_at');
        $childFolders = DocumentAccess::visibleFoldersTo($childFoldersQuery, $actorId, $tenantId, $departmentId)->get();

        foreach ($childFolders as $childFolder) {
            $result = $this->duplicateFolder($childFolder, $actorId, $tenantId, $departmentId, $newFolderId, false);
            $foldersCopied += $result['folders_copied'];
            $documentsCopied += $result['documents_copied'];
        }

        $documentsQuery = DB::table('document_library')->where('folder_id', $source->id)->whereNull('deleted_at');
        $documents = DocumentAccess::visibleTo($documentsQuery, $actorId, $tenantId, $departmentId)->get();

        foreach ($documents as $document) {
            if ($this->duplicateDocument($document, $actorId, $newFolderId, false) !== null) {
                $documentsCopied++;
            }
        }

        return ['folder_id' => $newFolderId, 'folders_copied' => $foldersCopied, 'documents_copied' => $documentsCopied];
    }
}
