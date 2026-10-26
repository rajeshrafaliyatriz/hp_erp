<?php

namespace App\Console\Commands;

use App\Domain\Signals\Sources\SourceRegistry;
use Illuminate\Console\Command;

/**
 * Read-only view of the education source inventory (config/signals_sources.php). Reads configuration only: no database, no network. Listing a
 * source here never makes it operational; only a recorded verification does.
 */
class SignalsSources extends Command
{
    protected $signature = 'signals:sources {--product= : only sources for core|learn|ccb|insight|compass} {--status= : pending_verification|needs_url|verified_operational}';
    protected $description = 'List the candidate education sources and how many are verified (none are fetched by this command).';

    public function handle(SourceRegistry $registry): int
    {
        $rows = $this->option('product') ? $registry->forProduct((string) $this->option('product')) : $registry->all();
        if ($this->option('status')) {
            $rows = array_values(array_filter($rows, fn ($s) => $s['status'] === $this->option('status')));
        }
        $this->table(['name', 'region', 'category', 'status', 'products', 'method'], array_map(fn ($s) => [
            mb_substr($s['name'], 0, 48), $s['region'], $s['category'], $s['status'], implode(',', $s['products']), $s['retrieval_method'],
        ], $rows));
        $this->line(json_encode($registry->counts()));
        $this->line('by category: ' . json_encode($registry->countsBy('category')));
        $this->line('by region: ' . json_encode($registry->countsBy('region')));
        $this->line('by status: ' . json_encode($registry->countsBy('status')));
        foreach ($registry->problems() as $problem) {
            $this->warn($problem);
        }

        return self::SUCCESS;
    }
}
