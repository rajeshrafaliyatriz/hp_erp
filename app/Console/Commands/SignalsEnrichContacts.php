<?php

namespace App\Console\Commands;

use App\Domain\Signals\Opportunities\CompanyOpportunity;
use App\Domain\Signals\Opportunities\SignalContactStore;
use App\Jobs\EnrichSignalContactsJob;
use Illuminate\Console\Command;

/**
 * Queues a public-contact lookup for signals that already exist (one per organisation, newest signals first). It only queues jobs: each job
 * searches the web through the configured provider and stores what was found. It never touches a signal, and it does nothing if no search provider is
 * configured. An organisation already searched within the re-check window is skipped by the job, so running this twice does not spend credits twice.
 */
class SignalsEnrichContacts extends Command
{
    protected $signature = 'signals:enrich-contacts {--tenant= : organisation (sub_institute_id); required} {--limit=25 : most organisations to queue}';

    protected $description = 'Queue a public-contact lookup for the organisations behind existing signals (queues jobs only; changes no signal)';

    public function handle(): int
    {
        $tenant = (int) $this->option('tenant');
        if ($tenant <= 0) {
            $this->error('Pass --tenant=<sub_institute_id>.');

            return self::FAILURE;
        }
        if (! EnrichSignalContactsJob::isAvailable()) {
            $this->warn('Contact research is off or no web-search provider key is configured. Nothing queued.');

            return self::SUCCESS;
        }
        $queued = 0;
        $seen = [];
        foreach (CompanyOpportunity::where('sub_institute_id', $tenant)->whereNotNull('company_id')->orderByDesc('id')->cursor() as $o) {
            if (isset($seen[$o->company_id]) || SignalContactStore::lastSearch($tenant, (int) $o->company_id) !== null) {
                continue;
            }
            $seen[$o->company_id] = true;
            if (EnrichSignalContactsJob::dispatchFor($tenant, (int) $o->id)) {
                $queued++;
            }
            if ($queued >= max(1, (int) $this->option('limit'))) {
                break;
            }
        }
        $this->info("Queued {$queued} organisation(s).");

        return self::SUCCESS;
    }
}
