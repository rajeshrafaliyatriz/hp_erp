<?php

namespace App\Http\Controllers\AI;

use App\Domain\AI\Reports\ModuleDataSourceCatalog;
use App\Domain\AI\Reports\ReportBuilder;
use App\Domain\AI\Support\AiAuditLogger;
use App\Domain\AI\Support\SchemaCache;
use App\Domain\AI\Templates\OrganisationBranding;
use App\Domain\AI\Templates\ReportLayoutRenderer;
use App\Services\Ai\AiRequestScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Build, read, edit and refresh a report from a module's AI Stack — the backend of the
 * shared (LMS_K12-universal) Templates tab's "Build report" and of the saved-report page.
 *
 * SAME CONTRACT AS LMS_K12
 *
 * `POST /workspace/report {route, arguments}` answers LMS_K12's `WorkspaceReport` shape,
 * and `/reports/{id}` answers its `AiReport` shape, because the screens reading them are
 * LMS_K12's. What differs is behind them: G2G resolves which module a route belongs to
 * from its own menu catalogue (`tblmenumaster_g2g.access_link` → `ai_modules.menu_id`),
 * and reads the module's rows from `ModuleDataSourceCatalog`.
 *
 * NOTHING HERE IS GENERATED
 *
 * A report is a layout an administrator published, filled by `ReportLayoutRenderer`
 * with rows a read-only source returned — escaped substitution, no model. So a report
 * can state a figure, and the figure is the database's.
 */
class AiReportController extends AiController
{
    public function __construct(
        private readonly ModuleDataSourceCatalog $sources,
        private readonly ReportLayoutRenderer $renderer,
        private readonly OrganisationBranding $branding,
        private readonly AiAuditLogger $audit,
        private readonly SchemaCache $schema,
        private readonly ReportBuilder $builder,
    ) {
    }

    /** Build a report for the module the given screen route belongs to. */
    public function build(Request $request)
    {
        try {
            $scope = $this->scope($request);
            $institute = $scope->selectedInstituteId;

            $data = $request->validate([
                'route' => 'required|string|max:255',
                'arguments' => 'nullable|array',
            ]);

            $module = $this->moduleForRoute($data['route'], $institute);

            if ($module === null) {
                return $this->failure('This page is not a module a report can be built from.', 422);
            }

            $layout = $this->activeLayout($module->module_key, $institute);
            $sourceName = $layout?->data_source ?: ($this->sources->forModule($module->module_key)[0]['name'] ?? null);

            // A layout bound to another module's source is refused rather than run: a
            // report built from this module's screen reads this module's data only.
            if ($sourceName === null || ! in_array($sourceName, array_column($this->sources->forModule($module->module_key), 'name'), true)) {
                return $this->failure('This module has no read-only data source a report can be built from.', 422);
            }

            $arguments = array_merge(
                $this->decodeArray($layout->data_arguments ?? null),
                $this->declaredOnly($sourceName, $data['arguments'] ?? [])
            );

            $built = $this->builder->build($scope, $module->module_key, (string) $module->label, $sourceName, $layout, $arguments);
            $title = $built['title'];

            // An empty result is an answer, not a document.
            if ($built['id'] === null) {
                return $this->success('No records matched.', [
                    'module' => $module->module_key,
                    'template_id' => null,
                    'title' => $title,
                    'row_count' => 0,
                    'columns' => [],
                    'source_tool' => $sourceName,
                    'layout_template_id' => $layout?->id === null ? null : (int) $layout->id,
                    'layout_name' => $layout?->name,
                    'template_link' => null,
                ]);
            }

            $id = $built['id'];
            $columns = $built['columns'];
            $result = ['total' => $built['row_count']];

            return $this->success('Report built.', [
                'module' => $module->module_key,
                'template_id' => $id,
                'title' => $title,
                'row_count' => $result['total'],
                'columns' => $columns,
                'source_tool' => $sourceName,
                'layout_template_id' => $layout?->id === null ? null : (int) $layout->id,
                'layout_name' => $layout?->name,
                'template_link' => '/ai/reports/' . $id,
            ], 201);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    public function show(Request $request, int $id)
    {
        try {
            $institute = $this->scope($request)->selectedInstituteId;
            $row = $this->owned($id, $institute);

            if ($row === null) {
                return $this->failure('That report was not found.', 404);
            }

            return $this->success('Report resolved.', ['report' => $this->present($row)]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /** Save an edited title and document. */
    public function update(Request $request, int $id)
    {
        try {
            $scope = $this->scope($request);
            $row = $this->owned($id, $scope->selectedInstituteId);

            if ($row === null) {
                return $this->failure('That report was not found.', 404);
            }

            $data = $request->validate([
                'title' => 'required|string|max:250',
                'html' => 'required|string|max:2000000',
            ]);

            DB::table('ai_generated_reports')->where('id', $id)->update([
                'title' => trim($data['title']),
                'html_content' => $data['html'],
                'updated_at' => now(),
            ]);

            $this->audit->record('ai.report.edited', $scope, [
                'related_type' => 'ai_generated_reports',
                'related_id' => $id,
                'message' => sprintf('Report "%s" edited.', trim($data['title'])),
            ]);

            return $this->success('Report saved.', ['report' => $this->present($this->owned($id, $scope->selectedInstituteId))]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /**
     * Re-read the figures against live records, with the same source, arguments and layout.
     *
     * Refused rather than blanking a document when its source can no longer be run.
     */
    public function regenerate(Request $request, int $id)
    {
        try {
            $scope = $this->scope($request);
            $institute = $scope->selectedInstituteId;
            $row = $this->owned($id, $institute);

            if ($row === null) {
                return $this->failure('That report was not found.', 404);
            }

            if (! $row->source_tool || ! $this->sources->exists((string) $row->source_tool)) {
                return $this->failure('This report\'s data source is no longer available, so its figures cannot be refreshed.', 422);
            }

            $arguments = $this->decodeArray($row->arguments);
            $result = $this->sources->run((string) $row->source_tool, $scope, $arguments);
            $layout = $row->layout_template_id
                ? DB::table('ai_templates')->where('id', $row->layout_template_id)->value('html_layout')
                : null;
            $moduleLabel = DB::table('ai_modules')->where('module_key', $row->module_key)->value('label') ?? $row->module_key;
            $columns = $result['rows'] === [] ? [] : array_keys($result['rows'][0]);
            $html = $this->render($layout, $result['rows'], $columns, (string) $row->title, (string) $moduleLabel, $institute);

            DB::table('ai_generated_reports')->where('id', $id)->update([
                'html_content' => $html,
                'row_count' => $result['total'],
                'updated_at' => now(),
            ]);

            return $this->success('Figures refreshed.', [
                'html' => $html,
                'row_count' => $result['total'],
                'module' => (string) $row->module_key,
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /**
     * Run one read-only source and return its rows — the Knowledge Base tab's "Check",
     * and what an Automations agent runs as its tool. Read-only by construction: the
     * catalogue holds nothing that writes.
     */
    public function runSource(Request $request, string $name)
    {
        try {
            $scope = $this->scope($request);

            if (! $this->sources->exists($name)) {
                return $this->failure("There is no data source called {$name}.", 404);
            }

            $data = $request->validate(['arguments' => 'nullable|array']);
            $result = $this->sources->run($name, $scope, $this->declaredOnly($name, $data['arguments'] ?? []));

            return $this->success('Data source read.', [
                'source' => $this->sources->describe($name),
                'data' => ['rows' => $result['rows'], 'total' => $result['total'], 'truncated' => $result['truncated']],
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    // ------------------------------------------------------------------ internals

    /** The AI Stack module a screen route belongs to, through G2G's own menu catalogue. */
    private function moduleForRoute(string $route, int|string|null $institute): ?object
    {
        $route = '/' . ltrim(trim(parse_url($route, PHP_URL_PATH) ?: $route), '/');
        $menuId = DB::table('tblmenumaster_g2g')->where('access_link', $route)->value('id');

        if ($menuId === null) {
            return null;
        }

        return DB::table('ai_modules')
            ->where('menu_id', $menuId)
            ->where('status', 1)
            ->where(fn ($q) => $q->where('sub_institute_id', $institute)->orWhereNull('sub_institute_id'))
            ->orderByRaw('sub_institute_id IS NULL ASC')
            ->first(['id', 'module_key', 'label']);
    }

    /** This module's published report layout: the organisation's own first, then the platform's. */
    private function activeLayout(string $module, int|string|null $institute): ?object
    {
        return $this->builder->activeLayout($module, $institute);
    }

    private function render(?string $layout, array $rows, array $columns, string $title, string $moduleLabel, int|string|null $institute): string
    {
        return $this->builder->render($layout, $rows, $columns, $title, $moduleLabel, $institute);
    }

    /** Only the arguments the source declares; anything else is dropped, never passed. */
    private function declaredOnly(string $source, array $given): array
    {
        $declared = array_column($this->sources->describe($source)['arguments'] ?? [], 'key');

        return array_intersect_key($given, array_flip($declared));
    }

    private function owned(int $id, int|string|null $institute): ?object
    {
        if (! $this->schema->hasTable('ai_generated_reports')) {
            return null;
        }

        return DB::table('ai_generated_reports')
            ->where('id', $id)
            ->where('sub_institute_id', $institute)
            ->where('status', 1)
            ->first();
    }

    private function present(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'title' => (string) $row->title,
            'created_on' => $row->created_at === null ? null : (string) $row->created_at,
            'html' => (string) $row->html_content,
            'figures' => $row->source_tool === null ? null : [
                'module' => (string) $row->module_key,
                'source' => (string) $row->source_tool,
                'generated_at' => (string) ($row->updated_at ?? $row->created_at),
            ],
        ];
    }

    private function decodeArray(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) ? $decoded : [];
    }
}
