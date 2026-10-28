<?php

namespace App\Console\Commands;

use App\Domain\AI\Examples\PageExampleBuilder;
use App\Domain\AI\Examples\Sources\SourceRegistry;
use App\Domain\AI\Reports\ModuleDataSourceCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Audit: does every module and every page of the AI Stack have an example, and is each one honest?
 *
 *   php artisan ai:audit-page-examples            all top-level modules
 *   php artisan ai:audit-page-examples --module=hrit_management
 *   php artisan ai:audit-page-examples --tenant=6 show live row counts for that organisation
 *
 * Reads only. Exits non-zero when a page of a module is not mapped, or a mapped source does not exist -
 * the two ways an example could quietly be missing or point at nothing.
 */
class AiAuditPageExamples extends Command
{
    protected $signature = 'ai:audit-page-examples {--module= : one ai_modules key} {--tenant= : organisation (sub_institute_id) to show live counts for}';

    protected $description = 'List every module page and whether it has a real AI Stack example (read-only).';

    public function handle(PageExampleBuilder $builder, ModuleDataSourceCatalog $catalog): int
    {
        $keys = $this->option('module')
            ? [(string) $this->option('module')]
            : DB::table('ai_modules')->whereNull('sub_institute_id')->where('status', 1)
                ->whereIn('menu_id', DB::table('tblmenumaster_g2g')->where('level', 1)->whereNull('deleted_at')->pluck('id'))
                ->orderBy('id')->pluck('module_key')->all();

        $problems = 0;

        // A mapped source that no longer exists is an example pointing at nothing.
        foreach (SourceRegistry::pages() as $route => $page) {
            foreach ((array) ($page['sources'] ?? []) as $name) {
                if (! $catalog->exists((string) $name)) {
                    $this->error("MISSING SOURCE  {$route} -> {$name}");
                    $problems++;
                }
            }
            if (($page['sources'] ?? []) === [] && empty($page['no_data_reason'])) {
                $this->error("NO REASON       {$route} has no source and no no_data_reason");
                $problems++;
            }
        }

        $tenant = $this->option('tenant');
        $scope = $tenant === null ? null : new \App\Services\Ai\AiRequestScope(0, 'Admin', (int) $tenant, [(int) $tenant], null, null, true, false);

        foreach ($keys as $key) {
            $row = DB::table('ai_modules')->where('module_key', $key)->whereNull('sub_institute_id')->first(['menu_id', 'label']);
            $pages = $builder->menuPages((int) ($row->menu_id ?? 0));
            $map = SourceRegistry::pages();
            $this->line('');
            $this->info(sprintf('%s  (%d pages)', strtoupper($key), count($pages)));

            $live = $scope === null ? null : $builder->forModule($scope, $key);
            $byRoute = $live === null ? [] : array_column($live['pages'], null, 'page.route');
            foreach (($live['pages'] ?? []) as $p) {
                $byRoute[$p['page']['route']] = $p;
            }

            foreach ($pages as $page) {
                $entry = $map[$page['route']] ?? null;

                if ($entry === null) {
                    $this->line(sprintf('  UNMAPPED  %-34s %s', $page['title'], $page['route']));
                    $problems++;
                    continue;
                }

                $sources = (array) ($entry['sources'] ?? []);
                $detail = $sources === [] ? 'no data: ' . ($entry['no_data_reason'] ?? '?') : implode(', ', $sources);
                $rows = isset($byRoute[$page['route']]) ? '  [' . ($byRoute[$page['route']]['status']) . ']' : '';
                $this->line(sprintf('  ok        %-34s %s%s', $page['title'], $detail, $rows));
            }
        }

        $this->line('');
        $problems === 0 ? $this->info('Every page of every audited module has an example or an honest reason.') : $this->error("{$problems} problem(s).");

        return $problems === 0 ? self::SUCCESS : self::FAILURE;
    }
}
