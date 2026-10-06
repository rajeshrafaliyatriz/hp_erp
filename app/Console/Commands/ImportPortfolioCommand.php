<?php

namespace App\Console\Commands;

use App\Domain\Portfolio\PortfolioImportService;
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
                ['Production Partners', $result['partner_count']],
            ]
        );

        $this->info($result['sample_partner_note']);

        return $result['failed'] > 0 ? 1 : 0;
    }
}

