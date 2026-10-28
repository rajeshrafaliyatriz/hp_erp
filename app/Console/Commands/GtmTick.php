<?php

namespace App\Console\Commands;

use App\Domain\Gtm\GtmScheduler;
use Illuminate\Console\Command;

/**
 * The one scheduled entry point for GTM (see GtmScheduler). Registered in routes/console.php every five
 * minutes. Safe to run by hand; `--daily` forces the once-a-day pass (snapshots and alert events).
 *
 *   php artisan gtm:tick
 *   php artisan gtm:tick --daily
 */
class GtmTick extends Command
{
    protected $signature = 'gtm:tick {--daily : Run the once-a-day pass even if it already ran today}';

    protected $description = 'GTM scheduler pass: scheduled sends, stuck-send recovery, workflow retries, daily snapshots and alert events.';

    public function handle(GtmScheduler $scheduler): int
    {
        $summary = $scheduler->tick((bool) $this->option('daily'));
        $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // A part that threw is already logged and stored; the exit code lets the platform's task ledger show it as failed.
        return $summary['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
