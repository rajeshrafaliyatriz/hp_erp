<?php

namespace App\Domain\AI\Reports;

use App\Domain\AI\Support\AiAuditLogger;
use App\Domain\AI\Templates\OrganisationBranding;
use App\Domain\AI\Templates\ReportLayoutRenderer;
use App\Services\Ai\AiRequestScope;
use Illuminate\Support\Facades\DB;

/**
 * The one code path that turns a read-only data source (and optionally a published
 * report layout) into a saved `ai_generated_reports` row.
 *
 * Extracted from `AiReportController::build()` so the Templates tab and the chat assistant
 * save a report identically: same source run, same layout rendering, same row, same audit
 * event. Nothing here calls a model; the figures are the database's.
 */
class ReportBuilder
{
    public function __construct(
        private readonly ModuleDataSourceCatalog $sources,
        private readonly ReportLayoutRenderer $renderer,
        private readonly OrganisationBranding $branding,
        private readonly AiAuditLogger $audit,
    ) {
    }

    /** A module's published report layout: the organisation's own first, then the platform's. */
    public function activeLayout(string $module, int|string|null $institute): ?object
    {
        return DB::table('ai_templates')
            ->where('module_key', $module)
            ->where('kind', 'report')
            ->where('status', 'published')
            ->where(fn ($q) => $q->where('sub_institute_id', $institute)->orWhereNull('sub_institute_id'))
            ->orderByRaw('sub_institute_id IS NULL ASC')
            ->orderByDesc('updated_at')
            ->first();
    }

    /**
     * Run the source and save the report.
     *
     * @param  array<string, mixed>  $arguments  Already filtered to the source's declared arguments.
     * @return array{id: int|null, title: string, row_count: int, columns: array<int, string>, generated_at: string|null}
     *         `id` is null when the source returned no rows: an empty result is an answer, not a document.
     */
    public function build(
        AiRequestScope $scope,
        string $moduleKey,
        string $moduleLabel,
        string $sourceName,
        ?object $layout,
        array $arguments,
        ?string $question = null,
    ): array {
        $institute = $scope->selectedInstituteId;
        $result = $this->sources->run($sourceName, $scope, $arguments);
        $source = $this->sources->describe($sourceName);
        $title = $layout !== null
            ? preg_replace('/\s*\(example\)\s*$/i', '', (string) $layout->name)
            : ($source['label'] ?? $moduleLabel);

        if ($result['total'] === 0) {
            return ['id' => null, 'title' => $title, 'row_count' => 0, 'columns' => [], 'generated_at' => null];
        }

        $columns = array_keys($result['rows'][0]);
        $html = $this->render($layout?->html_layout, $result['rows'], $columns, $title, $moduleLabel, $institute);
        $now = now();

        $id = (int) DB::table('ai_generated_reports')->insertGetId([
            'sub_institute_id' => $institute,
            'client_id' => $scope->clientId,
            'module_key' => $moduleKey,
            'layout_template_id' => $layout?->id,
            'title' => mb_substr($title, 0, 250),
            'html_content' => $html,
            'question' => $question === null ? null : mb_substr($question, 0, 2000),
            'source_tool' => $sourceName,
            'arguments' => json_encode($arguments),
            'row_count' => $result['total'],
            'status' => 1,
            'created_by' => $scope->userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->audit->record('ai.report.generated', $scope, [
            'related_type' => 'ai_generated_reports',
            'related_id' => $id,
            'message' => sprintf('Report "%s" built from %s (%d rows).', $title, $sourceName, $result['total']),
        ]);

        return [
            'id' => $id,
            'title' => $title,
            'row_count' => $result['total'],
            'columns' => $columns,
            'generated_at' => $now->toDateTimeString(),
        ];
    }

    public function render(?string $layout, array $rows, array $columns, string $title, string $moduleLabel, int|string|null $institute): string
    {
        $layout = trim((string) $layout) !== ''
            ? (string) $layout
            // No published layout: the plain table LMS_K12 builds in the same case.
            : '<h2><<report_title>></h2><p><small><<row_count>> record(s) · generated <<generated_at>></small></p><<rows_table>>';

        $branding = $this->branding->for($institute);

        return $this->renderer->render($layout, $rows, $columns, [
            'report_title' => $title,
            'module' => $moduleLabel,
            'institute_name' => $branding['institute_name'] ?? '',
        ])['html'];
    }
}
