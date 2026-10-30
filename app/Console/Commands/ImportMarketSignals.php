<?php

namespace App\Console\Commands;

use App\Domain\Signals\Market\MarketFileReader;
use App\Domain\Signals\Market\MarketImporter;
use App\Domain\Signals\Opportunities\ResearchRun;
use App\Domain\Signals\Products\ProductCatalog;
use App\Support\ColumnExists;
use Illuminate\Support\Carbon;
use Illuminate\Console\Command;

/**
 * Same importer as the API endpoint, for the daily agent and for running by hand, locally or on
 * the server:  php artisan signals:import-market storage/app/scan.json --tenant=6
 */
class ImportMarketSignals extends Command
{
    protected $signature = 'signals:import-market {path : A .json or .csv file in the documented schema}
        {--tenant= : sub_institute_id to import into (required)}
        {--label=command : A short name for this scan, shown in the scan log}
        {--report-date= : Attach imported records to a successful dated run (use today for the current app timezone)}
        {--product=scholarclone : Product key required with --report-date}
        {--dry-run : Validate only; write nothing}';

    protected $description = 'Import structured demand-side (market) signals for one organisation. Rejected rows are logged, never dropped silently.';

    public function handle(MarketImporter $importer, MarketFileReader $reader): int
    {
        $tenant = (int) $this->option('tenant');
        if ($tenant <= 0) {
            $this->error('--tenant=<sub_institute_id> is required.');

            return self::INVALID;
        }

        $path = (string) $this->argument('path');
        if (! is_file($path) || ! is_readable($path)) {
            $this->error("Cannot read file: {$path}");

            return self::INVALID;
        }

        try {
            $parsed = $reader->read((string) file_get_contents($path), pathinfo($path, PATHINFO_EXTENSION));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }

        $reportDate = null;
        $productKey = null;
        $businessFit = null;
        if ($this->option('report-date') !== null) {
            try {
                $rawDate = strtolower((string) $this->option('report-date')) === 'today'
                    ? Carbon::today((string) config('signals.timezone', 'Asia/Kolkata'))->toDateString()
                    : Carbon::parse((string) $this->option('report-date'), (string) config('signals.timezone', 'Asia/Kolkata'))->toDateString();
            } catch (\Throwable) {
                $this->error('--report-date must be YYYY-MM-DD or today.');

                return self::INVALID;
            }
            $reportDate = $rawDate;
            $productKey = ProductCatalog::normalise((string) $this->option('product'), $tenant);
            $businessFit = $productKey ? ProductCatalog::businessFit($productKey, $tenant) : null;
            if ($productKey === null || $businessFit === null) {
                $this->error('The selected --product is not configured for this organisation.');

                return self::INVALID;
            }
            foreach ($parsed['records'] as $index => $record) {
                if (! is_array($record) || ($record['business_fit'] ?? null) !== $businessFit) {
                    $this->error('Record ' . ($index + 1) . " must set business_fit={$businessFit} to match product {$productKey}.");

                    return self::INVALID;
                }
            }
            if (! ColumnExists::has('g2g_research_runs', 'product_key') || ! ColumnExists::has('g2g_research_runs', 'stage')) {
                $this->error('The dated Company Opportunities view needs research_runs.product_key and research_runs.stage. Apply the Signals migrations first.');

                return self::FAILURE;
            }
        }

        $label = $parsed['source_label'] ?? (string) $this->option('label');
        if ($this->option('dry-run')) {
            $result = $importer->import($tenant, $parsed['records'], ['source_label' => $label, 'origin' => 'command', 'dry_run' => true]);
            if ($reportDate !== null) {
                $this->line("DRY RUN: no dated run was created for {$reportDate} ({$productKey}).");
            }
        } else {
            $run = null;
            if ($reportDate !== null) {
                $now = Carbon::now((string) config('signals.timezone', 'Asia/Kolkata'));
                $run = ResearchRun::create([
                    'sub_institute_id' => $tenant,
                    'report_date' => $reportDate,
                    'trigger' => 'manual',
                    'status' => 'running',
                    'stage' => 'importing',
                    'stage_message' => 'Importing reviewed market signals.',
                    'started_at' => $now,
                    'product_key' => $productKey,
                    'queries_run' => 0,
                    'queries_failed' => 0,
                ]);
            }

            try {
                $result = $importer->import($tenant, $parsed['records'], [
                    'source_label' => $label,
                    'origin' => 'command',
                    'dry_run' => false,
                    'research_run_id' => $run?->id,
                ]);
                if ($run !== null) {
                    $finished = Carbon::now((string) config('signals.timezone', 'Asia/Kolkata'));
                    $accepted = $result['accepted'] + $result['updated'];
                    $run->update([
                        'status' => $result['rejected'] > 0 ? 'partial' : 'success',
                        'stage' => 'completed',
                        'stage_message' => "Market import: {$result['accepted']} added, {$result['updated']} updated, {$result['duplicate']} duplicate, {$result['rejected']} rejected.",
                        'completed_at' => $finished,
                        'duration_ms' => max(0, $run->started_at->diffInMilliseconds($finished)),
                        'sources_found' => count($parsed['records']),
                        'companies_researched' => $accepted,
                        'opportunities_qualified' => $accepted,
                        'opportunities_new' => $result['accepted'],
                        'opportunities_rejected' => $result['rejected'],
                    ]);
                }
            } catch (\Throwable $e) {
                if ($run !== null) {
                    $run->update([
                        'status' => 'failed',
                        'stage' => 'failed',
                        'stage_message' => 'Market import failed.',
                        'completed_at' => Carbon::now((string) config('signals.timezone', 'Asia/Kolkata')),
                        'error_message' => mb_substr($e->getMessage(), 0, 500),
                    ]);
                }
                throw $e;
            }
        }

        $this->line(sprintf(
            '%s: %d received, %d accepted, %d updated, %d duplicate, %d rejected%s',
            $this->option('dry-run') ? 'DRY RUN' : 'Imported', $result['received'], $result['accepted'], $result['updated'], $result['duplicate'], $result['rejected'],
            $result['scan_log_id'] ? " (scan log #{$result['scan_log_id']})" : ''
        ));
        if (isset($run) && $run !== null) {
            $this->line("Saved on Company Opportunities date {$reportDate} for {$productKey} (run #{$run->id}).");
        }

        foreach ($result['results'] as $row) {
            if (($row['outcome'] ?? '') === 'rejected') {
                $this->warn(sprintf('  row %d rejected [%s]: %s', $row['index'], $row['reason_code'], $row['reason']));
            }
        }

        return self::SUCCESS;
    }
}
