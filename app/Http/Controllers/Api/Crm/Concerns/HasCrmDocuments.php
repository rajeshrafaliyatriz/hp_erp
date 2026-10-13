<?php

namespace App\Http\Controllers\Api\Crm\Concerns;

use App\Services\Documents\DocumentStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Generalizes CrmTicketController's own comment-thread precedent
 * (getComments()/addComment()/commentResource() - NOT touched by this trait,
 * Ticket keeps its own working implementation) to a different cross-cutting
 * concern: letting ANY of this phase's 5 "hot" entities (Lead/Contact/
 * Organization/Opportunity/Ticket) have files attached, by reusing the
 * existing, already-shipped Document Library (`document_library` +
 * `DocumentStorageService`) rather than building a parallel attachment/
 * storage system per entity.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHY A NEW JOIN TABLE, NOT source_system/source_table/source_id
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `document_library`'s own federated-index columns carry a strict
 * UNIQUE(source_system, source_table, source_id) and are a 1:1 POINTER to a
 * file that still physically lives in another feature's own table (see
 * `DocumentIndexer`'s own docblock) - the wrong shape for "attach N files to
 * one CRM record", which is a genuine 1:many relationship. A CRM upload here
 * is a true NATIVE `document_library` row (a real `storage_path`, written via
 * the exact same `DocumentStorageService` every other native upload in this
 * codebase goes through) with source_system/source_table/source_id left
 * fully NULL, linked to its CRM record through the new
 * `document_library_links` join table instead - append-only, no
 * soft-deletes, unique on (document_id, linked_type, linked_id).
 *
 * ── VISIBILITY ──────────────────────────────────────────────────────────────
 * Every document this trait creates is written `visibility = 'organization'`
 * (and `category = 'organization'`, document_library's other enum value)
 * deliberately, not by oversight: a CRM record is routinely worked by more
 * than one person in the same tenant, and DocumentAccess::canView()'s
 * 'private' default would otherwise let only the uploader themselves ever
 * open a file attached to a shared Lead/Contact/Organization/Opportunity/
 * Ticket. Tenant scope (`sub_institute_id`) is the real boundary here, same
 * as everywhere else in this subsystem.
 *
 * ── GENERIC OVER 2 HOST METHODS, LIKE EVERY OTHER TRAIT IN THIS FOLDER ──────
 * `documentsLinkedType(): string` - the UPPERCASE SINGULAR constant for this
 * controller's own entity ('LEAD', 'CONTACT', 'ORGANIZATION', 'OPPORTUNITY',
 * 'TICKET' - matching CalendarController's PROJECT/WORKSTREAM/TASK/
 * BACKLOG_ITEM casing convention for this whole phase's new polymorphic
 * columns, even though `document_library_links.linked_type` is a wholly
 * separate column/table from Calendar's own `linked_type`).
 * `ownRow(int $tenantId, int $id): ?object` - the same tenant-scoped,
 * not-soft-deleted single-row lookup update()/destroy() already use on every
 * host controller. Reused here rather than re-implemented, so a document
 * attached to someone else's tenant's record 404s exactly the way editing
 * that record already would. Already exists on
 * Contact/Organization/Opportunity/Ticket; CrmLeadController has no such
 * method yet (its show()/update()/destroy() inline the same query instead) -
 * see this phase's integration report for the one extra method Lead alone
 * needs to add.
 */
trait HasCrmDocuments
{
    /** GET {prefix}/{id}/documents */
    public function getDocuments(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Record not found.'], 404);
        }

        $rows = DB::table('document_library_links as l')
            ->join('document_library as d', 'd.id', '=', 'l.document_id')
            ->leftJoin('tbluser as u', 'u.id', '=', 'l.created_by')
            ->where('l.linked_type', $this->documentsLinkedType())
            ->where('l.linked_id', $id)
            ->where('l.sub_institute_id', $identity['sub_institute_id'])
            ->whereNull('d.deleted_at')
            ->orderByDesc('l.created_at')
            ->select(
                'd.id', 'd.title', 'd.original_file_name', 'd.mime_type', 'd.size', 'd.created_at',
                'l.created_by', DB::raw("TRIM(CONCAT(u.first_name, ' ', u.last_name)) as created_by_name"),
            )
            ->get();

        return response()->json([
            'status' => 1,
            'message' => 'Documents.',
            'data' => $rows->map(fn ($row) => $this->documentResource($row))->all(),
        ]);
    }

    /** POST {prefix}/{id}/documents - multipart, field name `document` (same convention as every other native document_library writer). */
    public function attachDocument(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Record not found.'], 404);
        }

        $allowedExtensions = (string) config('documents.allowed_extensions');
        $maxKb = (int) config('documents.max_upload_kb');

        $validator = Validator::make($request->all(), [
            'document' => 'required|file|mimes:' . $allowedExtensions . '|max:' . $maxKb,
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $file = $request->file('document');
        $storage = new DocumentStorageService();
        $stored = $storage->storeUpload($file, $identity['user_id']);

        // Falls back to the file's own name, same default rule
        // DocumentLibraryController::fileDocument() already applies - no
        // title input on this tab (see crm-document-tab.tsx), every CRM
        // attachment is filename-titled.
        $title = mb_substr(pathinfo((string) $stored['original_file_name'], PATHINFO_FILENAME), 0, 191);

        $linkedType = $this->documentsLinkedType();

        $documentId = DB::transaction(function () use ($stored, $title, $identity, $id, $linkedType) {
            $documentId = DB::table('document_library')->insertGetId([
                'sub_institute_id' => $identity['sub_institute_id'],
                'owner_id' => $identity['user_id'],
                'title' => $title,
                'title_source' => 'filename',
                'original_file_name' => $stored['original_file_name'],
                'mime_type' => $stored['mime_type'],
                'size' => $stored['size'],
                'checksum_sha256' => $stored['checksum_sha256'],
                'storage_path' => $stored['storage_path'],
                'current_version' => 1,
                'category' => 'organization',
                'document_type' => 'CRM Attachment',
                'visibility' => 'organization',
                'processing_status' => 'done',
                'created_by' => $identity['user_id'],
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
                'created_by' => $identity['user_id'],
                'created_at' => now(),
            ]);

            DB::table('document_library_links')->insert([
                'document_id' => $documentId,
                'linked_type' => $linkedType,
                'linked_id' => $id,
                'sub_institute_id' => $identity['sub_institute_id'],
                'created_by' => $identity['user_id'],
                'created_at' => now(),
            ]);

            return $documentId;
        });

        $row = DB::table('document_library_links as l')
            ->join('document_library as d', 'd.id', '=', 'l.document_id')
            ->leftJoin('tbluser as u', 'u.id', '=', 'l.created_by')
            ->where('d.id', $documentId)
            ->where('l.linked_type', $linkedType)
            ->where('l.linked_id', $id)
            ->select(
                'd.id', 'd.title', 'd.original_file_name', 'd.mime_type', 'd.size', 'd.created_at',
                'l.created_by', DB::raw("TRIM(CONCAT(u.first_name, ' ', u.last_name)) as created_by_name"),
            )
            ->first();

        return response()->json(['status' => 1, 'message' => 'Document attached.', 'data' => $this->documentResource($row)], 201);
    }

    /** @return array<string, mixed> */
    private function documentResource(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'title' => $row->title,
            'originalFileName' => $row->original_file_name,
            'mimeType' => $row->mime_type,
            'size' => $row->size !== null ? (int) $row->size : null,
            'uploadedBy' => $row->created_by ? (string) $row->created_by : null,
            'uploadedByName' => ($row->created_by_name ?? '') !== '' ? $row->created_by_name : null,
            'createdAt' => $row->created_at,
        ];
    }
}
