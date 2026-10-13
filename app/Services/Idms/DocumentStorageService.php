<?php

namespace App\Services\Idms;

use App\Models\Idms\DocumentHistory;
use App\Models\Idms\DocumentMaster;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Private object storage under randomized keys: idms/documents/{yyyy}/{mm}/{uuid}.{ext}. Never a client-supplied name. */
class DocumentStorageService
{
    protected string $disk;

    public function __construct()
    {
        $this->disk = config('idms.disk', 'digitalocean');
    }

    public function storeUpload(UploadedFile $file): array
    {
        $extension = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $file->getClientOriginalExtension())) ?: 'bin';
        $path = sprintf('idms/documents/%s/%s/%s.%s', date('Y'), date('m'), Str::uuid()->toString(), $extension);

        $stream = fopen($file->getRealPath(), 'r');
        Storage::disk($this->disk)->put($path, $stream, 'private');
        if (is_resource($stream)) {
            fclose($stream);
        }

        return [
            'storage_path' => $path,
            'checksum_sha256' => hash_file('sha256', $file->getRealPath()),
            'size' => (int) $file->getSize(),
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'original_file_name' => mb_substr($file->getClientOriginalName(), 0, 255),
        ];
    }

    /** Short-lived signed URL. Create it only AFTER the permission check. */
    public function getTemporaryUrl(string $path, int $minutes = 15): string
    {
        return Storage::disk($this->disk)->temporaryUrl($path, now()->addMinutes($minutes));
    }

    public function get(string $path): string
    {
        return Storage::disk($this->disk)->get($path);
    }

    /** Remove the current file and every version. Only used when a trashed document is purged. */
    public function deleteAllFiles(DocumentMaster $document): void
    {
        $paths = DocumentHistory::where('document_id', $document->id)
            ->whereNotNull('storage_path')
            ->pluck('storage_path')
            ->push($document->storage_path)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($paths) {
            Storage::disk($this->disk)->delete($paths);
        }
    }
}
