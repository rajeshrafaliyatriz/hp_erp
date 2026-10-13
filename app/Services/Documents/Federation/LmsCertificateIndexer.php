<?php

namespace App\Services\Documents\Federation;

use Illuminate\Support\Facades\DB;

/**
 * Indexes `lms_certificates` rows. Unlike every other silo, there is
 * genuinely no file to point at - `LmsLearningController::downloadCertificate()`
 * renders the PDF live with dompdf from the row's own data each time it is
 * requested, and no `file_path`/`storage_path` column exists on this table
 * at all. `storage_path` stays NULL exactly as it would for any index row
 * (see `DocumentIndexer`'s docblock); there is simply never a version where
 * it would have been otherwise.
 *
 * With no uploaded file, `extracted_text` is built from the certificate's
 * own structured fields (title, course, description, tags) instead of
 * pulled from a document - still genuinely searchable content, just
 * authored by the system rather than extracted from a PDF.
 */
class LmsCertificateIndexer
{
    public const SOURCE_SYSTEM = 'lms_certificate';
    public const SOURCE_TABLE = 'lms_certificates';

    public function __construct(private readonly DocumentIndexer $indexer)
    {
    }

    public function indexById(int $certificateId): void
    {
        $row = DB::table('lms_certificates')
            ->where('id', $certificateId)
            ->whereNull('deleted_at')
            ->first(['id', 'sub_institute_id', 'user_id', 'name', 'course_title', 'description', 'tags', 'certificate_number', 'status', 'issued_at']);

        if (!$row) {
            return;
        }

        $this->indexRow($row);
    }

    public function indexAll(?int $tenant = null): int
    {
        $query = DB::table('lms_certificates')
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
        $title = (string) ($row->name ?: $row->course_title ?: 'Certificate');
        $tags = array_filter((array) (json_decode((string) ($row->tags ?? ''), true) ?: []), 'is_string');

        $text = trim(implode(' ', array_filter([
            $title,
            (string) ($row->course_title ?? ''),
            (string) ($row->description ?? ''),
            'Certificate number ' . (string) ($row->certificate_number ?? ''),
            implode(' ', $tags),
        ])));

        $this->indexer->index(self::SOURCE_SYSTEM, self::SOURCE_TABLE, (int) $row->id, [
            'sub_institute_id' => (int) $row->sub_institute_id,
            'owner_id' => $row->user_id ? (int) $row->user_id : null,
            'title' => $title,
            'category' => 'personnel',
            'document_type' => 'lms_certificate',
            'subject' => $row->course_title ?? null,
            'document_date' => $row->issued_at,
            'extracted_text' => $text !== '' ? $text : null,
        ]);
    }
}
