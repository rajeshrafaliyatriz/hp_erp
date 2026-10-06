<?php

namespace App\Console\Commands;

use App\Domain\Signals\Opportunities\OpportunityResearcher;
use App\Domain\Signals\Opportunities\ProductProfile;
use App\Domain\Signals\Opportunities\ProductProfileService;
use Illuminate\Console\Command;

class RunOpportunityResearch extends Command
{
    protected $signature = 'signals:research
        {--tenant= : Run this sub_institute_id now, ignoring the schedule}
        {--trigger=scheduled : scheduled|manual (recorded on the run)}';

    protected $description = 'Daily company-opportunity research. Without --tenant it runs only for organisations whose configured time has passed today and that have not run yet. Existing results are never deleted.';

    public function handle(OpportunityResearcher $researcher, ProductProfileService $profiles): int
    {
        $trigger = $this->option('trigger') === 'manual' ? 'manual' : 'scheduled';

        // Proof that the Laravel scheduler is actually ticking (shown in the Signals UI).
        // Stamped on EVERY tick, including the many that find nothing due.
        if ($this->option('tenant') === null) {
            \Illuminate\Support\Facades\Cache::forever('signals:scheduler:last_tick', now()->toIso8601String());
        }

        if ($this->option('tenant') !== null) {
            $tenants = [(int) $this->option('tenant')];
        } else {
            // Cheap check first: one query, then per-profile time/frequency logic. Most
            // ticks (the scheduler fires every 15 minutes) find nothing due and exit.
            $tenants = ProductProfile::where('research_enabled', true)->get()
                ->filter(fn (ProductProfile $p) => $profiles->isDue($p))
                ->pluck('sub_institute_id')->map(fn ($id) => (int) $id)->all();
        }

        foreach ($tenants as $tenantId) {
            // One organisation failing must not stop the others.
            $run = $researcher->run($tenantId, $trigger);
            $this->line(sprintf('tenant %d: %s (%d qualified, %d new)%s', $tenantId, $run->status, $run->opportunities_qualified, $run->opportunities_new, $run->error_code ? " [{$run->error_code}]" : ''));
        }

        return self::SUCCESS;
    }
}
