<?php

namespace App\Domain\AI\Templates;

use App\Domain\AI\Conversation\ModuleGrounding;
use App\Domain\AI\Reports\ModuleDataSourceCatalog;
use App\Domain\AI\Support\ActiveRowFilter;
use App\Domain\AI\Support\SchemaCache;
use App\Services\Ai\AiRequestScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The values Preview substitutes into a template, read from this organisation's own records.
 *
 * WHY REAL DATA RATHER THAN A FIXED SAMPLE
 *
 * The question an author is asking is not only "did my placeholder land where I meant it to" but "does the
 * prompt read well around the data it will actually receive". Three invented rows cannot answer that: an
 * author who writes "list each outstanding balance" cannot see that their own records have no such field.
 *
 * WHICH DATA
 *
 *   - With a module (the editor knows which AI Stack it is in): the first of THAT module's read-only data
 *     sources that holds rows and is not about individual people. Another module's records are never used.
 *   - Without one: the organisation's competency taxonomy (`competency`, `s_competency_frameworks`,
 *     `jobrole_competency_map`) - the capability model, never a person, a rating or an assessment.
 *
 * Scope is `AiRequestScope::selectedInstituteId`, never request input.
 *
 * NEVER INVENTED
 *
 * When there is nothing to show - a new organisation, an unreadable table - the values SAY so
 * (`(no records found)`, and `data_source` names the reason) instead of substituting made-up rows. A preview
 * of invented competencies cannot be told apart from a real one by anyone who has not read this comment.
 */
final class TemplatePreviewData
{
    /** How many records a preview shows. Enough to see the shape, short enough to read. */
    private const ROWS = 3;

    public function __construct(
        private readonly ModuleDataSourceCatalog $sources,
        private readonly ModuleGrounding $grounding,
    ) {
    }

    /**
     * The variable bag, keyed by the names in `TemplateVariableCatalog`.
     *
     * @return array<string, string>
     */
    public function forInstitute(int|string|null $institute, ?AiRequestScope $scope = null, ?string $moduleKey = null): array
    {
        try {
            $live = ($scope !== null && $moduleKey !== null && $moduleKey !== '')
                ? $this->fromModule($scope, $moduleKey)
                : $this->fromCompetencies($institute);

            return $live ?? $this->nothing('this organisation has no records for ' . ($moduleKey ?: 'the competency library') . ' yet');
        } catch (Throwable $exception) {
            report($exception);

            return $this->nothing('the records could not be read');
        }
    }

    /**
     * A module's own first non-empty, non-personal data source.
     *
     * @return array<string, string>|null
     */
    private function fromModule(AiRequestScope $scope, string $moduleKey): ?array
    {
        $module = $this->grounding->module($moduleKey, $scope->selectedInstituteId);

        if ($module === null) {
            return null;
        }

        foreach ($this->sources->forModule($moduleKey) as $source) {
            try {
                $result = $this->sources->run($source['name'], $scope, ['limit' => self::ROWS]);
            } catch (Throwable) {
                continue;
            }

            if ($result['rows'] === []) {
                continue;
            }

            $columns = array_keys($result['rows'][0]);

            if ($this->grounding->isPersonal($columns, $source['name'])) {
                continue;
            }

            $total = (int) ($result['exact_total'] ?? $result['total']);

            return [
                'records' => implode("\n", array_map(fn (array $row) => $this->line($row), $result['rows'])),
                'metrics' => sprintf('%s: %d record%s', $source['label'], $total, $total === 1 ? '' : 's'),
                'record_count' => (string) $total,
                'rows_shown' => (string) count($result['rows']),
                'is_partial' => $total > count($result['rows']) ? 'yes' : 'no',
                'page_title' => (string) $source['label'],
                'page_type' => 'list',
                'filters' => 'none',
                'search_query' => '',
                'data_source' => 'your organisation’s ' . mb_strtolower((string) $source['label']) . ' records (' . $source['name'] . ')',
                'module' => (string) $module->label,
                'entity_label' => '',
            ];
        }

        return null;
    }

    /** `- first column (other: value, ...)` for one row, listing only populated, scalar fields. */
    private function line(array $row): string
    {
        $head = null;
        $fields = [];

        foreach ($row as $column => $value) {
            if (! is_scalar($value) || trim((string) $value) === '') {
                continue;
            }

            if ($head === null && ! preg_match('/(^|_)ids?$/i', (string) $column)) {
                $head = mb_strimwidth((string) $value, 0, 60, '...');
                continue;
            }

            if (count($fields) < 5) {
                $fields[] = $column . ': ' . mb_strimwidth((string) $value, 0, 40, '...');
            }
        }

        return $fields === [] ? '- ' . ($head ?? 'record') : sprintf('- %s (%s)', $head ?? 'record', implode(', ', $fields));
    }

    /**
     * This organisation's real capability model, or null when it has none.
     *
     * @return array<string, string>|null
     */
    private function fromCompetencies(int|string|null $institute): ?array
    {
        if ($institute === null || trim((string) $institute) === '' || ! Schema::hasTable('competency')) {
            return null;
        }

        $query = DB::table('competency')->where('sub_institute_id', $institute);

        // NOT `where('status', 1)`: `competency.status` is a varchar ('active' | 'draft' | 'published'), so the
        // integer comparison matched nothing. See ActiveRowFilter.
        ActiveRowFilter::apply($query, 'competency');

        $rows = $query->orderBy('name')->limit(self::ROWS)->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $total = (int) DB::table('competency')->where('sub_institute_id', $institute)->count();
        $frameworks = $this->countFor('s_competency_frameworks', $institute);
        $mappings = $this->countFor('jobrole_competency_map', $institute);

        return [
            'records' => $rows->map(fn ($row) => $this->describe($row))->implode("\n"),
            'metrics' => sprintf('Competencies: %d; Frameworks: %d; Job-role requirements mapped: %d', $total, $frameworks, $mappings),
            'record_count' => (string) $total,
            'rows_shown' => (string) $rows->count(),
            // Honest: a window onto a larger set whenever it is one, so a prompt does not describe three rows as the whole library.
            'is_partial' => $total > $rows->count() ? 'yes' : 'no',
            'page_title' => 'Competency library',
            'page_type' => 'list',
            'filters' => 'none',
            'search_query' => '',
            'data_source' => 'your organisation’s competency records',
            'module' => 'Capability Intelligence',
            'entity_label' => '',
        ];
    }

    /** One competency, in the shape the catalogue documents: `- Label (field: value, …)`, populated fields only. */
    private function describe(object $row): string
    {
        $fields = [];

        foreach (['code' => 'code', 'competency_type' => 'type', 'criticality' => 'criticality'] as $column => $label) {
            $value = trim((string) ($row->{$column} ?? ''));

            if ($value !== '') {
                $fields[] = "{$label}: {$value}";
            }
        }

        $name = trim((string) ($row->name ?? '')) ?: 'Unnamed competency';

        return $fields === [] ? "- {$name}" : sprintf('- %s (%s)', $name, implode(', ', $fields));
    }

    private function countFor(string $table, int|string $institute): int
    {
        if (! Schema::hasTable($table) || ! app(SchemaCache::class)->hasColumn($table, 'sub_institute_id')) {
            return 0;
        }

        return (int) DB::table($table)->where('sub_institute_id', $institute)->count();
    }

    /**
     * What the variables say when there is nothing real to put in them.
     *
     * @return array<string, string>
     */
    private function nothing(string $reason): array
    {
        return [
            'records' => '(no records found)',
            'metrics' => 'No records',
            'record_count' => '0',
            'rows_shown' => '0',
            'is_partial' => 'no',
            'page_title' => '',
            'page_type' => 'list',
            'filters' => 'none',
            'search_query' => '',
            'data_source' => 'no records: ' . $reason,
            'module' => '',
            'entity_label' => '',
        ];
    }
}
