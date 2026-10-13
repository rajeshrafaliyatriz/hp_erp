<?php

namespace App\Http\Controllers\Api\Signals;

use App\Domain\Signals\Market\ImportRejection;
use App\Domain\Signals\Market\MarketFileReader;
use App\Domain\Signals\Market\MarketImporter;
use App\Domain\Signals\Market\ScanLog;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Structured demand-side import (the daily scan's output) and its audit trail.
 *
 * Admin/HR only (see routes). The organisation always comes from the caller's token; a record
 * or request body can never name another tenant.
 */
class MarketImportController extends Controller
{
    use ResolvesApiIdentity;

    public function __construct(private readonly MarketImporter $importer, private readonly MarketFileReader $reader)
    {
    }

    /**
     * POST /api/signals/market/import
     *
     * JSON body:  {"source_label": "daily-agent", "records": [ ... ]}  (or a bare array)
     * multipart:  file=<.json|.csv>, optional source_label, optional dry_run=1
     */
    public function import(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        $max = (int) config('signals.market_import.max_records', 500);

        try {
            if ($request->hasFile('file')) {
                $file = $request->file('file');
                if (! $file->isValid() || $file->getSize() > (int) config('signals.market_import.max_upload_kb', 5120) * 1024) {
                    return $this->fail('The file is missing, unreadable or too large.', 422);
                }
                $parsed = $this->reader->read((string) file_get_contents($file->getRealPath()), $file->getClientOriginalExtension());
                $records = $parsed['records'];
                $label = $request->input('source_label') ?: $parsed['source_label'];
            } else {
                $body = $request->json()->all();
                $records = array_is_list($body) ? $body : ($body['records'] ?? null);
                $label = $body['source_label'] ?? $request->input('source_label');
            }
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        if (! is_array($records) || $records === []) {
            return $this->fail('Send a JSON array of records, an object with a "records" array, or a .json/.csv file.', 422);
        }
        if (count($records) > $max) {
            return $this->fail("At most {$max} records can be imported in one request.", 422);
        }

        $result = $this->importer->import($identity['sub_institute_id'], $records, [
            'source_label' => is_string($label) ? mb_substr($label, 0, 100) : null,
            'origin' => 'api', 'user_id' => $identity['user_id'], 'dry_run' => $request->boolean('dry_run'),
            'ingestion_source_id' => $request->filled('ingestion_source_id') ? (int) $request->input('ingestion_source_id') : null,
        ]);

        return response()->json(['status' => 1, 'message' => 'Import processed.', 'data' => $result]);
    }

    /** GET /api/signals/market/scan-log: newest first, with the rejection rows of one scan on request. */
    public function scanLog(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        $logs = ScanLog::where('sub_institute_id', $identity['sub_institute_id'])->orderByDesc('id')->paginate(min(50, max(1, (int) $request->query('per_page', 15))));

        return response()->json(['status' => 1, 'data' => $logs]);
    }

    /** GET /api/signals/market/rejections?scan_log_id= */
    public function rejections(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        $rows = ImportRejection::where('sub_institute_id', $identity['sub_institute_id'])
            ->when($request->query('scan_log_id'), fn ($q, $id) => $q->where('scan_log_id', (int) $id))
            ->orderByDesc('id')->paginate(min(100, max(1, (int) $request->query('per_page', 25))));

        return response()->json(['status' => 1, 'data' => $rows]);
    }

    private function fail(string $message, int $status): JsonResponse
    {
        return response()->json(['status' => 0, 'message' => $message], $status);
    }
}
