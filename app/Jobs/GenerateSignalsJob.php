<?php

namespace App\Jobs;

use App\Domain\Signals\SignalRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Manual run, executed on the queue (needs `php artisan queue:work`).
 *
 * It used to run "after the HTTP response", which on a single-threaded server
 * (php artisan serve on Windows) blocks that response and every status poll until the AI /
 * search work finishes. A queued job returns the click instantly.
 */
class GenerateSignalsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;
    public int $timeout = 900;

    public function __construct(
        public readonly int $tenantId,
        public readonly ?int $userId,
    ) {
        $this->onQueue((string) config('signals.queue', 'signals'));
    }

    public function handle(SignalRunner $runner): void
    {
        $runner->run($this->tenantId, 'manual', $this->userId);
    }
}
