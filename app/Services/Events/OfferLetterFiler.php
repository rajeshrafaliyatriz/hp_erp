<?php

namespace App\Services\Events;

use App\Services\Documents\DocumentStorageService;
use App\Services\Documents\Extraction\TextExtractionManager;
use App\Services\Events\Concerns\DrivesFromEventStore;
use Illuminate\Support\Facades\DB;

/**
 * Files the offer letter into the new employee's own documents, automatically.
 *
 * ── WHAT A "CONSUMER" IS, IN ONE PARAGRAPH ──────────────────────────────────
 *
 * `EmployeeFactory` PRODUCES the fact "somebody was hired" by writing an
 * `employee.hired` row into `g2g_event`. Writing it changes nothing on its own -
 * it is a note in a ledger. A CONSUMER is a class that reads that note and does
 * something because of it. This one reads "somebody was hired", finds the offer
 * letter that was generated and emailed at offer time, and copies it into that
 * person's document list so they can download it from their own profile.
 *
 * Nobody presses a button for this. HR does not "attach the offer letter" as a
 * separate task, and it cannot be forgotten, because the hire itself is what
 * triggers it.
 *
 * ── WHY IT IS A CONSUMER AND NOT A LINE IN EmployeeFactory ──────────────────
 *
 * EmployeeFactory's job is to create an employee correctly. Filing documents,
 * assigning learning, starting onboarding and sending notifications are four
 * different concerns that all follow from the same fact. Bolting each one into
 * the factory makes creating an employee slower, more failure-prone, and
 * impossible to re-run: if the file copy fails, the factory would have to decide
 * whether the whole hire rolls back. As a consumer it retries on its own,
 * records why it failed in `g2g_event_delivery`, and the hire stands regardless.
 *
 * ── WHY THE FILE IS COPIED, NOT LINKED ──────────────────────────────────────
 *
 * The Employee Directory downloads from `public/hp_staff_document/{file_name}`.
 * The offer letter is written to `public/offerLetter/{file_name}` at offer time.
 * Pointing a row at the other folder produces something that looks right and
 * downloads nothing, so the file is copied into the folder its row claims.
 */
class OfferLetterFiler
{
    /*
     * Registered in ReactEvents::REACTORS but, until this line, missing the
     * trait that actually makes `events:react` able to call it -
     * `->pendingCount()`/`->catchUp()` did not exist on this class, so every
     * run threw "Call to undefined method" for this consumer, caught and
     * logged as FAILED, and moved on. Confirmed live:
     * `php artisan events:react --pending --consumer=offer_letter_filer`
     * before this fix. Found while building ResumeFiler as this class's
     * sibling - same bug, same missing trait, see OnboardingLauncher for
     * the other reactor this also affected.
     */
    use DrivesFromEventStore;

    public const CONSUMER = 'offer_letter_filer';

    public const HANDLES = [
        'employee.hired',
    ];

    /**
     * The document_library `document_type` these rows carry.
     *
     * An open string now, not a `student_document_type` id - document_library
     * does not use that lookup table (it has no seeder anywhere in this
     * codebase and its live contents are unknowable from source control; see
     * the document_library migration's docblock). 'offer_letter' is one of
     * `config('documents.types.personnel')`.
     */
    public const DOCUMENT_TYPE = 'offer_letter';

    public function handles(string $type): bool
    {
        return in_array($type, self::HANDLES, true);
    }

    /**
     * @throws \RuntimeException if called while replaying
     */
    public function dispatch(object $event): void
    {
        /*
         * Replay-safe by refusing, like every other reactor. Re-filing a document
         * a person can see is a visible change, not a silent recomputation.
         */
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

        // A hire typed straight into the Employee Directory has no offer and so
        // no letter. That is a normal outcome, recorded as such.
        if ($offerId <= 0) {
            $this->ledger($event, 'skipped', 'no offer_id on the hire (direct entry)');

            return;
        }

        $offer = DB::table('talent_offers')
            ->where('id', $offerId)->where('sub_institute_id', $tenant)
            ->first(['id', 'offer_letter_url']);

        if (!$offer || empty($offer->offer_letter_url)) {
            /*
             * The offer exists but no letter was stored - the PDF upload can fail
             * independently of the offer being created, and TalentOfferController
             * deliberately lets the offer survive that. Skipped with the reason so
             * "no letter was ever generated" and "the filer is broken" cannot look
             * the same later.
             */
            $this->ledger($event, 'skipped', 'offer ' . $offerId . ' has no stored letter');

            return;
        }

        $sourceName = basename(parse_url($offer->offer_letter_url, PHP_URL_PATH) ?: '');

        if ($sourceName === '') {
            $this->ledger($event, 'skipped', 'offer letter url has no file name');

            return;
        }

        // Already filed for this employee - the same offer re-delivered, or HR
        // attached it by hand first. Either way there is nothing to do.
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
                $this->ledger($event, 'skipped', 'offer letter file missing at public/offerLetter/' . $sourceName);

                return;
            }

            $extractedText = '';

            try {
                $localCopy = tempnam(sys_get_temp_dir(), 'offer_letter_');
                file_put_contents($localCopy, (new DocumentStorageService())->get($adopted['storage_path']));
                $extractedText = (new TextExtractionManager())->extract($localCopy, 'pdf');
                @unlink($localCopy);
            } catch (\Throwable $e) {
                $extractedText = '';
            }

            DB::table('document_library')->insert([
                'sub_institute_id'   => $tenant,
                'owner_id'           => $employeeId,
                'title'              => 'Offer Letter',
                'original_file_name' => $sourceName,
                'mime_type'          => 'application/pdf',
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

        // The second home. Failing here must not undo the permanent record above,
        // so it reports separately rather than throwing.
        $onboarding = $this->fileOnOnboardingJourney($tenant, $employeeId, $offerId, $sourceName, $offer->offer_letter_url);

        $this->ledger($event, 'done', $onboarding);
    }

    /**
     * The same letter, on the onboarding journey the new hire is working through.
     *
     * ── WHY BOTH, AND NOT ONE ───────────────────────────────────────────────
     *
     * `staff_document` is the PERMANENT personnel record - it is what the Employee
     * Directory shows, and it is still there in year three.
     * `talent_onboarding_documents` is scoped to the journey, which is what the new
     * hire actually has open in front of them in week one. They answer different
     * questions, so filing in only one leaves the letter either invisible on day
     * one or absent from the personnel file.
     *
     * Returns a note for the ledger, or null when there was nothing to say.
     */
    private function fileOnOnboardingJourney(
        int $tenant,
        int $employeeId,
        int $offerId,
        string $fileName,
        string $url
    ): ?string {
        $journeyId = DB::table('talent_onboarding_journeys')
            ->where('sub_institute_id', $tenant)
            ->where(function ($q) use ($offerId, $employeeId) {
                $q->where('offer_id', $offerId)->orWhere('employee_id', $employeeId);
            })
            ->whereNull('deleted_at')
            ->value('id');

        if (!$journeyId) {
            /*
             * OnboardingLauncher consumes the SAME event, and reactors run in the
             * order ReactEvents::REACTORS lists them - this one is registered after
             * it, so the journey normally exists by now. If it does not, the
             * permanent copy above still stands; only the convenience copy is
             * missing, and it says so.
             */
            return 'filed to the personnel record; no onboarding journey yet';
        }

        $already = DB::table('talent_onboarding_documents')
            ->where('journey_id', $journeyId)
            ->where('file_name', $fileName)
            ->whereNull('deleted_at')
            ->exists();

        if ($already) {
            return null;
        }

        try {
            DB::table('talent_onboarding_documents')->insert([
                'journey_id'       => (int) $journeyId,
                'sub_institute_id' => $tenant,
                'title'            => 'Offer Letter',
                'file_name'        => $fileName,
                // This table has a file_path column, so the URL is stored directly
                // and no second copy of the file is needed.
                'file_path'        => $url,
                'status'           => 'verified',
                'is_mandatory'     => 0,
                'submitted_at'     => now(),
                'verified_at'      => now(),
                'sort_order'       => 0,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);
        } catch (\Throwable $e) {
            return 'personnel record filed; onboarding copy failed: ' . mb_substr($e->getMessage(), 0, 200);
        }

        return null;
    }

    /**
     * Copy the letter from where TalentOfferController wrote it into the
     * document_library folder convention, via DocumentStorageService - the
     * same one every other document_library writer uses, so this table never
     * grows a fourth divergent folder the way `staff_document` grew three.
     *
     * Best effort by design: if the copy fails, null is returned and no row
     * is written (the caller records why), because a document row whose file
     * is missing is worse than no row - it offers a download that 404s.
     *
     * @return array{storage_path:string, size:int, checksum_sha256:string}|null
     */
    private function adoptIntoDocumentLibrary(string $fileName, int $employeeId): ?array
    {
        return (new DocumentStorageService())->adopt(
            'public/offerLetter/' . $fileName,
            $employeeId,
            'pdf'
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
