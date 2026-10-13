<?php

namespace App\Services\Documents\Federation;

use App\Services\Documents\Federation\Concerns\ExtractsSourceFileText;
use Illuminate\Support\Facades\DB;

/**
 * Indexes `talent_onboarding_documents` rows so they turn up in Document
 * Library search. Nothing about `OnboardingDocumentController`'s own
 * storage, upload, or permission logic changes - see `DocumentIndexer`'s
 * docblock for why a pointer, not a migrated copy.
 *
 * `talent_onboarding_documents` has no employee column of its own - a
 * document belongs to a JOURNEY, and the journey names the employee - so
 * `owner_id` here comes from `talent_onboarding_journeys.employee_id`.
 *
 * Files live on the `public` (local) disk, not `digitalocean` - confirmed in
 * `OnboardingDocumentController::storeUpload()`. Only rows that actually
 * have a `file_path` are indexed; a requested-but-not-yet-submitted
 * checklist item has nothing to search.
 */
class OnboardingDocumentIndexer
{
    use ExtractsSourceFileText;

    public const SOURCE_SYSTEM = 'onboarding';
    public const SOURCE_TABLE = 'talent_onboarding_documents';
    private const DISK = 'public';

    public function __construct(private readonly DocumentIndexer $indexer)
    {
    }

    public function indexById(int $documentId): void
    {
        $row = DB::table('talent_onboarding_documents as d')
            ->leftJoin('talent_onboarding_journeys as j', 'j.id', '=', 'd.journey_id')
            ->leftJoin('document_type as t', 't.id', '=', 'd.document_type_id')
            ->where('d.id', $documentId)
            ->whereNull('d.deleted_at')
            ->first(['d.id', 'd.sub_institute_id', 'd.title', 'd.file_path', 'd.status', 'd.created_at', 'j.employee_id', 't.document_type as type_name']);

        if (!$row) {
            // Already gone (hard case: should not happen, soft-deletes
            // only) or never existed - nothing to index.
            return;
        }

        $this->indexRow($row);
    }

    public function removeById(int $documentId): void
    {
        $this->indexer->remove(self::SOURCE_SYSTEM, self::SOURCE_TABLE, $documentId);
    }

    /** Every live row, for the backfill command. */
    public function indexAll(?int $tenant = null): int
    {
        $query = DB::table('talent_onboarding_documents as d')
            ->leftJoin('talent_onboarding_journeys as j', 'j.id', '=', 'd.journey_id')
            ->leftJoin('document_type as t', 't.id', '=', 'd.document_type_id')
            /*
             * EXPLICIT, because three joined tables here each have their own
             * `id` column. A bare `SELECT *` collapses all three into one
             * `id` property - PHP's result object keeps only the LAST one
             * written, which is `document_type.id`, not this document's own
             * id. chunkById() then paginates and `indexRow()` then inserts
             * against the wrong id entirely, silently - no error, just
             * every row attributed to whichever document_type happened to
             * join last. `d.id AS id` pins it to the one that matters.
             */
            ->select(['d.id as id', 'd.sub_institute_id', 'd.title', 'd.file_path', 'd.status', 'd.created_at', 'j.employee_id', 't.document_type as type_name'])
            ->whereNull('d.deleted_at')
            ->whereNotNull('d.file_path')
            ->when($tenant, fn ($q) => $q->where('d.sub_institute_id', $tenant));

        $count = 0;

        $query->orderBy('d.id')->chunkById(200, function ($rows) use (&$count) {
            foreach ($rows as $row) {
                $this->indexRow($row);
                $count++;
            }
        }, 'd.id', 'id');

        return $count;
    }

    private function indexRow(object $row): void
    {
        if (empty($row->file_path)) {
            // A pending/requested document has nothing uploaded yet -
            // indexing a title with no content would be a search result
            // that leads nowhere once opened.
            return;
        }

        $file = $this->readSourceFile(self::DISK, $row->file_path);

        $this->indexer->index(self::SOURCE_SYSTEM, self::SOURCE_TABLE, (int) $row->id, [
            'sub_institute_id' => (int) $row->sub_institute_id,
            'owner_id' => $row->employee_id ? (int) $row->employee_id : null,
            'title' => (string) $row->title,
            'category' => 'personnel',
            'document_type' => 'onboarding_document',
            'subject' => $row->type_name ?? null,
            'document_date' => $row->created_at,
            ...$file,
        ]);
    }
}
