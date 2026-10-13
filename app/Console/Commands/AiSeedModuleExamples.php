<?php

namespace App\Console\Commands;

use App\Domain\AI\Examples\ModuleExampleSeeder;
use Illuminate\Console\Command;

/**
 * Seed the starter report and prompt templates behind the AI Stack "Example" panels.
 *
 *   php artisan ai:seed-module-examples            dry run: shows what would be created
 *   php artisan ai:seed-module-examples --execute  creates the missing platform templates
 *
 * Idempotent (a template_key that exists is left untouched), additive only (never edits or
 * deletes a row), and creates no policy or restriction. See `ModuleExampleSeeder`.
 */
class AiSeedModuleExamples extends Command
{
    protected $signature = 'ai:seed-module-examples
        {--execute : Actually create the rows. Without it nothing is changed}';

    protected $description = 'Create the starter report/prompt templates that back the AI Stack Example panels (idempotent)';

    public function handle(ModuleExampleSeeder $seeder): int
    {
        $execute = (bool) $this->option('execute');
        $rows = $execute ? $seeder->run() : $seeder->plan();

        $this->table(
            ['template_key', 'kind', 'action', 'id', 'note'],
            array_map(fn (array $r) => [$r['key'], $r['kind'], $execute || $r['action'] !== 'create' ? $r['action'] : 'would create', $r['id'] ?? '', $r['reason'] ?? ''], $rows)
        );

        $created = array_filter($rows, fn (array $r) => isset($r['id']));
        $this->info($execute
            ? count($created) . ' template(s) created.' . ($created ? ' ids: ' . implode(', ', array_column($created, 'id')) : '')
            : 'Dry run. Pass --execute to create the rows marked "would create".');

        return self::SUCCESS;
    }
}
