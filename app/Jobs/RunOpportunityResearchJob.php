<?php

namespace App\Jobs;

use App\Domain\Signals\Opportunities\OpportunityResearcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

/**
 * Manual run, executed on the queue (needs `php artisan queue:work`).
 *
 * It used to run "after the HTTP response", which on a single-threaded server
 * (php artisan serve on Windows) blocks that response and every status poll until the AI /
 * search work finishes. A queued job returns the click instantly.
 */
class RunOpportunityResearchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;
    public int $timeout = 900;

    public function __construct(
        public readonly int $tenantId,
        public readonly ?int $userId,
        public readonly ?array $customParams = null,
        public readonly ?int $runId = null
    ) {
        $this->onQueue((string) config('signals.queue', 'signals'));
    }

    public function handle(OpportunityResearcher $researcher): void
    {
        try {
            $researcher->run($this->tenantId, 'manual', $this->userId, $this->customParams, $this->runId);
        } finally {
            Cache::forget("signals:research-pending:{$this->tenantId}");
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Cache::forget("signals:research-pending:{$this->tenantId}");
        if ($this->runId) {
            \App\Domain\Signals\Opportunities\ResearchRun::where('id', $this->runId)
                ->where('status', 'running')
                ->update([
                    'status' => 'failed',
                    'stage' => 'failed',
                    'error_code' => 'job_failed',
                    'error_message' => $exception?->getMessage() ?: 'Research worker encountered an error.',
                    'stage_message' => $exception?->getMessage() ?: 'Research worker encountered an error.',
                    'completed_at' => now(),
                ]);
        }
    }
}
