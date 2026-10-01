<?php

namespace App\Http\Controllers\Api\Signals;

use App\Domain\Signals\Ingestion\DocumentExtractor;
use App\Domain\Signals\Ingestion\IngestionAnalysis;
use App\Domain\Signals\Ingestion\IngestionAnalyzer;
use App\Domain\Signals\Ingestion\IngestionFinding;
use App\Domain\Signals\Ingestion\IngestionService;
use App\Domain\Signals\Ingestion\IngestionSource;
use App\Domain\Signals\SignalRunner;
use App\Domain\Signals\Support\UnsafeUrlException;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use App\Jobs\AnalyzeIngestionSourceJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Ingestion: upload a file or submit one URL and signals are generated automatically.
 *
 * FLOW
 *   upload()/url()  -> extract text (synchronous, fast) -> create the analysis row as
 *                      "running" -> queue AnalyzeIngestionSourceJob -> respond immediately
 *   worker          -> chunked AI analysis -> validated findings saved
 *   UI              -> polls until the source is no longer "generating"
 *
 * If extraction fails, or no AI provider is configured, nothing is fabricated: the source
 * is kept with its real status and message, and Retry starts the analysis later.
 * Every query is bounded by the token's tenant; the storage path and full extracted text
 * never leave the server.
 */
class IngestionController extends Controller
{
    use ResolvesApiIdentity;

    /** A submission of identical content inside this window is the same ingestion. */
    private const DUPLICATE_WINDOW_SECONDS = 30;

    public function __construct(private readonly IngestionService $ingestion)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];
        $this->closeStale($tenantId);

        $sources = IngestionSource::where('sub_institute_id', $tenantId)->orderByDesc('id')->limit(100)->get();
        $ids = $sources->pluck('id');
        $latest = IngestionAnalysis::where('sub_institute_id', $tenantId)->whereIn('source_id', $ids)->orderByDesc('id')->get()->unique('source_id')->keyBy('source_id');
        $counts = IngestionFinding::where('sub_institute_id', $tenantId)->whereIn('source_id', $ids)
            ->whereIn('analysis_id', $latest->pluck('id'))->selectRaw('source_id, COUNT(*) as c')->groupBy('source_id')->pluck('c', 'source_id');

        return response()->json(['status' => 1, 'data' => $sources->map(fn ($s) => $this->presentSource($s, $latest->get($s->id), (int) ($counts[$s->id] ?? 0)))->values()]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];
        $this->closeStale($tenantId);

        $source = IngestionSource::where('sub_institute_id', $tenantId)->where('id', $id)->first();
        if (! $source) {
            return response()->json(['status' => 0, 'message' => 'Source not found'], 404);
        }

        $analyses = IngestionAnalysis::where('sub_institute_id', $tenantId)->where('source_id', $id)->orderByDesc('id')->limit(10)->get();
        $latest = $analyses->first();
        $findings = IngestionFinding::where('sub_institute_id', $tenantId)->where('source_id', $id)
            ->when($latest, fn ($q) => $q->where('analysis_id', $latest->id))->orderBy('id')->get();

        // A bounded preview of the extracted text, so a user can see what was read.
        $preview = array_slice((array) json_decode((string) $source->segments, true), 0, 12);

        return response()->json(['status' => 1, 'data' => $this->presentSource($source, $latest, $findings->count()) + [
            'preview' => array_map(fn ($s) => ['ref' => $s['ref'], 'text' => mb_substr($s['text'], 0, 600)], $preview),
            'analyses' => $analyses->map(fn ($a) => $this->presentAnalysis($a))->values(),
            'findings' => $findings->map(fn ($f) => $this->presentFinding($f))->values(),
        ]]);
    }

    /** Signals generated across all sources (latest analysis of each), with source provenance. */
    public function findings(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];

        $v = Validator::make($request->query(), [
            'source_id' => 'nullable|integer|min:1',
            'kind' => 'nullable|in:' . implode(',', IngestionAnalyzer::KINDS),
            'search' => 'nullable|string|max:100',
            'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:50',
        ]);
        if ($v->fails()) {
            return response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $v->errors()], 422);
        }

        // Only findings from each source's most recent analysis.
        $latestIds = DB::table('g2g_ingestion_analyses')->where('sub_institute_id', $tenantId)->selectRaw('MAX(id)')->groupBy('source_id');

        $query = DB::table('g2g_ingestion_findings as f')
            ->join('g2g_ingestion_sources as s', function ($join) use ($tenantId) {
                $join->on('s.id', '=', 'f.source_id')->where('s.sub_institute_id', '=', $tenantId);
            })
            ->where('f.sub_institute_id', $tenantId)
            ->whereIn('f.analysis_id', $latestIds)
            ->select('f.*', 's.name as source_name', 's.type as source_type', 's.url as source_url');

        $base = fn () => (clone $query)
            ->when($request->query('source_id'), fn ($q, $id) => $q->where('f.source_id', (int) $id))
            ->when(trim((string) $request->query('search', '')) !== '', function ($q) use ($request) {
                $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim((string) $request->query('search'))) . '%';
                $q->where(fn ($w) => $w->where('f.title', 'like', $like)->orWhere('f.detail', 'like', $like));
            });

        $page = $base()->when($request->query('kind'), fn ($q, $k) => $q->where('f.kind', $k))
            ->orderByDesc('f.id')->paginate((int) $request->query('per_page', 12));

        $kindCounts = $base()->selectRaw('f.kind, COUNT(*) as c')->groupBy('f.kind')->pluck('c', 'kind');

        return response()->json([
            'status' => 1,
            'data' => collect($page->items())->map(fn ($f) => $this->presentFinding($f) + [
                'source_id' => (int) $f->source_id, 'source_name' => $f->source_name, 'source_type' => $f->source_type, 'source_url' => $f->source_url,
                'created_at' => \Illuminate\Support\Carbon::parse($f->created_at)->toIso8601String(),
            ])->all(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
            'kind_counts' => $kindCounts,
        ]);
    }

    public function upload(Request $request, SignalRunner $signals): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];

        $v = Validator::make($request->all(), [
            'file' => 'required|file|max:' . (int) config('signals.ingestion.max_upload_kb') . '|extensions:' . implode(',', DocumentExtractor::EXTENSIONS),
        ]);
        if ($v->fails()) {
            return response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $v->errors()], 422);
        }

        $file = $request->file('file');
        $hash = hash_file('sha256', $file->getRealPath());

        // Double-click / double-submit: the same bytes seconds apart are the same ingestion.
        if ($existing = $this->recentDuplicate($tenantId, $hash)) {
            return $this->respond($existing, 200, 'This file was just submitted and is already being processed.');
        }

        $source = $this->ingestion->ingestFile($tenantId, $identity['user_id'], $file);
        $source->forceFill(['content_hash' => $hash])->save();

        if ($source->status !== 'ready') {
            // Extraction failed: keep the record with its real reason; nothing is analysed.
            return $this->respond($source, 422, $source->error_message);
        }

        return $this->afterIngest($source, $tenantId, $identity['user_id'], $signals);
    }

    public function url(Request $request, SignalRunner $signals): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];

        $v = Validator::make($request->all(), ['url' => 'required|string|max:2000']);
        if ($v->fails()) {
            return response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $v->errors()], 422);
        }

        $url = trim((string) $request->input('url'));
        $hash = hash('sha256', 'url:' . mb_strtolower($url));

        if ($existing = $this->recentDuplicate($tenantId, $hash)) {
            return $this->respond($existing, 200, 'This page was just submitted and is already being processed.');
        }

        try {
            $source = $this->ingestion->ingestUrl($tenantId, $identity['user_id'], $url);
        } catch (UnsafeUrlException $e) {
            return response()->json(['status' => 0, 'message' => $e->getMessage()], 422);
        }
        $source->forceFill(['content_hash' => $hash])->save();

        return $this->afterIngest($source, $tenantId, $identity['user_id'], $signals);
    }

    /** Retry: (re)start analysis for a source whose last analysis failed or never started. */
    public function analyze(Request $request, int $id, SignalRunner $signals): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];
        $this->closeStale($tenantId);

        $source = IngestionSource::where('sub_institute_id', $tenantId)->where('id', $id)->first();
        if (! $source) {
            return response()->json(['status' => 0, 'message' => 'Source not found'], 404);
        }

        $latest = IngestionAnalysis::where('sub_institute_id', $tenantId)->where('source_id', $id)->orderByDesc('id')->first();
        if ($latest && in_array($latest->status, ['success', 'partial'], true)) {
            return response()->json(['status' => 0, 'code' => 'already_analyzed', 'message' => 'This source has already been analysed. Delete it and add it again to analyse it afresh.'], 409);
        }

        [$started, $code, $message] = $this->startAnalysis($source, $tenantId, $identity['user_id'], $signals);
        if (! $started) {
            return response()->json(['status' => 0, 'code' => $code, 'message' => $message], $code === 'already_running' ? 409 : 422);
        }

        return response()->json(['status' => 1, 'message' => 'Analysis started.'], 202);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        $source = IngestionSource::where('sub_institute_id', $identity['sub_institute_id'])->where('id', $id)->first();
        if (! $source) {
            return response()->json(['status' => 0, 'message' => 'Source not found'], 404);
        }

        $this->ingestion->delete($source);

        return response()->json(['status' => 1, 'message' => 'Source and its findings were deleted.']);
    }

    public function actOnFinding(Request $request, int $id, string $action): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        $map = ['review' => 'Reviewed', 'dismiss' => 'Dismissed'];
        $finding = IngestionFinding::where('sub_institute_id', $identity['sub_institute_id'])->where('id', $id)->first();
        if (! $finding || ! isset($map[$action])) {
            return response()->json(['status' => 0, 'message' => 'Finding not found'], 404);
        }

        $finding->forceFill(['review_status' => $map[$action], 'reviewed_by' => $identity['user_id'], 'reviewed_at' => now()])->save();

        return response()->json(['status' => 1, 'data' => ['id' => $finding->id, 'review_status' => $finding->review_status]]);
    }

    // ── workflow ─────────────────────────────────────────────────────────────

    private function afterIngest(IngestionSource $source, int $tenantId, ?int $userId, SignalRunner $signals): JsonResponse
    {
        [$started, $code, $message] = $this->startAnalysis($source, $tenantId, $userId, $signals);

        return $this->respond(
            $source, 201,
            $started ? 'Source read. Generating signals automatically…' : "Source read, but analysis did not start: {$message} Use Retry once that is resolved.",
            ['analysis_started' => $started, 'analysis_code' => $code]
        );
    }

    /** @return array{0: bool, 1: ?string, 2: ?string} started, code, message */
    private function startAnalysis(IngestionSource $source, int $tenantId, ?int $userId, SignalRunner $signals): array
    {
        if ($source->status !== 'ready') {
            return [false, 'source_not_ready', 'This source could not be read.'];
        }
        if (! $signals->aiConfigured($tenantId)) {
            return [false, 'ai_not_configured', 'No AI provider is configured for this organisation.'];
        }
        if (IngestionAnalysis::where('sub_institute_id', $tenantId)->where('source_id', $source->id)->where('status', 'running')->exists()) {
            return [false, 'already_running', 'This source is already being analysed.'];
        }

        // The row exists BEFORE the job runs, so the UI shows "generating" immediately and a
        // second click finds a running analysis instead of starting another.
        $analysis = IngestionAnalyzer::begin($source, $userId);
        AnalyzeIngestionSourceJob::dispatch($tenantId, $analysis->id, $userId);

        return [true, null, null];
    }

    private function recentDuplicate(int $tenantId, string $hash): ?IngestionSource
    {
        return IngestionSource::where('sub_institute_id', $tenantId)->where('content_hash', $hash)
            ->where('created_at', '>=', now()->subSeconds(self::DUPLICATE_WINDOW_SECONDS))->orderByDesc('id')->first();
    }

    /** An analysis still "running" past the stale window lost its worker: say so, allow Retry. */
    private function closeStale(int $tenantId): void
    {
        IngestionAnalysis::where('sub_institute_id', $tenantId)->where('status', 'running')
            ->where('started_at', '<', now()->subMinutes((int) config('signals.stale_run_minutes', 15)))
            ->update([
                'status' => 'failed', 'error_code' => 'timed_out', 'completed_at' => now(),
                'error_message' => 'The analysis did not finish. Make sure the queue worker is running (php artisan queue:work), then retry.',
            ]);
    }

    // ── presenters ───────────────────────────────────────────────────────────

    private function respond(IngestionSource $source, int $status, ?string $message, array $extra = []): JsonResponse
    {
        $latest = IngestionAnalysis::where('sub_institute_id', $source->sub_institute_id)->where('source_id', $source->id)->orderByDesc('id')->first();

        return response()->json(['status' => $status < 400 ? 1 : 0, 'message' => $message, 'data' => $this->presentSource($source, $latest, 0)] + $extra, $status);
    }

    /**
     * The single status the UI shows, derived from real state (never guessed).
     *
     * @return array{status: string, label: string, message: ?string}
     */
    private function processing(IngestionSource $s, ?IngestionAnalysis $a): array
    {
        if ($s->status === 'failed') {
            return ['status' => 'failed', 'label' => 'Failed', 'message' => $s->error_message];
        }
        if ($a === null) {
            return ['status' => 'uploaded', 'label' => 'Uploaded', 'message' => 'Read successfully; signals have not been generated yet.'];
        }

        return match ($a->status) {
            'running' => ['status' => 'generating', 'label' => 'Generating signals', 'message' => null],
            'success' => ['status' => 'completed', 'label' => 'Completed', 'message' => null],
            'partial' => ['status' => 'completed_with_warnings', 'label' => 'Completed with warnings', 'message' => $a->error_message],
            default => ['status' => 'failed', 'label' => 'Failed', 'message' => $a->error_message],
        };
    }

    /** @return array<string, mixed> */
    private function presentSource(IngestionSource $s, ?IngestionAnalysis $latest = null, int $findingsCount = 0): array
    {
        $p = $this->processing($s, $latest);

        return [
            'id' => $s->id, 'type' => $s->type, 'name' => $s->name, 'url' => $s->url, 'page_title' => $s->page_title,
            'mime' => $s->mime, 'size_bytes' => $s->size_bytes, 'status' => $s->status, 'error_message' => $s->error_message,
            'char_count' => $s->char_count, 'truncated' => (bool) $s->truncated,
            'retrieved_at' => $s->retrieved_at?->toIso8601String(), 'created_at' => $s->created_at?->toIso8601String(),
            'last_analyzed_at' => $s->last_analyzed_at?->toIso8601String(),
            'processing_status' => $p['status'], 'processing_label' => $p['label'], 'processing_message' => $p['message'],
            'can_retry' => $s->status === 'ready' && in_array($p['status'], ['uploaded', 'failed'], true),
            'findings_count' => $findingsCount,
            'latest_analysis' => $latest ? $this->presentAnalysis($latest) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function presentAnalysis(IngestionAnalysis $a): array
    {
        return [
            'id' => $a->id, 'status' => $a->status, 'started_at' => $a->started_at?->toIso8601String(),
            'completed_at' => $a->completed_at?->toIso8601String(), 'duration_ms' => $a->duration_ms,
            'findings_count' => $a->findings_count, 'findings_rejected' => $a->findings_rejected,
            'error_code' => $a->error_code, 'error_message' => $a->error_message,
        ];
    }

    /** @return array<string, mixed> */
    private function presentFinding(object $f): array
    {
        $evidence = $f->evidence;

        return [
            'id' => (int) $f->id, 'analysis_id' => (int) $f->analysis_id, 'kind' => $f->kind, 'title' => $f->title, 'detail' => $f->detail,
            'business_impact' => $f->business_impact ?? null, 'suggested_action' => $f->suggested_action ?? null,
            'priority' => $f->priority, 'confidence' => $f->confidence ?? null,
            'evidence' => is_string($evidence) ? (json_decode($evidence, true) ?: []) : ($evidence ?: []),
            'review_status' => $f->review_status,
        ];
    }
}
