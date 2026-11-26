<?php

namespace App\Console\Commands;

use App\Domain\Portfolio\PortfolioImportService;
use App\Domain\ProductMaster\ProductMasterAfterImport;
use Illuminate\Console\Command;

class ImportPortfolioCommand extends Command
{
    protected $signature = 'g2g:import-portfolio {--path= : Path to G2G_Foundation_Data_Portfolio_Partners.xlsx} {--tenant= : Optional tenant/sub_institute_id}';

    protected $description = 'Import or synchronize foundation Product Portfolio from workbook';

    public function handle(PortfolioImportService $importer): int
    {
        $path = $this->option('path');
        $tenant = $this->option('tenant') ? (int) $this->option('tenant') : null;

        $this->info('Starting G2G Product Portfolio Import...');
        $result = $importer->import($path, $tenant);

        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Offers in Source', $result['total_in_source']],
                ['Newly Imported', $result['imported']],
                ['Updated (unaltered)', $result['updated']],
                ['Skipped (user-edited)', $result['skipped']],
                ['Failed', $result['failed']],
            ]
        );

        // The import writes g2g_product_offers with the query builder, which the Product Master's write-through never sees: bring it in step now (slice 15, G-D2).
        foreach (ProductMasterAfterImport::run($tenant) as [$kind, $text]) {
            $kind === 'warn' ? $this->warn($text) : $this->line($text);
        }

        return $result['failed'] > 0 ? 1 : 0;
    }
}

