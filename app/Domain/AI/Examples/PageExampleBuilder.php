<?php

namespace App\Domain\AI\Examples;

use App\Domain\AI\Chat\ChatReportService;
use App\Domain\AI\Conversation\ModuleGrounding;
use App\Domain\AI\Examples\Sources\SourceRegistry;
use App\Domain\AI\Reports\ModuleDataSourceCatalog;
use App\Domain\AI\Workspace\SourceFacets;
use App\Services\Ai\AiRequestScope;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One AI Stack example PER PAGE of a module, built from that page's real data.
 *
 * WHAT IS READ AT REQUEST TIME, AND WHAT IS WRITTEN BY PEOPLE
 *
 * The pages come from the sidebar's own menu table (`tblmenumaster_g2g`) under the module's menu row, so a
 * page added to the menu appears here without anyone touching this file. Which data stands behind each page
 * is the module's `SourceGroup::pages()` map (a few words per page: its sources, one sentence on its purpose,
 * a chat action that works there). Everything else is computed now, for the signed-in user's organisation:
 * how many records the page's sources hold, how they break down, a question worth asking that is built from
 * that breakdown, and how to turn the same data into a report.
 *
 * A page with no map entry is reported as `unmapped` - never quietly skipped and never given a made-up
 * example - and `php artisan ai:audit-page-examples` lists them. A page with no data behind it says so and why.
 */
final class PageExampleBuilder
{
    /** Rows read per source: enough for counts and a breakdown without turning a request into an export. */
    private const READ_ROWS = 500;

    /** @var array<string, array<string, mixed>> memo for one request: source name => result summary */
    private array $read = [];

    public function __construct(
        private readonly ModuleDataSourceCatalog $sources,
        private readonly ModuleGrounding $grounding,
        private readonly SourceFacets $facets,
        private readonly ChatReportService $reports,
    ) {
    }

    /**
     * @return array<string, mixed>|null Null when the key is not a registered AI module.
     */
    public function forModule(AiRequestScope $scope, string $module): ?array
    {
        $row = $this->grounding->module($module, $scope->selectedInstituteId);

        if ($row === null) {
            return null;
        }

        $this->read = [];
        $map = SourceRegistry::pages();
        $reportTypes = $this->reportTypes($scope, $module);
        $canReport = $scope->isAdmin || $scope->isPlatformOwner;

        $pages = [];

        $menuPages = $this->menuPages((int) ($row->menu_id ?? 0));
        $seen = array_column($menuPages, 'route');

        // A page that is a real route but not a visible sidebar row (the Document Library) is declared by its
        // module's group with `module` and `title`; it is listed here under that module.
        foreach ($map as $route => $entry) {
            if (($entry['module'] ?? null) === $module && ! in_array($route, $seen, true)) {
                $menuPages[] = [
                    'id' => 0,
                    'title' => (string) ($entry['title'] ?? $route),
                    'route' => (string) $route,
                    'breadcrumb' => [(string) $row->label, (string) ($entry['title'] ?? $route)],
                ];
            }
        }

        foreach ($menuPages as $page) {
            $pages[] = $this->describe($scope, $module, $page, $map[$page['route']] ?? null, $reportTypes, $canReport);
        }

        $count = fn (string $status) => count(array_filter($pages, fn ($p) => $p['status'] === $status));

        return [
            'module' => ['key' => $module, 'label' => (string) $row->label],
            'generated_at' => now()->toIso8601String(),
            'coverage' => [
                'pages' => count($pages),
                'ready' => $count('ready'),
                'empty' => $count('empty'),
                'no_data' => $count('no_data'),
                'unmapped' => $count('unmapped'),
            ],
            'pages' => $pages,
        ];
    }

    /**
     * The module's pages from the live menu: the module's own row and every row beneath it that opens a route.
     *
     * @return array<int, array{id:int, title:string, route:string, breadcrumb:array<int,string>}>
     */
    public function menuPages(int $menuId): array
    {
        if ($menuId <= 0) {
            return [];
        }

        $columns = ['id', 'menu_name', 'access_link', 'parent_id'];
        $active = fn () => DB::table('tblmenumaster_g2g')->whereNull('deleted_at')->where('status', 1);

        $root = $active()->where('id', $menuId)->first($columns);

        if ($root === null) {
            return [];
        }

        $pages = [];
        $add = function (object $menu, array $path) use (&$pages): void {
            if (! empty($menu->access_link)) {
                $pages[] = ['id' => (int) $menu->id, 'title' => (string) $menu->menu_name, 'route' => (string) $menu->access_link, 'breadcrumb' => $path];
            }
        };

        // Breadth-first, a level at a time, each row remembering the names above it.
        $frontier = [(int) $root->id => [(string) $root->menu_name]];
        $add($root, $frontier[(int) $root->id]);

        for ($depth = 0; $depth < 6 && $frontier !== []; $depth++) {
            $next = [];

            foreach ($active()->whereIn('parent_id', array_keys($frontier))->orderBy('sort_order')->orderBy('id')->get($columns) as $menu) {
                $path = array_merge($frontier[(int) $menu->parent_id], [(string) $menu->menu_name]);
                $add($menu, $path);
                $next[(int) $menu->id] = $path;
            }

            $frontier = $next;
        }

        return $pages;
    }

    /**
     * @param  array{id:int, title:string, route:string, breadcrumb:array<int,string>}  $page
     * @param  array<string, mixed>|null  $mapped
     * @param  array<string, array<string, mixed>>  $reportTypes  data source => suggested report
     * @return array<string, mixed>
     */
    private function describe(AiRequestScope $scope, string $module, array $page, ?array $mapped, array $reportTypes, bool $canReport): array
    {
        $base = ['page' => $page, 'module_key' => $module];

        if ($mapped === null) {
            return $base + [
                'status' => 'unmapped',
                'purpose' => null,
                'sources' => [],
                'no_data_reason' => null,
                'example' => null,
                'action' => null,
                'steps' => [],
            ];
        }

        $names = array_values(array_filter((array) ($mapped['sources'] ?? []), fn ($name) => $this->sources->exists((string) $name)));
        $action = $mapped['action'] ?? null;

        if ($names === []) {
            return $base + [
                'status' => 'no_data',
                'purpose' => (string) ($mapped['purpose'] ?? ''),
                'sources' => [],
                'no_data_reason' => (string) ($mapped['no_data_reason'] ?? 'No data source is mapped to this page.'),
                'example' => null,
                'action' => $action,
                'steps' => [],
            ];
        }

        $infos = array_map(fn (string $name) => $this->sourceInfo($scope, $name), $names);
        $primary = $infos[0];
        $totalRows = array_sum(array_map(fn ($i) => $i['error'] === null ? $i['rows'] : 0, $infos));

        $question = $this->question($primary);
        $report = $reportTypes[$primary['name']] ?? null;
        $label = mb_strtolower($primary['label']);

        $run = ['kind' => 'chat', 'label' => 'Ask the assistant', 'message' => $question];
        $reportRun = $report !== null && $canReport
            ? ['kind' => 'report', 'label' => 'Build the report', 'module_key' => $module, 'message' => (string) $report['prompt']]
            : null;

        $steps = [
            sprintf('Open %s from the sidebar (%s).', $page['title'], implode(' › ', $page['breadcrumb'])),
            sprintf(
                'The assistant reads %s for your organisation right now: %s%s.',
                $label,
                $primary['error'] === null ? number_format($primary['rows']) . ($primary['truncated'] ? '+' : '') . ' record' . ($primary['rows'] === 1 ? '' : 's') : 'no readable records',
                $primary['breakdown'] === '' ? '' : ' (' . $primary['breakdown'] . ')'
            ),
            sprintf('Ask it: "%s" - it answers from those records and cites them as [E1], [E2].', $question),
        ];

        if ($report !== null) {
            $steps[] = $canReport
                ? sprintf('Or say "%s" to turn the same data into a saved report you can open, print or send.', $report['prompt'])
                : 'Administrators can also turn the same data into a saved report from the chat.';
        }

        if ($action !== null) {
            $steps[] = 'Or act on this page from the chat: it shows exactly what will be written and waits for your confirmation (and an administrator\'s approval when approvals are on).';
        }

        return $base + [
            'status' => $totalRows > 0 ? 'ready' : 'empty',
            'purpose' => (string) ($mapped['purpose'] ?? ''),
            'sources' => $infos,
            'no_data_reason' => $totalRows > 0 ? null : 'The data behind this page is empty for your organisation right now, so the example has nothing to show yet.',
            'example' => [
                'title' => sprintf('%s: %s', $page['title'], $primary['label']),
                'explanation' => sprintf(
                    '%s Everything below is read from this organisation\'s own records at the moment you open this tab.',
                    (string) ($mapped['purpose'] ?? '')
                ),
                'question' => $question,
                'run' => $run,
                'report_run' => $reportRun,
            ],
            'action' => $action,
            'steps' => $steps,
        ];
    }

    /**
     * What one source holds right now: its label, how many records, how they split, and whether it was readable.
     *
     * @return array<string, mixed>
     */
    private function sourceInfo(AiRequestScope $scope, string $name): array
    {
        if (isset($this->read[$name])) {
            return $this->read[$name];
        }

        $definition = $this->sources->describe($name) ?? ['label' => $name, 'description' => ''];
        $info = [
            'name' => $name,
            'label' => (string) $definition['label'],
            'description' => (string) ($definition['description'] ?? ''),
            'rows' => 0,
            'truncated' => false,
            'columns' => [],
            'breakdown' => '',
            'facet' => null,
            'error' => null,
        ];

        try {
            $result = $this->sources->run($name, $scope, ['limit' => self::READ_ROWS]);
            $info['rows'] = (int) $result['total'];
            $info['truncated'] = (bool) $result['truncated'];
            $info['columns'] = $result['rows'] === [] ? [] : array_keys($result['rows'][0]);

            $facet = $this->facets->compute($result['rows'])[0] ?? null;
            if ($facet !== null) {
                $info['facet'] = $facet['label'];
                $info['breakdown'] = $facet['label'] . ': ' . implode(', ', array_map(
                    fn ($v) => $v['value'] . ' ' . $v['count'],
                    array_slice($facet['values'], 0, 3)
                ));
            }
        } catch (Throwable $exception) {
            report($exception);
            $info['error'] = 'This source could not be read.';
        }

        return $this->read[$name] = $info;
    }

    /** A question worth asking about this data, built from its real breakdown - never a fixed sentence about a number. */
    private function question(array $source): string
    {
        $label = mb_strtolower($source['label']);

        return $source['facet'] !== null
            ? sprintf('What is the breakdown of %s by %s?', $label, mb_strtolower((string) $source['facet']))
            : sprintf('How many %s are there, and what stands out?', $label);
    }

    /**
     * The report types the module can build, by the source each one reads.
     *
     * @return array<string, array<string, mixed>>
     */
    private function reportTypes(AiRequestScope $scope, string $module): array
    {
        try {
            $types = [];
            foreach ($this->reports->suggest($scope, $module) as $type) {
                $types[(string) $type['data_source']] ??= $type;
            }

            return $types;
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }
    }
}
