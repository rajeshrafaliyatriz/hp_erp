<?php

namespace App\Domain\Signals\Ingestion;

use App\Domain\Signals\Support\HtmlText;
use App\Domain\Signals\Support\RobotsPolicy;
use App\Domain\Signals\Support\SafeUrlFetcher;
use App\Domain\Signals\Support\UnsafeUrlException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Turns an uploaded file or a submitted URL into a stored, referenced text source.
 *
 * NOTHING HERE CALLS AN AI PROVIDER. Ingestion is extraction only; analysis is a
 * separate explicit action (IngestionAnalyzer), so a user can ingest with AI down.
 *
 * Files are kept on a private disk under a random name. The original filename is
 * metadata only and never becomes part of a path, and the storage path is never
 * returned by the API.
 */
class IngestionService
{
    public function __construct(
        private readonly DocumentExtractor $extractor,
        private readonly SafeUrlFetcher $fetcher,
    ) {
    }

    public function ingestFile(int $tenantId, ?int $userId, UploadedFile $file): IngestionSource
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $bytes = (string) file_get_contents($file->getRealPath());

        $source = new IngestionSource([
            'sub_institute_id' => $tenantId,
            'type' => 'file',
            'name' => Str::limit(basename($file->getClientOriginalName()), 250, ''),
            'original_filename' => Str::limit(basename($file->getClientOriginalName()), 250, ''),
            'mime' => $file->getClientMimeType(),
            'size_bytes' => strlen($bytes),
            'created_by' => $userId,
        ]);

        try {
            $extracted = $this->extractor->extract($bytes, $extension);
            $source->fill($this->segmentAttributes($extracted['segments'], $extracted['truncated']) + ['status' => 'ready']);

            // Stored only after a successful read: no orphaned private files for rejects.
            $path = "signals/ingestion/{$tenantId}/" . Str::uuid() . '.' . $extension;
            Storage::disk((string) config('signals.ingestion.disk'))->put($path, $bytes);
            $source->storage_path = $path;
        } catch (\RuntimeException $e) {
            $source->fill(['status' => 'failed', 'error_message' => Str::limit($e->getMessage(), 480, '')]);
        }

        $source->save();

        return $source;
    }

    /** @throws UnsafeUrlException for URLs that must not be fetched (message is user-safe) */
    public function ingestUrl(int $tenantId, ?int $userId, string $url): IngestionSource
    {
        $url = trim($url);
        // Validates scheme, credentials, port and resolves DNS before any request is made.
        $this->fetcher->validate($url);

        if (! (new RobotsPolicy($this->fetcher))->allows($url)) {
            throw new UnsafeUrlException('This website asks automated tools not to read that page (robots.txt).');
        }

        $page = $this->fetcher->fetch($url);
        $html = HtmlText::extract($page['body']);

        if (mb_strlen($html['text']) < 40) {
            throw new UnsafeUrlException('No readable text was found on that page.');
        }

        $limit = (int) config('signals.ingestion.max_chars', 300000);
        $segments = DocumentExtractor::chunk(mb_substr($html['text'], 0, $limit), 'Section');

        $source = new IngestionSource([
            'sub_institute_id' => $tenantId,
            'type' => 'url',
            'name' => Str::limit($html['title'] ?: (parse_url($page['url'], PHP_URL_HOST) ?: $url), 250, ''),
            'url' => Str::limit($page['url'], 1990, ''),
            'page_title' => $html['title'] ? Str::limit($html['title'], 490, '') : null,
            'mime' => $page['content_type'],
            'size_bytes' => strlen($page['body']),
            'status' => 'ready',
            'retrieved_at' => now(),
            'created_by' => $userId,
        ] + $this->segmentAttributes($segments, $page['truncated'] || mb_strlen($html['text']) > $limit));

        $source->save();

        return $source;
    }

    public function delete(IngestionSource $source): void
    {
        if ($source->storage_path) {
            Storage::disk((string) config('signals.ingestion.disk'))->delete($source->storage_path);
        }
        IngestionFinding::where('sub_institute_id', $source->sub_institute_id)->where('source_id', $source->id)->delete();
        IngestionAnalysis::where('sub_institute_id', $source->sub_institute_id)->where('source_id', $source->id)->delete();
        $source->delete();
    }

    /** @param array<int, array{ref: string, text: string}> $segments */
    private function segmentAttributes(array $segments, bool $truncated): array
    {
        return [
            'segments' => json_encode($segments, JSON_UNESCAPED_UNICODE),
            'char_count' => array_sum(array_map(fn ($s) => mb_strlen($s['text']), $segments)),
            'truncated' => $truncated,
        ];
    }
}
