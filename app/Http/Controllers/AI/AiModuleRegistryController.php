<?php

namespace App\Http\Controllers\AI;

use App\Domain\AI\Discovery\DiscoveredSources;
use App\Domain\AI\Modules\ModuleRegistrar;
use App\Domain\AI\Reports\ModuleDataSourceCatalog;
use App\Services\Ai\AiRequestScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * `GET /api/ai/modules/resolve?menu_id=` - the AI Stack's description of the module behind a menu.
 *
 * This is what lets the front end show a full AI Stack for a module it has never heard of: it asks
 * which module a menu row is, and this registers the module if it is new (from the menu tree, the
 * same way `php artisan ai:sync-modules` does) and answers with the data sources it can actually
 * read, which of them were discovered rather than written by hand, and what it CANNOT do. Nothing
 * in the answer is invented: sources and row counts are read now, for the caller's organisation, and
 * a module with nothing readable is reported as such so the screen can say so.
 */
class AiModuleRegistryController extends AiController
{
    public function __construct(
        private readonly ModuleRegistrar $registrar,
        private readonly ModuleDataSourceCatalog $catalog,
        private readonly DiscoveredSources $discovered,
    ) {
    }

    public function resolve(Request $request)
    {
        try {
            $scope = $this->scope($request);
            $menuId = (int) $request->validate(['menu_id' => 'required|integer|min:1'])['menu_id'];

            $result = $this->registrar->ensureForMenu($menuId);

            if ($result['row'] === null) {
                return $this->success('This menu is not a module.', ['registered' => false, 'reason' => $result['reason']]);
            }

            $row = $result['row'];
            $key = (string) $row->module_key;
            $sources = $this->catalog->forModule($key);
            $enabled = false;

            // A module registered as dormant that now has data behind it is switched on - an off flag
            // with readable sources is a module the rest of the stack would silently skip.
            if ($sources !== [] && $this->registrar->isDormant($row)) {
                $enabled = $this->registrar->enable($row);
            }

            $institute = $scope->selectedInstituteId;
            $payload = Cache::remember(
                'ai.registry.resolve.' . $key . '.' . $institute . '.' . ($scope->isAdmin ? 'a' : 'u') . '.' . md5(json_encode(array_column($sources, 'name'))),
                30,
                fn () => $this->describe($scope, $row, $sources)
            );

            return $this->success('Module resolved.', array_merge($payload, [
                'registered' => true,
                'newly_registered' => $result['created'],
                'newly_enabled' => $enabled,
            ]));
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $sources
     * @return array<string, mixed>
     */
    private function describe(AiRequestScope $scope, object $row, array $sources): array
    {
        $key = (string) $row->module_key;
        $menu = DB::table('tblmenumaster_g2g')->where('id', $row->menu_id)->first(['access_link', 'menu_name']);

        $discoveredNames = array_flip(array_column(array_filter($this->discovered->definitions(), fn ($d) => $d['module'] === $key), 'name'));
        $described = [];
        $primary = null;
        $best = -1;

        foreach ($sources as $source) {
            $rows = null;
            $readable = true;

            try {
                $result = $this->catalog->run($source['name'], $scope, ['limit' => 1]);
                $rows = (int) ($result['exact_total'] ?? $result['total']);
            } catch (Throwable) {
                $readable = false;
            }

            $discovered = isset($discoveredNames[$source['name']]);
            $described[] = [
                'key' => $source['name'],
                'label' => $source['label'],
                'description' => $source['description'],
                'discovered' => $discovered,
                'rows' => $rows,
                'readable' => $readable,
            ];

            // The default source is the one with the most records for this organisation; a hand-written
            // source wins a tie because it is the better-described of the two.
            $score = ($rows ?? -1) * 2 + ($discovered ? 0 : 1);
            if ($readable && $score > $best) {
                $best = $score;
                $primary = $source['name'];
            }
        }

        $report = $this->discovered->report($key);
        $hasCurated = collect($described)->contains(fn ($s) => ! $s['discovered']);
        $hasAny = $described !== [];

        return [
            'key' => $key,
            'label' => (string) $row->label,
            'menu_id' => (int) $row->menu_id,
            'route' => $menu->access_link ?? null,
            'menu_slug' => Str::of((string) ($menu->access_link ?? ''))->after('/module/')->before('/')->toString() ?: null,
            'sources' => $described,
            'primary_source' => $primary,
            'discovery' => $report,
            'support' => [
                'level' => ! $hasAny ? 'none' : ($hasCurated && ! $report['discovered'] ? 'full' : 'partial'),
                'can' => $hasAny ? [
                    'List and count the records of each source for your organisation.',
                    'Group records by their categories (status, type, stage...) with exact counts.',
                    "Name the parent record a foreign key points at (for example a deal's account).",
                    'Build reports and read-only automations from these sources.',
                ] : [],
                'cannot' => array_values(array_filter([
                    $report['discovered'] ? 'Derived figures and business rules (forecasts, scores, margins): only stored columns are read.' : null,
                    $report['discovered'] ? 'Rows of tables with no sub_institute_id column are not read; see the skipped list.' : null,
                    'Write actions: no create, update or delete is offered for this module until one is defined for it.',
                    'Agent tools beyond reading these sources.',
                ])),
                'needs_configuration' => array_values(array_filter([
                    ! $hasAny ? "No readable tables were found. Name the module's tables in config/ai_modules.php (table_prefixes), or add hand-written sources to ModuleDataSourceCatalog." : null,
                    $report['skipped'] !== [] ? count($report['skipped']) . ' table(s) were skipped (see discovery.skipped for each reason).' : null,
                ])),
            ],
        ];
    }
}
