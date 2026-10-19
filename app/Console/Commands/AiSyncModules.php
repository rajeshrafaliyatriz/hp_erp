<?php

namespace App\Console\Commands;

use App\Domain\AI\Discovery\DiscoveredSources;
use App\Domain\AI\Discovery\ModuleSchemaDiscovery;
use App\Domain\AI\Modules\ModuleRegistrar;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Register every menu module with the AI architecture and report what each can read.
 *
 * Idempotent and non-destructive: it creates the `ai_modules` row of a module that has none,
 * switches on a dormant module that now has readable data, and re-reads the schema so discovered
 * sources never describe tables as they were. Run it after adding a module or changing its tables;
 * it also runs daily, and the same registration happens the first time anyone opens a new module's
 * AI Stack, so running it is a convenience rather than a requirement.
 */
class AiSyncModules extends Command
{
    protected $signature = 'ai:sync-modules {--dry-run : Show what would change without writing} {--no-enable : Do not switch dormant modules on}';

    protected $description = 'Register menu modules with the AI Stack and report their discovered data sources';

    public function handle(ModuleRegistrar $registrar, ModuleSchemaDiscovery $discovery, DiscoveredSources $discovered): int
    {
        $dry = (bool) $this->option('dry-run');
        $discovery->refresh();

        foreach ($registrar->syncAll($dry) as $line) {
            $this->line(sprintf('menu %-4d %-28s %s', $line['menu_id'], $line['key'] ?? '-', $line['action']));
        }

        foreach ($discovered->modules() as $module) {
            $report = $discovered->report($module['key']);
            $row = DB::table('ai_modules')->where('module_key', $module['key'])->whereNull('sub_institute_id')->first();
            $this->newLine();
            $this->info(sprintf('%s: prefixes [%s] -> %d table(s) readable, %d skipped', $module['key'], implode(', ', $module['prefixes']), count($report['tables']), count($report['skipped'])));

            foreach ($report['skipped'] as $skip) {
                $this->line(sprintf('  skipped %-40s %s', $skip['table'], $skip['reason']));
            }

            if ($row !== null && $report['tables'] !== [] && $registrar->isDormant($row)) {
                if ($dry || $this->option('no-enable')) {
                    $this->line('  dormant, has readable data: would switch on');
                } else {
                    $registrar->enable($row);
                    $this->line('  was dormant and has readable data: switched on');
                }
            }
        }

        return self::SUCCESS;
    }
}
