<?php

namespace App\Jobs;

use App\Domain\Signals\Ingestion\IngestionAnalysis;
use App\Domain\Signals\Ingestion\IngestionAnalyzer;
use App\Domain\Signals\Ingestion\DataConnectorSync;
use App\Domain\Signals\Ingestion\IngestionSource;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Automatic analysis of one ingested source, run on the queue.
 *
 * WHY A REAL QUEUE JOB
 * This used to run "after the HTTP response". On a single-threaded server (php artisan
 * serve on Windows) that blocks the response AND every status poll until the AI call
 * finishes, so the UI could never show "Generating signals". A queued job returns the
 * upload instantly and needs `php artisan queue:work` to be running.
 *
 * The analysis row already exists (status "running") when this is dispatched, so the UI
 * reflects it immediately. The job re-loads everything scoped to the tenant, and does
 * nothing if the analysis is no longer running (safe against double delivery).
 */
class AnalyzeIngestionSourceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;
    public int $timeout = 900;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $analysisId,
        public readonly ?int $userId = null,
        public readonly ?int $connectorSyncId = null,
    ) {
        $this->onQueue((string) config('signals.queue', 'signals'));
    }

    public function handle(IngestionAnalyzer $analyzer): void
    {
        $analysis = IngestionAnalysis::where('sub_institute_id', $this->tenantId)->where('id', $this->analysisId)->first();
        if (! $analysis) {
            $this->completeConnectorSync(null);

            return;
        }
        if ($analysis->status !== 'running') {
            $this->completeConnectorSync($analysis);

            return;
        }

        $source = IngestionSource::where('sub_institute_id', $this->tenantId)->where('id', $analysis->source_id)->first();
        if (! $source || $source->status !== 'ready') {
            $analysis->forceFill(['status' => 'failed', 'error_code' => 'source_missing', 'error_message' => 'The source is no longer available.', 'completed_at' => now()])->save();
            $this->completeConnectorSync($analysis);

            return;
        }

        $analyzer->analyze($source, $analysis);
        $this->completeConnectorSync($analysis->refresh());
    }

    /** The worker crashed or the job blew up outside the analyzer's own handling. */
    public function failed(\Throwable $e): void
    {
        IngestionAnalysis::where('sub_institute_id', $this->tenantId)->where('id', $this->analysisId)->where('status', 'running')
            ->update(['status' => 'failed', 'error_code' => 'job_failed', 'error_message' => 'The analysis job failed unexpectedly. Please retry.', 'completed_at' => now()]);
        $this->completeConnectorSync(null, 'The ingestion analysis job failed unexpectedly. Please retry.');
    }

    private function completeConnectorSync(?IngestionAnalysis $analysis, ?string $error = null): void
    {
        if ($this->connectorSyncId === null) {
            return;
        }

        $sync = DataConnectorSync::where('sub_institute_id', $this->tenantId)->whereKey($this->connectorSyncId)->first();
        if (! $sync || $sync->status !== 'queued') {
            return;
        }

        $successful = $analysis !== null && in_array($analysis->status, ['success', 'partial'], true);
        $message = $successful ? $analysis->error_message : ($error ?: $analysis?->error_message ?: 'The source analysis did not complete.');
        $sync->forceFill([
            'status' => ! $successful ? 'failed' : ($analysis->status === 'partial' ? 'partial' : 'completed'),
            'records_processed' => $successful ? 1 : 0,
            'records_failed' => $successful ? 0 : 1,
            'error_message' => $message,
            'completed_at' => now(),
        ])->save();
    }
}
