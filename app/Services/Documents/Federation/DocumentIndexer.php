<?php

namespace App\Services\Documents\Federation;

use Illuminate\Support\Facades\DB;

/**
 * Upserts ONE `document_library` row that INDEXES a document still owned by
 * another feature, rather than holding the file itself.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHY AN INDEX ROW, NOT A MIGRATED ONE
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Onboarding documents, competency evidence, task attachments, offboarding
 * documents and LMS certificates each already have a working feature around
 * them - their own upload, their own permission check, their own download
 * route. Moving the FILES into `document_library` would mean re-deciding
 * every one of those permission checks here, which is a much bigger and
 * riskier change than "let search find them too." So this writes a pointer:
 * `storage_path` stays NULL, `source_system`/`source_table`/`source_id`
 * say where the real thing is, and `DocumentLibraryController::download()`
 * already knows (since Phase 1) to hand back a 409 naming the source rather
 * than a 404, when it finds a row shaped like this.
 *
 * ── IDEMPOTENT BY THE SAME UNIQUE KEY THE STAFF_DOCUMENT BACKFILL USES ──────
 *
 * `document_library` has a UNIQUE constraint on
 * (`source_system`, `source_table`, `source_id`) - see that migration's
 * docblock. `index()` always `updateOrInsert`s on that triple, so calling it
 * twice for the same source row updates one record rather than creating a
 * duplicate - safe to call from a write path AND from a backfill command for
 * the same row.
 *
 * ── WHY source_id IS A STRING PARAMETER EVEN THOUGH THE COLUMN IS NUMERIC ──
 *
 * `document_library.source_id` is an unsignedBigInteger because every real
 * source row (onboarding document, evidence row, task document, LMS
 * certificate) has a normal auto-increment id. Offboarding is the one
 * exception - its "documents" are entries inside a JSON column on
 * `talent_offboarding_cases`, with no id of their own - so
 * `OffboardingDocumentIndexer` passes the CASE's numeric id as `$sourceId`
 * and folds the JSON entry's own key (`d1`..`d4`) into `$sourceTable`
 * instead (`'talent_offboarding_cases:d1'`), keeping the source triple
 * unique per checklist slot without a schema change for one silo's quirk.
 */
class DocumentIndexer
{
    /**
     * @param  array{
     *   sub_institute_id: int, owner_id: ?int, title: string, category: string,
     *   document_type: string, subject?: ?string, extracted_text: ?string, size: ?int,
     *   checksum_sha256: ?string, mime_type: ?string, document_date: ?string,
     *   visibility?: string
     * }  $attributes
     */
    public function index(string $sourceSystem, string $sourceTable, int $sourceId, array $attributes): int
    {
        $now = now();

        $row = [
            'sub_institute_id' => $attributes['sub_institute_id'],
            'owner_id' => $attributes['owner_id'] ?? null,
            'title' => $attributes['title'],
            'mime_type' => $attributes['mime_type'] ?? null,
            'size' => $attributes['size'] ?? null,
            'checksum_sha256' => $attributes['checksum_sha256'] ?? null,
            'category' => $attributes['category'],
            'document_type' => $attributes['document_type'],
            'subject' => $attributes['subject'] ?? null,
            'document_date' => $attributes['document_date'] ?? null,
            'extracted_text' => $attributes['extracted_text'] ?? null,
            'visibility' => $attributes['visibility'] ?? 'private',
            'processing_status' => 'done',
            // Never set for an index row - see this class's docblock.
            'storage_path' => null,
        ];

        $existing = DB::table('document_library')
            ->where('source_system', $sourceSystem)
            ->where('source_table', $sourceTable)
            ->where('source_id', $sourceId)
            ->first(['id']);

        if ($existing) {
            DB::table('document_library')->where('id', $existing->id)->update($row + ['updated_at' => $now]);

            return $existing->id;
        }

        return DB::table('document_library')->insertGetId($row + [
            'source_system' => $sourceSystem,
            'source_table' => $sourceTable,
            'source_id' => $sourceId,
            'current_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Soft-delete the index row when the source document is removed. Never
     * throws - an indexer call is always a best-effort mirror of the source
     * feature's own write, and the source write has already happened by the
     * time this runs (see each indexer's docblock on where it is called from).
     */
    public function remove(string $sourceSystem, string $sourceTable, int $sourceId): void
    {
        DB::table('document_library')
            ->where('source_system', $sourceSystem)
            ->where('source_table', $sourceTable)
            ->where('source_id', $sourceId)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now(), 'updated_at' => now()]);
    }
}
