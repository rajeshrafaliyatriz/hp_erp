<?php

namespace App\Console\Commands;

use App\Domain\Signals\Market\MarketFileReader;
use App\Domain\Signals\Market\MarketImporter;
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

        $result = $importer->import($tenant, $parsed['records'], [
            'source_label' => $parsed['source_label'] ?? (string) $this->option('label'), 'origin' => 'command', 'dry_run' => (bool) $this->option('dry-run'),
        ]);

        $this->line(sprintf(
            '%s: %d received, %d accepted, %d updated, %d duplicate, %d rejected%s',
            $this->option('dry-run') ? 'DRY RUN' : 'Imported', $result['received'], $result['accepted'], $result['updated'], $result['duplicate'], $result['rejected'],
            $result['scan_log_id'] ? " (scan log #{$result['scan_log_id']})" : ''
        ));

        foreach ($result['results'] as $row) {
            if (($row['outcome'] ?? '') === 'rejected') {
                $this->warn(sprintf('  row %d rejected [%s]: %s', $row['index'], $row['reason_code'], $row['reason']));
            }
        }

        return self::SUCCESS;
    }
}
