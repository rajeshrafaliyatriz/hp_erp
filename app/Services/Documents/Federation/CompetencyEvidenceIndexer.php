<?php

namespace App\Services\Documents\Federation;

use App\Services\Documents\Federation\Concerns\ExtractsSourceFileText;
use Illuminate\Support\Facades\DB;

/**
 * Indexes `s_competency_evidence` rows that back a certification
 * (`certification_id IS NOT NULL`) - the same table also holds generic
 * Employee Profile evidence with no certification link, which this
 * deliberately leaves alone: that is a different concept (self-asserted
 * evidence, not a verified certification document) and out of scope here.
 *
 * Files live on the `digitalocean` disk (confirmed in
 * `CertificationController::storeDocument()`). A row may carry only an
 * external `link` with no file at all - those are still indexed (the title
 * and description are searchable even with nothing to extract), just with
 * no extracted_text.
 */
class CompetencyEvidenceIndexer
{
    use ExtractsSourceFileText;

    public const SOURCE_SYSTEM = 'competency';
    public const SOURCE_TABLE = 's_competency_evidence';
    private const DISK = 'digitalocean';

    public function __construct(private readonly DocumentIndexer $indexer)
    {
    }

    public function indexById(int $evidenceId): void
    {
        $row = DB::table('s_competency_evidence')
            ->where('id', $evidenceId)
            ->whereNotNull('certification_id')
            ->whereNull('deleted_at')
            ->first(['id', 'sub_institute_id', 'user_id', 'title', 'description', 'evidence_type', 'file_path', 'status', 'created_at']);

        if (!$row) {
            return;
        }

        $this->indexRow($row);
    }

    public function removeById(int $evidenceId): void
    {
        $this->indexer->remove(self::SOURCE_SYSTEM, self::SOURCE_TABLE, $evidenceId);
    }

    public function indexAll(?int $tenant = null): int
    {
        $query = DB::table('s_competency_evidence')
            ->whereNotNull('certification_id')
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
        $file = !empty($row->file_path)
            ? $this->readSourceFile(self::DISK, (string) $row->file_path)
            : ['size' => null, 'checksum_sha256' => null, 'extracted_text' => null];

        $this->indexer->index(self::SOURCE_SYSTEM, self::SOURCE_TABLE, (int) $row->id, [
            'sub_institute_id' => (int) $row->sub_institute_id,
            'owner_id' => $row->user_id ? (int) $row->user_id : null,
            'title' => (string) $row->title,
            'category' => 'personnel',
            'document_type' => 'competency_certificate',
            'subject' => $row->description ? mb_substr((string) $row->description, 0, 500) : null,
            'document_date' => $row->created_at,
            ...$file,
        ]);
    }
}
