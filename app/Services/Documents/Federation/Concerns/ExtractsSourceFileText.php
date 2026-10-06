<?php

namespace App\Services\Documents\Federation\Concerns;

use App\Services\Documents\Extraction\TextExtractionManager;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The one piece every federated indexer with a real file needs: pull its
 * bytes off whichever disk that silo actually uses (they are not all the
 * same - see each indexer's own docblock), hash them, and extract text the
 * same way a native upload does.
 */
trait ExtractsSourceFileText
{
    /**
     * @return array{size: ?int, checksum_sha256: ?string, extracted_text: ?string}
     */
    private function readSourceFile(string $disk, string $path): array
    {
        $empty = ['size' => null, 'checksum_sha256' => null, 'extracted_text' => null];

        if ($path === '') {
            return $empty;
        }

        /*
         * SOME WRITERS STORE A RELATIVE PATH; AT LEAST ONE STORES A FULL URL.
         *
         * `talent_onboarding_documents.file_path` is relative when written by
         * `OnboardingDocumentController::storeUpload()` - but
         * `OfferLetterFiler::fileOnOnboardingJourney()` (a second, independent
         * writer of the same column) copies `talent_offers.offer_letter_url`
         * in verbatim, which is a full `Storage::disk('digitalocean')->url()`
         * string. This is the same "two writers, one column, two conventions"
         * shape `staff_document`'s own history already has (see the
         * document_library migration's docblock) - caught here rather than
         * reproduced, by detecting a URL and both re-pointing at the
         * `digitalocean` disk AND recovering the real relative key from its
         * path component (which IS the storage path `Storage::url()` was
         * built from), since the `$disk` this method was called with would
         * otherwise be wrong for these rows too.
         */
        if (preg_match('#^https?://#i', $path)) {
            $disk = 'digitalocean';
            $path = ltrim((string) parse_url($path, PHP_URL_PATH), '/');

            if ($path === '') {
                return $empty;
            }
        }

        try {
            $store = Storage::disk($disk);

            if (!$store->exists($path)) {
                return $empty;
            }

            $bytes = $store->get($path);
        } catch (Throwable $e) {
            return $empty;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION) ?: '');
        $text = '';

        $extractor = new TextExtractionManager();

        if ($extractor->isTextBearing($extension)) {
            $local = tempnam(sys_get_temp_dir(), 'source_doc_');

            try {
                file_put_contents($local, $bytes);
                $text = $extractor->extract($local, $extension);
            } catch (Throwable $e) {
                $text = '';
            } finally {
                @unlink($local);
            }
        }

        return [
            'size' => strlen($bytes),
            'checksum_sha256' => hash('sha256', $bytes),
            'extracted_text' => $text !== '' ? $text : null,
        ];
    }
}
