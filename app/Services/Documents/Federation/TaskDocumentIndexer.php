<?php

namespace App\Services\Documents\Federation;

use App\Services\Documents\Federation\Concerns\ExtractsSourceFileText;
use Illuminate\Support\Facades\DB;

/**
 * Indexes `task_documents` rows. These are reference material attached to a
 * TASK, not a person's own record - `owner_id` is left null rather than set
 * to the uploader (`uploaded_by`), because "who uploaded this" and "whose
 * document this is" are different facts and `document_library.owner_id`
 * means the latter everywhere else in this table. `category` is
 * `'organization'` for the same reason.
 *
 * Files live on the `public` (local) disk, not `digitalocean` - confirmed in
 * `TaskDocumentController::store()`.
 */
class TaskDocumentIndexer
{
    use ExtractsSourceFileText;

    public const SOURCE_SYSTEM = 'task_management';
    public const SOURCE_TABLE = 'task_documents';
    private const DISK = 'public';

    public function __construct(private readonly DocumentIndexer $indexer)
    {
    }

    public function indexById(int $documentId): void
    {
        $row = DB::table('task_documents')
            ->where('id', $documentId)
            ->whereNull('deleted_at')
            ->first(['id', 'sub_institute_id', 'title', 'document_type', 'file_path', 'mime_type', 'file_size', 'created_at']);

        if (!$row) {
            return;
        }

        $this->indexRow($row);
    }

    public function removeById(int $documentId): void
    {
        $this->indexer->remove(self::SOURCE_SYSTEM, self::SOURCE_TABLE, $documentId);
    }

    public function indexAll(?int $tenant = null): int
    {
        $query = DB::table('task_documents')
            ->whereNull('deleted_at')
            ->when($tenant, fn ($q) => $q->where('sub_institute_id', $tenant));

        $count = 0;

        $query->orderBy('id')->chunkById(200, function ($rows) use (&$count) {
            foreach ($rows as $row) {
                $this->indexRow($row);
                $count++;
            }
        });

        return $count;
    }

    private function indexRow(object $row): void
    {
        $file = $this->readSourceFile(self::DISK, (string) $row->file_path);

        $this->indexer->index(self::SOURCE_SYSTEM, self::SOURCE_TABLE, (int) $row->id, [
            'sub_institute_id' => (int) $row->sub_institute_id,
            'owner_id' => null,
            'title' => (string) $row->title,
            'category' => 'organization',
            'document_type' => 'task_document',
            'mime_type' => $row->mime_type ?? null,
            // The row's own recorded size is trusted over a re-fetch's, when present.
            'size' => $row->file_size ? (int) $row->file_size : $file['size'],
            'document_date' => $row->created_at,
            'checksum_sha256' => $file['checksum_sha256'],
            'extracted_text' => $file['extracted_text'],
        ]);
    }
}
