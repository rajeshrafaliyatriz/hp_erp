<?php

namespace App\Services\Documents\Federation;

use App\Services\Documents\Federation\Concerns\ExtractsSourceFileText;
use Illuminate\Support\Facades\DB;

/**
 * Indexes the offboarding checklist's uploaded documents - which, unlike
 * every other silo, are NOT rows in their own table. `talent_offboarding_cases`
 * carries a `documents` longText column holding a fixed 4-entry JSON array
 * (Resignation Letter / Clearance Certificate / Exit Survey Form / Signed
 * NDA - see `OffboardingController::DEFAULT_DOCUMENTS`), each entry keyed by
 * a fixed slot id (`d1`..`d4`), not an auto-increment one.
 *
 * `document_library.source_id` is a real unsignedBigInteger, so there is no
 * column to put `'d1'` into. This uses the CASE's own numeric id as
 * `source_id` and folds the slot id into `source_table`
 * (`'talent_offboarding_cases:d1'`) instead - keeps the
 * (source_system, source_table, source_id) triple unique per checklist slot
 * without a schema change for the one silo shaped differently. See
 * `DocumentIndexer`'s docblock for the general reasoning this is a variant
 * of.
 *
 * Only entries where `fileUrl` is actually set are indexed - most of the 16
 * possible slots across today's 4 cases are still `Pending` with nothing
 * uploaded, and a search result for an empty checklist item would open to
 * nothing.
 *
 * Files live on the `digitalocean` disk (confirmed in
 * `OffboardingController::uploadDocument()`), and `fileUrl` is a full DO
 * Spaces URL, not a relative path - same shape `talent_offers.offer_letter_url`
 * has, so the same `parse_url()`/path-reconstruction approach applies.
 */
class OffboardingDocumentIndexer
{
    use ExtractsSourceFileText;

    public const SOURCE_SYSTEM = 'offboarding';
    private const TABLE_PREFIX = 'talent_offboarding_cases';
    private const DISK = 'digitalocean';
    private const UPLOAD_FOLDER = 'public/hp_offboarding_document/';

    public function __construct(private readonly DocumentIndexer $indexer)
    {
    }

    public function indexCase(int $caseId): void
    {
        $row = DB::table('talent_offboarding_cases')
            ->where('id', $caseId)
            ->whereNull('deleted_at')
            ->first(['id', 'sub_institute_id', 'employee_id', 'documents']);

        if (!$row) {
            return;
        }

        $this->indexCaseRow($row);
    }

    public function indexAll(?int $tenant = null): int
    {
        $query = DB::table('talent_offboarding_cases')
            ->whereNull('deleted_at')
            ->whereNotNull('documents')
            ->when($tenant, fn ($q) => $q->where('sub_institute_id', $tenant));

        $count = 0;

        foreach ($query->orderBy('id')->cursor() as $row) {
            $count += $this->indexCaseRow($row);
        }

        return $count;
    }

    private function indexCaseRow(object $row): int
    {
        $documents = json_decode((string) ($row->documents ?? '[]'), true);

        if (!is_array($documents)) {
            return 0;
        }

        $indexed = 0;

        foreach ($documents as $entry) {
            $docId = (string) ($entry['id'] ?? '');
            $fileUrl = (string) ($entry['fileUrl'] ?? '');

            if ($docId === '' || $fileUrl === '') {
                // Nothing uploaded to this slot yet - see this class's docblock.
                continue;
            }

            $sourceName = basename(parse_url($fileUrl, PHP_URL_PATH) ?: '');
            $file = $sourceName !== ''
                ? $this->readSourceFile(self::DISK, self::UPLOAD_FOLDER . $sourceName)
                : ['size' => null, 'checksum_sha256' => null, 'extracted_text' => null];

            $this->indexer->index(
                self::SOURCE_SYSTEM,
                self::TABLE_PREFIX . ':' . $docId,
                (int) $row->id,
                [
                    'sub_institute_id' => (int) $row->sub_institute_id,
                    'owner_id' => $row->employee_id ? (int) $row->employee_id : null,
                    'title' => (string) ($entry['title'] ?? 'Offboarding document'),
                    'category' => 'personnel',
                    'document_type' => 'offboarding_document',
                    'document_date' => $entry['uploadedAt'] ?? null,
                    ...$file,
                ]
            );

            $indexed++;
        }

        return $indexed;
    }
}
