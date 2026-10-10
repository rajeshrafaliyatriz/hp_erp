<?php

namespace App\Console\Commands;

use App\Services\Events\Neo4jProjector;
use Illuminate\Console\Command;

/**
 * Drains organization.changed/department.changed/jobrole.changed events into
 * the Neo4j graph via Neo4jProjector::catchUp().
 *
 * This is this application's FIRST working scheduled job. The one line that
 * existed before this (`sync:data` in app/Console/kernel.php) pointed at a
 * command that was never registered, and the command it probably meant to
 * invoke (SyncDataCron / app:sync-data-cron) is itself dead placeholder code
 * that would fatal if ever run - there was no working precedent to copy.
 */
class GraphCatchUpCommand extends Command
{
    protected $signature = 'graph:catch-up {--limit=500}';

    protected $description = 'Project pending organization/department/jobrole change events into the Neo4j graph';

    public function handle(Neo4jProjector $projector): int
    {
        $limit = (int) $this->option('limit');
        $total = 0;

        while (($n = $projector->catchUp($limit)) > 0) {
            $total += $n;
            $this->info("Projected {$n} event(s).");
        }

        if ($total === 0) {
            $this->info('Nothing pending.');
        }

        return self::SUCCESS;
    }
}
