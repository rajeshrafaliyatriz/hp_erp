<?php

namespace App\Domain\Reports;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Turns a report's `definition` (JSON) into SQL and shapes the result for
 * its `report_type` — the only place a saved (or previewed) report actually
 * becomes a query. See Docs/reports-engine/PLAN.md §3 for the full design.
 *
 * EVERY SAFETY PROPERTY LIVES HERE, NOT IN THE CALLER
 *
 * Tenant scoping and soft-delete filtering are applied unconditionally from
 * the module's own ReportModuleDefinition — never from the JSON a user
 * supplied, so a report definition cannot express cross-tenant access no
 * matter what it contains. Joins only follow registry-declared paths (3
 * fixed code paths below, direct/pivot/polymorphic); there is no way to
 * express an arbitrary join from a report's own JSON. Filter fields are
 * resolved through the registry to a trusted column before use; an
 * unresolvable field throws rather than being silently dropped.
 */
final class ReportQueryExecutionService
{
    private const MAX_TABULAR_ROWS = 2000;
    private const MAX_GROUPS = 500;

    /** @var array<string, string> */
    private const OPERATORS = [
        'equals' => '=',
        'not_equals' => '!=',
        'greater_than' => '>',
        'greater_or_equal' => '>=',
        'less_than' => '<',
        'less_or_equal' => '<=',
    ];

    public function __construct(private readonly ReportModuleRegistry $registry)
    {
    }

    public function execute(string $reportType, string $primaryModuleKey, array $definition, int $subInstituteId): array
    {
        $primary = $this->registry->get($primaryModuleKey);
        $query = $this->buildBaseQuery($primary, $subInstituteId);

        $this->applyJoins($query, $primary, $definition['secondary_modules'] ?? []);
        $this->applyStandardFilter($query, $primary, $definition['standard_filter'] ?? null, $subInstituteId);
        $this->applyAdvancedFilter($query, $definition['advanced_filter'] ?? null, $subInstituteId);

        return match ($reportType) {
            'tabular' => $this->runTabular($query, $primary, $definition, $subInstituteId),
            'summary' => $this->runSummary($query, $definition, $subInstituteId),
            'pivot' => $this->runPivot($query, $definition, $subInstituteId),
            'chart' => $this->runChart($query, $definition, $subInstituteId),
            default => throw new \InvalidArgumentException("Unknown report type: {$reportType}"),
        };
    }

    private function buildBaseQuery(ReportModuleDefinition $primary, int $subInstituteId): Builder
    {
        $query = DB::table($primary->table . ' as ' . $primary->alias)
            ->where($primary->alias . '.' . $primary->tenantColumn, $subInstituteId);

        if ($primary->softDeleteColumn !== null) {
            $query->whereNull($primary->alias . '.' . $primary->softDeleteColumn);
        }

        foreach ($primary->alwaysJoins as $join) {
            $query->leftJoin(
                $join['table'] . ' as ' . $join['alias'],
                $primary->alias . '.' . $join['localColumn'],
                '=',
                $join['alias'] . '.' . $join['foreignColumn'],
            );
        }

        return $query;
    }

    /** @param string[] $secondaryModuleKeys */
    private function applyJoins(Builder $query, ReportModuleDefinition $primary, array $secondaryModuleKeys): void
    {
        foreach ($secondaryModuleKeys as $targetKey) {
            $join = $primary->join($targetKey);

            if ($join === null) {
                throw new \InvalidArgumentException("{$primary->key} declares no join to {$targetKey}");
            }

            $target = $this->registry->get($targetKey);

            /*
             * LEFT JOIN, never INNER — confirmed against a real failure:
             * previewing tasks + their project (a secondary module most
             * tasks have no link to at all) silently returned ZERO rows
             * with an INNER join, because adding a column should never
             * also act as an implicit filter. The real vtiger reference
             * this engine is modeled on agrees - "Contacts by Accounts"
             * and "Contacts WITHOUT Accounts" are two different reports,
             * the second needing its own explicit "account is null"
             * filter condition, which only makes sense if the join itself
             * never excludes unmatched rows.
             */
            match ($join->kind) {
                'direct' => $query->leftJoin(
                    $target->table . ' as ' . $target->alias,
                    $primary->alias . '.' . $join->localKey,
                    '=',
                    $target->alias . '.' . $join->foreignKey,
                ),
                'pivot' => $query
                    ->leftJoin($join->viaPivot . ' as pv_' . $target->alias, function ($on) use ($primary, $target, $join) {
                        $on->on('pv_' . $target->alias . '.' . $join->pivotLocalKey, '=', $primary->alias . '.' . $primary->primaryKey);
                    })
                    ->leftJoin($target->table . ' as ' . $target->alias, 'pv_' . $target->alias . '.' . $join->pivotForeignKey, '=', $target->alias . '.' . $target->primaryKey),
                'polymorphicOut' => $query->leftJoin($target->table . ' as ' . $target->alias, function ($on) use ($primary, $target, $join) {
                    $on->on($primary->alias . '.' . $join->polymorphicIdColumn, '=', $target->alias . '.' . $target->primaryKey)
                        ->where($primary->alias . '.' . $join->polymorphicTypeColumn, '=', $join->polymorphicTypeValue);
                }),
                'polymorphicIn' => $query->leftJoin($target->table . ' as ' . $target->alias, function ($on) use ($primary, $target, $join) {
                    $on->on($target->alias . '.' . $join->polymorphicIdColumn, '=', $primary->alias . '.' . $primary->primaryKey)
                        ->where($target->alias . '.' . $join->polymorphicTypeColumn, '=', $join->polymorphicTypeValue);
                }),
                default => throw new \InvalidArgumentException("Unknown join kind: {$join->kind}"),
            };

            /*
             * Safe under a LEFT JOIN: an unmatched row's target.deleted_at is
             * NULL (nothing joined), which passes whereNull exactly like a
             * genuinely not-deleted row does — so this excludes soft-deleted
             * JOINED rows without also excluding primary rows that simply
             * have no match at all.
             */
            if ($target->softDeleteColumn !== null) {
                $query->whereNull($target->alias . '.' . $target->softDeleteColumn);
            }
        }
    }

    private function applyStandardFilter(Builder $query, ReportModuleDefinition $primary, ?array $filter, int $subInstituteId): void
    {
        if ($filter === null || !isset($filter['field'])) {
            return;
        }

        $field = $this->requireField($filter['module'] ?? $primary->key, $filter['field'], $subInstituteId);
        [$from, $to] = $this->resolveDatePreset($filter['preset'] ?? 'custom', $filter['from'] ?? null, $filter['to'] ?? null);

        if ($from !== null && $to !== null) {
            $query->whereBetween(DB::raw($field->column), [$from, $to]);
        } elseif ($from !== null) {
            $query->where(DB::raw($field->column), '>=', $from);
        } elseif ($to !== null) {
            $query->where(DB::raw($field->column), '<=', $to);
        }
    }

    /** @return array{0: ?string, 1: ?string} [from, to] as Y-m-d strings, or [null, null] */
    private function resolveDatePreset(string $preset, ?string $customFrom, ?string $customTo): array
    {
        $now = Carbon::now();

        return match ($preset) {
            'today' => [$now->copy()->startOfDay()->toDateString(), $now->copy()->endOfDay()->toDateString()],
            'this_week' => [$now->copy()->startOfWeek()->toDateString(), $now->copy()->endOfWeek()->toDateString()],
            'this_month' => [$now->copy()->startOfMonth()->toDateString(), $now->copy()->endOfMonth()->toDateString()],
            'last_month' => [$now->copy()->subMonthNoOverflow()->startOfMonth()->toDateString(), $now->copy()->subMonthNoOverflow()->endOfMonth()->toDateString()],
            'this_quarter' => [$now->copy()->startOfQuarter()->toDateString(), $now->copy()->endOfQuarter()->toDateString()],
            'this_year' => [$now->copy()->startOfYear()->toDateString(), $now->copy()->endOfYear()->toDateString()],
            'custom' => [$customFrom, $customTo],
            default => [null, null],
        };
    }

    /**
     * A group's OWN `op` governs how ALL of its direct children combine with
     * each other — whether each child is a leaf condition or a nested group.
     * A nested group's `op` governs only ITS OWN children, once recursed
     * into; it must never be read by the PARENT to decide how that nested
     * group combines with its siblings (that was a real bug here: reading
     * the child's own `op` as if it were the sibling-combination rule).
     */
    private function applyAdvancedFilter(Builder $query, ?array $node, int $subInstituteId): void
    {
        if ($node === null || empty($node['conditions'])) {
            return;
        }

        $groupBoolean = strtoupper($node['op'] ?? 'AND') === 'OR' ? 'or' : 'and';

        $query->where(function ($inner) use ($node, $subInstituteId, $groupBoolean) {
            foreach ($node['conditions'] as $condition) {
                if (isset($condition['conditions'])) {
                    // Nested group — combines with its siblings via THIS group's
                    // own boolean; its own `op` only governs its own children,
                    // applied by the recursive call below.
                    $inner->where(function ($nested) use ($condition, $subInstituteId) {
                        $this->applyAdvancedFilter($nested, $condition, $subInstituteId);
                    }, boolean: $groupBoolean);

                    continue;
                }

                $this->applyLeafCondition($inner, $condition, $subInstituteId, $groupBoolean);
            }
        });
    }

    private function applyLeafCondition(Builder $query, array $condition, int $subInstituteId, string $boolean): void
    {
        $field = $this->requireField($condition['module'], $condition['field'], $subInstituteId);
        $column = DB::raw($field->column);
        $operator = $condition['operator'] ?? 'equals';
        $value = $condition['value'] ?? null;

        match ($operator) {
            'contains' => $query->where($column, 'LIKE', '%' . $value . '%', $boolean),
            'starts_with' => $query->where($column, 'LIKE', $value . '%', $boolean),
            'ends_with' => $query->where($column, 'LIKE', '%' . $value, $boolean),
            'between' => $query->whereBetween($column, [$condition['from'] ?? null, $condition['to'] ?? null], $boolean),
            'in' => $query->whereIn($column, (array) $value, $boolean),
            'not_in' => $query->whereNotIn($column, (array) $value, $boolean),
            'is_null' => $query->whereNull($column, $boolean),
            'is_not_null' => $query->whereNotNull($column, $boolean),
            default => $query->where($column, self::OPERATORS[$operator] ?? throw new \InvalidArgumentException("Unknown filter operator: {$operator}"), $value, $boolean),
        };
    }

    private function requireField(string $moduleKey, string $fieldKey, int $subInstituteId): ReportFieldDefinition
    {
        $field = $this->registry->resolve($moduleKey, $fieldKey, $subInstituteId);

        if ($field === null) {
            throw new \InvalidArgumentException("Unknown report field: {$moduleKey}.{$fieldKey}");
        }

        if ($field->isCustomField) {
            throw new \InvalidArgumentException("Custom field {$fieldKey} cannot be used as a filter/group/sort field (display-only in v1)");
        }

        return $field;
    }

    // ── Result shaping per report type ─────────────────────────────────

    /**
     * Custom fields (Docs/reports-engine/PLAN.md §5) are display-only, and
     * only on the PRIMARY module's own record — resolved here via ONE extra
     * `g2g_custom_field_values` lookup keyed by the page's own primary ids,
     * not a join per field (the EAV value column is untyped TEXT; a join
     * would also multiply rows per answered field). A secondary (joined)
     * module's custom field is rejected earlier, at save time
     * (ReportController::validateDefinition) — merging it correctly would
     * need that module's OWN primary key value per row, which runTabular's
     * single `__primary_id` (always the PRIMARY module's id) cannot express.
     */
    private function runTabular(Builder $query, ReportModuleDefinition $primary, array $definition, int $subInstituteId): array
    {
        $columns = array_values(array_filter($definition['columns'] ?? [], fn ($c) => $c['visible'] ?? true));

        $realColumns = [];
        $customColumns = [];

        foreach ($columns as $col) {
            $field = $this->registry->resolve($col['module'], $col['field'], $subInstituteId);

            if ($field === null) {
                throw new \InvalidArgumentException("Unknown report field: {$col['module']}.{$col['field']}");
            }

            if ($field->isCustomField) {
                $customColumns[] = ['key' => $col['module'] . '.' . $col['field'], 'field' => $field];
            } else {
                $realColumns[] = $col;
            }
        }

        $select = [$primary->alias . '.' . $primary->primaryKey . ' as __primary_id'];

        foreach ($realColumns as $col) {
            $field = $this->requireField($col['module'], $col['field'], $subInstituteId);
            $select[] = DB::raw($field->column . ' as `' . $col['module'] . '.' . $col['field'] . '`');
        }

        foreach ($definition['sort_columns'] ?? [] as $sort) {
            $field = $this->requireField($sort['module'], $sort['field'], $subInstituteId);
            $query->orderBy(DB::raw($field->column), ($sort['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc');
        }

        $total = (clone $query)->count();
        $page = max(1, (int) ($definition['page'] ?? 1));
        $perPage = min(self::MAX_TABULAR_ROWS, max(1, (int) ($definition['per_page'] ?? 50)));

        $rows = $query->select($select)
            ->forPage($page, $perPage)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();

        if ($customColumns !== [] && $rows !== []) {
            $recordIds = array_column($rows, '__primary_id');
            $fieldIds = array_values(array_unique(array_map(fn ($c) => $c['field']->customFieldId, $customColumns)));

            $answers = DB::table('g2g_custom_field_values')
                ->where('sub_institute_id', $subInstituteId)
                ->where('record_table', $primary->table)
                ->whereIn('record_id', $recordIds)
                ->whereIn('field_id', $fieldIds)
                ->get(['record_id', 'field_id', 'value']);

            $byRecordThenField = [];

            foreach ($answers as $answer) {
                $byRecordThenField[$answer->record_id][$answer->field_id] = $answer->value;
            }

            foreach ($rows as &$row) {
                foreach ($customColumns as $custom) {
                    $row[$custom['key']] = $byRecordThenField[$row['__primary_id']][$custom['field']->customFieldId] ?? null;
                }
            }

            unset($row);
        }

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    private function runSummary(Builder $query, array $definition, int $subInstituteId): array
    {
        $groupFields = array_map(
            fn ($g) => $this->requireField($g['module'], $g['field'], $subInstituteId),
            $definition['group_by'] ?? [],
        );

        if ($groupFields === []) {
            throw new \InvalidArgumentException('A summary report requires at least one group_by field');
        }

        $select = [];
        $groupByRaw = [];

        foreach ($groupFields as $i => $field) {
            $select[] = DB::raw($field->column . " as `g{$i}`");
            $groupByRaw[] = DB::raw($field->column);
        }

        $select[] = DB::raw('COUNT(*) as `__count`');

        foreach ($definition['columns'] ?? [] as $col) {
            if (!($col['aggregate'] ?? null)) {
                continue;
            }

            $field = $this->requireField($col['module'], $col['field'], $subInstituteId);
            $select[] = DB::raw($this->aggregateSql($col['aggregate'], $field->column) . " as `agg_{$col['module']}.{$col['field']}_{$col['aggregate']}`");
        }

        $rows = $query->select($select)->groupBy($groupByRaw)->limit(self::MAX_GROUPS)->get();

        $groups = $rows->map(function ($row) use ($groupFields) {
            $arr = (array) $row;
            $labelParts = [];

            foreach ($groupFields as $i => $field) {
                $labelParts[] = (string) ($arr["g{$i}"] ?? '—');
            }

            return array_merge(['label' => implode(' / ', $labelParts), 'count' => (int) $arr['__count']], array_diff_key($arr, array_flip(array_merge(array_map(fn ($i) => "g{$i}", array_keys($groupFields)), ['__count']))));
        })->all();

        return ['groups' => $groups];
    }

    private function runPivot(Builder $query, array $definition, int $subInstituteId): array
    {
        $pivot = $definition['pivot'] ?? null;

        if (!$pivot || empty($pivot['rows']) || empty($pivot['columns']) || empty($pivot['values'])) {
            throw new \InvalidArgumentException('A pivot report requires rows, columns and values');
        }

        $rowField = $this->requireField($pivot['rows'][0]['module'], $pivot['rows'][0]['field'], $subInstituteId);
        $colField = $this->requireField($pivot['columns'][0]['module'], $pivot['columns'][0]['field'], $subInstituteId);
        $valueSpec = $pivot['values'][0];
        $valueField = $this->requireField($valueSpec['module'], $valueSpec['field'], $subInstituteId);
        $aggregate = $valueSpec['aggregate'] ?? 'count';

        $rows = $query->select([
            DB::raw($rowField->column . ' as `row_dim`'),
            DB::raw($colField->column . ' as `col_dim`'),
            DB::raw($this->aggregateSql($aggregate, $valueField->column) . ' as `cell_value`'),
        ])
            ->groupBy(DB::raw($rowField->column), DB::raw($colField->column))
            ->limit(self::MAX_GROUPS)
            ->get();

        $rowLabels = [];
        $columnLabels = [];
        $cells = [];

        foreach ($rows as $row) {
            $r = (string) ($row->row_dim ?? '—');
            $c = (string) ($row->col_dim ?? '—');

            if (!in_array($r, $rowLabels, true)) {
                $rowLabels[] = $r;
            }

            if (!in_array($c, $columnLabels, true)) {
                $columnLabels[] = $c;
            }

            $cells[$r][$c] = (float) $row->cell_value;
        }

        $matrix = [];
        $rowTotals = [];
        $columnTotals = array_fill(0, count($columnLabels), 0);

        foreach ($rowLabels as $r) {
            $line = [];
            $rowTotal = 0;

            foreach ($columnLabels as $ci => $c) {
                $v = $cells[$r][$c] ?? 0;
                $line[] = $v;
                $rowTotal += $v;
                $columnTotals[$ci] += $v;
            }

            $matrix[] = $line;
            $rowTotals[] = $rowTotal;
        }

        return [
            'row_labels' => $rowLabels,
            'column_labels' => $columnLabels,
            'matrix' => $matrix,
            'row_totals' => $rowTotals,
            'column_totals' => array_values($columnTotals),
        ];
    }

    private function runChart(Builder $query, array $definition, int $subInstituteId): array
    {
        $chart = $definition['chart'] ?? null;

        if (!$chart || empty($chart['group_by']) || empty($chart['data_fields'])) {
            throw new \InvalidArgumentException('A chart report requires group_by and at least one data field');
        }

        $dataFields = array_slice($chart['data_fields'], 0, 3); // vtiger's own cap for bar/line charts
        $groupField = $this->requireField($chart['group_by']['module'], $chart['group_by']['field'], $subInstituteId);

        $select = [DB::raw($groupField->column . ' as `label`')];

        foreach ($dataFields as $i => $spec) {
            $field = $this->requireField($spec['module'], $spec['field'], $subInstituteId);
            $select[] = DB::raw($this->aggregateSql($spec['aggregate'] ?? 'count', $field->column) . " as `s{$i}`");
        }

        $rows = $query->select($select)->groupBy(DB::raw($groupField->column))->limit(self::MAX_GROUPS)->get();

        $labels = $rows->map(fn ($r) => (string) ($r->label ?? '—'))->all();
        $series = [];

        foreach ($dataFields as $i => $spec) {
            $series[] = [
                'name' => $spec['label'] ?? ($spec['aggregate'] ?? 'count'),
                'data' => $rows->map(fn ($r) => (float) ($r->{"s{$i}"} ?? 0))->all(),
            ];
        }

        return ['labels' => $labels, 'series' => $series];
    }

    private function aggregateSql(string $aggregate, string $column): string
    {
        return match (strtolower($aggregate)) {
            'sum' => "SUM({$column})",
            'avg' => "AVG({$column})",
            'min' => "MIN({$column})",
            'max' => "MAX({$column})",
            'count' => 'COUNT(*)',
            default => throw new \InvalidArgumentException("Unknown aggregate: {$aggregate}"),
        };
    }
}
