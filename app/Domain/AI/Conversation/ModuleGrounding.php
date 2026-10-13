<?php

namespace App\Domain\AI\Conversation;

use App\Domain\AI\Modules\ModuleRollUp;
use App\Domain\AI\Reports\ModuleDataSourceCatalog;
use App\Domain\AI\Support\SchemaCache;
use App\Domain\AI\Workspace\SourceFacets;
use App\Services\Ai\AiRequestScope;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * What the assistant may know when it is opened inside one module.
 *
 * `OrganisationContext` briefs the model on the whole organisation - employees, tasks,
 * competencies. Inside the LMS that is exactly wrong: a question about learning would be
 * answered from figures that belong to other modules. This class builds the module's own
 * briefing instead, from the module's real rows, and the pipeline uses it in place of the
 * organisation-wide one.
 *
 * WHAT COUNTS AS "THE MODULE"
 *
 * The key itself plus every screen beneath it, derived by `ModuleRollUp` from the menu
 * tree. Nothing is listed here, so a new screen joins its parent's briefing without a
 * code change.
 *
 * NOTHING IS ESTIMATED, NOTHING PERSONAL IS PASSED ON
 *
 * Every line is a count or a name read from the database for the caller's organisation.
 * A data source whose rows are about individual people (a learner, an employee) is
 * summarised as a count only - the assistant is told how many rows exist and which
 * columns they carry, never who they are.
 */
final class ModuleGrounding
{
    /** Rows of a non-personal source passed to the model. */
    private const SAMPLE_ROWS = 12;

    /** Characters of row data passed per source. */
    private const SAMPLE_CHARS = 1800;

    /** Columns whose presence marks a source's rows as being about individual people. */
    private const PERSONAL_COLUMN = '/(^|_)(user|learner|employee|candidate|person|assessee)(_|$)/i';

    public function __construct(
        private readonly ModuleRollUp $rollUp,
        private readonly ModuleDataSourceCatalog $sources,
        private readonly SchemaCache $schema,
        private readonly SourceFacets $facets,
    ) {
    }

    /**
     * The registered `ai_modules` row for a key, or null.
     *
     * A tenant's own row beats the platform's. Null means the key names nothing, and the
     * caller should refuse the request rather than answer under an invented module.
     */
    public function module(string $moduleKey, int|string|null $institute): ?object
    {
        if (! $this->schema->hasTable('ai_modules')) {
            return null;
        }

        return DB::table('ai_modules')
            ->where('module_key', $moduleKey)
            ->where(fn ($q) => $q->where('sub_institute_id', $institute)->orWhereNull('sub_institute_id'))
            ->orderByRaw('sub_institute_id IS NULL ASC')
            ->first();
    }

    /** @return array<int, string> The key and the screens beneath it. */
    public function keys(string $moduleKey): array
    {
        return $this->rollUp->keysFor($moduleKey);
    }

    /**
     * The briefing, or null when nothing about this module could be read.
     */
    public function briefing(AiRequestScope $scope, string $moduleKey, ?string $focusModule = null): ?string
    {
        $keys = $this->keys($moduleKey);
        $sections = array_filter([
            $this->sourceLines($scope, $moduleKey, $focusModule),
            $this->templateLines($scope, $keys),
            $this->agentLines($scope, $keys),
            $this->policyLines($scope, $keys),
        ]);

        return $sections === [] ? null : implode("\n\n", $sections);
    }

    /** The data sources this module reads, with real row counts and a sample where safe. */
    private function sourceLines(AiRequestScope $scope, string $moduleKey, ?string $focusModule = null): ?string
    {
        $lines = [];
        $sources = $this->sources->forModule($moduleKey);

        // The page's own module first, so what the user is looking at leads the briefing.
        if ($focusModule !== null) {
            usort($sources, fn ($a, $b) => (int) ($b['module'] === $focusModule) <=> (int) ($a['module'] === $focusModule));
        }

        foreach ($sources as $source) {
            try {
                $result = $this->sources->run($source['name'], $scope, ['limit' => 500]);
            } catch (Throwable) {
                // One unreadable source is not worth losing the rest of the briefing.
                continue;
            }

            $count = $result['total'] . ($result['truncated'] ? '+' : '');
            $columns = $result['rows'] === [] ? [] : array_keys($result['rows'][0]);
            $line = sprintf('- %s (%s): %s rows', $source['label'], $source['name'], $count);

            // Aggregates are safe even where the rows are about people: counts per category,
            // never who. They are what lets "how many X are Y" be answered from real totals.
            $breakdown = $this->breakdown($result);

            if ($columns !== [] && $this->isPersonal($columns)) {
                $lines[] = $line . ' - one row per person, so individual rows are not shared; columns: '
                    . implode(', ', $columns) . ($breakdown === '' ? '' : '. ' . $breakdown);

                continue;
            }

            if ($breakdown !== '') {
                $line .= '. ' . $breakdown;
            }

            if ($result['rows'] !== []) {
                $sample = json_encode(
                    array_slice($result['rows'], 0, self::SAMPLE_ROWS),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
                );
                $line .= '. First rows: ' . mb_strimwidth((string) $sample, 0, self::SAMPLE_CHARS, '...');
            }

            $lines[] = $line;
        }

        return $lines === [] ? null : "DATA THIS MODULE READS:\n" . implode("\n", $lines);
    }

    /**
     * "Counts by status: active 12, draft 3; by department: ..." for the rows read.
     *
     * @param  array{rows: array<int, array<string, mixed>>, total: int, truncated: bool}  $result
     */
    private function breakdown(array $result): string
    {
        $parts = [];

        foreach ($this->facets->compute($result['rows']) as $facet) {
            $values = implode(', ', array_map(fn ($v) => $v['value'] . ' ' . $v['count'], $facet['values']));
            $parts[] = $facet['label'] . ': ' . $values;
        }

        if ($parts === []) {
            return '';
        }

        return 'Counts by ' . implode('; by ', $parts) . ($result['truncated'] ? ' (first rows read only)' : '');
    }

    /** @param array<int, string> $columns */
    public function isPersonal(array $columns): bool
    {
        foreach ($columns as $column) {
            if (preg_match(self::PERSONAL_COLUMN, (string) $column) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Published report templates and prompts for the module - what it can generate.
     *
     * @param  array<int, string>  $keys
     */
    private function templateLines(AiRequestScope $scope, array $keys): ?string
    {
        if (! $this->schema->hasTable('ai_templates')) {
            return null;
        }

        $rows = DB::table('ai_templates')
            ->whereIn('module_key', $keys)
            ->where('status', 'published')
            ->where(fn ($q) => $q->where('sub_institute_id', $scope->selectedInstituteId)->orWhereNull('sub_institute_id'))
            ->orderBy('kind')
            ->orderBy('name')
            ->limit(40)
            ->get(['name', 'kind', 'data_source']);

        if ($rows->isEmpty()) {
            return 'AI TEMPLATES: none are published for this module.';
        }

        return "AI TEMPLATES PUBLISHED FOR THIS MODULE:\n" . $rows->map(fn ($row) => sprintf(
            '- %s (%s%s)',
            $row->name,
            $row->kind === 'report' ? 'report' : 'prompt',
            $row->data_source ? ', reads ' . $row->data_source : ''
        ))->implode("\n");
    }

    /**
     * Automations (tool agents) configured for the module.
     *
     * @param  array<int, string>  $keys
     */
    private function agentLines(AiRequestScope $scope, array $keys): ?string
    {
        if (! $this->schema->hasTable('agentic_agents')) {
            return null;
        }

        $rows = DB::table('agentic_agents')
            ->where('sub_institute_id', $scope->selectedInstituteId)
            ->whereIn('sub_module', $keys)
            ->orderBy('name')
            ->limit(40)
            ->get(['name', 'status', 'tools']);

        if ($rows->isEmpty()) {
            return 'AUTOMATIONS: none are configured for this module.';
        }

        return "AUTOMATIONS CONFIGURED FOR THIS MODULE:\n" . $rows->map(function ($row) {
            $tools = json_decode((string) $row->tools, true);

            return sprintf(
                '- %s (%s; tools: %s)',
                $row->name,
                $row->status === 'deployed' ? 'active' : $row->status,
                is_array($tools) && $tools !== [] ? implode(', ', $tools) : 'none'
            );
        })->implode("\n");
    }

    /**
     * Active policies assigned to the module itself.
     *
     * @param  array<int, string>  $keys
     */
    private function policyLines(AiRequestScope $scope, array $keys): ?string
    {
        if (! $this->schema->hasTable('ai_policies') || ! $this->schema->hasTable('ai_policy_assignments')) {
            return null;
        }

        $institute = $scope->selectedInstituteId;

        $moduleIds = DB::table('ai_modules')
            ->whereIn('module_key', $keys)
            ->where(fn ($q) => $q->where('sub_institute_id', $institute)->orWhereNull('sub_institute_id'))
            ->pluck('id');

        $rows = DB::table('ai_policies as p')
            ->join('ai_policy_assignments as a', 'a.policy_id', '=', 'p.id')
            ->where('p.status', 1)
            ->where('a.status', 1)
            ->where('a.scope_type', 'module')
            ->whereIn('a.scope_id', $moduleIds)
            ->where(fn ($q) => $q->where('p.sub_institute_id', $institute)->orWhereNull('p.sub_institute_id'))
            ->distinct()
            ->orderBy('p.name')
            ->get(['p.name', 'p.policy_type']);

        if ($rows->isEmpty()) {
            return 'POLICIES: no active policy is assigned to this module.';
        }

        return "ACTIVE POLICIES ASSIGNED TO THIS MODULE:\n" . $rows->map(
            fn ($row) => sprintf('- %s (%s)', $row->name, str_replace('_', '-', (string) $row->policy_type))
        )->implode("\n");
    }
}
