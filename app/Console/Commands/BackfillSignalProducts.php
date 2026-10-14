<?php

namespace App\Console\Commands;

use App\Domain\Signals\Products\ProductCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Records which product an earlier research run (or ingested document) was made for, ONLY where the records themselves prove it:
 * every opportunity the run (or document) produced is stored for the same product (`business_fit`). A run with no opportunities, or
 * with opportunities for several products, is left unassigned. Nothing is guessed from a title, a company name or a keyword, and no
 * existing value is ever overwritten. A dry run unless --apply is given.
 */
class BackfillSignalProducts extends Command
{
    protected $signature = 'signals:backfill-product {--tenant= : Only this sub_institute_id} {--apply : Write the product (default: show what would change)}';

    protected $description = 'Set product_key on research runs and ingested sources from the product their own opportunities are stored for. Dry run unless --apply.';

    public function handle(): int
    {
        if (! Schema::hasTable('g2g_company_opportunities')) {
            $this->error('No opportunities table.');

            return self::FAILURE;
        }
        $fitToKey = [];
        foreach (ProductCatalog::definitions() as $key => $d) {
            $fitToKey[$d['business_fit']] = $key;
        }

        $total = 0;
        foreach ([['g2g_research_runs', 'research_run_id'], ['g2g_ingestion_sources', 'ingestion_source_id']] as [$table, $link]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'product_key')) {
                $this->warn("{$table}: no product_key column yet (run the migration first).");

                continue;
            }
            $rows = DB::table('g2g_company_opportunities')->whereNotNull($link)->whereNotNull('business_fit')
                ->when($this->option('tenant'), fn ($q, $t) => $q->where('sub_institute_id', (int) $t))
                ->select($link . ' as owner', 'sub_institute_id', 'business_fit')->distinct()->get()->groupBy(fn ($r) => $r->sub_institute_id . ':' . $r->owner);

            $n = 0;
            foreach ($rows as $group) {
                $fits = $group->pluck('business_fit')->unique();
                $key = $fits->count() === 1 ? ($fitToKey[$fits->first()] ?? null) : null;
                if ($key === null) {
                    continue;
                }
                $q = DB::table($table)->where('id', $group->first()->owner)->where('sub_institute_id', $group->first()->sub_institute_id)->whereNull('product_key');
                if ($this->option('apply')) {
                    $n += $q->update(['product_key' => $key]);
                } else {
                    $n += $q->count();
                }
            }
            $this->line(sprintf('%s: %d %s', $table, $n, $this->option('apply') ? 'updated' : 'would be updated'));
            $total += $n;
        }
        if (! $this->option('apply')) {
            $this->line('Dry run: nothing written. Add --apply to record these.');
        }

        return self::SUCCESS;
    }
}
