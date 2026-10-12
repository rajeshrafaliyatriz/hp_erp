<?php

namespace App\Services\Documents;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Per-person document sharing grants - the write side of `view_principals`
 * (see the document_library_shares migration's own docblock: the `user:{id}`
 * shape it writes into that column has been read by DocumentAccess since
 * document_library's first migration, just never populated by anything
 * until now).
 *
 * Shaped after CalendarShareService: one upsert-based share()/unshare() pair
 * over a dedicated pivot table, plus a list read for the owner/admin's own
 * "People with access" view. There is no `incoming()` twin here the way
 * Calendar sharing has one - a shared document simply appears in the
 * recipient's ordinary document list via DocumentAccess::visibleTo(), it
 * doesn't need a second, separate "feed" concept.
 */
class DocumentShareService
{
    public function share(int $documentId, int $viewerUserId, bool $canEdit, int $subInstituteId, int $actorId): void
    {
        DB::table('document_library_shares')->updateOrInsert(
            ['document_id' => $documentId, 'viewer_user_id' => $viewerUserId],
            ['sub_institute_id' => $subInstituteId, 'can_edit' => $canEdit, 'created_by' => $actorId, 'updated_at' => now(), 'created_at' => now()]
        );

        $this->syncViewPrincipals($documentId);
    }

    public function unshare(int $documentId, int $shareId): bool
    {
        $deleted = (bool) DB::table('document_library_shares')
            ->where('id', $shareId)->where('document_id', $documentId)->delete();

        if ($deleted) {
            $this->syncViewPrincipals($documentId);
        }

        return $deleted;
    }

    /** Who this document has been shared with - the owner/admin's "People with access" list. */
    public function listShares(int $documentId): Collection
    {
        return DB::table('document_library_shares as s')
            ->leftJoin('tbluser as viewer', 'viewer.id', '=', 's.viewer_user_id')
            ->where('s.document_id', $documentId)
            ->orderBy('s.id')
            ->get([
                's.id', 's.viewer_user_id', 's.can_edit',
                DB::raw("TRIM(CONCAT_WS(' ', viewer.first_name, viewer.middle_name, viewer.last_name)) as viewer_name"),
            ]);
    }

    /** Does this user have an active edit-level share on this document? */
    public function canEdit(int $documentId, int $userId): bool
    {
        return DB::table('document_library_shares')
            ->where('document_id', $documentId)
            ->where('viewer_user_id', $userId)
            ->where('can_edit', true)
            ->exists();
    }

    /**
     * Rebuilds and persists view_principals from this document's CURRENT
     * visibility/department (unchanged by a share/unshare) plus its current
     * share list (just changed) - called after every share()/unshare(), and
     * also by DocumentLibraryController::update() after a visibility change,
     * so the three things that can add or remove a principal never drift out
     * of sync with each other.
     */
    public function syncViewPrincipals(int $documentId): void
    {
        $document = DB::table('document_library')->where('id', $documentId)->first(['visibility', 'department_id']);

        if (!$document) {
            return;
        }

        $sharedUserIds = DB::table('document_library_shares')
            ->where('document_id', $documentId)->pluck('viewer_user_id')->all();

        $principals = DocumentAccess::computePrincipalsForDocument(
            (string) $document->visibility,
            $document->department_id !== null ? (int) $document->department_id : null,
            $sharedUserIds
        );

        DB::table('document_library')->where('id', $documentId)->update([
            'view_principals' => json_encode($principals),
        ]);
    }
}
