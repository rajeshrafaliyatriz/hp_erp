<?php

namespace Database\Seeders;

use App\Domain\Portfolio\PortfolioImportService;
use App\Domain\ProductMaster\ProductMasterAfterImport;
use Illuminate\Database\Seeder;

class G2GPortfolioSeeder extends Seeder
{
    public function run(): void
    {
        $importer = app(PortfolioImportService::class);
        $importer->import();

        // The offers are written with the query builder, which the Product Master's write-through never sees: bring it in step now (slice 15, G-D2). The seeder
        // writes the shared foundation offers (no organisation). $this->command is null when it is called outside `db:seed`.
        foreach (ProductMasterAfterImport::run(null) as [$kind, $text]) {
            $kind === 'warn' ? $this->command?->warn($text) : $this->command?->line($text);
        }
    }
}
