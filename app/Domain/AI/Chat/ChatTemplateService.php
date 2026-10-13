<?php

namespace App\Domain\AI\Chat;

use App\Domain\AI\Reports\ModuleDataSourceCatalog;
use App\Domain\AI\Reports\ReportBuilder;
use App\Domain\AI\Templates\TemplateCatalog;
use App\Domain\AI\Templates\TemplatePreviewData;
use App\Services\Ai\AiRequestScope;
use Illuminate\Support\Facades\DB;

/**
 * Templates from the chat assistant: what the module has, and what one would produce.
 *
 * Both are reads. `suggest()` lists the module's templates through `TemplateCatalog`
 * (tenant-visible: the organisation's own plus the platform's). `preview()` renders one
 * with the organisation's real rows - a report layout is filled with its data source's
 * rows (not saved), a prompt template with `TemplatePreviewData`'s real counts - and
 * saves nothing. Creating or editing a template is a chat ACTION, not part of this class.
 */
class ChatTemplateService
{
    public function __construct(
        private readonly TemplateCatalog $templates,
        private readonly TemplatePreviewData $previewData,
        private readonly ModuleDataSourceCatalog $sources,
        private readonly ReportBuilder $builder,
    ) {
    }

    /**
     * Published and draft templates for the module (and the screens beneath it).
     *
     * @return array<int, array{id:int,name:string,kind:string,status:string,module_key:string|null,module_label:string|null,data_source:string|null,description:string|null,is_platform:bool,screen_path:string|null}>
     */
    public function suggest(AiRequestScope $scope, string $moduleKey): array
    {
        $rows = array_filter(
            $this->templates->forModule($moduleKey, $scope->selectedInstituteId, true),
            fn (array $t) => in_array($t['status'], ['published', 'draft'], true)
        );

        $paths = [];

        return array_values(array_map(function (array $t) use (&$paths) {
            $key = (string) ($t['module_key'] ?? '');
            $paths[$key] ??= $this->screenPath($key);

            return [
                'id' => $t['id'],
                'name' => preg_replace('/\s*\(example\)\s*$/i', '', $t['name']),
                'kind' => $t['kind'],
                'status' => $t['status'],
                'module_key' => $t['module_key'],
                'module_label' => $t['module_label'],
                'data_source' => $t['data_source'],
                'description' => $t['description'],
                'is_platform' => $t['is_platform'],
                // The module screen's Templates tab - where it can be opened and edited.
                'screen_path' => $paths[$key],
            ];
        }, $rows));
    }

    /**
     * What one template produces with this organisation's real records. Saves nothing.
     *
     * @return array<string,mixed>|null  Null when the template is not visible to this organisation.
     */
    public function preview(AiRequestScope $scope, int $templateId): ?array
    {
        $template = $this->templates->find($templateId, $scope->selectedInstituteId);

        if ($template === null) {
            return null;
        }

        $base = [
            'template_id' => $template['id'],
            'name' => $template['name'],
            'kind' => $template['kind'],
            'status' => $template['status'],
            'module_key' => $template['module_key'],
        ];

        if ($template['kind'] === 'report') {
            $source = $template['data_source'];

            if ($source === null || ! $this->sources->exists($source)) {
                return $base + ['html' => null, 'row_count' => 0, 'note' => 'This layout is not bound to an available data source.'];
            }

            $declared = array_column($this->sources->describe($source)['arguments'] ?? [], 'key');
            $arguments = array_intersect_key($template['data_arguments'] ?? [], array_flip($declared));
            $result = $this->sources->run($source, $scope, $arguments);

            if ($result['total'] === 0) {
                return $base + ['html' => null, 'row_count' => 0, 'data_source' => $source, 'note' => 'The data source has no records for this organisation.'];
            }

            $label = (string) (DB::table('ai_modules')->where('module_key', $template['module_key'])->value('label') ?? $template['module_key']);

            return $base + [
                'data_source' => $source,
                'row_count' => $result['total'],
                'html' => $this->builder->render(
                    $template['html_layout'],
                    $result['rows'],
                    array_keys($result['rows'][0]),
                    $base['name'],
                    $label,
                    $scope->selectedInstituteId
                ),
                'note' => null,
            ];
        }

        $rendered = $this->templates->preview(
            (string) ($template['system_prompt'] ?? ''),
            (string) $template['user_prompt'],
            $this->previewData->forInstitute($scope->selectedInstituteId)
        );

        return $base + ['html' => null, 'row_count' => null, 'rendered' => $rendered, 'note' => null];
    }

    /** The access link of the module's menu row, with the Templates tab selected. */
    private function screenPath(string $moduleKey): ?string
    {
        if ($moduleKey === '') {
            return null;
        }

        $menuId = DB::table('ai_modules')->where('module_key', $moduleKey)->whereNull('sub_institute_id')->value('menu_id');
        $link = $menuId === null ? null : DB::table('tblmenumaster_g2g')->where('id', $menuId)->value('access_link');

        return $link === null || $link === '' ? null : '/' . ltrim((string) $link, '/') . '?aiTab=templates';
    }
}
