<?php

namespace App\Services\Events;

use App\Services\Documents\DocumentStorageService;
use App\Services\Documents\Extraction\TextExtractionManager;
use App\Services\Events\Concerns\DrivesFromEventStore;
use Illuminate\Support\Facades\DB;

/**
 * Copies a new hire's own resume onto their personnel record, automatically.
 *
 * Built as `OfferLetterFiler`'s sibling - same event, same shape, same
 * reasoning for being a consumer rather than a line in `EmployeeFactory`
 * (see that class's docblock). The one thing genuinely different here: a
 * resume belongs to the JOB APPLICATION that led to the hire, not to the
 * offer directly, so this walks `talent_offers.application_id ->
 * talent_job_applications.resume_path` rather than reading a column on the
 * offer itself.
 *
 * ── A HIRE WITH NO RESUME ON FILE IS NOT AN ERROR ───────────────────────────
 *
 * An employee created directly in the Employee Directory, or an application
 * that was never asked for a CV, has no `resume_path`. That is recorded as
 * 'skipped', the same vocabulary `OfferLetterFiler` uses for "no offer_id on
 * the hire (direct entry)" - a fact about this hire, not a failure of this
 * consumer.
 */
class ResumeFiler
{
    use DrivesFromEventStore;

    public const CONSUMER = 'resume_filer';

    public const HANDLES = [
        'employee.hired',
    ];

    /** One of `config('documents.types.personnel')`. */
    public const DOCUMENT_TYPE = 'resume';

    public function handles(string $type): bool
    {
        return in_array($type, self::HANDLES, true);
    }

    /**
     * @throws \RuntimeException if called while replaying
     */
    public function dispatch(object $event): void
    {
        ReplayMode::assertNotReplaying(self::CONSUMER);

        if (!$this->handles((string) $event->type)) {
            return;
        }

        $done = DB::table('g2g_event_delivery')
            ->where('event_id', (int) $event->id)
            ->where('consumer', self::CONSUMER)
            ->where('status', 'done')
            ->exists();

        if ($done) {
            return;
        }

        $tenant = (int) $event->sub_institute_id;
        $employeeId = (int) $event->entity_id;
        $payload = $this->payload($event);
        $offerId = isset($payload['offer_id']) ? (int) $payload['offer_id'] : 0;

        if ($offerId <= 0) {
            $this->ledger($event, 'skipped', 'no offer_id on the hire (direct entry)');

            return;
        }

        $offer = DB::table('talent_offers')
            ->where('id', $offerId)->where('sub_institute_id', $tenant)
            ->first(['id', 'application_id']);

        if (!$offer || empty($offer->application_id)) {
            $this->ledger($event, 'skipped', 'offer ' . $offerId . ' names no job application');

            return;
        }

        $application = DB::table('talent_job_applications')
            ->where('id', (int) $offer->application_id)
            ->where('sub_institute_id', $tenant)
            ->whereNull('deleted_at')
            ->first(['id', 'resume_path']);

        if (!$application || empty($application->resume_path)) {
            $this->ledger($event, 'skipped', 'application ' . $offer->application_id . ' has no resume on file');

            return;
        }

        // `resume_path` is a full URL (CareersController::apply() stores
        // Storage::url(), not a relative path - same convention
        // `talent_offers.offer_letter_url` uses, which is why this mirrors
        // OfferLetterFiler's own parse_url()/basename() extraction below.
        $sourceName = basename(parse_url($application->resume_path, PHP_URL_PATH) ?: '');

        if ($sourceName === '') {
            $this->ledger($event, 'skipped', 'resume url has no file name');

            return;
        }

        $exists = DB::table('document_library')
            ->where('sub_institute_id', $tenant)
            ->where('owner_id', $employeeId)
            ->where('document_type', self::DOCUMENT_TYPE)
            ->whereNull('deleted_at')
            ->exists();

        if ($exists) {
            $this->ledger($event, 'done', 'already filed');

            return;
        }

        try {
            $adopted = $this->adoptIntoDocumentLibrary($sourceName, $employeeId);

            if ($adopted === null) {
                $this->ledger($event, 'skipped', 'resume file missing at public/hp_resume/' . $sourceName);

                return;
            }

            $extractedText = '';
            $extension = strtolower(pathinfo($sourceName, PATHINFO_EXTENSION) ?: 'pdf');

            try {
                $extractor = new TextExtractionManager();

                if ($extractor->isTextBearing($extension)) {
                    $local = tempnam(sys_get_temp_dir(), 'resume_');
                    file_put_contents($local, (new DocumentStorageService())->get($adopted['storage_path']));
                    $extractedText = $extractor->extract($local, $extension);
                    @unlink($local);
                }
            } catch (\Throwable $e) {
                $extractedText = '';
            }

            DB::table('document_library')->insert([
                'sub_institute_id'   => $tenant,
                'owner_id'           => $employeeId,
                'title'              => 'Resume',
                'original_file_name' => $sourceName,
                'mime_type'          => $extension === 'pdf' ? 'application/pdf' : null,
                'size'               => $adopted['size'],
                'checksum_sha256'    => $adopted['checksum_sha256'],
                'storage_path'       => $adopted['storage_path'],
                'current_version'    => 1,
                'category'           => 'personnel',
                'document_type'      => self::DOCUMENT_TYPE,
                'extracted_text'     => $extractedText !== '' ? $extractedText : null,
                'visibility'         => 'private',
                'processing_status'  => 'done',
                // NULL means SYSTEM. Nobody attached this; the hire did.
                'created_by'         => null,
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);
        } catch (\Throwable $e) {
            $this->ledger($event, 'failed', mb_substr($e->getMessage(), 0, 500));

            return;
        }

        $this->ledger($event, 'done', null);
    }

    /**
     * Copy the resume from where CareersController wrote it into the
     * document_library folder convention, via DocumentStorageService - same
     * reasoning as OfferLetterFiler::adoptIntoDocumentLibrary().
     *
     * @return array{storage_path:string, size:int, checksum_sha256:string}|null
     */
    private function adoptIntoDocumentLibrary(string $fileName, int $employeeId): ?array
    {
        return (new DocumentStorageService())->adopt(
            'public/hp_resume/' . $fileName,
            $employeeId,
            pathinfo($fileName, PATHINFO_EXTENSION) ?: null
        );
    }

    /** @return array<string, mixed> */
    private function payload(object $event): array
    {
        if (is_array($event->payload)) {
            return $event->payload;
        }

        $decoded = json_decode((string) ($event->payload ?? ''), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function ledger(object $event, string $status, ?string $error): void
    {
        DB::table('g2g_event_delivery')->updateOrInsert(
            ['event_id' => (int) $event->id, 'consumer' => self::CONSUMER],
            [
                'status'       => $status,
                'attempts'     => DB::raw('attempts + 1'),
                'last_error'   => $error,
                'completed_at' => $status === 'done' ? now() : null,
            ]
        );
    }
}
