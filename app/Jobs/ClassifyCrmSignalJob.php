<?php

namespace App\Jobs;

use App\Domain\AI\Support\AiNotConfiguredException;
use App\Domain\Crm\Ai\CrmSignalClassifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Async job that classifies a freshly ingested CRM signal using AI.
 *
 * Pattern mirrors RunOpportunityResearchJob: queued on the `signals` queue,
 * 900s timeout, updates the signal row on completion or failure.
 */
class ClassifyCrmSignalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public int $tries = 2;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $signalId,
    ) {
        $this->onQueue(config('signals.queue', 'signals'));
    }

    public function handle(CrmSignalClassifier $classifier): void
    {
        try {
            $result = $classifier->classify($this->tenantId, $this->signalId);

            DB::table('crm_signal_inbox')->where('id', $this->signalId)->update([
                'classification'            => $result['classification'],
                'classification_confidence' => $result['classification_confidence'],
                'classification_reason'     => $result['classification_reason'],
                'relevance_score'           => $result['relevance_score'],
                'linked_account_id'         => $result['linked_account_id'],
                'updated_at'                => now(),
            ]);
        } catch (AiNotConfiguredException $e) {
            Log::info('CRM signal classification skipped — AI not configured', [
                'signal_id' => $this->signalId,
                'reason'    => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            Log::error('CRM signal classification failed', [
                'signal_id' => $this->signalId,
                'error'     => $e->getMessage(),
            ]);
            $this->fail($e);
        }
    }
}
