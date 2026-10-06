<?php

namespace App\Services\Documents;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Where a document_library object is written, and how.
 *
 * Private visibility, a random filename, and an extension taken from the
 * BYTES rather than the client's filename - the same three rules
 * `EmployeeDocumentController::fileDocument()` already enforces, and for the
 * same reason its docblock gives: a genuine image uploaded as `payload.html`
 * must not become an executing page on the company's own CDN, and a
 * guessable key on a public object is how somebody reads a colleague's ID
 * proof without signing in.
 *
 * One folder convention, not three. `staff_document` ended up with
 * `public/hp_staff_document/`, `public/staff_document/` and
 * `public/offerLetter/` because three writers each assumed they owned the
 * convention. Every document_library writer - uploads, payslips, offer
 * letters, Form 16, resumes - goes through this class instead.
 */
class DocumentStorageService
{
    public function disk(): string
    {
        return (string) config('documents.disk', 'digitalocean');
    }

    private function folder(): string
    {
        return rtrim((string) config('documents.folder', 'private/document_library/'), '/') . '/';
    }

    /**
     * Store an uploaded file and return everything the document_library row needs.
     *
     * @return array{storage_path:string, original_file_name:string, mime_type:string, size:int, checksum_sha256:string}
     */
    public function storeUpload(UploadedFile $file, int $subjectId): array
    {
        $bytes = file_get_contents($file->getRealPath());
        $checksum = hash('sha256', (string) $bytes);

        $extension = $file->extension() ?: ($file->getClientOriginalExtension() ?: 'bin');
        $fileName = $subjectId . '_' . Str::random(24) . '.' . $extension;
        $path = $this->folder() . $fileName;

        Storage::disk($this->disk())->putFileAs(
            $this->folder(),
            $file,
            $fileName,
            ['visibility' => 'private', 'ContentType' => $file->getMimeType()]
        );

        return [
            'storage_path' => $path,
            'original_file_name' => $file->getClientOriginalName(),
            'mime_type' => (string) $file->getMimeType(),
            'size' => (int) $file->getSize(),
            'checksum_sha256' => $checksum,
        ];
    }

    /**
     * Store raw bytes already in memory (a generated PDF - payslip, Form 16).
     *
     * @return array{storage_path:string, mime_type:string, size:int, checksum_sha256:string}
     */
    public function storeGenerated(string $bytes, string $fileName, string $mimeType, int $subjectId): array
    {
        $extension = pathinfo($fileName, PATHINFO_EXTENSION) ?: 'pdf';
        $storedName = $subjectId . '_' . Str::random(24) . '.' . $extension;
        $path = $this->folder() . $storedName;

        Storage::disk($this->disk())->put($path, $bytes, [
            'visibility' => 'private',
            'ContentType' => $mimeType,
        ]);

        return [
            'storage_path' => $path,
            'mime_type' => $mimeType,
            'size' => strlen($bytes),
            'checksum_sha256' => hash('sha256', $bytes),
        ];
    }

    /** Copy an object already on this disk into the document_library folder. */
    public function adopt(string $fromPath, int $subjectId, ?string $extension = null): ?array
    {
        $disk = Storage::disk($this->disk());

        if (!$disk->exists($fromPath)) {
            return null;
        }

        $extension = $extension ?: (pathinfo($fromPath, PATHINFO_EXTENSION) ?: 'bin');
        $fileName = $subjectId . '_' . Str::random(24) . '.' . $extension;
        $path = $this->folder() . $fileName;

        $bytes = $disk->get($fromPath);

        $disk->put($path, $bytes, ['visibility' => 'private']);

        return [
            'storage_path' => $path,
            'size' => strlen($bytes),
            'checksum_sha256' => hash('sha256', $bytes),
        ];
    }

    public function exists(string $path): bool
    {
        return Storage::disk($this->disk())->exists($path);
    }

    public function get(string $path): string
    {
        return Storage::disk($this->disk())->get($path);
    }

    public function delete(string $path): void
    {
        Storage::disk($this->disk())->delete($path);
    }
}
