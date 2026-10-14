<?php

namespace App\Console\Commands;

use App\Domain\Signals\Opportunities\OpportunityResearcher;
use App\Domain\Signals\Opportunities\ResearchRun;
use App\Domain\Signals\Products\ProductCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RunOpportunityResearch extends Command
{
    protected $signature = 'signals:research
        {--tenant= : Run this sub_institute_id now, ignoring the schedule}
        {--product= : The product to find buyers for (scholarclone, enterprise_brain, g2g). Default: SIGNALS_PRODUCTS, else ScholarClone}
        {--school= : Scan this one school by name instead of the day\'s rotation}
        {--trigger=scheduled : scheduled|manual (recorded on the run)}
        {--batches=1 : Run this many runs in a row (max 6); each takes the next slice of the searches and the next unchecked schools}';

    protected $description = 'Daily ScholarClone buying-signal research. Without --tenant it runs for the organisations listed in SIGNALS_TENANTS (or, when empty, those that already have target schools) once the daily time has passed and no scheduled run exists for today. Existing results are never deleted.';

    public function handle(OpportunityResearcher $researcher): int
    {
        $trigger = $this->option('trigger') === 'manual' ? 'manual' : 'scheduled';

        // Proof that the Laravel scheduler is actually ticking (shown in the Signals UI).
        if ($this->option('tenant') === null) {
            Cache::forever('signals:scheduler:last_tick', now()->toIso8601String());
        }

        $products = $this->products();
        if ($products === null) {
            $this->error('Unknown product. Use one of: ' . implode(', ', array_keys(ProductCatalog::definitions())));

            return self::FAILURE;
        }
        $tenants = $this->option('tenant') !== null ? [(int) $this->option('tenant')] : null;
        $batches = max(1, min(6, (int) $this->option('batches')));
        $school = $this->option('school') ?: null;

        foreach ($products as $product) {
            foreach ($tenants ?? $this->dueTenants($product) as $tenantId) {
                for ($b = 0; $b < ($school ? 1 : $batches); $b++) {
                    config(['signals.icp.batch_shift' => $b]);
                    $run = $researcher->run($tenantId, $trigger, null, $school, null, null, $product);
                    $this->line(sprintf('tenant %d %s%s: %s (%d qualified, %d new)%s', $tenantId, $product, $batches > 1 ? ' batch ' . ($b + 1) . "/{$batches}" : '', $run->status, $run->opportunities_qualified, $run->opportunities_new, $run->error_code ? " [{$run->error_code}]" : ''));
                    if ($run->error_code === 'already_running' || $run->error_code === 'product_not_configured') {
                        break;
                    }
                }
                config(['signals.icp.batch_shift' => 0]);
            }
        }

        return self::SUCCESS;
    }

    /** The products to run: --product, else SIGNALS_PRODUCTS, else ScholarClone. Null when one is not a known product. @return list<string>|null */
    private function products(): ?array
    {
        $given = $this->option('product') !== null ? [(string) $this->option('product')] : (array) config('signals.products', ['scholarclone']);
        $out = [];
        foreach ($given as $p) {
            $key = ProductCatalog::normalise((string) $p);
            if ($key === null) {
                return null;
            }
            $out[$key] = $key;
        }

        return array_values($out) ?: ['scholarclone'];
    }

    /** Organisations whose daily time has passed and that have no scheduled run for this product today. @return list<int> */
    private function dueTenants(string $product): array
    {
        $tz = (string) config('signals.timezone', 'Asia/Kolkata');
        $local = now($tz);
        $time = (string) config('signals.opportunities.default_schedule_time', '10:00');
        if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) || $local->format('H:i') < $time) {
            return [];
        }

        $ids = array_map('intval', (array) config('signals.tenants', []));
        if ($ids === [] && Schema::hasTable('g2g_target_accounts')) {
            $ids = DB::table('g2g_target_accounts')->distinct()->pluck('sub_institute_id')->map(fn ($i) => (int) $i)->all();
        }

        return array_values(array_filter($ids, fn (int $id) => ! ResearchRun::where('sub_institute_id', $id)
            // Runs made before products were recorded (no product) were ScholarClone's.
            ->where(fn ($q) => $product === 'scholarclone' ? $q->whereNull('product_key')->orWhere('product_key', $product) : $q->where('product_key', $product))
            ->where('trigger', 'scheduled')->whereDate('report_date', $local->toDateString())->exists()));
    }
}
