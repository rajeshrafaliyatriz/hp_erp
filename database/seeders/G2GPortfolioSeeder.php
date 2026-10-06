<?php

namespace Database\Seeders;

use App\Domain\Portfolio\PortfolioImportService;
use Illuminate\Database\Seeder;

class G2GPortfolioSeeder extends Seeder
{
    public function run(): void
    {
        $importer = app(PortfolioImportService::class);
        $importer->import();
    }
}

